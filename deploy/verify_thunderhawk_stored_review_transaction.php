<?php
/** Rollback-only regression against the isolated Core staging runtime. */
declare(strict_types=1);
const STORED_REVIEW_TRANSACTION_ROOT = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const STORED_REVIEW_TRANSACTION_PRIVATE = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
if (PHP_SAPI !== 'cli' || posix_geteuid() !== 1003 || getcwd() !== STORED_REVIEW_TRANSACTION_ROOT
    || getenv('COREFLUX_ENV') !== 'staging' || ($argv[1] ?? '') !== '--verify-isolated-stored-review-transaction'
    || !preg_match('/^[a-f0-9]{64}$/D', $argv[2] ?? '')) exit(2);
require_once STORED_REVIEW_TRANSACTION_ROOT . '/core/db.php';
require_once STORED_REVIEW_TRANSACTION_ROOT . '/core/integrations/identity.php';
$pdo = getDB();
if (!$pdo || $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'muzqvdvqbx' || !COREFLUX_STAGING
    || integrationInstanceId() !== '9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
    || COREFLUX_INTEGRATION_DELIVERY_TENANTS !== '2002'
    || (int) $pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn() !== 1) exit(2);
$fixture = __DIR__ . '/stored-review-transaction-fixture.php';
if (is_link($fixture) || !hash_equals($argv[2], hash_file('sha256', $fixture))) exit(2);
$install = json_decode(file_get_contents(STORED_REVIEW_TRANSACTION_PRIVATE . '/stored-review-transaction-install-result.json'), true, 64, JSON_THROW_ON_ERROR);
if (($install['passed'] ?? false) !== true) exit(2);
foreach (json_decode('{"core/jobdiva/stored_review.php":"4bca4f875bc7aceeaf8bdd55738ae7c6f7b898a56c6da8d5920c7056c80ca5e8","core/jobdiva/client.php":"7a67fe2d44118cb6451a69fdc657bdcdc997e4b61c9f92f235743e916875f30c","core/jobdiva/sync.php":"fe11e09348b40f1cbd6868b54b0a05a8f36e8d8ee036cca7cb9238a83a1353ca","modules/placements/api/jobdiva_reconciliation.php":"d72767f3bb15047deb518a29da7fe4d670a2cdbb495bd4952443300d5a3493d1"}',true) as $file=>$expected) {
    if (is_link(STORED_REVIEW_TRANSACTION_ROOT.'/'.$file) || hash_file('sha256',STORED_REVIEW_TRANSACTION_ROOT.'/'.$file)!==$expected) exit(2);
}
$lock = fopen(STORED_REVIEW_TRANSACTION_PRIVATE . '/contact-install.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(2);
if ((int) $pdo->query("SELECT COUNT(*) FROM placements WHERE tenant_id=2002 AND external_id IN ('99180001','jd:99180001','99180011','jd:99180011')")->fetchColumn() !== 0) exit(2);
$receipt = STORED_REVIEW_TRANSACTION_PRIVATE . '/stored-review-transaction-hosted-result.json';
if (file_exists($receipt)) exit(2);
function storedReviewTransactionSnapshot(PDO $pdo): array {
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
$storedReviewTransactionBefore = storedReviewTransactionSnapshot($pdo);
ob_start();
try {
    require $fixture;
    $log = ob_get_contents();
    if (!str_contains($log, '20 stored review transaction checks passed.')) throw new RuntimeException('Regression did not finish');
    if ($pdo->inTransaction()) throw new RuntimeException('Fixture transaction still open');
    if (storedReviewTransactionSnapshot($pdo) !== $storedReviewTransactionBefore) throw new RuntimeException('Original database rows changed');
    $report += ['stored_review_transaction_checks_passed' => 20, 'tables_verified' => count($storedReviewTransactionBefore),
        'original_rows_preserved' => array_sum(array_column($storedReviewTransactionBefore, 'rows')), 'synthetic_rows_rolled_back' => true];
    $report['passed'] = true;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $report['failure'] = $e->getMessage();
    $report['original_rows_preserved_after_failure'] = storedReviewTransactionSnapshot($pdo) === $storedReviewTransactionBefore;
} finally {
    $log = ob_get_clean();
    file_put_contents(__DIR__ . '/stored-review-transaction-hosted.log', $log);
    $report['status'] = $report['passed'] ? 'complete' : 'failed';
    file_put_contents($receipt, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
}
echo json_encode($report, JSON_THROW_ON_ERROR);
exit($report['passed'] ? 0 : 1);
