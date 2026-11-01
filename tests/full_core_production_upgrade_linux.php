<?php
/** Native combined release fixture. The production-shaped database exists only inside an isolated container. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !is_file('/run/core-production-upgrade-fixture') || realpath('/fixture') !== '/fixture') {
    throw new RuntimeException('Explicit disposable production-upgrade container required.');
}
require_once __DIR__ . '/../deploy/full_core_upgrade_executor.php';
require_once __DIR__ . '/../deploy/full_core_release_files.php';
require_once __DIR__ . '/../deploy/full_core_maintenance_channel.php';
const FIXTURE_ROOT = FullCoreProductionUpgradeContext::ROOT;
const FIXTURE_DB = FullCoreProductionUpgradeContext::DATABASE;

function fixtureDb(string $user = 'grcudkpvcd', bool $select = true): PDO
{
    return new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;' . ($select ? 'dbname=' . FIXTURE_DB . ';' : '') . 'charset=utf8mb4',
        $user, '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
}
function fixtureWrite(string $path, string $bytes, int $uid, int $mode = 0600, int $mtime = 1700000000): void
{
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
    file_put_contents($path, $bytes); chown($path, $uid); chgrp($path, 33); chmod($path, $mode); touch($path, $mtime);
}
function fixturePrivate(string $path, int $uid): void
{
    mkdir($path, 0700); chown($path, $uid); chgrp($path, 33); chmod($path, 0700);
}
function fixtureMeta(string $path, bool $file = true): array
{
    clearstatcache(true, $path); $s = stat($path);
    $v = ['uid' => $s['uid'], 'gid' => $s['gid'], 'mode' => $s['mode'] & 07777];
    if ($file) $v += ['sha256' => hash_file('sha256', $path), 'size' => $s['size'], 'mtime' => $s['mtime']];
    return $v;
}
function fixtureHttp(array $spec, ?array $body = null, string $path = ''): array
{
    $path = $path ?: '/' . $spec['path'];
    $context = stream_context_create(['http' => ['method' => $body === null ? 'GET' : 'POST', 'timeout' => 10,
        'ignore_errors' => true, 'header' => "Host: fixture.local\r\nContent-Type: application/json\r\nX-CoreFlux-Maintenance: " . $spec['token'] . "\r\nConnection: close\r\n",
        'content' => $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR)]]);
    $bytes = file_get_contents('http://127.0.0.1' . $path, false, $context);
    preg_match('~^HTTP/\S+ (\d+)~', $http_response_header[0] ?? '', $match);
    return [(int)($match[1] ?? 0), $bytes];
}
function fixtureCommand(array $command, string $input = '/dev/null', ?string $output = null): array
{
    $p = proc_open($command, [0 => ['file', $input, 'r'], 1 => $output ? ['file', $output, 'w'] : ['pipe', 'w'],
        2 => ['file', '/fixture/commands.stderr', 'a']], $pipes);
    if (!is_resource($p)) throw new RuntimeException('Fixture process unavailable.');
    $bytes = $output ? '' : stream_get_contents($pipes[1]); if (!$output) fclose($pipes[1]);
    return [proc_close($p), $bytes];
}
function fixtureOwner(string $base, int $uid, string $action): array
{
    return fixtureCommand(['/usr/sbin/runuser', '-u', $uid === 1003 ? 'coremaster' : 'coreapp', '--',
        PHP_BINARY, __FILE__, '--owner', $base, $action]);
}
/** This container's entire writer inventory is known; report observed extra owner processes, not a supplied quiet flag. */
function fixtureWriters(string $base, string $inventoryHash): array
{
    $extra = 0; $unmanaged = 0; $upgradeProcesses = 0;
    $expected = [PHP_BINARY, __FILE__, '--owner', $base, 'upgrade'];
    foreach (glob('/proc/[0-9]*/status') as $path) {
        $status = @file_get_contents($path); $command = @file_get_contents(dirname($path) . '/cmdline');
        if ($status === false || $command === false || $command === '') continue;
        $parts = explode("\0", rtrim($command, "\0"));
        if (in_array(basename($parts[0]), ['cron', 'crond'], true)) $unmanaged++;
        if (!preg_match('/^Uid:\s+(\d+)\s+(\d+)/m', $status, $match) || !in_array((int)$match[2], [1003, 1004], true)) continue;
        if ($parts === $expected) { $upgradeProcesses++; continue; }
        if (str_starts_with($command, 'php-fpm: pool www')) continue;
        $extra++;
    }
    return ['inventory_sha256' => $inventoryHash, 'registry_unchanged' => $upgradeProcesses === 1,
        'core_cli_processes' => $extra, 'unmanaged_writers' => $unmanaged];
}

