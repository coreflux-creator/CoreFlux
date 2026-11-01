<?php
/** Native combined release fixture. The production-shaped database exists only inside an isolated container. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !is_file('/run/core-production-upgrade-fixture') || realpath('/fixture') !== '/fixture') {
    throw new RuntimeException('Explicit disposable production-upgrade container required.');
}
require_once __DIR__ . '/../deploy/full_core_upgrade_executor.php';
require_once __DIR__ . '/../deploy/full_core_release_owner.php';
require_once __DIR__ . '/../deploy/full_core_database_state.php';
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
function fixtureQuietHttp(array $spec): array
{
    $deadline=microtime(true)+5;
    do {
        [$status,$bytes]=fixtureHttp($spec,['action'=>'status']);
        if ($status === 200 && (json_decode($bytes,true)['pool']['request_pool_quiet'] ?? false) === true) return [$status,$bytes];
        usleep(20000); // Observe an actually finishing request; never retry a deployment side effect.
    } while (microtime(true) < $deadline);
    return [$status,$bytes];
}
function fixtureOwnerNativeCommand(string $base, int $uid): array
{
    return [PHP_BINARY,
        __DIR__.'/../deploy/run_full_core_release_owner.php','--execute-owner-config',
        $base.'/owner-config-'.$uid.'.json',hash_file('sha256',$base.'/owner-config-'.$uid.'.json')];
}
function fixtureOwnerCommand(string $base, int $uid): array
{
    return ['/usr/bin/ssh','-T','-i','/fixture/ssh-key','-o','BatchMode=yes','-o','IdentitiesOnly=yes',
        '-o','StrictHostKeyChecking=yes','-o','UserKnownHostsFile=/fixture/known-hosts','-o','ConnectTimeout=5',
        '-p','2222',($uid === 1003 ? 'coremaster' : 'coreapp').'@127.0.0.1',
        'exec '.implode(' ',array_map('escapeshellarg',fixtureOwnerNativeCommand($base,$uid)))];
}
function fixtureOwnerRequest(string $base, int $uid, string $action): array
{
    $phase=['pause'=>'hold','prepare'=>'prepare-files','apply'=>'apply-files','recover'=>'recover-files',
        'upgrade'=>'upgrade-database','verify-original'=>'verify-recovery','verify-applied'=>'verify-files','master-witness'=>'witness-held'][$action] ?? $action;
    if ($action === 'restore-hold') {
        $spec=json_decode(file_get_contents($base.'/spec.json'),true,512,JSON_THROW_ON_ERROR);
        $state=json_decode(file_get_contents(dirname(FIXTURE_ROOT).'/private_html/core-release-control-'.$uid.'/operation-'.$spec['operation'].'/state.json'),true);
        $phase=$state['direction'] === 'recovery' ? ($uid === 1003 ? 'restore-scheduler' : 'restore-routing') : 'resume-scheduler';
    }
    $input=[];
    if (is_file($base.'/guard-'.$uid.'.json')) $input['guard_sha256']=hash_file('sha256',$base.'/guard-'.$uid.'.json');
    if ($phase === 'upgrade-database') $input['binding_sha256']=hash_file('sha256',$base.'/execution/release-binding.json');
    if (in_array($phase,['begin-database-recovery','confirm-database-recovery'],true)) $input['expectation_sha256']=hash_file('sha256',$base.'/restore-expectation.json');
    if (in_array($phase,['refresh-cache','refresh-recovery-cache','recover-files'],true)) $input['cache_plan_sha256']=hash_file('sha256',$base.'/cache-plan-for-'.$uid.'.json');
    $peers=$uid === 1004 ? ['prepare-files'=>'hold','upgrade-database'=>'prepare-files','refresh-cache'=>'verify-files','complete'=>'resume-scheduler','restore-routing'=>'verify-recovery']
        : ['prepare-files'=>'hold','apply-files'=>'upgrade-database','resume-scheduler'=>'activate','recover-files'=>'restore-database','restore-scheduler'=>'restore-routing'];
    $peer=isset($peers[$phase]) ? json_decode(file_get_contents($base.'/peer-'.($uid === 1003 ? 1004 : 1003).'-'.$peers[$phase].'.json'),true,512,JSON_THROW_ON_ERROR) : null;
    return ['action'=>$phase,'input'=>$input,'peer'=>$peer,'challenge'=>bin2hex(random_bytes(32))];
}
function fixtureOwner(string $base, int $uid, string $action): array
{
    $spec = json_decode(file_get_contents($base . '/spec.json'), true,512,JSON_THROW_ON_ERROR);
    if (in_array($action, ['refresh-cache','refresh-recovery-cache','recover'], true)) {
        $bytes = fixtureCachePlan($spec, $action === 'refresh-cache' ? 'candidate' : 'recovery');
        fixtureWrite($base . '/channel/cache-plan.json', $bytes, 1004);
        fixtureWrite($base . '/cache-plan-for-' . $uid . '.json', $bytes, $uid);
    }
    $initial=fixtureOwnerRequest($base,$uid,$action);
    $p = proc_open(fixtureOwnerCommand($base,$uid), [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['file','/fixture/commands.stderr','a']], $pipes);
    if (!is_resource($p)) throw new RuntimeException('Owner process unavailable.');
    fwrite($pipes[0],json_encode($initial,JSON_THROW_ON_ERROR)."\n"); fflush($pipes[0]);
    $result = null;
    while (($line = fgets($pipes[1])) !== false) {
        $event = json_decode($line,true,512,JSON_THROW_ON_ERROR);
        if (isset($event['response'])) {
            if ($event['challenge'] !== $initial['challenge'] || $event['owner_uid'] !== $uid) throw new RuntimeException('Owner response identity differs.');
            $result = $event['response']; continue;
        }
        $request = $event['request'];
        if (($request['kind'] ?? null) === 'invalidate') {
            [$status,$bytes] = fixtureHttp($spec, ['action'=>'invalidate','plan_sha256'=>$request['cache_plan_sha256']]);
            $response = $status === 200 ? json_decode($bytes,true,512,JSON_THROW_ON_ERROR) : [];
        } else {
            $other = $uid === 1003 ? 1004 : 1003;
            [$exit,$bytes] = fixtureOwner($base,$other,'witness-held');
            $peer = $exit === 0 ? json_decode($bytes,true,512,JSON_THROW_ON_ERROR) : [];
            [$status,$bytes] = fixtureQuietHttp($spec);
            $binding = json_decode(file_get_contents($base.'/guard-'.$uid.'.json'),true,512,JSON_THROW_ON_ERROR);
            $response = $request + ['observed_at_unix'=>microtime(true),
                'master'=>$uid === 1003 ? $request['own_hold'] : $peer,
                'application'=>$uid === 1004 ? $request['own_hold'] : $peer,
                'web'=>$status === 200 ? json_decode($bytes,true,512,JSON_THROW_ON_ERROR) : [],
                'writers'=>fixtureWriters($base,$binding['writer_inventory_sha256'],$request['owner_pid'],$action)];
        }
        fwrite($pipes[0],json_encode($response,JSON_THROW_ON_ERROR)."\n"); fflush($pipes[0]);
    }
    fclose($pipes[0]); fclose($pipes[1]); $exit=proc_close($p);
    if ($exit === 0 && is_array($result)) {
        if (isset($result['control'])) {
            $receipt=$result['control'];
            fixtureWrite($base.'/peer-'.$uid.'-'.$receipt['action'].'.json',json_encode($receipt,JSON_THROW_ON_ERROR),$uid === 1003 ? 1004 : 1003);
            $result=$receipt;
        }
        return [0,json_encode($result,JSON_THROW_ON_ERROR)];
    }
    return [$exit,''];
}

function fixtureSnapshot(PDO $db): array
{
    return ['rows' => $db->query('SELECT id,tenant_id,amount,HEX(evidence) FROM balances ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'columns' => $db->query('SHOW COLUMNS FROM balances')->fetchAll(PDO::FETCH_ASSOC),
        'ledger' => $db->query('SELECT * FROM _migrations ORDER BY filename')->fetchAll(PDO::FETCH_ASSOC),
        'legacy' => $db->query('SELECT * FROM coreflux_migrations ORDER BY file_path')->fetchAll(PDO::FETCH_ASSOC),
        'tables' => $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)];
}
function fixtureCachePlan(array $spec, string $phase): string
{
    $files=[];
    foreach (['application.php','master.php','added-worker.php'] as $relative) if (is_file(FIXTURE_ROOT.'/'.$relative)) {
        $files[]=['path'=>$relative,'sha256'=>hash_file('sha256',FIXTURE_ROOT.'/'.$relative)];
    }
    return json_encode(['version'=>1,'operation'=>$spec['operation'],'candidate_commit'=>$spec['candidate_commit'],
        'root'=>FIXTURE_ROOT,'held_web_sha256'=>$spec['held_web_sha256'],'phase'=>$phase,'files'=>$files],JSON_THROW_ON_ERROR);
}
/** This container's entire writer inventory is known; report observed extra owner processes, not a supplied quiet flag. */
function fixtureSshSupervisor(string $status, string $command, int $uid): bool
{
    $user=$uid === 1003 ? 'coremaster' : 'coreapp';
    if (!preg_match('/^Name:\s+sshd$/m',$status) || rtrim($command,"\0") !== 'sshd: '.$user.'@notty'
        || !preg_match('/^PPid:\s+(\d+)/m',$status,$parent)) return false;
    // Docker intentionally has no ptrace capability: /proc/<sshd>/exe is unreadable after privilege separation.
    // Verify the root authentication parent and the fixture's exact listener ancestry instead.
    $parentStatus=@file_get_contents('/proc/'.$parent[1].'/status');
    $parentCommand=@file_get_contents('/proc/'.$parent[1].'/cmdline');
    return is_string($parentStatus) && is_string($parentCommand)
        && preg_match('/^Name:\s+sshd$/m',$parentStatus) && preg_match('/^Uid:\s+0\s+0\s+0\s+0$/m',$parentStatus)
        && rtrim($parentCommand,"\0") === 'sshd: '.$user.' [priv]'
        && preg_match('/^PPid:\s+(\d+)/m',$parentStatus,$listener)
        && (int)$listener[1] === (int)file_get_contents('/fixture/sshd.pid');
}
function fixtureWriters(string $base, string $inventoryHash, ?int $ownerPid = null, string $action = 'upgrade'): array
{
    $extra = 0; $unmanaged = 0; $upgradeProcesses = 0; $extraDetails = [];
    $expected = null;
    foreach (glob('/proc/[0-9]*/status') as $path) {
        $status = @file_get_contents($path); $command = @file_get_contents(dirname($path) . '/cmdline');
        if ($status === false || $command === false || $command === '') continue;
        $parts = explode("\0", rtrim($command, "\0"));
        if (in_array(basename($parts[0]), ['cron', 'crond'], true)) $unmanaged++;
        if (!preg_match('/^Uid:\s+(\d+)\s+(\d+)/m', $status, $match) || !in_array((int)$match[2], [1003, 1004], true)) continue;
        if ($parts === fixtureOwnerNativeCommand($base,(int)$match[2])
            && ($ownerPid === null || (int)basename(dirname($path)) === $ownerPid)) { $upgradeProcesses++; continue; }
        // Only the disposable fixture's key-authenticated command-session supervisor; it is not a Core writer.
        if (fixtureSshSupervisor($status,$command,(int)$match[2])) continue;
        if (str_starts_with($command, 'php-fpm: pool www')) continue;
        $extraDetails[] = ['pid'=>(int)basename(dirname($path)), 'uid'=>(int)$match[2], 'command'=>$parts,
            'exe'=>@readlink(dirname($path).'/exe')];
        $extra++;
    }
    if ($extra > 0 || $upgradeProcesses !== 1) file_put_contents('/fixture/writer-inventory.json', json_encode([
        'expected_owner_pid'=>$ownerPid,'matched_workers'=>$upgradeProcesses,'extra'=>$extraDetails],JSON_THROW_ON_ERROR));
    return ['inventory_sha256' => $inventoryHash, 'registry_unchanged' => $upgradeProcesses === 1,
        'core_cli_processes' => $extra, 'unmanaged_writers' => $unmanaged];
}


