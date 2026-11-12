<?php
/** Exact paired backend and screen update of the isolated Core pilot only. */
declare(strict_types=1);
const CI_ROOT = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const CI_PRIVATE = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
const CI_FEATURE = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/coreflux.thunderhawk-pilot.php';
const CI_FILES = ['core/migrations/194_document_ownership_transfers.sql', 'core/storage/document_ownership.php', 'core/integrations/staffing_document_field_contract.php', 'core/integrations/staffing_field_contract.php', 'core/jobdiva/mapping_alignment.php', 'modules/people/api/merge.php', 'modules/people/api/documents.php', 'modules/people/lib/documents.php', 'modules/people/lib/onboarding.php', 'modules/placements/lib/documents.php'];
if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging' || getcwd() !== CI_ROOT
    || posix_geteuid() !== 1003 || realpath(CI_PRIVATE) !== CI_PRIVATE
    || !in_array($argv[1] ?? '', ['--inspect-document-merge', '--install-document-merge'], true)
    || !preg_match('/^[a-f0-9]{64}$/D', $argv[2] ?? '')) exit(2);
$packageFile = __DIR__ . '/document-merge-package.json';
if (is_link($packageFile) || !hash_equals($argv[2], hash_file('sha256', $packageFile))) exit(2);
$package = json_decode(file_get_contents($packageFile), true, 64, JSON_THROW_ON_ERROR);
if (!preg_match('/^[a-f0-9]{40}$/D', $package['source_commit'] ?? '')
    || array_keys($package['files'] ?? []) !== CI_FILES) exit(2);
$lock = fopen(CI_PRIVATE . '/contact-install.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(2);
require_once CI_ROOT . '/core/db.php';
require_once CI_ROOT . '/core/integrations/identity.php';
require_once CI_ROOT . '/core/migrate.php';
$pdo = getDB();
if (!$pdo || $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'muzqvdvqbx' || !COREFLUX_STAGING
    || integrationInstanceId() !== '9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
    || COREFLUX_INTEGRATION_DELIVERY_TENANTS !== '2002'
    || (int) $pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn() !== 1) exit(2);

function ciSnapshot(PDO $pdo, array $projection = []): array {
    $result = [];
    foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) throw new RuntimeException('Unexpected table');
        $hashes = [];
        $columns = $projection[$table] ?? '*';
        foreach ($pdo->query('SELECT ' . $columns . ' FROM `' . $table . '`') as $row) $hashes[] = hash('sha256', serialize($row));
        sort($hashes);
        $result[$table] = ['rows' => count($hashes), 'hash' => hash('sha256', implode("\n", $hashes))];
    }
    ksort($result);
    return $result;
}
function ciWrite(string $path, string $bytes, int $mode = 0644): void {
    if (is_link($path) || realpath(dirname($path)) !== dirname($path)) throw new RuntimeException('Redirected file');
    $temp = dirname($path) . '/.document-merge-' . bin2hex(random_bytes(12));
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
    $name = 'document_merge_' . bin2hex(random_bytes(12)) . '.php';
    $path = CI_ROOT . '/api/admin/' . $name;
    $body = <<<'PHP'
<?php
header('Cache-Control: no-store');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||($_SERVER['HTTP_HOST']??'')!=='phpstack-1516771-6707602.cloudwaysapps.com'
 ||time()>EXPIRES||!hash_equals(TOKEN,(string)($_SERVER['HTTP_X_DOCUMENT_MERGE_PROBE']??''))){http_response_code(404);exit;}
try{
 foreach(FILES as $f)if(function_exists('opcache_invalidate'))opcache_invalidate(ROOT.'/'.$f,true);
 if(VERIFY){
  require_once ROOT.'/core/db.php';
  require_once ROOT.'/modules/people/lib/documents.php';
  require_once ROOT.'/modules/placements/lib/documents.php';
  require_once ROOT.'/core/integrations/staffing_field_contract.php';
  if(documentOwnershipDefinition('people')!==['people_documents','person_id','people']
    ||documentOwnershipDefinition('placements')!==['placement_documents','placement_id','placements']
    ||staffingFieldContractRules('document_ownership_transfers','receipt_sha256')['owner']!=='core_document_ownership_history')throw new RuntimeException('Document ownership runtime differs');
  $q=getDB()->query('SELECT COUNT(*) FROM document_ownership_transfers');
  if((int)$q->fetchColumn()!==0)throw new RuntimeException('Transfer history is already in use');
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
                CURLOPT_HTTPHEADER => $key === null ? [] : ['X-Document-Merge-Probe: ' . $key]]);
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

$backup = CI_PRIVATE . '/document-merge-before-' . substr($argv[2], 0, 20);
$state = CI_PRIVATE . '/document-merge-install-result.json';
if ($argv[1] === '--install-document-merge' && (file_exists($state) || file_exists($backup))) {
    fwrite(STDERR, "Inspect the existing install receipt before continuing.\n"); exit(2);
}
$report = ['checked_at' => gmdate('c'), 'source_commit' => $package['source_commit'], 'passed' => false,
    'production_changed' => false, 'provider_changed' => false, 'financial_postings' => false];
