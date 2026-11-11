<?php
/** Exact four-file, data-preserving update of the isolated Core pilot only. */
declare(strict_types=1);
const TP_ROOT = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const TP_PRIVATE = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
const TP_FEATURE = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/coreflux.thunderhawk-pilot.php';
const TP_FILES = ['modules/time/lib/time.php','modules/staffing/lib/timesheets.php','modules/time/api/entries.php','modules/time/api/csv_import.php'];
if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging' || getcwd() !== TP_ROOT
    || posix_geteuid() !== 1003 || realpath(TP_PRIVATE) !== TP_PRIVATE
    || !in_array($argv[1] ?? '', ['--inspect-time-projection', '--install-time-projection'], true)
    || !preg_match('/^[a-f0-9]{64}$/D', $argv[2] ?? '')) exit(2);
$packageFile = __DIR__ . '/time-projection-package.json';
if (is_link($packageFile) || !hash_equals($argv[2], hash_file('sha256', $packageFile))) exit(2);
$package = json_decode(file_get_contents($packageFile), true, 64, JSON_THROW_ON_ERROR);
if (!preg_match('/^[a-f0-9]{40}$/D', $package['source_commit'] ?? '')
    || array_keys($package['files'] ?? []) !== TP_FILES) exit(2);
$lock = fopen(TP_PRIVATE . '/contact-install.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(2);
require_once TP_ROOT . '/core/db.php';
require_once TP_ROOT . '/core/integrations/identity.php';
$pdo = getDB();
if (!$pdo || $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'muzqvdvqbx' || !COREFLUX_STAGING
    || integrationInstanceId() !== '9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
    || COREFLUX_INTEGRATION_DELIVERY_TENANTS !== '2002'
    || (int) $pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn() !== 1) exit(2);

function tpSnapshot(PDO $pdo): array {
    $result = [];
    foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) throw new RuntimeException('Unexpected table');
        $hashes = [];
        foreach ($pdo->query('SELECT * FROM `' . $table . '`') as $row) $hashes[] = hash('sha256', serialize($row));
        sort($hashes);
        $result[$table] = ['rows' => count($hashes), 'hash' => hash('sha256', implode("\n", $hashes))];
    }
    ksort($result);
    return $result;
}
function tpWrite(string $path, string $bytes, int $mode = 0644): void {
    if (is_link($path) || realpath(dirname($path)) !== dirname($path)) throw new RuntimeException('Redirected file');
    $temp = dirname($path) . '/.time-projection-' . bin2hex(random_bytes(12));
    $old = umask(0077); $f = fopen($temp, 'x'); umask($old);
    if (!$f) throw new RuntimeException('Exclusive file creation failed');
    try { if (fwrite($f, $bytes) !== strlen($bytes) || !fflush($f) || !fsync($f)) throw new RuntimeException('Incomplete write'); }
    finally { fclose($f); }
    if (!chgrp($temp, 33) || !chmod($temp, $mode) || !rename($temp, $path)) throw new RuntimeException('Install failed');
    clearstatcache(true, $path);
    if (fileowner($path) !== 1003 || filegroup($path) !== 33 || (fileperms($path) & 0777) !== $mode) throw new RuntimeException('File owner differs');
}
function tpRefresh(bool $verify): array {
    $token = bin2hex(random_bytes(32));
    $name = 'time_projection_' . bin2hex(random_bytes(12)) . '.php';
    $path = TP_ROOT . '/api/admin/' . $name;
    $body = <<<'PHP'
<?php
header('Cache-Control: no-store');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||($_SERVER['HTTP_HOST']??'')!=='phpstack-1516771-6707602.cloudwaysapps.com'
 ||time()>EXPIRES||!hash_equals(TOKEN,(string)($_SERVER['HTTP_X_PROJECTION_PROBE']??''))){http_response_code(404);exit;}
try{
 foreach(FILES as $f)if(function_exists('opcache_invalidate'))opcache_invalidate(ROOT.'/'.$f,true);
 if(VERIFY){
  require_once ROOT.'/modules/time/lib/time.php';require_once ROOT.'/modules/staffing/lib/timesheets.php';
  if((new ReflectionFunction('timeReconcileTimesheetHeader'))->getNumberOfParameters()!==2)throw new RuntimeException();
  $p=staffingTimesheetArtifactPayload(['id'=>7,'person_id'=>368,'period_start'=>'2026-11-09','period_end'=>'2026-11-15','approved_by_user_id'=>71,'approved_at'=>'2026-11-11 14:40:16']);
  if(($p['approved_by_user_id']??null)!==71)throw new RuntimeException();
 }
 header('Content-Type: application/json');echo json_encode(['passed'=>true,'sapi'=>PHP_SAPI]);
}catch(Throwable $e){http_response_code(500);echo json_encode(['passed'=>false,'failure_class'=>get_class($e)]);}
PHP;
    $body = strtr($body, ['EXPIRES' => (string) (time() + 180), 'TOKEN' => var_export($token, true),
        'FILES' => var_export(TP_FILES, true), 'ROOT' => var_export(TP_ROOT, true), 'VERIFY' => $verify ? 'true' : 'false']);
    tpWrite($path, $body);
    try {
        foreach ([null, $token] as $key) {
            $curl = curl_init('https://phpstack-1516771-6707602.cloudwaysapps.com/api/admin/' . $name);
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => $key === null ? [] : ['X-Projection-Probe: ' . $key]]);
            $response = curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
            if ($key === null && ($status !== 404 || $response !== '')) throw new RuntimeException('Anonymous probe access');
            if ($key !== null) {
                $result = is_string($response) ? json_decode($response, true) : null;
                if ($status !== 200 || ($result['passed'] ?? false) !== true || ($result['sapi'] ?? '') !== 'fpm-fcgi') throw new RuntimeException('Web cache verification failed');
            }
        }
        return $result;
    } finally { if (!unlink($path) || file_exists($path)) throw new RuntimeException('Probe cleanup failed'); }
}

