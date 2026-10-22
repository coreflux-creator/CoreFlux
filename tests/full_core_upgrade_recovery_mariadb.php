<?php
/** Native dump/restore after partial upgrade DDL, only on a newly created disposable database. */
declare(strict_types=1);
$ci = getenv('CI') === 'true';
$local = getenv('COREFLUX_DELIVERY_LOCAL_FIXTURE') === '1' && (string)getenv('COREFLUX_DELIVERY_TEST_PASSWORD') !== '';
if (PHP_SAPI !== 'cli' || (!$ci && !$local) || !in_array('--confirm-disposable-fixture', $argv, true)) {
    throw new RuntimeException('Explicit disposable recovery fixture required.');
}
require_once __DIR__ . '/../deploy/full_core_upgrade_executor.php';
$password = $ci ? 'root' : (string)getenv('COREFLUX_DELIVERY_TEST_PASSWORD');
$db = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$name = 'coreflux_upgrade_fixture_recovery_' . gmdate('YmdHis') . '_' . getmypid();
if (!preg_match('/^coreflux_upgrade_fixture_recovery_[0-9_]+$/D', $name) || strlen($name) > 64) exit(2);
$directory = str_replace('\\', '/', sys_get_temp_dir()) . '/' . $name;
if (!mkdir($directory, 0700) || !mkdir($directory . '/candidate', 0700) || !mkdir($directory . '/live', 0700)
    || !mkdir($directory . '/private', 0700)) throw new RuntimeException('New private fixture directory required.');
