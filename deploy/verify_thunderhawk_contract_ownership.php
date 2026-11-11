<?php
/** Rollback-only regression against the isolated Core staging runtime. */
declare(strict_types=1);
const CONTRACT_OWNERSHIP_ROOT = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const CONTRACT_OWNERSHIP_PRIVATE = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
if (PHP_SAPI !== 'cli' || posix_geteuid() !== 1003 || getcwd() !== CONTRACT_OWNERSHIP_ROOT
    || getenv('COREFLUX_ENV') !== 'staging' || ($argv[1] ?? '') !== '--verify-isolated-contract-ownership'
    || !preg_match('/^[a-f0-9]{64}$/D', $argv[2] ?? '')) exit(2);
require_once CONTRACT_OWNERSHIP_ROOT . '/core/db.php';
require_once CONTRACT_OWNERSHIP_ROOT . '/core/integrations/identity.php';
$pdo = getDB();
if (!$pdo || $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'muzqvdvqbx' || !COREFLUX_STAGING
    || integrationInstanceId() !== '9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
    || COREFLUX_INTEGRATION_DELIVERY_TENANTS !== '2002'
    || (int) $pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn() !== 1) exit(2);
$fixture = __DIR__ . '/contract-ownership-fixture.php';
if (is_link($fixture) || !hash_equals($argv[2], hash_file('sha256', $fixture))) exit(2);
$install = json_decode(file_get_contents(CONTRACT_OWNERSHIP_PRIVATE . '/contract-ownership-install-result.json'), true, 64, JSON_THROW_ON_ERROR);
if (($install['passed'] ?? false) !== true) exit(2);
foreach (json_decode('{"core/jobdiva/sync.php":"ab0faee59bcce539c6a35a30434a44978cba4303a09d531efde1e2d42a675527","core/jobdiva/projector.php":"7e755642fe01d3758a44d41858b84d28aa78362864445456f0625196668ded1e","core/jobdiva/contract_projection.php":"b60eaf948a77e2e3a03d0720a333d1e32d8f66296e9fa1e4e2096fe145eaa3f9","modules/placements/lib/economics.php":"ee01bae8602dcf22bac4c35b551cbc54ac4e436c8276000e3f839ae09b845d76"}',true) as $file=>$expected) {
    if (is_link(CONTRACT_OWNERSHIP_ROOT.'/'.$file) || hash_file('sha256',CONTRACT_OWNERSHIP_ROOT.'/'.$file)!==$expected) exit(2);
}
$lock = fopen(CONTRACT_OWNERSHIP_PRIVATE . '/contact-install.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(2);
if ((int) $pdo->query("SELECT COUNT(*) FROM placements WHERE tenant_id=2002 AND external_id IN ('99140001','jd:99140001')")->fetchColumn() !== 0) exit(2);
$receipt = CONTRACT_OWNERSHIP_PRIVATE . '/contract-ownership-hosted-result.json';
if (file_exists($receipt)) exit(2);
function contractOwnershipSnapshot(PDO $pdo): array {
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
$report = ['checked_at' => gmdate('c'), 'source_commit' => $install['source_commit'], 'passed' => false,
    'status' => 'running', 'pid' => getmypid(), 'tenant_id' => 2002,
    'provider_changed' => false, 'production_changed' => false, 'financial_postings' => false,
    'test_data_policy' => 'Synthetic rows enclosed in an outer transaction and rolled back; sequence gaps are allowed.'];
file_put_contents($receipt, json_encode($report, JSON_THROW_ON_ERROR));
$contractOwnershipBefore = contractOwnershipSnapshot($pdo);
ob_start();
try {
    require $fixture;
    $log = ob_get_contents();
    if (!str_contains($log, '28 JobDiva contract ownership checks passed.')) throw new RuntimeException('Regression did not finish');
    if ($pdo->inTransaction()) throw new RuntimeException('Fixture transaction still open');
    if (contractOwnershipSnapshot($pdo) !== $contractOwnershipBefore) throw new RuntimeException('Original database rows changed');
    $report += ['contract_ownership_checks_passed' => 28, 'tables_verified' => count($contractOwnershipBefore),
        'original_rows_preserved' => array_sum(array_column($contractOwnershipBefore, 'rows')), 'synthetic_rows_rolled_back' => true];
    $report['passed'] = true;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $report['failure'] = $e->getMessage();
    $report['original_rows_preserved_after_failure'] = contractOwnershipSnapshot($pdo) === $contractOwnershipBefore;
} finally {
    $log = ob_get_clean();
    file_put_contents(__DIR__ . '/contract-ownership-hosted.log', $log);
    $report['status'] = $report['passed'] ? 'complete' : 'failed';
    file_put_contents($receipt, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
}
echo json_encode($report, JSON_THROW_ON_ERROR);
exit($report['passed'] ? 0 : 1);