if (($argv[1] ?? '') === '--owner') {
    $base = $argv[2] ?? ''; $action = $argv[3] ?? ''; $uid = posix_geteuid();
    if (!preg_match('~^/fixture/[a-z-]+$~D', $base) || !in_array($uid, [1003, 1004], true)) exit(80);
    $spec = json_decode(file_get_contents($base . '/spec.json'), true, 512, JSON_THROW_ON_ERROR);
    try {
        if ($action === 'upgrade') {
            $binding = $base . '/execution/release-binding.json';
            $witness = static function (array $request): array {
                echo json_encode(['request' => $request], JSON_THROW_ON_ERROR) . "\n"; fflush(STDOUT);
                $response = json_decode((string)fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
                if (($response['terminate'] ?? null) === 73) exit(73); // Deliberate abrupt fixture process loss.
                return $response;
            };
            $context = new FullCoreProductionUpgradeContext($base . '/execution', hash_file('sha256', $binding), $witness);
            $result = fullCoreUpgradeProduction(fixtureDb(), $context);
            echo json_encode(['result' => $result], JSON_THROW_ON_ERROR) . "\n";
        } else {
            $host = new FullCoreMaintenanceHost(FIXTURE_ROOT, $base . '/maintenance-' . $uid);
            $operation = $spec['operation'];
            if ($action === 'pause') {
                if ($uid === 1003) $result = $host->pauseScheduler($operation, hash('sha256', $host->crontab()), $spec['cron_review']);
                else $result = $host->pause($operation, $host->webSnapshot(), hash('sha256', $host->crontab()), [], $spec['path']);
            } elseif ($action === 'master-witness') {
                $result = $host->assertSchedulerHeld($operation);
                $result['receipt_sha256'] = hash_file('sha256', $base . '/maintenance-1003/scheduler-' . $operation . '/receipt.json');
            } elseif ($action === 'restore-hold') {
                if ($uid === 1003) $host->restoreScheduler($operation); else $host->restore($operation);
                $result = ['restored' => true];
            } elseif ($action === 'activate') {
                $web = file_get_contents($base . '/package-1004/.htaccess');
                $host->prepareActivation($operation, $web, hash('sha256', $web), $spec['candidate_commit'], $spec['manifest_sha256']);
                $host->activate($operation, $spec['candidate_commit'], $spec['manifest_sha256']);
                $result = ['activated' => true];
            } else {
                $guard = static function () use ($host, $uid, $operation, $spec): bool {
                    if ($uid === 1003) $host->assertSchedulerHeld($operation); else $host->assertHeld($operation);
                    if (!hash_equals($spec['held_web_sha256'], hash_file('sha256', FIXTURE_ROOT . '/.htaccess'))) return false;
                    [$status, $bytes] = fixtureHttp($spec, ['action' => 'status']);
                    return $status === 200 && (json_decode($bytes, true)['pool']['request_pool_quiet'] ?? false) === true;
                };
                $plan = file_get_contents($base . '/plan-' . $uid . '.json');
                $adapter = new FullCoreReleaseFiles(FIXTURE_ROOT, $base . '/package-' . $uid, $base . '/files-' . $uid,
                    $plan, hash('sha256', $plan), $guard);
                if ($action === 'prepare') $result = $adapter->prepare();
                elseif ($action === 'apply') $result = $adapter->apply();
                elseif ($action === 'recover') $result = $adapter->recover();
                elseif ($action === 'verify-original') { $adapter->verify(false); $result = ['verified' => true]; }
                else throw new RuntimeException('Unknown owner action.');
            }
            echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
        }
        exit(0);
    } catch (Throwable $e) {
        do { fwrite(STDERR, $e->getMessage() . "\n"); $e = $e->getPrevious(); } while ($e);
        exit(2);
    }
}

if (posix_geteuid() !== 0) throw new RuntimeException('Container fixture initializer required.');
$scenario = $argv[1] ?? '';
if (!in_array($scenario, ['stale', 'extra-db', 'late-db', 'guard-loss', 'crash', 'recover', 'activate'], true)) throw new RuntimeException('Explicit fixture scenario required.');
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $label . '; inspect /fixture/commands.stderr');
    $checks++; echo "PASS $label\n";
};
$base = '/fixture/' . $scenario; mkdir($base, 0755);
mkdir(FIXTURE_ROOT, 0775, true); chown(FIXTURE_ROOT, 1004); chgrp(FIXTURE_ROOT, 33); chmod(FIXTURE_ROOT, 0775);
foreach ([1003, 1004] as $uid) foreach (['maintenance-', 'package-', 'files-'] as $prefix) fixturePrivate($base . '/' . $prefix . $uid, $uid);
fixturePrivate($base . '/execution', 1004); fixturePrivate($base . '/channel', 1004); fixturePrivate($base . '/backup', 0);
$operation = bin2hex(random_bytes(16)); $commit = str_repeat('b', 40);
$path = 'core-maintenance-' . $operation . '-' . bin2hex(random_bytes(16)) . '.php';
$held = fullCoreMaintenanceWebRule($operation, $path);
$originalWeb = "Options -Indexes\nRewriteEngine On\n# original fixture routing\n";
fixtureWrite(FIXTURE_ROOT . '/.htaccess', $originalWeb, 1004, 0664);
fixtureWrite(FIXTURE_ROOT . '/application.php', '<?php return "old-app";', 1004, 0664);
fixtureWrite(FIXTURE_ROOT . '/master.php', '<?php return "old-master";', 1003, 0644);
fixtureWrite(FIXTURE_ROOT . '/read.php', '<?php echo (include __DIR__."/application.php")."|".(include __DIR__."/master.php");', 1004, 0644);
$baseline = 'CREATE TABLE balances(id INT PRIMARY KEY, tenant_id INT, amount DECIMAL(12,2), evidence BLOB) ENGINE=InnoDB;';
$pending = 'ALTER TABLE balances ADD note VARCHAR(50); UPDATE balances SET amount=amount+12.34 WHERE id=1; CREATE TABLE upgraded_only(id INT);';
fixtureWrite(FIXTURE_ROOT . '/core/migrations/001_baseline.sql', $baseline, 1004, 0644);
foreach (['core', 'core/migrations'] as $dir) { chown(FIXTURE_ROOT . '/' . $dir, 1004); chgrp(FIXTURE_ROOT . '/' . $dir, 33); }
$sources = ['.htaccess' => "Options -Indexes\nRewriteEngine On\n# candidate fixture routing\n",
    'application.php' => '<?php return "new-app";', 'master.php' => '<?php return "new-master";',
    'core/migrations/001_baseline.sql' => $baseline, 'core/migrations/002_upgrade.sql' => $pending];
