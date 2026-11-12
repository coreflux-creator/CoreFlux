<?php
/** Rollback-only regression against the isolated Core staging runtime. */
declare(strict_types=1);
const SUBCONTRACT_EVIDENCE_ROOT = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const SUBCONTRACT_EVIDENCE_PRIVATE = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
if (PHP_SAPI !== 'cli' || posix_geteuid() !== 1003 || getcwd() !== SUBCONTRACT_EVIDENCE_ROOT
    || getenv('COREFLUX_ENV') !== 'staging' || ($argv[1] ?? '') !== '--verify-isolated-subcontract-evidence'
    || !preg_match('/^[a-f0-9]{64}$/D', $argv[2] ?? '')) exit(2);
require_once SUBCONTRACT_EVIDENCE_ROOT . '/core/db.php';
require_once SUBCONTRACT_EVIDENCE_ROOT . '/core/integrations/identity.php';
$pdo = getDB();
if (!$pdo || $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'muzqvdvqbx' || !COREFLUX_STAGING
    || integrationInstanceId() !== '9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
    || COREFLUX_INTEGRATION_DELIVERY_TENANTS !== '2002'
    || (int) $pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn() !== 1) exit(2);
$fixture = __DIR__ . '/subcontract-evidence-fixture.php';
if (is_link($fixture) || !hash_equals($argv[2], hash_file('sha256', $fixture))) exit(2);
$install = json_decode(file_get_contents(SUBCONTRACT_EVIDENCE_PRIVATE . '/subcontract-evidence-install-result.json'), true, 64, JSON_THROW_ON_ERROR);
if (($install['passed'] ?? false) !== true) exit(2);
foreach (json_decode('{"core/jobdiva/stored_review.php":"4bca4f875bc7aceeaf8bdd55738ae7c6f7b898a56c6da8d5920c7056c80ca5e8","core/jobdiva/client.php":"7a67fe2d44118cb6451a69fdc657bdcdc997e4b61c9f92f235743e916875f30c","core/jobdiva/sync.php":"ee497c86645101a14048598851b110eafdfe6ee23203bfa58d79013764c33ae2","modules/placements/api/jobdiva_reconciliation.php":"44a4698c475248099ef7c964a625900979bae798c1effb4905069197d513b630"}',true) as $file=>$expected) {
    if (is_link(SUBCONTRACT_EVIDENCE_ROOT.'/'.$file) || hash_file('sha256',SUBCONTRACT_EVIDENCE_ROOT.'/'.$file)!==$expected) exit(2);
}
$lock = fopen(SUBCONTRACT_EVIDENCE_PRIVATE . '/contact-install.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(2);
if ((int) $pdo->query("SELECT COUNT(*) FROM placements WHERE tenant_id=2002 AND external_id IN ('99181001','jd:99181001','99181101','jd:99181101')")->fetchColumn() !== 0) exit(2);
$receipt = SUBCONTRACT_EVIDENCE_PRIVATE . '/subcontract-evidence-hosted-result.json';
if (file_exists($receipt)) exit(2);
function subcontractEvidenceSnapshot(PDO $pdo): array {
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
$subcontractEvidenceBefore = subcontractEvidenceSnapshot($pdo);
ob_start();
try {
    require $fixture;
    $log = ob_get_contents();
    if (!str_contains($log, '26 subcontract company evidence checks passed.')) throw new RuntimeException('Regression did not finish');
    if ($pdo->inTransaction()) throw new RuntimeException('Fixture transaction still open');
    if (subcontractEvidenceSnapshot($pdo) !== $subcontractEvidenceBefore) throw new RuntimeException('Original database rows changed');
    $report += ['subcontract_evidence_checks_passed' => 26, 'tables_verified' => count($subcontractEvidenceBefore),
        'original_rows_preserved' => array_sum(array_column($subcontractEvidenceBefore, 'rows')), 'synthetic_rows_rolled_back' => true];
    $report['passed'] = true;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $report['failure'] = $e->getMessage();
    $report['original_rows_preserved_after_failure'] = subcontractEvidenceSnapshot($pdo) === $subcontractEvidenceBefore;
} finally {
    $log = ob_get_clean();
    file_put_contents(__DIR__ . '/subcontract-evidence-hosted.log', $log);
    $report['status'] = $report['passed'] ? 'complete' : 'failed';
    file_put_contents($receipt, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
}
echo json_encode($report, JSON_THROW_ON_ERROR);
exit($report['passed'] ? 0 : 1);
