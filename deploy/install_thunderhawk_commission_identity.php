<?php
/** Exact commission identity update of the isolated Core pilot only. */
declare(strict_types=1);
const CI_ROOT = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const CI_PRIVATE = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
const CI_FEATURE = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/coreflux.thunderhawk-pilot.php';
const CI_FILES = ['core/integrations/purepay_assignment_contract.php', 'modules/placements/lib/economics.php', 'modules/placements/lib/commission_identity.php'];
if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging' || getcwd() !== CI_ROOT
    || posix_geteuid() !== 1003 || realpath(CI_PRIVATE) !== CI_PRIVATE
    || !in_array($argv[1] ?? '', ['--inspect-commission-identity', '--install-commission-identity'], true)
    || !preg_match('/^[a-f0-9]{64}$/D', $argv[2] ?? '')) exit(2);
$packageFile = __DIR__ . '/commission-identity-package.json';
if (is_link($packageFile) || !hash_equals($argv[2], hash_file('sha256', $packageFile))) exit(2);
$package = json_decode(file_get_contents($packageFile), true, 64, JSON_THROW_ON_ERROR);
if (!preg_match('/^[a-f0-9]{40}$/D', $package['source_commit'] ?? '')
    || array_keys($package['files'] ?? []) !== CI_FILES) exit(2);