$prior = []; $changed = []; $locked = false; $schemaInstalled = false;
$report['created_tables']=[];$report['schema_statements_completed']=0;
$migration='core/migrations/194_document_ownership_transfers.sql';
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
    if($pdo->query('SELECT * FROM _migrations ORDER BY filename')->fetchAll(PDO::FETCH_ASSOC)!==$package['migration_ledger'])throw new RuntimeException('Migration ledger changed');
    if($pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='document_ownership_transfers'")->fetchColumn())throw new RuntimeException('Document ownership schema already exists; inspect the prior operation');
    if ($argv[1] === '--inspect-document-merge') {
        echo json_encode(['ready' => true, 'files' => 10, 'hosted_changes' => false]); exit;
    }
    if (!mkdir($backup, 0700)) throw new RuntimeException('Private backup unavailable');
    foreach ($prior as $file => $bytes) if ($bytes !== null) file_put_contents($backup . '/' . str_replace('/', '_', $file), $bytes);
    file_put_contents($backup . '/preimages.json', json_encode(array_map(static fn($bytes) => $bytes === null ? null : hash('sha256', $bytes), $prior), JSON_THROW_ON_ERROR));
    $report['backup'] = $backup;
    file_put_contents($state, json_encode($report, JSON_THROW_ON_ERROR));
    $baseline=ciSnapshot($pdo);
    file_put_contents($backup.'/database-before-schema.json',json_encode($baseline,JSON_THROW_ON_ERROR));
    $sql=base64_decode($package['files'][$migration]['body'],true);
    $statements=coreflux_split_sql_statements($sql);
    if(count($statements)!==1||!str_starts_with($statements[0],'CREATE TABLE IF NOT EXISTS document_ownership_transfers ('))throw new RuntimeException('Unexpected document ownership migration');
    $pdo->exec('SET SESSION lock_wait_timeout=10');$pdo->exec($statements[0]);
    $report['schema_statements_completed']=1;$report['created_tables'][]='document_ownership_transfers';
    file_put_contents($state,json_encode($report,JSON_THROW_ON_ERROR));
    $pdo->prepare('INSERT INTO _migrations(filename,sha256,last_error,duration_ms) VALUES(?,?,NULL,0)')->execute([basename($migration),corefluxMigrationHash($sql)]);
    // Additive schema is retained on a later screen rollback; never erase captured source evidence.
    ciWrite(CI_ROOT.'/'.$migration,$sql);$schemaInstalled=true;
    $afterSchema=ciSnapshot($pdo);
    $preserved=ciSnapshot($pdo);
    foreach($baseline as$table=>$value)if($table!=='_migrations'&&($preserved[$table]??null)!==$value)throw new RuntimeException('Existing records changed during additive migration');
    $ledger=$pdo->query('SELECT * FROM _migrations WHERE filename<>"194_document_ownership_transfers.sql" ORDER BY filename')->fetchAll(PDO::FETCH_ASSOC);
    if($ledger!==$package['migration_ledger'])throw new RuntimeException('Existing migration ledger changed');
    foreach(['document_ownership_transfers']as$table)if(($afterSchema[$table]['rows']??null)!==0)throw new RuntimeException('New evidence tables are already in use');
    $tables = array_keys($afterSchema);
    $pdo->exec('SET SESSION lock_wait_timeout=10'); $pdo->exec('SET autocommit=0');
    $pdo->exec('LOCK TABLES ' . implode(',', array_map(static fn($table) => '`' . $table . '` READ', $tables))); $locked = true;
    $before = ciSnapshot($pdo);
    if($before!==$afterSchema)throw new RuntimeException('Records changed before runtime activation');
    file_put_contents($backup . '/database-fingerprints.json', json_encode($before, JSON_THROW_ON_ERROR));
    foreach ($prior as $file => $bytes) {
        $path = CI_ROOT . '/' . $file;
        if ($file===$migration&&$schemaInstalled) continue;
        if (is_link($path) || ($bytes === null ? file_exists($path) : file_get_contents($path) !== $bytes)) throw new RuntimeException('Target changed before activation');
    }
    foreach (CI_FILES as $file) { if($file===$migration)continue; $changed[] = $file; ciWrite(CI_ROOT . '/' . $file, base64_decode($package['files'][$file]['body'], true)); }
    $report['web'] = ciRefresh(true);
    foreach (CI_FILES as $file) {
        if (!str_starts_with($file, 'spa-assets/') && $file !== 'index.html') continue;
        $curl = curl_init('https://phpstack-1516771-6707602.cloudwaysapps.com/' . $file . '?release=' . substr($argv[2],0,16));
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>20,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
        $bytes=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
        if ($status!==200 || !is_string($bytes) || hash('sha256',$bytes)!==$package['files'][$file]['sha256']) throw new RuntimeException('Served asset differs: '.$file);
    }
    $report['screen_assets_changed'] = false;
    if (ciSnapshot($pdo) !== $before || hash_file('sha256', CI_FEATURE) !== $package['feature_sha256']) throw new RuntimeException('Database or configuration changed');
    foreach (CI_FILES as $file) if (hash_file('sha256', CI_ROOT . '/' . $file) !== $package['files'][$file]['sha256']) throw new RuntimeException('Installed hash differs');
    $report += ['tables_verified' => count($before), 'rows_preserved' => array_sum(array_column($before, 'rows')),
        'files_verified' => 10, 'configuration_preserved' => true, 'temporary_probe_removed' => true];
    $report['existing_document_values_preserved']=true;
    $report['migration_applied']=basename($migration);$report['preexisting_tables_preserved']=count($baseline);
    $report['preexisting_rows_preserved']=array_sum(array_column($baseline,'rows'));
    $report['passed'] = true;
} catch (Throwable $e) {
    $report['failure'] = $e->getMessage();$report['additive_schema_retained']=$report['schema_statements_completed']>=1;
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
