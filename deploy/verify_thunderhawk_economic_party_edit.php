<?php
/** Rollback-only regression against the isolated Core staging runtime. */
declare(strict_types=1);
const ECONOMIC_PARTY_EDIT_ROOT = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const ECONOMIC_PARTY_EDIT_PRIVATE = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
if (PHP_SAPI !== 'cli' || posix_geteuid() !== 1003 || getcwd() !== ECONOMIC_PARTY_EDIT_ROOT
    || getenv('COREFLUX_ENV') !== 'staging' || ($argv[1] ?? '') !== '--verify-isolated-economic-party-edit'
    || !preg_match('/^[a-f0-9]{64}$/D', $argv[2] ?? '')) exit(2);
require_once ECONOMIC_PARTY_EDIT_ROOT . '/core/db.php';
require_once ECONOMIC_PARTY_EDIT_ROOT . '/core/integrations/identity.php';
$pdo = getDB();
if (!$pdo || $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'muzqvdvqbx' || !COREFLUX_STAGING
    || integrationInstanceId() !== '9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
    || COREFLUX_INTEGRATION_DELIVERY_TENANTS !== '2002'
    || (int) $pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn() !== 1) exit(2);
$fixture = __DIR__ . '/economic-party-edit-fixture.php';
if (is_link($fixture) || !hash_equals($argv[2], hash_file('sha256', $fixture))) exit(2);
$install = json_decode(file_get_contents(ECONOMIC_PARTY_EDIT_PRIVATE . '/economic-party-edit-install-result.json'), true, 64, JSON_THROW_ON_ERROR);
if (($install['passed'] ?? false) !== true) exit(2);
foreach (json_decode('{"modules/placements/lib/economics_edit.php":"596eddd9abe285dce11a9192d9d81e05cccd8dfcdfc26259a8c25bc033255484","modules/placements/api/economics.php":"81da12b68721fc6c8426d226470abb62adc8d245ac486675a88e4dc4fef017b5"}',true) as $file=>$expected) {
    if (is_link(ECONOMIC_PARTY_EDIT_ROOT.'/'.$file) || hash_file('sha256',ECONOMIC_PARTY_EDIT_ROOT.'/'.$file)!==$expected) exit(2);
}
$lock = fopen(ECONOMIC_PARTY_EDIT_PRIVATE . '/contact-install.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(2);
if ((int) $pdo->query("SELECT COUNT(*) FROM placements WHERE tenant_id=2002 AND external_id IN ('99160001','jd:99160001','99160011','jd:99160011','99160021','jd:99160021')")->fetchColumn() !== 0) exit(2);
$receipt = ECONOMIC_PARTY_EDIT_PRIVATE . '/economic-party-edit-hosted-result.json';
if (file_exists($receipt)) exit(2);
function economicPartyEditSnapshot(PDO $pdo): array {
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
$economicPartyEditBefore = economicPartyEditSnapshot($pdo);
ob_start();
try {
    require $fixture;
    $log = ob_get_contents();
    if (!str_contains($log, '33 economic-party native edit checks passed.')) throw new RuntimeException('Regression did not finish');
    if ($pdo->inTransaction()) throw new RuntimeException('Fixture transaction still open');
    if (economicPartyEditSnapshot($pdo) !== $economicPartyEditBefore) throw new RuntimeException('Original database rows changed');
    $report += ['economic_party_edit_checks_passed' => 33, 'tables_verified' => count($economicPartyEditBefore),
        'original_rows_preserved' => array_sum(array_column($economicPartyEditBefore, 'rows')), 'synthetic_rows_rolled_back' => true];
    $report['passed'] = true;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $report['failure'] = $e->getMessage();
    $report['original_rows_preserved_after_failure'] = economicPartyEditSnapshot($pdo) === $economicPartyEditBefore;
} finally {
    $log = ob_get_clean();
    file_put_contents(__DIR__ . '/economic-party-edit-hosted.log', $log);
    $report['status'] = $report['passed'] ? 'complete' : 'failed';
    file_put_contents($receipt, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
}
echo json_encode($report, JSON_THROW_ON_ERROR);
exit($report['passed'] ? 0 : 1);