$backup = TP_PRIVATE . '/time-projection-before-' . substr($argv[2], 0, 20);
$state = TP_PRIVATE . '/time-projection-install-result.json';
if ($argv[1] === '--install-time-projection' && (file_exists($state) || file_exists($backup))) {
    fwrite(STDERR, "Inspect the existing install receipt before continuing.\n"); exit(2);
}
$report = ['checked_at' => gmdate('c'), 'source_commit' => $package['source_commit'], 'passed' => false,
    'production_changed' => false, 'provider_changed' => false, 'financial_postings' => false];
$prior = []; $changed = []; $locked = false;
try {
    if (hash_file('sha256', TP_FEATURE) !== $package['feature_sha256']) throw new RuntimeException('Private configuration changed');
    foreach ($package['dependencies'] as $file => $hash) {
        if (is_link(TP_ROOT . '/' . $file) || hash('sha256', str_replace("\r\n", "\n", file_get_contents(TP_ROOT . '/' . $file))) !== $hash) throw new RuntimeException('Dependency changed');
    }
    foreach (TP_FILES as $file) {
        $path = TP_ROOT . '/' . $file; $stat = stat($path);
        $bytes = base64_decode($package['files'][$file]['body'], true);
        if (is_link($path) || $stat['uid'] !== 1003 || $stat['gid'] !== 33 || ($stat['mode'] & 0777) !== 0644
            || $bytes === false || hash('sha256', $bytes) !== $package['files'][$file]['sha256']
            || hash('sha256', str_replace("\r\n", "\n", file_get_contents($path))) !== $package['files'][$file]['before_sha256']) throw new RuntimeException('File preimage or ownership changed');
        token_get_all($bytes, TOKEN_PARSE); $prior[$file] = file_get_contents($path);
    }
    if ($argv[1] === '--inspect-time-projection') {
        echo json_encode(['ready' => true, 'files' => 4, 'hosted_changes' => false]); exit;
    }
    if (!mkdir($backup, 0700)) throw new RuntimeException('Private backup unavailable');
    foreach ($prior as $file => $bytes) file_put_contents($backup . '/' . str_replace('/', '_', $file), $bytes);
    $report['backup'] = $backup;
    file_put_contents($state, json_encode($report, JSON_THROW_ON_ERROR));
    $tables = array_keys(tpSnapshot($pdo));
    $pdo->exec('SET SESSION lock_wait_timeout=10'); $pdo->exec('SET autocommit=0');
    $pdo->exec('LOCK TABLES ' . implode(',', array_map(static fn($table) => '`' . $table . '` READ', $tables))); $locked = true;
    $before = tpSnapshot($pdo);
    file_put_contents($backup . '/database-fingerprints.json', json_encode($before, JSON_THROW_ON_ERROR));
    foreach (TP_FILES as $file) { $changed[] = $file; tpWrite(TP_ROOT . '/' . $file, base64_decode($package['files'][$file]['body'], true)); }
    $report['web'] = tpRefresh(true);
    if (tpSnapshot($pdo) !== $before || hash_file('sha256', TP_FEATURE) !== $package['feature_sha256']) throw new RuntimeException('Database or configuration changed');
    foreach (TP_FILES as $file) if (hash_file('sha256', TP_ROOT . '/' . $file) !== $package['files'][$file]['sha256']) throw new RuntimeException('Installed hash differs');
    $report += ['tables_verified' => count($before), 'rows_preserved' => array_sum(array_column($before, 'rows')),
        'files_verified' => 4, 'configuration_preserved' => true, 'temporary_probe_removed' => true];
    $report['passed'] = true;
} catch (Throwable $e) {
    $report['failure'] = $e->getMessage();
    if ($changed) {
        try { foreach (array_reverse($changed) as $file) tpWrite(TP_ROOT . '/' . $file, $prior[$file]); tpRefresh(false); $report['runtime_restored'] = true; }
        catch (Throwable $recovery) { $report['recovery_failure'] = $recovery->getMessage(); }
    }
} finally {
    if ($locked) $pdo->exec('UNLOCK TABLES');
    $pdo->exec('SET autocommit=1');
}
file_put_contents($state, json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
echo json_encode($report, JSON_THROW_ON_ERROR); exit($report['passed'] ? 0 : 1);