$private = $directory . '/private'; $ini = $private . '/client.cnf';
$quote = static fn(string $s): string => '"' . str_replace(["\\", '"', "\n", "\r"], ["\\\\", '\\"', '\\n', '\\r'], $s) . '"';
$container = $ci ? (string)getenv('COREFLUX_RECOVERY_MARIADB_CONTAINER') : '';
if ($ci && !preg_match('/^[a-f0-9]{12,64}$/D', $container)) throw new RuntimeException('Exact disposable CI MariaDB container required.');
$bin = rtrim((string)getenv('COREFLUX_RECOVERY_MARIADB_BIN'), '/\\');
if (!$ci && (!$bin || !is_executable($bin . '/mariadb-dump.exe') || !is_executable($bin . '/mysql.exe'))) {
    throw new RuntimeException('Explicit local MariaDB client directory required.');
}
$command = static function (array $args, string $input, string $output) use ($private): void {
    $process = proc_open($args, [0 => ['file', $input, 'r'], 1 => ['file', $output, 'w'],
        2 => ['file', $private . '/client-errors.log', 'a']], $pipes);
    if (!is_resource($process) || proc_close($process) !== 0) throw new RuntimeException('Native recovery client failed; inspect the private fixture log.');
    chmod($output, 0600);
};
$null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$containerIni = '/tmp/' . $name . '.cnf'; $copied = false; $created = false; $checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $message);
    $checks++; echo "PASS $message\n";
};
$client = static function (bool $dump) use ($ci, $container, $containerIni, $bin, $ini): array {
    return $ci ? ['docker', 'exec', '-i', $container, $dump ? 'mariadb-dump' : 'mariadb', '--defaults-extra-file=' . $containerIni]
        : [$bin . ($dump ? '/mariadb-dump.exe' : '/mysql.exe'), '--defaults-extra-file=' . $ini];
};
try {
    file_put_contents($ini, "[client]\nhost=127.0.0.1\nport=3306\nuser=root\npassword=" . $quote($password) . "\n"); chmod($ini, 0600);
    if ($ci) { $command(['docker', 'cp', $ini, $container . ':' . $containerIni], $null, $private . '/copy.log'); $copied = true; }
    $db->exec('CREATE DATABASE ' . $name); $created = true; $db->exec('USE ' . $name);
    $assert(str_contains((string)$db->query('SELECT VERSION()')->fetchColumn(), 'MariaDB'), 'native recovery uses MariaDB');
    $db->exec('CREATE TABLE _migrations(filename VARCHAR(190) PRIMARY KEY,sha256 CHAR(64) NOT NULL,applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,last_error TEXT NULL,duration_ms INT NULL) ENGINE=InnoDB');
    $db->exec('CREATE TABLE coreflux_migrations(file_path VARCHAR(255) PRIMARY KEY,checksum_sha CHAR(64),applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
    $baseline = 'CREATE TABLE balances(id INT PRIMARY KEY, tenant_id INT, amount DECIMAL(18,2), memo VARCHAR(100), evidence BLOB) ENGINE=InnoDB;';
    $db->exec($baseline);
    $db->exec('CREATE TABLE change_audit(id INT AUTO_INCREMENT PRIMARY KEY, balance_id INT, before_amount DECIMAL(18,2), after_amount DECIMAL(18,2)) ENGINE=InnoDB');
    $db->exec('CREATE TRIGGER balances_changed AFTER UPDATE ON balances FOR EACH ROW INSERT INTO change_audit(balance_id,before_amount,after_amount) VALUES(OLD.id,OLD.amount,NEW.amount)');
    $db->exec('CREATE VIEW balance_totals AS SELECT tenant_id,SUM(amount) AS total FROM balances GROUP BY tenant_id');
    $insert = $db->prepare('INSERT INTO balances VALUES(?,?,?,?,?)');
    $insert->execute([1, 999, '123456789.07', "Synthetic\nUnicode café and 'quoted' text", "\0\xff\x01"]);
    $insert->execute([2, 998, '-42.19', 'Other synthetic tenant', "\x7f\0\xfe"]);
    $path = 'core/migrations/001_baseline.sql';
    foreach (['candidate', 'live'] as $tree) { mkdir($directory . '/' . $tree . '/core/migrations', 0700, true); file_put_contents($directory . '/' . $tree . '/' . $path, $baseline); }
    $migration = 'ALTER TABLE balances ADD partial_marker INT; UPDATE balances SET amount=amount+12.34 WHERE id=1; CREATE TABLE interrupted_only(id INT); UPDATE missing_recovery_fixture SET id=1;';
    $pending = 'core/migrations/002_partial.sql'; file_put_contents($directory . '/candidate/' . $pending, $migration);
    $db->prepare('INSERT INTO _migrations(filename,sha256) VALUES(?,?)')->execute(['001_baseline.sql', corefluxMigrationHash($baseline)]);
    $db->prepare('INSERT INTO coreflux_migrations(file_path,checksum_sha) VALUES(?,?)')->execute([$path, corefluxMigrationHash($baseline)]);
    $manifest = ['product' => 'full-core-staging', 'commit' => str_repeat('b',40), 'files' => [$path => hash('sha256',$baseline), $pending => hash('sha256',$migration)]];
    $snapshot = static function () use ($db): array {
        return ['rows' => $db->query('SELECT id,tenant_id,amount,memo,HEX(evidence) AS evidence FROM balances ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            'audit' => $db->query('SELECT * FROM change_audit ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            'view' => $db->query('SELECT * FROM balance_totals ORDER BY tenant_id')->fetchAll(PDO::FETCH_ASSOC),
            'canonical' => $db->query('SELECT * FROM _migrations ORDER BY filename')->fetchAll(PDO::FETCH_ASSOC),
            'legacy' => $db->query('SELECT * FROM coreflux_migrations ORDER BY file_path')->fetchAll(PDO::FETCH_ASSOC),
            'columns' => $db->query('SHOW COLUMNS FROM balances')->fetchAll(PDO::FETCH_ASSOC)];
    };
    $before = $snapshot();
    $ledger = fullCoreUpgradeLedger($db); $legacy = $db->query('SELECT file_path,checksum_sha FROM coreflux_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);
    $plan = fullCoreMigrationPlan($manifest, $directory . '/candidate', $directory . '/live', $ledger, $legacy);
    $assert($plan['source_review_ready'], 'exact populated fixture upgrade plan is ready');
    $dump = $private . '/before.sql';
    $command(array_merge($client(true), ['--single-transaction', '--skip-lock-tables', '--hex-blob', '--routines', '--events', '--triggers', '--default-character-set=utf8mb4', $name]), $null, $dump);
    $assert(filesize($dump) > 1000, 'native backup produced a populated dump');
    $dumpHash = hash_file('sha256', $dump);
    $receipt = ['database' => $name, 'dump_sha256' => $dumpHash, 'plan_sha256' => $plan['plan_sha256'], 'snapshot_sha256' => hash('sha256',serialize($before))];
    file_put_contents($private . '/backup-receipt.json', json_encode($receipt, JSON_PRETTY_PRINT)); chmod($private . '/backup-receipt.json',0600);
    $failed = false;
    try { fullCoreUpgradeRehearse($db, $name, $plan['plan_sha256'], $manifest, $directory . '/candidate', $directory . '/live', $private . '/partial.jsonl', static fn() => true); }
    catch (RuntimeException $error) { $failed = true; }
    $assert($failed && $db->query("SHOW COLUMNS FROM balances LIKE 'partial_marker'")->fetch() !== false, 'partial upgrade leaves committed DDL');
    $assert($db->query('SELECT amount FROM balances WHERE id=1')->fetchColumn() === '123456801.41'
        && (int)$db->query('SELECT COUNT(*) FROM change_audit')->fetchColumn() === 1, 'partial business write and trigger audit are actually committed');
    $assert(str_starts_with((string)$db->query("SELECT sha256 FROM _migrations WHERE filename='002_partial.sql'")->fetchColumn(), 'FAIL:'), 'incomplete migration requires recovery');
    $assert($db->query('SELECT amount FROM balances WHERE id=2')->fetchColumn() === '-42.19', 'other tenant stays unchanged before recovery');
    // Deliberate destructive recovery is confined to this database created by this process.
    // Verify exact identity and backup before dropping anything; production has no entrypoint here.
    $assert($created && $receipt['database'] === $name && $db->query('SELECT DATABASE()')->fetchColumn() === $name
        && hash_equals($receipt['dump_sha256'], hash_file('sha256',$dump)), 'target identity and exact backup verified before fixture recovery');
    $db->exec('USE mysql'); $db->exec('DROP DATABASE ' . $name); $created = false;
    $db->exec('CREATE DATABASE ' . $name); $created = true;
    $command(array_merge($client(false), ['--default-character-set=utf8mb4', $name]), $dump, $private . '/restore.log');
    $db->exec('USE ' . $name);
    $assert($snapshot() === $before, 'native restore recovers exact decimals binary text views both ledgers and original columns');
    $assert((int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='interrupted_only'")->fetchColumn() === 0,
        'empty-target recovery removes objects created by the interrupted upgrade');
    $assert((int)$db->query("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name='balances_changed'")->fetchColumn() === 1,
        'database trigger definition is restored');
    $db->beginTransaction(); $db->exec('UPDATE balances SET amount=amount+0.01 WHERE id=1');
    $assert((int)$db->query('SELECT COUNT(*) FROM change_audit')->fetchColumn() === 1, 'restored trigger actually executes'); $db->rollBack();
    $assert($snapshot() === $before && is_file($private . '/partial.jsonl'), 'rollback of the trigger check preserves restored baseline and failed-upgrade journal');
    $recoveredPlan = fullCoreMigrationPlan($manifest, $directory . '/candidate', $directory . '/live', fullCoreUpgradeLedger($db),
        $db->query('SELECT file_path,checksum_sha FROM coreflux_migrations')->fetchAll(PDO::FETCH_KEY_PAIR));
    $assert($recoveredPlan['plan_sha256'] === $receipt['plan_sha256'], 'restored history and original source reproduce the reviewed plan');
    if (PHP_OS_FAMILY !== 'Windows') $assert((fileperms($dump)&0777) === 0600, 'backup remains private');
    echo "Native upgrade recovery: $checks checks passed; synthetic dump and receipts retained at $private\n";
} finally {
    if ($db->inTransaction()) $db->rollBack();
    if ($created) { $db->exec('USE mysql'); $db->exec('DROP DATABASE ' . $name); }
    if ($copied) $command(['docker','exec',$container,'rm','--',$containerIni], $null, $private . '/credential-cleanup.log');
    if (is_file($ini)) unlink($ini);
}