$manifest = ['product' => 'full-core-staging', 'commit' => $commit, 'files' => []];
foreach ($sources as $relative => $bytes) $manifest['files'][$relative] = hash('sha256', $bytes);
$manifestBytes = json_encode($manifest, JSON_THROW_ON_ERROR);
foreach ([1003, 1004] as $uid) {
    foreach ($sources as $relative => $bytes) fixtureWrite($base . '/package-' . $uid . '/' . $relative, $bytes, $uid, 0600, 1700000100);
    fixtureWrite($base . '/package-' . $uid . '/integration-manifest.json', $manifestBytes, $uid);
    $plan = ['version' => 1, 'operation' => $operation, 'candidate_commit' => $commit, 'manifest_sha256' => hash('sha256', $manifestBytes),
        'root' => FIXTURE_ROOT, 'owner_uid' => $uid, 'files' => [], 'directories' => []];
    $paths = $uid === 1003 ? ['master.php'] : ['application.php', 'core/migrations/002_upgrade.sql'];
    foreach ($paths as $relative) {
        $before = is_file(FIXTURE_ROOT . '/' . $relative) ? fixtureMeta(FIXTURE_ROOT . '/' . $relative) : null;
        $after = fixtureMeta($base . '/package-' . $uid . '/' . $relative); $after['mode'] = $before['mode'] ?? 0644;
        $plan['files'][$relative] = ['action' => $before ? 'replace' : 'add', 'before' => $before, 'after' => $after];
    }
    if ($uid === 1004) foreach (['core', 'core/migrations'] as $dir) $plan['directories'][$dir] = [
        'before' => fixtureMeta(FIXTURE_ROOT . '/' . $dir, false), 'after' => fixtureMeta(FIXTURE_ROOT . '/' . $dir, false)];
    fixtureWrite($base . '/plan-' . $uid . '.json', json_encode($plan, JSON_THROW_ON_ERROR), $uid);
}
$library = $base . '/channel/full_core_maintenance_channel.php';
fixtureWrite($library, file_get_contents(__DIR__ . '/../deploy/full_core_maintenance_channel.php'), 1004);
$token = bin2hex(random_bytes(32));
$channel = ['operation' => $operation, 'candidate_commit' => $commit, 'path' => $path, 'root' => FIXTURE_ROOT,
    'private_directory' => $base . '/channel', 'host' => 'fixture.local', 'pool' => 'www',
    'token_sha256' => hash('sha256', $token), 'held_web_sha256' => hash('sha256', $held),
    'library_sha256' => hash_file('sha256', $library), 'issued_at' => time() - 1, 'expires' => time() + 600];