if (posix_geteuid() !== 0) throw new RuntimeException('Container fixture initializer required.');
$scenario = $argv[1] ?? '';
if (!in_array($scenario, ['wrong-file-plan', 'stale', 'extra-db', 'late-db', 'guard-loss', 'crash', 'recover', 'activate'], true)) throw new RuntimeException('Explicit fixture scenario required.');
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $label . '; inspect /fixture/commands.stderr');
    $checks++; echo "PASS $label\n";
};
$base = '/fixture/' . $scenario; mkdir($base, 0755);
mkdir(FIXTURE_ROOT, 0775, true); chown(FIXTURE_ROOT, 1004); chgrp(FIXTURE_ROOT, 33); chmod(FIXTURE_ROOT, 0775);
$privateRoot = dirname(FIXTURE_ROOT) . '/private_html';
mkdir($privateRoot, 0775); chown($privateRoot, 1004); chgrp($privateRoot, 33); chmod($privateRoot, 0775);
foreach ([1003, 1004] as $uid) foreach (['maintenance-', 'package-', 'files-', 'native-'] as $prefix) fixturePrivate($base . '/' . $prefix . $uid, $uid);
fixturePrivate($base . '/execution', 1004); fixturePrivate($base . '/channel', 1004); fixturePrivate($base . '/backup', 0);
$operation = bin2hex(random_bytes(16)); $commit = str_repeat('b', 40);
$path = 'core-maintenance-' . $operation . '-' . bin2hex(random_bytes(16)) . '.php';
$held = fullCoreMaintenanceWebRule($operation, $path);
$originalWeb = "Options -Indexes\nRewriteEngine On\n# original fixture routing\n";
fixtureWrite(FIXTURE_ROOT . '/.htaccess', $originalWeb, 1004, 0664);
fixtureWrite(FIXTURE_ROOT . '/application.php', '<?php return "old-app";', 1004, 0664);
fixtureWrite(FIXTURE_ROOT . '/master.php', '<?php return "old-master";', 1003, 0644);
fixtureWrite(FIXTURE_ROOT . '/read.php', '<?php echo (include __DIR__."/application.php")."|".(include __DIR__."/master.php");', 1004, 0644);
$baseline = 'CREATE TABLE balances(id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT, amount DECIMAL(12,2), evidence BLOB) ENGINE=InnoDB;';
$pending = 'ALTER TABLE balances ADD note VARCHAR(50); UPDATE balances SET amount=amount+12.34 WHERE id=1; CREATE TABLE upgraded_only(id INT);';
fixtureWrite(FIXTURE_ROOT . '/core/migrations/001_baseline.sql', $baseline, 1004, 0644);
foreach (['core', 'core/migrations'] as $dir) { chown(FIXTURE_ROOT . '/' . $dir, 1004); chgrp(FIXTURE_ROOT . '/' . $dir, 33); }
$sources = ['.htaccess' => "Options -Indexes\nRewriteEngine On\n# candidate fixture routing\n",
    'application.php' => '<?php return "new-app";', 'master.php' => '<?php return "new-master";',
    'core/migrations/001_baseline.sql' => $baseline, 'core/migrations/002_upgrade.sql' => $pending,
    'added-worker.php' => '<?php return 42;',
    'assets/logo with spaces.bin' => "\x89PNG\0synthetic packaged asset\xff"];
