<?php
/** Populated disposable rehearsals, including committed DDL and interrupted/uncertain outcomes. */
declare(strict_types=1);
$ci = getenv('CI') === 'true';
$local = getenv('COREFLUX_DELIVERY_LOCAL_FIXTURE') === '1' && (string) getenv('COREFLUX_DELIVERY_TEST_PASSWORD') !== '';
if (PHP_SAPI !== 'cli' || (!$ci && !$local) || !in_array('--confirm-disposable-fixture', $argv, true)) {
    throw new RuntimeException('Explicit disposable fixture required.');
}
require_once __DIR__ . '/../deploy/full_core_upgrade_executor.php';
$password = $ci ? 'root' : (string) getenv('COREFLUX_DELIVERY_TEST_PASSWORD');
$db = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
if (in_array('--crash-child', $argv, true)) {
    $input = json_decode((string) file_get_contents((string) getenv('COREFLUX_UPGRADE_CRASH_INPUT')), true, 512, JSON_THROW_ON_ERROR);
    if (!preg_match('/^coreflux_upgrade_fixture_[a-z0-9_]+$/D', $input['database'] ?? '')) exit(72);
    $db->exec('USE ' . $input['database']);
    [$package, $live, $manifest, $plan] = $input['state']; $calls = 0;
    $guard = static function () use (&$calls): bool { if (++$calls === 4) exit(73); return true; };
    fullCoreUpgradeRehearse($db, $input['database'], $plan['plan_sha256'], $manifest, $package, $live, $input['journal'], $guard);
    exit(74);
}
$name = 'coreflux_upgrade_fixture_' . gmdate('YmdHis') . '_' . getmypid();
$directory = str_replace('\\', '/', sys_get_temp_dir()) . '/' . $name;
mkdir($directory, 0700); mkdir($directory . '/private', 0700);
$created = false; $checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $message);
    $checks++; echo 'PASS ' . $message . "\n";
};
$refuses = static function (callable $fn, string $message) use ($assert): void {
    try { $fn(); } catch (Throwable $error) { $assert(true, $message); return; }
    $assert(false, $message);
};
$write = static function (string $root, string $path, string $contents): void {
    if (!is_dir(dirname($root . '/' . $path))) mkdir(dirname($root . '/' . $path), 0700, true);
    file_put_contents($root . '/' . $path, $contents);
};
try {
    $db->exec('CREATE DATABASE ' . $name); $created = true; $db->exec('USE ' . $name);
    $db->exec('CREATE TABLE _migrations(filename VARCHAR(190) PRIMARY KEY,sha256 CHAR(64) NOT NULL,applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,last_error TEXT NULL,duration_ms INT NULL) ENGINE=InnoDB');
    $db->exec('CREATE TABLE coreflux_migrations(file_path VARCHAR(255) PRIMARY KEY,checksum_sha CHAR(64),applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
    $baseline = 'CREATE TABLE balances(id INT PRIMARY KEY, amount DECIMAL(12,2)) ENGINE=InnoDB;';
    $db->exec($baseline); $db->exec('INSERT INTO balances VALUES(1,25.19),(2,99.00)');
    $case = static function (string $id, array $migrations) use ($directory, $db, $write, $baseline): array {
        $root = $directory . '/' . $id; mkdir($root, 0700); mkdir($root . '/candidate', 0700); mkdir($root . '/live', 0700);
        $package = $root . '/candidate'; $live = $root . '/live';
        $manifest = ['product' => 'full-core-staging', 'commit' => str_repeat('a', 40), 'files' => []];
        $migrations = ['core/migrations/001_baseline.sql' => $baseline] + $migrations;
        foreach ($migrations as $path => $sql) { $write($package, $path, $sql); $manifest['files'][$path] = hash('sha256', $sql); }
        $write($live, 'core/migrations/001_baseline.sql', $baseline);
        $db->exec('DELETE FROM _migrations'); $db->exec('DELETE FROM coreflux_migrations');
        $insert = $db->prepare('INSERT INTO _migrations(filename,sha256,last_error) VALUES(?,?,NULL)');
        $insert->execute(['001_baseline.sql', corefluxMigrationHash($baseline)]);
        $plan = fullCoreMigrationPlan($manifest, $package, $live, fullCoreUpgradeLedger($db), []);
        return [$package, $live, $manifest, $plan];
    };
    $run = static function (array $state, string $journal, ?callable $guard = null) use ($db, $name, $directory): array {
        [$package, $live, $manifest, $plan] = $state;
        return fullCoreUpgradeRehearse($db, $name, $plan['plan_sha256'], $manifest, $package, $live,
            $directory . '/private/' . $journal . '.jsonl', $guard ?? static fn() => true);
    };
    $events = static fn(string $journal): array => array_map(static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
        file($directory . '/private/' . $journal . '.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    $state = $case('success', ['core/migrations/002_change.sql' => <<<'SQL'
ALTER TABLE balances ADD note VARCHAR(100) NULL;
UPDATE balances SET note='Preserve -- ; /*literal*/ #',amount=amount+1 WHERE id=1;
SET @sql='SELECT 1'; PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SQL]);
    $oldRow = $db->query("SELECT * FROM _migrations WHERE filename='001_baseline.sql'")->fetch(PDO::FETCH_ASSOC);
    $refuses(fn() => fullCoreUpgradeRehearse($db, 'production', $state[3]['plan_sha256'], $state[2], $state[0], $state[1], $directory . '/private/prohibited.jsonl', static fn() => true), 'production-like database identity is rejected');
    $assert(!file_exists($directory . '/private/prohibited.jsonl'), 'refused production attempt creates no journal');
    $refuses(fn() => $run($state, 'no-maintenance', static fn() => false), 'unheld maintenance guard refuses before SQL');
    $assert((int)$db->query('SELECT COUNT(*) FROM _migrations')->fetchColumn() === 1, 'preflight refusal leaves ledger untouched');
    $initialMode = (string) $db->query('SELECT @@SESSION.sql_mode')->fetchColumn();
    $db->exec("SET SESSION sql_mode=CONCAT(@@SESSION.sql_mode,',NO_BACKSLASH_ESCAPES')");
    $refuses(fn() => $run($state, 'quoting'), 'incompatible SQL quoting mode is refused');
    $db->exec('SET SESSION sql_mode=' . $db->quote($initialMode));
    $receipt = $run($state, 'success');
    $assert($receipt['applied_files'] === ['core/migrations/002_change.sql'] && $receipt['executed_statements'] === 6, 'exact reviewed migration runs and drains prepared result sets');
    $row = $db->query('SELECT * FROM balances WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    $assert($row['amount'] === '26.19' && $row['note'] === 'Preserve -- ; /*literal*/ #', 'populated row receives exact change without corrupting quoted content');
    $assert($db->query('SELECT amount FROM balances WHERE id=2')->fetchColumn() === '99.00', 'unrelated populated row is unchanged');
    $assert($db->query("SELECT * FROM _migrations WHERE filename='001_baseline.sql'")->fetch(PDO::FETCH_ASSOC) === $oldRow, 'existing successful migration row is not rewritten');
    $assert((int)$db->query('SELECT COUNT(*) FROM coreflux_migrations')->fetchColumn() === 0, 'secondary ledger is untouched');
    $log = $events('success');
    $assert($log[0]['event'] === 'start' && end($log)['event'] === 'complete'
        && count(array_filter($log, static fn($e) => $e['event'] === 'statement_start')) === 6, 'durable journal covers every statement and terminal completion');
    $assert(!str_contains((string)file_get_contents($directory . '/private/success.jsonl'), 'Preserve --'), 'journal contains hashes instead of source data or SQL');
    $refuses(fn() => $run($state, 'success'), 'same journal cannot be replayed');
    $refuses(fn() => $run($state, 'success-new-journal'), 'fresh filename cannot replay a stale completed plan');
    $assert($db->query('SELECT amount FROM balances WHERE id=1')->fetchColumn() === '26.19', 'replay attempts perform no duplicate update');

    $state = $case('partial', ['core/migrations/002_partial.sql' => 'ALTER TABLE balances ADD partial_marker INT NULL; UPDATE balances SET amount=amount+2 WHERE id=1; UPDATE unavailable_upgrade_fixture SET x=1; UPDATE balances SET amount=amount+100 WHERE id=1;',
        'core/migrations/003_must_not_run.sql' => 'CREATE TABLE should_not_exist(id INT);']);
    $refuses(fn() => $run($state, 'partial'), 'first SQL failure stops the remaining statements and files');
    $assert($db->query('SELECT amount FROM balances WHERE id=1')->fetchColumn() === '28.19'
        && $db->query("SHOW COLUMNS FROM balances LIKE 'partial_marker'")->fetch() !== false, 'fixture proves earlier DDL and data changes remain committed after failure');
    $assert((int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='should_not_exist'")->fetchColumn() === 0, 'later migration is not executed');
    $failed = $db->query("SELECT sha256,last_error FROM _migrations WHERE filename='002_partial.sql'")->fetch(PDO::FETCH_ASSOC);
    $assert(str_starts_with($failed['sha256'], 'FAIL:') && $failed['last_error'] !== null, 'durable incomplete marker prevents false success after partial DDL');
    $log = $events('partial');
    $assert(end($log)['event'] === 'failed' && end($log)['recovery_required'], 'failed receipt explicitly requires recovery');
    $refuses(fn() => $run($state, 'partial-again'), 'new journal cannot bypass incomplete database history');

    $state = $case('guard-loss', ['core/migrations/002_guard.sql' => 'ALTER TABLE balances ADD guard_marker INT NULL; UPDATE balances SET amount=amount+100 WHERE id=1;']);
    $calls = 0; $guard = static function () use (&$calls): bool { return ++$calls < 4; };
    $refuses(fn() => $run($state, 'guard-loss', $guard), 'losing maintenance between statements stops execution');
    $assert($db->query('SELECT amount FROM balances WHERE id=1')->fetchColumn() === '28.19'
        && $db->query("SHOW COLUMNS FROM balances LIKE 'guard_marker'")->fetch() !== false, 'maintenance-loss fixture retains first DDL and skips following write');
    $refuses(fn() => $run($state, 'guard-retry'), 'interrupted run remains non-replayable after maintenance returns');

    $state = $case('process-loss', ['core/migrations/002_crash.sql' => 'ALTER TABLE balances ADD crash_marker INT NULL; UPDATE balances SET amount=amount+100 WHERE id=1;']);
    $crashInput = $directory . '/private/crash-input.json';
    file_put_contents($crashInput, json_encode(['database' => $name, 'state' => $state, 'journal' => $directory . '/private/crash.jsonl'], JSON_THROW_ON_ERROR));
    chmod($crashInput, 0600);
    $command = [PHP_BINARY];
    if (PHP_OS_FAMILY === 'Windows') $command = array_merge($command, ['-d', 'extension_dir=' . ini_get('extension_dir'), '-d', 'extension=pdo_mysql']);
    $command = array_merge($command, [__FILE__, '--confirm-disposable-fixture', '--crash-child']);
    $environment = getenv(); $environment['COREFLUX_UPGRADE_CRASH_INPUT'] = $crashInput;
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $directory . '/private/crash-output.log', 'w'],
        2 => ['file', $directory . '/private/crash-error.log', 'w']], $pipes, null, $environment);
    if (!is_resource($process)) throw new RuntimeException('Cannot start disposable crash fixture.');
    fclose($pipes[0]); $exit = proc_close($process);
    $assert($exit === 73, 'child process exits abruptly after committed DDL');
    $assert($db->query('SELECT amount FROM balances WHERE id=1')->fetchColumn() === '28.19'
        && $db->query("SHOW COLUMNS FROM balances LIKE 'crash_marker'")->fetch() !== false, 'crash leaves earlier DDL and skips later financial-marker update');
    $log = $events('crash');
    $assert(end($log)['event'] === 'statement_complete'
        && str_starts_with((string)$db->query("SELECT sha256 FROM _migrations WHERE filename='002_crash.sql'")->fetchColumn(), 'FAIL:'), 'missing terminal journal event still has a durable database recovery marker');
    $refuses(fn() => $run($state, 'crash-retry'), 'restart with another journal cannot rerun an interrupted migration');

    $state = $case('compile-all', ['core/migrations/002_compile.sql' => 'UPDATE balances SET amount=amount+100 WHERE id=1;', 'core/migrations/003_bad.sql' => "SELECT 'unterminated"]);
    $refuses(fn() => $run($state, 'compile-all'), 'every migration is parsed before the first one starts');
    $assert($db->query('SELECT amount FROM balances WHERE id=1')->fetchColumn() === '28.19' && (int)$db->query('SELECT COUNT(*) FROM _migrations')->fetchColumn() === 1, 'later parse failure leaves earlier SQL and history untouched');

    $state = $case('concurrency', ['core/migrations/002_concurrency.sql' => 'UPDATE balances SET amount=amount+100 WHERE id=1;']);
    $other = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $name, 'root', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $lock = 'core-upgrade:' . substr(hash('sha256', $name), 0, 50);
    $claim = $other->prepare('SELECT GET_LOCK(?,0)'); $claim->execute([$lock]);
    $refuses(fn() => $run($state, 'locked'), 'concurrent executor cannot acquire the same upgrade');
    $release = $other->prepare('SELECT RELEASE_LOCK(?)'); $release->execute([$lock]);
    $calls = 0; $guard = static function () use (&$calls, $other): bool {
        if (++$calls === 3) $other->exec("INSERT INTO coreflux_migrations(file_path,checksum_sha) VALUES('core/migrations/unexpected.sql',REPEAT('f',64))");
        return true;
    };
    $refuses(fn() => $run($state, 'history-drift', $guard), 'concurrent ledger drift stops before its next SQL statement');
    $assert($db->query('SELECT amount FROM balances WHERE id=1')->fetchColumn() === '28.19', 'history conflict performs no business update');
    $assert((int)$other->query('SELECT IS_FREE_LOCK(' . $other->quote($lock) . ')')->fetchColumn() === 1, 'failed runs release their advisory lock');
    if (PHP_OS_FAMILY !== 'Windows') $assert((fileperms($directory . '/private/success.jsonl') & 0777) === 0600, 'journal permissions remain private');
    echo "Upgrade executor MariaDB: $checks checks passed. Private fixture journals: $directory/private\n";
} finally {
    if ($created) { $db->exec('USE mysql'); $db->exec('DROP DATABASE ' . $name); }
}