$lock = fopen(CI_PRIVATE . '/contact-install.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(2);
require_once CI_ROOT . '/core/db.php';
require_once CI_ROOT . '/core/integrations/identity.php';
$pdo = getDB();
if (!$pdo || $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'muzqvdvqbx' || !COREFLUX_STAGING
    || integrationInstanceId() !== '9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
    || COREFLUX_INTEGRATION_DELIVERY_TENANTS !== '2002'
    || (int) $pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn() !== 1) exit(2);

function ciSnapshot(PDO $pdo): array {
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
function ciWrite(string $path, string $bytes, int $mode = 0644): void {
    if (is_link($path) || realpath(dirname($path)) !== dirname($path)) throw new RuntimeException('Redirected file');
    $temp = dirname($path) . '/.commission-identity-' . bin2hex(random_bytes(12));
    $old = umask(0077); $f = fopen($temp, 'x'); umask($old);
    if (!$f) throw new RuntimeException('Exclusive file creation failed');
    try { if (fwrite($f, $bytes) !== strlen($bytes) || !fflush($f) || !fsync($f)) throw new RuntimeException('Incomplete write'); }
    finally { fclose($f); }
    if (!chgrp($temp, 33) || !chmod($temp, $mode) || !rename($temp, $path)) throw new RuntimeException('Install failed');
    clearstatcache(true, $path);
    if (fileowner($path) !== 1003 || filegroup($path) !== 33 || (fileperms($path) & 0777) !== $mode) throw new RuntimeException('File owner differs');
}
function ciRefresh(bool $verify): array {
    $token = bin2hex(random_bytes(32));
    $name = 'commission_identity_' . bin2hex(random_bytes(12)) . '.php';
    $path = CI_ROOT . '/api/admin/' . $name;
    $body = <<<'PHP'
<?php
header('Cache-Control: no-store');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||($_SERVER['HTTP_HOST']??'')!=='phpstack-1516771-6707602.cloudwaysapps.com'
 ||time()>EXPIRES||!hash_equals(TOKEN,(string)($_SERVER['HTTP_X_ASSIGNMENT_CONTEXT_PROBE']??''))){http_response_code(404);exit;}
try{
 foreach(FILES as $f)if(function_exists('opcache_invalidate'))opcache_invalidate(ROOT.'/'.$f,true);
 if(VERIFY){
  require_once ROOT.'/core/db.php';
  require_once ROOT.'/modules/placements/lib/commission_identity.php';
  require_once ROOT.'/core/integrations/purepay_assignment_contract.php';
  if(PUREPAY_ASSIGNMENT_INPUT_VERSION!=='2026-11-12.1')throw new RuntimeException('Assignment contract changed');
  $pdo=getDB();
  if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx')throw new RuntimeException('Wrong pilot database');
  if(placementCommissionPersonIdentity($pdo,2002,103)!==null)throw new RuntimeException('Unlinked fixture account gained an identity');
  $q=$pdo->query('SELECT economics_snapshot_json FROM placement_rates WHERE tenant_id=2002 AND id=29');
  $snapshot=purepayAssignmentSnapshotJson($q->fetchColumn());
  $parties=array_values(array_filter($snapshot['contract_parties'],static fn($p)=>$p['source_ref']==='commission:9'));
  if(count($parties)!==1||$parties[0]['person_id']!==null||$parties[0]['currency']!=='INR'||$parties[0]['fee_flat']!=7500)throw new RuntimeException('Original approval changed');
  try{purepayAssignmentParty($parties[0],[],static fn()=>[],[],true);throw new LogicException('Unlinked approval accepted');}
  catch(RuntimeException $e){if(!str_starts_with($e->getMessage(),'Approved payroll participant ')||!str_contains($e->getMessage(),'canonical person identity'))throw $e;}
 }
 header('Content-Type: application/json');echo json_encode(['passed'=>true,'sapi'=>PHP_SAPI]);
}catch(Throwable $e){http_response_code(500);echo json_encode(['passed'=>false,'failure_class'=>get_class($e)]);}
PHP;
    $body = strtr($body, ['EXPIRES' => (string) (time() + 180), 'TOKEN' => var_export($token, true),
        'FILES' => var_export(CI_FILES, true), 'ROOT' => var_export(CI_ROOT, true), 'VERIFY' => $verify ? 'true' : 'false']);
    ciWrite($path, $body);
    try {
        foreach ([null, $token] as $key) {
            $curl = curl_init('https://phpstack-1516771-6707602.cloudwaysapps.com/api/admin/' . $name);
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => $key === null ? [] : ['X-Assignment-Context-Probe: ' . $key]]);
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

$backup = CI_PRIVATE . '/commission-identity-before-' . substr($argv[2], 0, 20);
$state = CI_PRIVATE . '/commission-identity-install-result.json';
if ($argv[1] === '--install-commission-identity' && (file_exists($state) || file_exists($backup))) {
    fwrite(STDERR, "Inspect the existing install receipt before continuing.\n"); exit(2);
}
$report = ['checked_at' => gmdate('c'), 'source_commit' => $package['source_commit'], 'passed' => false,
    'production_changed' => false, 'provider_changed' => false, 'financial_postings' => false];
$prior = []; $changed = []; $locked = false;
try {
    if (hash_file('sha256', CI_FEATURE) !== $package['feature_sha256']) throw new RuntimeException('Private configuration changed');
    foreach ($package['dependencies'] as $file => $hash) {
        if (is_link(CI_ROOT . '/' . $file) || hash('sha256', str_replace("\r\n", "\n", file_get_contents(CI_ROOT . '/' . $file))) !== $hash) throw new RuntimeException('Dependency changed');
    }
    foreach (CI_FILES as $file) {
        $path = CI_ROOT . '/' . $file;
        $bytes = base64_decode($package['files'][$file]['body'], true);
        if (realpath(dirname($path)) !== dirname($path) || is_link($path)
            || $bytes === false || hash('sha256', $bytes) !== $package['files'][$file]['sha256']) throw new RuntimeException('Invalid target or package');
        $existing = is_file($path) ? file_get_contents($path) : null;
        $stat = $existing === null ? null : stat($path);
        if (($existing === null ? null : hash('sha256', $existing)) !== $package['files'][$file]['before_sha256']
            || ($stat !== null && ($stat['uid'] !== 1003 || $stat['gid'] !== 33 || ($stat['mode'] & 0777) !== 0644))) throw new RuntimeException('File preimage or ownership changed: ' . $file);
        $prior[$file] = $existing;
        if (str_ends_with($file, '.php')) token_get_all($bytes, TOKEN_PARSE);
    }
    if ($argv[1] === '--inspect-commission-identity') {
        echo json_encode(['ready' => true, 'files' => 3, 'hosted_changes' => false]); exit;
    }
    if (!mkdir($backup, 0700)) throw new RuntimeException('Private backup unavailable');
    foreach ($prior as $file => $bytes) if ($bytes !== null) file_put_contents($backup . '/' . str_replace('/', '_', $file), $bytes);
    file_put_contents($backup . '/preimages.json', json_encode(array_map(static fn($bytes) => $bytes === null ? null : hash('sha256', $bytes), $prior), JSON_THROW_ON_ERROR));
    $report['backup'] = $backup;
    file_put_contents($state, json_encode($report, JSON_THROW_ON_ERROR));
    $tables = array_keys(ciSnapshot($pdo));
    $pdo->exec('SET SESSION lock_wait_timeout=10'); $pdo->exec('SET autocommit=0');
    $pdo->exec('LOCK TABLES ' . implode(',', array_map(static fn($table) => '`' . $table . '` READ', $tables))); $locked = true;
    $before = ciSnapshot($pdo);
    file_put_contents($backup . '/database-fingerprints.json', json_encode($before, JSON_THROW_ON_ERROR));
    foreach ($prior as $file => $bytes) {
        $path = CI_ROOT . '/' . $file;
        if (is_link($path) || ($bytes === null ? file_exists($path) : file_get_contents($path) !== $bytes)) throw new RuntimeException('Target changed before activation');
    }
    foreach (CI_FILES as $file) { $changed[] = $file; ciWrite(CI_ROOT . '/' . $file, base64_decode($package['files'][$file]['body'], true)); }
    $report['web'] = ciRefresh(true);
    $report['screen_files_preserved'] = true;
    if (ciSnapshot($pdo) !== $before || hash_file('sha256', CI_FEATURE) !== $package['feature_sha256']) throw new RuntimeException('Database or configuration changed');
    foreach (CI_FILES as $file) if (hash_file('sha256', CI_ROOT . '/' . $file) !== $package['files'][$file]['sha256']) throw new RuntimeException('Installed hash differs');
    $report += ['tables_verified' => count($before), 'rows_preserved' => array_sum(array_column($before, 'rows')),
        'files_verified' => 3, 'configuration_preserved' => true, 'temporary_probe_removed' => true];
    $report['passed'] = true;
} catch (Throwable $e) {
    $report['failure'] = $e->getMessage();
    if ($changed) {
        try { foreach (array_reverse($changed) as $file) {
            $path = CI_ROOT . '/' . $file;
            if ($prior[$file] !== null) ciWrite($path, $prior[$file]);
            elseif (file_exists($path)) {
                if (is_link($path) || hash_file('sha256', $path) !== $package['files'][$file]['sha256'] || !unlink($path)) throw new RuntimeException('New library rollback refused');
            }
        } ciRefresh(false); $report['runtime_restored'] = true; }
        catch (Throwable $recovery) { $report['recovery_failure'] = $recovery->getMessage(); }
    }
} finally {
    if ($locked) $pdo->exec('UNLOCK TABLES');
    $pdo->exec('SET autocommit=1');
}
file_put_contents($state, json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
echo json_encode($report, JSON_THROW_ON_ERROR); exit($report['passed'] ? 0 : 1);
