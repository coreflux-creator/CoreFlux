<?php
/** Rollback-only regression against the isolated Core staging runtime. */
declare(strict_types=1);
const NATIVE_ITEMS_ROOT = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const NATIVE_ITEMS_PRIVATE = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
if (PHP_SAPI !== 'cli' || posix_geteuid() !== 1003 || getcwd() !== NATIVE_ITEMS_ROOT
    || getenv('COREFLUX_ENV') !== 'staging' || ($argv[1] ?? '') !== '--verify-isolated-native-items'
    || !preg_match('/^[a-f0-9]{64}$/D', $argv[2] ?? '')) exit(2);
require_once NATIVE_ITEMS_ROOT . '/core/db.php';
require_once NATIVE_ITEMS_ROOT . '/core/integrations/identity.php';
$pdo = getDB();
if (!$pdo || $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'muzqvdvqbx' || !COREFLUX_STAGING
    || integrationInstanceId() !== '9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
    || COREFLUX_INTEGRATION_DELIVERY_TENANTS !== '2002'
    || (int) $pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn() !== 1) exit(2);
$fixture = __DIR__ . '/native-items-fixture.php';
if (is_link($fixture) || !hash_equals($argv[2], hash_file('sha256', $fixture))) exit(2);
$install = json_decode(file_get_contents(NATIVE_ITEMS_PRIVATE . '/native-items-install-result.json'), true, 64, JSON_THROW_ON_ERROR);
if (($install['passed'] ?? false) !== true) exit(2);
foreach (json_decode('{"modules/placements/lib/economic_items_edit.php":"e2e21a4397e8b4823963dd539e0661f3fa991d6a0d80dc94f4ea5026d1aea61c","modules/placements/lib/economics.php":"9bd7c5657bd2aa9844a69cbdf9a2c2bb80f0f24794ee3c69460d6c4b11d6d76b","modules/placements/api/economics.php":"2e1bb76d34e8fa34d7e4e149d8944f2a95abe7f7e0ac950915de43646ff1c50d","modules/placements/lib/ownership.php":"37d797f2cf7a045230d03809634ecd262b7e7b53005254dd7c08ac2950ca8a76","core/jobdiva/sync.php":"e75bc552e4b0796b29d1776b7633b0ca0cedc43daf2bbc760c9b87655ddeeb09"}',true) as $file=>$expected) {
    if (is_link(NATIVE_ITEMS_ROOT.'/'.$file) || hash_file('sha256',NATIVE_ITEMS_ROOT.'/'.$file)!==$expected) exit(2);
}
$lock = fopen(NATIVE_ITEMS_PRIVATE . '/contact-install.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(2);
if ((int) $pdo->query("SELECT COUNT(*) FROM placements WHERE tenant_id=2002 AND external_id IN ('99185001','jd:99185001')")->fetchColumn() !== 0) exit(2);
$receipt = NATIVE_ITEMS_PRIVATE . '/native-items-hosted-result.json';
if (file_exists($receipt)) exit(2);
function corporateIdentitySnapshot(PDO $pdo): array {
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
$corporateIdentityBefore = corporateIdentitySnapshot($pdo);
ob_start();
try {
    require $fixture;
    $log = ob_get_contents();
    if (!str_contains($log, '49 native one-time item checks passed.')) throw new RuntimeException('Regression did not finish');
    if ($pdo->inTransaction()) throw new RuntimeException('Fixture transaction still open');
    if (corporateIdentitySnapshot($pdo) !== $corporateIdentityBefore) throw new RuntimeException('Original database rows changed');
    $report += ['native_items_checks_passed' => 49, 'tables_verified' => count($corporateIdentityBefore),
        'original_rows_preserved' => array_sum(array_column($corporateIdentityBefore, 'rows')), 'synthetic_rows_rolled_back' => true];
    $report['passed'] = true;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $report['failure'] = $e->getMessage();
    $report['original_rows_preserved_after_failure'] = corporateIdentitySnapshot($pdo) === $corporateIdentityBefore;
} finally {
    $log = ob_get_clean();
    file_put_contents(__DIR__ . '/native-items-hosted.log', $log);
    $report['status'] = $report['passed'] ? 'complete' : 'failed';
    file_put_contents($receipt, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
}
echo json_encode($report, JSON_THROW_ON_ERROR);
exit($report['passed'] ? 0 : 1);