$manifest = ['product' => 'full-core-staging', 'commit' => $commit, 'files' => []];
foreach ($sources as $relative => $bytes) $manifest['files'][$relative] = hash('sha256', $bytes);
$manifestBytes = json_encode($manifest, JSON_THROW_ON_ERROR);
foreach ([1003, 1004] as $uid) {
    foreach ($sources as $relative => $bytes) fixtureWrite($base . '/package-' . $uid . '/' . $relative, $bytes, $uid, 0600, 1700000100);
    fixtureWrite($base . '/package-' . $uid . '/integration-manifest.json', $manifestBytes, $uid);
    $plan = ['version' => 1, 'operation' => $operation, 'candidate_commit' => $commit, 'manifest_sha256' => hash('sha256', $manifestBytes),
        'root' => FIXTURE_ROOT, 'owner_uid' => $uid, 'files' => [], 'directories' => []];
    $paths = $uid === 1003 ? ['master.php'] : ['application.php', 'core/migrations/002_upgrade.sql', 'added-worker.php'];
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
$snapshot = static fn(): array => fixtureSnapshot($rootDb);
$schemaOwner=fixtureDb();
$schemaOwner->exec('CREATE VIEW balance_view AS SELECT id,amount FROM balances');
$schemaOwner->exec('CREATE TRIGGER preserve_evidence BEFORE UPDATE ON balances FOR EACH ROW SET NEW.evidence=OLD.evidence');
$schemaOwner=null;
$before = $snapshot();
$beforeFingerprint=fullCoreNativeDatabaseState($rootDb,FIXTURE_DB);
$connectionBytes=FullCoreReleaseControl::encode(['dsn'=>'mysql:unix_socket=/run/mysqld/mysqld.sock;dbname='.FIXTURE_DB.';charset=utf8mb4',
    'username'=>FIXTURE_DB,'password'=>'']);
fixtureWrite($base.'/database-connection.json',$connectionBytes,1004);
$plan = fullCoreMigrationPlan($manifest, $base . '/package-1004', FIXTURE_ROOT, fullCoreUpgradeLedger($rootDb),
    $rootDb->query('SELECT file_path,checksum_sha FROM coreflux_migrations')->fetchAll(PDO::FETCH_KEY_PAIR));
$check($plan['source_review_ready'], 'actual populated fixture has an exact reviewed migration plan');
$spec['control'] = ['version' => 1, 'root' => FIXTURE_ROOT, 'operation' => $operation, 'candidate_commit' => $commit,
    'manifest_sha256' => $spec['manifest_sha256'], 'migration_plan_sha256' => $plan['plan_sha256'],
    'owner_plan_sha256' => [1003 => hash_file('sha256', $base . '/plan-1003.json'), 1004 => hash_file('sha256', $base . '/plan-1004.json')]];
fixtureWrite($base . '/spec.json', json_encode($spec, JSON_THROW_ON_ERROR), 0, 0644);
foreach ([1003,1004] as $uid) {
    $config=['version'=>1,'owner_uid'=>$uid,'control_spec'=>$spec['control'],
        'package_directory'=>$base.'/package-'.$uid,'maintenance_parent'=>$base.'/maintenance-'.$uid,
        'files_directory'=>$base.'/files-'.$uid,'receipts_directory'=>$base.'/native-'.$uid,
        'owner_plan_path'=>$base.'/plan-'.$uid.'.json','guard_binding_path'=>$base.'/guard-'.$uid.'.json',
        'execution_directory'=>$base.'/execution','restore_expectation_path'=>$base.'/restore-expectation.json',
        'recovery_backup_pair_path'=>$base.'/recovery-backup-pair.json','database_connection_path'=>$base.'/database-connection.json',
        'database_connection_sha256'=>hash('sha256',$connectionBytes),'database_server_id'=>(int)$rootDb->query('SELECT @@server_id')->fetchColumn(),
        'original_cron_sha256'=>hash('sha256',$uid === 1003 ? $cron."\n" : ''),'cron_review'=>$uid === 1003 ? $spec['cron_review'] : [],
        'original_web'=>['sha256'=>hash_file('sha256',FIXTURE_ROOT.'/.htaccess')]+fixtureMeta(FIXTURE_ROOT.'/.htaccess',false),
        'diagnostic_path'=>$spec['path']];
    fixtureWrite($base.'/owner-config-'.$uid.'.json',FullCoreReleaseControl::encode($config),$uid);
}
if ($scenario === 'activate') {
    $inspect = static function (array $request, int $uid) use ($base): array {
        $input = $base . '/inspect-input-' . $uid . '.json';
        fixtureWrite($input, json_encode($request, JSON_THROW_ON_ERROR), $uid);
        return fixtureCommand(['/usr/sbin/runuser', '-u', $uid === 1003 ? 'coremaster' : 'coreapp', '--',
            PHP_BINARY, __DIR__ . '/../deploy/inspect_full_core_release_owner.php'], $input);
    };
    foreach ([1003, 1004] as $uid) {
        $request = ['version' => 1, 'action' => 'inspect', 'owner_uid' => $uid, 'challenge' => bin2hex(random_bytes(32)),
            'control_spec' => $spec['control'], 'control_spec_sha256' => hash('sha256', FullCoreReleaseControl::encode($spec['control'])),
            'package_directory' => $base . '/package-' . $uid, 'owner_plan_path' => $base . '/plan-' . $uid . '.json'];
        [$exit, $bytes] = $inspect($request, $uid); $report = json_decode($bytes, true);
        $check($exit === 0 && $report['challenge'] === $request['challenge'] && $report['owner_uid'] === $uid
            && $report['preimage_drift'] === [] && $report['inaccessible_parents_or_files'] === []
            && $report['candidate_files_verified'] === count($sources) && $report['control_directory_exists'] === false,
            'read-only owner worker verifies actual candidate and preimages without creating release state');
        $request['owner_uid'] = $uid === 1003 ? 1004 : 1003;
        $check($inspect($request, $uid)[0] === 2, 'inspection refuses a different SSH owner identity');
    }
    $request['owner_uid'] = 1004;
    $originalApp = file_get_contents(FIXTURE_ROOT . '/application.php');
    $originalAppMeta = fixtureMeta(FIXTURE_ROOT . '/application.php');
    file_put_contents(FIXTURE_ROOT . '/application.php', $originalApp . "\n// concurrent fixture edit");
    [$exit, $bytes] = $inspect($request, 1004); $report = json_decode($bytes, true);
    $check($exit === 0 && in_array('application.php', $report['preimage_drift'], true)
        && str_contains(file_get_contents(FIXTURE_ROOT . '/application.php'), 'concurrent fixture edit'),
        'inspection reports concurrent preimage drift without overwriting it');
    fixtureWrite(FIXTURE_ROOT . '/application.php', $originalApp, 1004, $originalAppMeta['mode'], $originalAppMeta['mtime']);
    $check($snapshot() === $before && fixtureHttp($spec, null, '/read.php')[1] === 'old-app|old-master'
        && !file_exists($privateRoot . '/core-release-control-1003') && !file_exists($privateRoot . '/core-release-control-1004'),
        'SSH preparation preserves business routing database and absent release state');
}
foreach ([1003, 1004] as $uid) $check(fixtureOwner($base, $uid, 'pause')[0] === 0, 'native schedule/web hold established by its real owner');
if ($scenario === 'activate') {
    $lostReceipt=$base.'/peer-1004-hold.json'; $oldHash=hash_file('sha256',$lostReceipt); unlink($lostReceipt);
    [$exit,$bytes]=fixtureOwner($base,1004,'inspect-control'); $inspection=json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
    $recovered=$inspection['completed']['hold']['control'] ?? [];
    $check($exit === 0 && hash('sha256',json_encode($recovered,JSON_THROW_ON_ERROR)) === $oldHash
        && $inspection['state']['sequence'] === 1 && $inspection['state']['pending'] === null,
        'fresh authenticated SSH inspection recovers a completed hold receipt without replaying its side effect');
    fixtureWrite($lostReceipt,json_encode($recovered,JSON_THROW_ON_ERROR),1003);
}
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
    'writer_inventory_sha256' => hash('sha256', 'network-isolated fixture; cron daemon absent; no application bootstrap or external DB network'),
    'release_control_spec_sha256' => hash('sha256', FullCoreReleaseControl::encode($spec['control']))];
