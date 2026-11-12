<?php
/** Rollback-only regression against the isolated Core staging runtime. */
declare(strict_types=1);
const CORPORATE_IDENTITY_ROOT = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const CORPORATE_IDENTITY_PRIVATE = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
if (PHP_SAPI !== 'cli' || posix_geteuid() !== 1003 || getcwd() !== CORPORATE_IDENTITY_ROOT
    || getenv('COREFLUX_ENV') !== 'staging' || ($argv[1] ?? '') !== '--verify-isolated-corporate-identity'
    || !preg_match('/^[a-f0-9]{64}$/D', $argv[2] ?? '')) exit(2);
require_once CORPORATE_IDENTITY_ROOT . '/core/db.php';
require_once CORPORATE_IDENTITY_ROOT . '/core/integrations/identity.php';
$pdo = getDB();
if (!$pdo || $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'muzqvdvqbx' || !COREFLUX_STAGING
    || integrationInstanceId() !== '9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
    || COREFLUX_INTEGRATION_DELIVERY_TENANTS !== '2002'
    || (int) $pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn() !== 1) exit(2);
$fixture = __DIR__ . '/corporate-identity-fixture.php';
if (is_link($fixture) || !hash_equals($argv[2], hash_file('sha256', $fixture))) exit(2);
$install = json_decode(file_get_contents(CORPORATE_IDENTITY_PRIVATE . '/corporate-identity-install-result.json'), true, 64, JSON_THROW_ON_ERROR);
if (($install['passed'] ?? false) !== true) exit(2);
foreach (json_decode('{"core/jobdiva/contract_projection.php":"d615ce7e9b507e46592d8bfe1eaeef64e5f1a81e817c920e8395ebee69de9cef","core/jobdiva/sync.php":"e75bc552e4b0796b29d1776b7633b0ca0cedc43daf2bbc760c9b87655ddeeb09","modules/placements/lib/commercial_ownership.php":"d56eafc68ea15a69b080acac07b69e06c49b9245651e5f0fced077dc1fe6d556","modules/placements/lib/economics_participants.php":"e52e799d2434fab9413872fbfdc9bca380764d8d5c98da16999824b9825dcd76","modules/placements/lib/ownership.php":"37d797f2cf7a045230d03809634ecd262b7e7b53005254dd7c08ac2950ca8a76","modules/placements/lib/economics.php":"1b713f5a3d3805ff63b6f243033455e990217fc65e84ab0af8e6c376741458ae","modules/placements/api/economics.php":"10195ad21b30f8a7fcb566750f34e6d59011d9039201055934de7e823e345fa3"}',true) as $file=>$expected) {
    if (is_link(CORPORATE_IDENTITY_ROOT.'/'.$file) || hash_file('sha256',CORPORATE_IDENTITY_ROOT.'/'.$file)!==$expected) exit(2);
}
$lock = fopen(CORPORATE_IDENTITY_PRIVATE . '/contact-install.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(2);
if ((int) $pdo->query("SELECT COUNT(*) FROM placements WHERE tenant_id=2002 AND external_id IN ('99183001','jd:99183001')")->fetchColumn() !== 0) exit(2);
$receipt = CORPORATE_IDENTITY_PRIVATE . '/corporate-identity-hosted-result.json';
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
    if (!str_contains($log, '37 corporate identity ownership checks passed.')) throw new RuntimeException('Regression did not finish');
    if ($pdo->inTransaction()) throw new RuntimeException('Fixture transaction still open');
    if (corporateIdentitySnapshot($pdo) !== $corporateIdentityBefore) throw new RuntimeException('Original database rows changed');
    $report += ['corporate_identity_checks_passed' => 37, 'tables_verified' => count($corporateIdentityBefore),
        'original_rows_preserved' => array_sum(array_column($corporateIdentityBefore, 'rows')), 'synthetic_rows_rolled_back' => true];
    $report['passed'] = true;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $report['failure'] = $e->getMessage();
    $report['original_rows_preserved_after_failure'] = corporateIdentitySnapshot($pdo) === $corporateIdentityBefore;
} finally {
    $log = ob_get_clean();
    file_put_contents(__DIR__ . '/corporate-identity-hosted.log', $log);
    $report['status'] = $report['passed'] ? 'complete' : 'failed';
    file_put_contents($receipt, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
}
echo json_encode($report, JSON_THROW_ON_ERROR);
exit($report['passed'] ? 0 : 1);
