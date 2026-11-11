<?php
/** Rollback-only regression against the isolated Core staging runtime. */
declare(strict_types=1);
const NATIVE_EDIT_ROOT = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const NATIVE_EDIT_PRIVATE = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
if (PHP_SAPI !== 'cli' || posix_geteuid() !== 1003 || getcwd() !== NATIVE_EDIT_ROOT
    || getenv('COREFLUX_ENV') !== 'staging' || ($argv[1] ?? '') !== '--verify-isolated-native-edit'
    || !preg_match('/^[a-f0-9]{64}$/D', $argv[2] ?? '')) exit(2);
require_once NATIVE_EDIT_ROOT . '/core/db.php';
require_once NATIVE_EDIT_ROOT . '/core/integrations/identity.php';
$pdo = getDB();
if (!$pdo || $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'muzqvdvqbx' || !COREFLUX_STAGING
    || integrationInstanceId() !== '9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
    || COREFLUX_INTEGRATION_DELIVERY_TENANTS !== '2002'
    || (int) $pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn() !== 1) exit(2);
$fixture = __DIR__ . '/native-edit-fixture.php';
if (is_link($fixture) || !hash_equals($argv[2], hash_file('sha256', $fixture))) exit(2);
$install = json_decode(file_get_contents(NATIVE_EDIT_PRIVATE . '/native-edit-install-result.json'), true, 64, JSON_THROW_ON_ERROR);
if (($install['passed'] ?? false) !== true) exit(2);
foreach (json_decode('{"modules/placements/lib/edit.php":"7f468d24a6032c6802f1591ef82cf8ebfa5e34ed51be1f42f2cb95f5b0d4ac05","modules/placements/lib/ownership.php":"37d797f2cf7a045230d03809634ecd262b7e7b53005254dd7c08ac2950ca8a76","modules/placements/api/placements.php":"23a7a1e203cbde2c72d0cd2a9d16517d0669cdc4d966c2b7db51f599349208ce"}',true) as $file=>$expected) {
    if (is_link(NATIVE_EDIT_ROOT.'/'.$file) || hash_file('sha256',NATIVE_EDIT_ROOT.'/'.$file)!==$expected) exit(2);
}
$lock = fopen(NATIVE_EDIT_PRIVATE . '/contact-install.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(2);
if ((int) $pdo->query("SELECT COUNT(*) FROM placements WHERE tenant_id=2002 AND external_id IN ('99130001','jd:99130001')")->fetchColumn() !== 0) exit(2);
$receipt = NATIVE_EDIT_PRIVATE . '/native-edit-hosted-result.json';
if (file_exists($receipt)) exit(2);
function nativeEditSnapshot(PDO $pdo): array {
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
$nativeEditBefore = nativeEditSnapshot($pdo);
ob_start();
try {
    require $fixture;
    $log = ob_get_contents();
    if (!str_contains($log, '27 placement native edit checks passed.')) throw new RuntimeException('Regression did not finish');
    if ($pdo->inTransaction()) throw new RuntimeException('Fixture transaction still open');
    if (nativeEditSnapshot($pdo) !== $nativeEditBefore) throw new RuntimeException('Original database rows changed');
    $report += ['native_edit_checks_passed' => 27, 'tables_verified' => count($nativeEditBefore),
        'original_rows_preserved' => array_sum(array_column($nativeEditBefore, 'rows')), 'synthetic_rows_rolled_back' => true];
    $report['passed'] = true;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $report['failure'] = $e->getMessage();
    $report['original_rows_preserved_after_failure'] = nativeEditSnapshot($pdo) === $nativeEditBefore;
} finally {
    $log = ob_get_clean();
    file_put_contents(__DIR__ . '/native-edit-hosted.log', $log);
    $report['status'] = $report['passed'] ? 'complete' : 'failed';
    file_put_contents($receipt, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
}
echo json_encode($report, JSON_THROW_ON_ERROR);
exit($report['passed'] ? 0 : 1);