fixtureWrite(FIXTURE_ROOT . '/' . $path, fullCoreMaintenanceChannelRender($channel), 1004, 0644, time());
$cron = '* * * * * php ' . FIXTURE_ROOT . '/fixture-job.php';
$spec = $channel + ['token' => $token, 'manifest_sha256' => hash('sha256', $manifestBytes),
    'cron_review' => [1 => ['sha256' => hash('sha256', $cron), 'action' => 'pause']]];
fixtureWrite($base . '/spec.json', json_encode($spec, JSON_THROW_ON_ERROR), 0, 0644);
fixtureWrite($base . '/initial.cron', $cron . "\n", 0);
$check(fixtureCommand(['crontab', '-u', 'coremaster', $base . '/initial.cron'])[0] === 0, 'native master schedule installed; cron daemon stays stopped');
for ($i = 0; $i < 100; $i++) {
    if (fixtureHttp($spec, null, '/read.php')[0] === 200) break;
    usleep(50000);
}
$check(fixtureHttp($spec, null, '/read.php')[1] === 'old-app|old-master', 'both owner scripts warm in real FPM cache');
$rootDb = fixtureDb('root', false);
$rootDb->exec('CREATE DATABASE ' . FIXTURE_DB);
$rootDb->exec("CREATE USER 'grcudkpvcd'@'localhost' IDENTIFIED BY ''");
$rootDb->exec('GRANT ALL ON ' . FIXTURE_DB . ".* TO 'grcudkpvcd'@'localhost'");
$rootDb->exec('USE ' . FIXTURE_DB);
$rootDb->exec('CREATE TABLE _migrations(filename VARCHAR(190) PRIMARY KEY,sha256 CHAR(64) NOT NULL,applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,last_error TEXT NULL,duration_ms INT NULL) ENGINE=InnoDB');
$rootDb->exec('CREATE TABLE coreflux_migrations(file_path VARCHAR(255) PRIMARY KEY,checksum_sha CHAR(64),applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
$rootDb->exec($baseline); $rootDb->exec("INSERT INTO balances VALUES(1,999,25.19,0x00ff),(2,998,99.00,0xfe7f)");
$rootDb->prepare('INSERT INTO _migrations(filename,sha256) VALUES(?,?)')->execute(['001_baseline.sql', corefluxMigrationHash($baseline)]);
$rootDb->prepare('INSERT INTO coreflux_migrations(file_path,checksum_sha) VALUES(?,?)')->execute(['core/migrations/001_baseline.sql', corefluxMigrationHash($baseline)]);
$snapshot = static fn(): array => ['rows' => $rootDb->query('SELECT id,tenant_id,amount,HEX(evidence) FROM balances ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    'columns' => $rootDb->query('SHOW COLUMNS FROM balances')->fetchAll(PDO::FETCH_ASSOC),
    'ledger' => $rootDb->query('SELECT * FROM _migrations ORDER BY filename')->fetchAll(PDO::FETCH_ASSOC),
    'legacy' => $rootDb->query('SELECT * FROM coreflux_migrations ORDER BY file_path')->fetchAll(PDO::FETCH_ASSOC),
    'tables' => $rootDb->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)];
$before = $snapshot();
$plan = fullCoreMigrationPlan($manifest, $base . '/package-1004', FIXTURE_ROOT, fullCoreUpgradeLedger($rootDb),
    $rootDb->query('SELECT file_path,checksum_sha FROM coreflux_migrations')->fetchAll(PDO::FETCH_KEY_PAIR));
$check($plan['source_review_ready'], 'actual populated fixture has an exact reviewed migration plan');
foreach ([1003, 1004] as $uid) $check(fixtureOwner($base, $uid, 'pause')[0] === 0, 'native schedule/web hold established by its real owner');
$check(fixtureHttp($spec, null, '/read.php')[0] === 503, 'business HTTP blocked before backup or SQL');
$appReceipt = $base . '/maintenance-1004/maintenance-' . $operation . '/receipt.json';
$masterReceipt = $base . '/maintenance-1003/scheduler-' . $operation . '/receipt.json';
$a = json_decode(file_get_contents($appReceipt), true); $m = json_decode(file_get_contents($masterReceipt), true);
$binding = ['version' => 1, 'action' => 'apply-reviewed-core-database-upgrade', 'root' => FIXTURE_ROOT, 'database' => FIXTURE_DB,
    'application_uid' => 1004, 'master_uid' => 1003, 'database_server_id' => (int)$rootDb->query('SELECT @@server_id')->fetchColumn(),
    'operation' => $operation, 'candidate_commit' => $commit, 'package_directory' => $base . '/package-1004',
    'application_maintenance_parent' => $base . '/maintenance-1004', 'manifest_sha256' => hash('sha256', $manifestBytes),
    'migration_plan_sha256' => $plan['plan_sha256'], 'application_maintenance_receipt_sha256' => hash_file('sha256', $appReceipt),
    'master_maintenance_receipt_sha256' => hash_file('sha256', $masterReceipt), 'held_web_sha256' => $spec['held_web_sha256'],
    'application_held_cron_sha256' => $a['held_cron_sha256'], 'master_held_cron_sha256' => $m['held_cron_sha256'],
    'writer_inventory_sha256' => hash('sha256', 'network-isolated fixture; cron daemon absent; no application bootstrap or external DB network')];
$pair = ['version' => 1, 'source_writers_held' => true];
foreach (['operation', 'candidate_commit', 'root', 'database', 'migration_plan_sha256', 'held_web_sha256',
    'application_held_cron_sha256', 'master_held_cron_sha256', 'writer_inventory_sha256'] as $key) $pair[$key] = $binding[$key];
foreach ([1003, 1004] as $uid) {
    [$exit, $out] = fixtureOwner($base, $uid, 'prepare'); $check($exit === 0, 'actual per-owner file backup verified under native holds');
    $pair['file_owners'][$uid] = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
    $pair['file_owners'][$uid]['prepared_receipt_sha256'] = hash_file('sha256', $base . '/files-' . $uid . '/prepared.json');
}
[$exit, $sql] = fixtureCommand(['mariadb-dump', '--socket=/run/mysqld/mysqld.sock', '--user=grcudkpvcd', '--single-transaction',
    '--skip-lock-tables', '--hex-blob', '--routines', '--events', '--triggers', FIXTURE_DB]);
$check($exit === 0 && strlen($sql) > 1000, 'native full backup succeeds with application database identity');
$key = random_bytes(32); $iv = random_bytes(12); $tag = '';
$aad = json_encode($pair, JSON_THROW_ON_ERROR);
$encrypted = openssl_encrypt($sql, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad);
if ($encrypted === false) throw new RuntimeException('Fixture backup encryption failed.');
$archive = $iv . $tag . $encrypted;
fixtureWrite($base . '/backup/database.aesgcm', $archive, 0);
$read = file_get_contents($base . '/backup/database.aesgcm');
$restoredSql = openssl_decrypt(substr($read, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($read, 0, 12), substr($read, 12, 16), $aad);
$check($restoredSql === $sql, 'encrypted backup readback verifies all native SQL bytes');
$pair['database_backup'] = ['native_full_schema_and_data' => true, 'encryption' => 'aes-256-gcm-envelope-v1',
    'sql_bytes' => strlen($sql), 'archive_bytes' => strlen($archive), 'restoring_database_user' => FIXTURE_DB,
    'sql_sha256' => hash('sha256', $sql), 'readback_sql_sha256' => hash('sha256', $restoredSql), 'archive_sha256' => hash('sha256', $archive)];
$pairBytes = json_encode($pair, JSON_THROW_ON_ERROR); $binding['backup_pair_sha256'] = hash('sha256', $pairBytes);
fixtureWrite($base . '/execution/backup-pair.json', $pairBytes, 1004);
fixtureWrite($base . '/execution/release-binding.json', json_encode($binding, JSON_THROW_ON_ERROR), 1004);
FullCoreProductionUpgradeContext::validateBinding($binding); FullCoreProductionUpgradeContext::validateBackupPair($pair, $binding);

$extra = $scenario === 'extra-db' ? fixtureDb() : null;
$masterChanged = false;
$upgrade = static function (string $fault = '') use ($base, $binding, $spec, $rootDb, &$masterChanged): array {
    $process = proc_open(['/usr/sbin/runuser', '-u', 'coreapp', '--', PHP_BINARY, __FILE__, '--owner', $base, 'upgrade'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/fixture/commands.stderr', 'a']], $pipes);
    $requests = 0; $result = null; $lateConnection = null;
    while (($line = fgets($pipes[1])) !== false) {
        $event = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if (isset($event['result'])) { $result = $event['result']; continue; }
        $request = $event['request']; $requests++;
        $ddl = $rootDb->query("SHOW COLUMNS FROM balances LIKE 'note'")->fetch() !== false;
        if ($fault === 'crash' && $ddl) $w = ['terminate' => 73];
        else {
            if ($fault === 'guard-loss' && $ddl && !$masterChanged) {
                $heldPath = $base . '/maintenance-1003/scheduler-' . $spec['operation'] . '/held.crontab';
                fixtureWrite($base . '/changed.cron', file_get_contents($heldPath) . "# unexpected edit\n", 0);
                if (fixtureCommand(['crontab', '-u', 'coremaster', $base . '/changed.cron'])[0] !== 0) throw new RuntimeException('Fixture cron drift failed.');
                $masterChanged = true;
            }
            [$exit, $out] = fixtureOwner($base, 1003, 'master-witness');
            [$http, $web] = fixtureHttp($spec, ['action' => 'status']);
            $w = $request + ['observed_at_unix' => microtime(true), 'backup_pair_sha256' => $binding['backup_pair_sha256'],
                'master' => $exit === 0 ? json_decode($out, true) : [], 'web' => $http === 200 ? json_decode($web, true) : [],
                'writers' => fixtureWriters($base, $binding['writer_inventory_sha256'])];
            if ($fault === 'stale') $w['challenge'] = str_repeat('0', 64);
        }
        if ($fault === 'late-db' && $requests === 1) $lateConnection = fixtureDb();
        fwrite($pipes[0], json_encode($w, JSON_THROW_ON_ERROR) . "\n"); fflush($pipes[0]);
    }
    fclose($pipes[0]); fclose($pipes[1]); return [proc_close($process), $requests, $result];
};
[$exit, $requests, $result] = $upgrade($scenario);
$extra = null;
$journal = $base . '/execution/database-upgrade.jsonl';
if (in_array($scenario, ['stale', 'extra-db', 'late-db'], true)) {
    $check($exit === 2 && !file_exists($journal) && $snapshot() === $before, 'unsafe first guard refuses before journal or database writes');
    $check($requests === ($scenario === 'extra-db' ? 0 : 1), 'database drain is checked before and after the external witness');
} elseif (in_array($scenario, ['guard-loss', 'crash'], true)) {
    $check($exit === ($scenario === 'crash' ? 73 : 2), 'real process interruption or schedule drift stops upgrade');
    $check($rootDb->query("SHOW COLUMNS FROM balances LIKE 'note'")->fetch() !== false
        && $rootDb->query('SELECT amount FROM balances WHERE id=1')->fetchColumn() === '25.19', 'first DDL committed but following business update never ran');
    $check(str_starts_with((string)$rootDb->query("SELECT sha256 FROM _migrations WHERE filename='002_upgrade.sql'")->fetchColumn(), 'FAIL:'), 'durable incomplete marker survives process loss');
    if ($masterChanged) {
        $check(fixtureCommand(['crontab', '-u', 'coremaster', $base . '/maintenance-1003/scheduler-' . $operation . '/held.crontab'])[0] === 0,
            'fixture explicitly reinstates reviewed held schedule before recovery');
    }
    $check($upgrade()[0] === 2, 'fresh process cannot automatically replay existing upgrade journal');
} else {
    $check($exit === 0 && $result['production_execution'] === true && $result['executed_statements'] === 3,
        'bound production entry executes exact statements using real owners and fresh observations');
    $check($result['release_binding']['backup_pair_sha256'] === $binding['backup_pair_sha256']
        && $rootDb->query('SELECT amount FROM balances WHERE id=1')->fetchColumn() === '37.53', 'completion binds matching backup and preserves exact decimals');
    $check($upgrade()[0] === 2, 'completed production journal cannot be replayed');
    foreach ([1003, 1004] as $uid) $check(fixtureOwner($base, $uid, 'apply')[0] === 0, 'candidate bytes applied separately by their original owners');
}
$check(fixtureHttp($spec, null, '/read.php')[0] === 503, 'all success and failure outcomes retain business hold until explicit action');

$invalidate = static function (string $phase) use ($base, $spec, $commit, $operation, $check): void {
    $files = [];
    foreach (['application.php', 'master.php'] as $relative) $files[] = ['path' => $relative, 'sha256' => hash_file('sha256', FIXTURE_ROOT . '/' . $relative)];
    $bytes = json_encode(['version' => 1, 'operation' => $operation, 'candidate_commit' => $commit, 'root' => FIXTURE_ROOT,
        'held_web_sha256' => $spec['held_web_sha256'], 'phase' => $phase, 'files' => $files], JSON_THROW_ON_ERROR);
    fixtureWrite($base . '/channel/cache-plan.json', $bytes, 1004);
    [$status] = fixtureHttp($spec, ['action' => 'invalidate', 'plan_sha256' => hash('sha256', $bytes)]);
    $check($status === 200, 'exact installed PHP hashes refreshed in held Core pool');
};
if ($scenario === 'activate') {
    $invalidate('candidate');
    $check(fixtureOwner($base, 1004, 'activate')[0] === 0, 'explicit candidate routing activation follows completed SQL files and cache checks');
    $check(fixtureOwner($base, 1003, 'restore-hold')[0] === 0, 'master scheduler resumes only after candidate activation');
    $check(fixtureHttp($spec, null, '/read.php')[1] === 'new-app|new-master', 'actual FPM serves new code from both owners after activation');
    $check($rootDb->query('SELECT amount FROM balances WHERE id=1')->fetchColumn() === '37.53', 'activated code remains paired with upgraded database');
} else {
    fixtureWrite($base . '/execution/recovery-requested.json', json_encode(['operation' => $operation]), 1004);
    $check($upgrade()[0] === 2, 'durable recovery direction forbids production re-entry');
    // Only this fresh container database may be emptied; exact identity and authenticated backup are checked first.
    $check($rootDb->query('SELECT DATABASE()')->fetchColumn() === FIXTURE_DB
        && hash_equals($pair['database_backup']['archive_sha256'], hash_file('sha256', $base . '/backup/database.aesgcm'))
        && hash_equals($pair['database_backup']['sql_sha256'], hash('sha256', $restoredSql)), 'exact paired native backup verified before disposable recovery');
    $rootDb->exec('DROP DATABASE ' . FIXTURE_DB); $rootDb->exec('CREATE DATABASE ' . FIXTURE_DB); $rootDb->exec('USE ' . FIXTURE_DB);
    fixtureWrite($base . '/backup/restore.sql', $restoredSql, 0);
    $check(fixtureCommand(['mariadb', '--socket=/run/mysqld/mysqld.sock', '--user=grcudkpvcd', FIXTURE_DB],
        $base . '/backup/restore.sql')[0] === 0, 'native empty-target database restore runs as application database user');
    unlink($base . '/backup/restore.sql');
    foreach ([1004, 1003] as $uid) {
        $check(fixtureOwner($base, $uid, 'recover')[0] === 0 && fixtureOwner($base, $uid, 'verify-original')[0] === 0,
            'original file bytes and metadata restored by each owner while web stays held');
    }
    $check($snapshot() === $before, 'paired recovery restores exact rows binary values ledgers and schema');
    $check(fixtureHttp($spec, null, '/read.php')[0] === 503, 'recovery does not reopen business requests before verification');
    $invalidate('recovery');
    foreach ([1004, 1003] as $uid) $check(fixtureOwner($base, $uid, 'restore-hold')[0] === 0, 'explicit recovery completion restores each original schedule/routing');
    $check(fixtureHttp($spec, null, '/read.php')[1] === 'old-app|old-master', 'actual FPM serves matching original code after verified recovery');
}
echo "PASS combined production-shaped fixture $scenario: $checks checks; no live application or external network\n";