foreach ([1003,1004] as $uid) fixtureWrite($base.'/guard-'.$uid.'.json',FullCoreReleaseControl::encode($binding),$uid);
$pair = ['version' => 1, 'source_writers_held' => true];
foreach (['operation', 'candidate_commit', 'root', 'database', 'migration_plan_sha256', 'held_web_sha256',
    'application_held_cron_sha256', 'master_held_cron_sha256', 'writer_inventory_sha256'] as $key) $pair[$key] = $binding[$key];
foreach ([1003, 1004] as $uid) {
    [$exit, $out] = fixtureOwner($base, $uid, 'prepare'); $check($exit === 0, 'actual per-owner file backup verified under native holds');
    $pair['file_owners'][$uid] = json_decode(file_get_contents($base . '/files-' . $uid . '/prepared.json'), true, 512, JSON_THROW_ON_ERROR);
    $pair['file_owners'][$uid]['prepared_receipt_sha256'] = hash_file('sha256', $base . '/files-' . $uid . '/prepared.json');
}
[$fingerprintExit,$fingerprintBytes]=fixtureOwner($base,1004,'inspect-database-state');
$check($fingerprintExit === 0 && json_decode($fingerprintBytes,true)['snapshot_sha256'] === $beforeFingerprint['snapshot_sha256'],
    'native worker fingerprints all original rows schema trigger view and sequence counters without returning business values');
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
    'snapshot_sha256' => $beforeFingerprint['snapshot_sha256'],
    'sql_bytes' => strlen($sql), 'archive_bytes' => strlen($archive), 'restoring_database_user' => FIXTURE_DB,
    'sql_sha256' => hash('sha256', $sql), 'readback_sql_sha256' => hash('sha256', $restoredSql), 'archive_sha256' => hash('sha256', $archive)];
