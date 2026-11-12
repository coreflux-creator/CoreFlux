<?php
/** Rollback-only regression against the isolated Core staging runtime. */
declare(strict_types=1);
const SHARED_SCHEDULES_ROOT = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const SHARED_SCHEDULES_PRIVATE = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
if (PHP_SAPI !== 'cli' || posix_geteuid() !== 1003 || getcwd() !== SHARED_SCHEDULES_ROOT
    || getenv('COREFLUX_ENV') !== 'staging' || ($argv[1] ?? '') !== '--verify-isolated-shared-schedules'
    || !preg_match('/^[a-f0-9]{64}$/D', $argv[2] ?? '')) exit(2);
require_once SHARED_SCHEDULES_ROOT . '/core/db.php';
require_once SHARED_SCHEDULES_ROOT . '/core/integrations/identity.php';
$pdo = getDB();
if (!$pdo || $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'muzqvdvqbx' || !COREFLUX_STAGING
    || integrationInstanceId() !== '9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
    || COREFLUX_INTEGRATION_DELIVERY_TENANTS !== '2002'
    || (int) $pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn() !== 1) exit(2);
$fixture = __DIR__ . '/shared-schedules-fixture.php';
if (is_link($fixture) || !hash_equals($argv[2], hash_file('sha256', $fixture))) exit(2);
$install = json_decode(file_get_contents(SHARED_SCHEDULES_PRIVATE . '/shared-schedules-install-result.json'), true, 64, JSON_THROW_ON_ERROR);
if (($install['passed'] ?? false) !== true) exit(2);
foreach (json_decode('{"modules/placements/lib/cycle_edits.php":"22b17a1cd3c0a74cb556b4251962abd7fa4cef318278e1a998a849529311970c","modules/placements/api/cycles.php":"0f6c48ba492dc770d61b2f1e50ecb509392c0f193f00b44ac70d4f23aeb4d533","modules/payroll/lib/staffing_cycle_guard.php":"27975577befe779e2ef24c9086fd3db72e881e63fb4e0ed02fadae11f4876a9f","modules/payroll/api/cycles.php":"ee49527cd6219e595d28eb17626f6af99ad02ba443c86e01f082eec1d7ffabce","modules/payroll/api/pay_schedules.php":"af9479c63be84cda2f570f4a9254a103cc2779e035b6cf7fdf5b573dc2dfee9d","modules/placements/lib/ownership.php":"37d797f2cf7a045230d03809634ecd262b7e7b53005254dd7c08ac2950ca8a76","core/jobdiva/sync.php":"e75bc552e4b0796b29d1776b7633b0ca0cedc43daf2bbc760c9b87655ddeeb09","core/integrations/purepay_assignment_contract.php":"837549856661ab0b084546f780f6fa655ed6e8f85f52f65cafbc26fcbca734d0","core/integrations/delivery.php":"78e00174fbe5789d3a79cf5f2fc999cbb399f0243ac725adc618873771c96bcd","core/encryption.php":"0724924e9362eb0f5fac3a1f04556c4e85fc66a2b141d04ec8ecd1dbba1f27cf"}',true) as $file=>$expected) {
    if (is_link(SHARED_SCHEDULES_ROOT.'/'.$file) || hash_file('sha256',SHARED_SCHEDULES_ROOT.'/'.$file)!==$expected) exit(2);
}
$lock = fopen(SHARED_SCHEDULES_PRIVATE . '/contact-install.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(2);
if ((int) $pdo->query("SELECT COUNT(*) FROM placements WHERE tenant_id=2002 AND external_id IN ('99187001','jd:99187001','99187011','jd:99187011','99187021','jd:99187021')")->fetchColumn() !== 0) exit(2);
// The required fixture runs in this scope; keep its local $receipt independent.
$sharedScheduleReceipt = SHARED_SCHEDULES_PRIVATE . '/shared-schedules-hosted-recovery-result.json';
if (file_exists($sharedScheduleReceipt)) exit(2);
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
file_put_contents($sharedScheduleReceipt, json_encode($report, JSON_THROW_ON_ERROR));
$corporateIdentityBefore = corporateIdentitySnapshot($pdo);
ob_start();
try {
    require $fixture;
    $log = ob_get_contents();
    if (!str_contains($log, '35 shared schedule editing checks passed.')) throw new RuntimeException('Regression did not finish');
    if ($pdo->inTransaction()) throw new RuntimeException('Fixture transaction still open');
    if (corporateIdentitySnapshot($pdo) !== $corporateIdentityBefore) throw new RuntimeException('Original database rows changed');
    $report += ['shared_schedules_checks_passed' => 35, 'tables_verified' => count($corporateIdentityBefore),
        'original_rows_preserved' => array_sum(array_column($corporateIdentityBefore, 'rows')), 'synthetic_rows_rolled_back' => true];
    $report['passed'] = true;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $report['failure'] = $e->getMessage();
    $report['original_rows_preserved_after_failure'] = corporateIdentitySnapshot($pdo) === $corporateIdentityBefore;
} finally {
    $log = ob_get_clean();
    file_put_contents(__DIR__ . '/shared-schedules-hosted.log', $log);
    $report['status'] = $report['passed'] ? 'complete' : 'failed';
    file_put_contents($sharedScheduleReceipt, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
}
echo json_encode($report, JSON_THROW_ON_ERROR);
exit($report['passed'] ? 0 : 1);