if ($scenario === 'wrong-file-plan') $pair['file_owners'][1003]['plan_sha256'] = hash('sha256', 'different owner plan');
$pairBytes = json_encode($pair, JSON_THROW_ON_ERROR); $binding['backup_pair_sha256'] = hash('sha256', $pairBytes);
fixtureWrite($base . '/execution/backup-pair.json', $pairBytes, 1004);
fixtureWrite($base . '/execution/release-binding.json', json_encode($binding, JSON_THROW_ON_ERROR), 1004);
FullCoreProductionUpgradeContext::validateBinding($binding); FullCoreProductionUpgradeContext::validateBackupPair($pair, $binding);

$extra = $scenario === 'extra-db' ? fixtureDb() : null;
$masterChanged = false;
$upgrade = static function (string $fault = '') use ($base, $binding, $spec, $rootDb, &$masterChanged): array {
    $initial=fixtureOwnerRequest($base,1004,'upgrade');
    $process = proc_open(fixtureOwnerCommand($base,1004),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/fixture/commands.stderr', 'a']], $pipes);
    fwrite($pipes[0],json_encode($initial,JSON_THROW_ON_ERROR)."\n"); fflush($pipes[0]);
    $requests = 0; $result = null; $lateConnection = null;
    while (($line = fgets($pipes[1])) !== false) {
        $event = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if (isset($event['response'])) {
            if ($event['challenge'] !== $initial['challenge'] || $event['owner_uid'] !== 1004) throw new RuntimeException('Upgrade owner response identity differs.');
            $event = $event['response'];
            $result = $event['result'];
            fixtureWrite($base . '/peer-1004-upgrade-database.json', json_encode($event['control'],JSON_THROW_ON_ERROR),1003);
            continue;
        }
        $request = $event['request']; $requests++;
        $ddl = $rootDb->query("SHOW COLUMNS FROM balances LIKE 'note'")->fetch() !== false;
        if ($fault === 'crash' && $ddl) { posix_kill($request['owner_pid'], 9); break; } // Native SIGKILL; no fixture exit hook in the worker.
        else {
            if ($fault === 'guard-loss' && $ddl && !$masterChanged) {
                $heldPath = $base . '/maintenance-1003/scheduler-' . $spec['operation'] . '/held.crontab';
                fixtureWrite($base . '/changed.cron', file_get_contents($heldPath) . "# unexpected edit\n", 0);
                if (fixtureCommand(['crontab', '-u', 'coremaster', $base . '/changed.cron'])[0] !== 0) throw new RuntimeException('Fixture cron drift failed.');
                $masterChanged = true;
            }
            [$exit, $out] = fixtureOwner($base, 1003, 'master-witness');
            [$http, $web] = fixtureQuietHttp($spec);
            $w = $request + ['observed_at_unix' => microtime(true), 'backup_pair_sha256' => $binding['backup_pair_sha256'],
                'master' => $exit === 0 ? json_decode($out, true) : [], 'web' => $http === 200 ? json_decode($web, true) : [],
                'writers' => fixtureWriters($base, $binding['writer_inventory_sha256'], $request['owner_pid'])];
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
if (in_array($scenario, ['wrong-file-plan', 'stale', 'extra-db', 'late-db'], true)) {
    $check($exit === 2 && !file_exists($journal) && $snapshot() === $before, 'unsafe first guard refuses before journal or database writes');
    $check($requests === (in_array($scenario, ['extra-db', 'wrong-file-plan'], true) ? 0 : 1), 'owner plan and database drain are checked before fresh external witness acceptance');
} elseif (in_array($scenario, ['guard-loss', 'crash'], true)) {
    $check($scenario === 'crash' ? $exit !== 0 : $exit === 2, 'real process interruption or schedule drift stops upgrade');
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
    foreach ([1003, 1004] as $uid) {
        $check(fixtureOwner($base, $uid, 'apply')[0] === 0, 'candidate bytes applied separately by their original owners');
        $check(fixtureOwner($base, $uid, 'verify-applied')[0] === 0, 'each owner verifies installed bytes before activation');
    }
}
$check(fixtureHttp($spec, null, '/read.php')[0] === 503, 'all success and failure outcomes retain business hold until explicit action');

$invalidate = static function (string $phase) use ($base, $check): void {
    $check(fixtureOwner($base, 1004, $phase === 'candidate' ? 'refresh-cache' : 'refresh-recovery-cache')[0] === 0,
        'exact installed PHP hashes refreshed under coordinated intent in held Core pool');
};
if ($scenario === 'activate') {
    $invalidate('candidate');
    $check(fixtureOwner($base, 1004, 'activate')[0] === 0, 'explicit candidate routing activation follows completed SQL files and cache checks');
    $check(fixtureOwner($base, 1003, 'restore-hold')[0] === 0, 'master scheduler resumes only after candidate activation');
    $check(fixtureOwner($base, 1004, 'complete')[0] === 0, 'application owner records completed release');
    $check(fixtureHttp($spec, null, '/read.php')[1] === 'new-app|new-master', 'actual FPM serves new code from both owners after activation');
    $check($rootDb->query('SELECT amount FROM balances WHERE id=1')->fetchColumn() === '37.53', 'activated code remains paired with upgraded database');
    $check(fixtureOwner($base, 1004, 'request-recovery')[0] === 2, 'old-backup recovery is refused after activation may admit new business writes');
} else {
    foreach ([1003, 1004] as $uid) $check(fixtureOwner($base, $uid, 'request-recovery')[0] === 0, 'both owner controls permanently select coordinated recovery');
    fixtureWrite($base . '/execution/recovery-requested.json', json_encode(['operation' => $operation]), 1004);
    $check($upgrade()[0] === 2, 'durable recovery direction forbids production re-entry');
    // Only this fresh container database may be emptied; exact identity and authenticated backup are checked first.
    $check($rootDb->query('SELECT DATABASE()')->fetchColumn() === FIXTURE_DB
        && hash_equals($pair['database_backup']['archive_sha256'], hash_file('sha256', $base . '/backup/database.aesgcm'))
        && hash_equals($pair['database_backup']['sql_sha256'], hash('sha256', $restoredSql)), 'exact paired native backup verified before disposable recovery');
    $recoveryPair=$pair;
    if ($scenario === 'wrong-file-plan') $recoveryPair['file_owners'][1003]['plan_sha256']=$spec['control']['owner_plan_sha256'][1003];
    $recoveryPairBytes=FullCoreReleaseControl::encode($recoveryPair);
    fixtureWrite($base.'/recovery-backup-pair.json',$recoveryPairBytes,1004);
    fixtureWrite($base . '/restore-expectation.json', json_encode(['operation' => $operation, 'candidate_commit' => $commit,
        'backup_pair_sha256' => hash('sha256',$recoveryPairBytes), 'snapshot_sha256' => $beforeFingerprint['snapshot_sha256']], JSON_THROW_ON_ERROR), 1004);
    $check(fixtureOwner($base, 1004, 'begin-database-recovery')[0] === 0, 'database recovery intent persists before native restore begins');
    $rootDb->exec('DROP DATABASE ' . FIXTURE_DB); $rootDb->exec('CREATE DATABASE ' . FIXTURE_DB); $rootDb->exec('USE ' . FIXTURE_DB);
    fixtureWrite($base . '/backup/restore.sql', $restoredSql, 0);
    $check(fixtureCommand(['mariadb', '--socket=/run/mysqld/mysqld.sock', '--user=grcudkpvcd', FIXTURE_DB],
        $base . '/backup/restore.sql')[0] === 0, 'native empty-target database restore runs as application database user');
    unlink($base . '/backup/restore.sql');
    if ($scenario === 'recover') {
        $rootDb->exec('ALTER TABLE balances AUTO_INCREMENT=8001');
        $check(fixtureOwner($base,1004,'confirm-database-recovery')[0] === 2, 'native restore confirmation refuses changed sequence counters');
        $rootDb->exec('ALTER TABLE balances AUTO_INCREMENT=3');
    }
    $check(fixtureOwner($base, 1004, 'confirm-database-recovery')[0] === 0, 'new owner process verifies actual restored database before completing its intent');
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
