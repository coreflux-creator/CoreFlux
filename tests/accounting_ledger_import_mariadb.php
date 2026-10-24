<?php
/** Rollback-only acceptance check against an isolated, provisioned local schema. */
declare(strict_types=1);

$database = (string) getenv('DB_NAME');
if (getenv('COREFLUX_ENV') !== 'staging'
    || !preg_match('/^coreaccounting_blank_test\d+$/D', $database)) {
    fwrite(STDERR, "This test requires an isolated local staging schema.\n");
    exit(2);
}
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../modules/accounting/lib/ledger_import.php';

$pdo = getDB();
if (!$pdo || (string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) {
    throw new RuntimeException('Connected database does not match the isolated test schema.');
}
$entity = $pdo->query(
    'SELECT e.tenant_id, e.id AS entity_id, e.base_currency
       FROM accounting_entities e JOIN tenants t ON t.id = e.tenant_id
      WHERE t.is_simulation = 0 AND e.active = 1
      ORDER BY e.id LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
if (!$entity) throw new RuntimeException('No provisioned entity in the test schema.');
$tenantId = (int) $entity['tenant_id'];
$entityId = (int) $entity['entity_id'];
$period = $pdo->prepare(
    'SELECT start_date FROM accounting_periods
      WHERE tenant_id = :t AND entity_id = :e AND status = "open"
      ORDER BY start_date LIMIT 1'
);
$period->execute(['t' => $tenantId, 'e' => $entityId]);
$postingDate = (string) $period->fetchColumn();
if ($postingDate === '') throw new RuntimeException('No open test period.');
$batchRef = 'ROLLBACK-IMPORT-' . bin2hex(random_bytes(8));
$journal = [
    'entity_id' => $entityId,
    'posting_date' => $postingDate,
    'memo' => 'Rollback-only CSV import acceptance',
    'lines' => [
        ['account_code' => '6990', 'debit' => '1.23', 'credit' => '', 'memo' => 'Expense', 'dims' => []],
        ['account_code' => '3000', 'debit' => '', 'credit' => '1.23', 'memo' => 'Equity', 'dims' => []],
    ],
];
$beforeJournals = (int) $pdo->query('SELECT COUNT(*) FROM accounting_journal_entries')->fetchColumn();
$checks = 0;
$assert = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    $checks++;
};

$review = accountingImportReviewJe($tenantId, $batchRef, $journal);
$assert($review['errors'] === [], 'real-schema preview accepts a balanced journal');
$intent = $review['journal'];
$assert($intent['currency'] === $entity['base_currency'], 'currency follows the legal entity');
$pdo->beginTransaction();
try {
    $posted = accountingPostJe($tenantId, $intent, null, true);
    $assert($posted['status'] === 'posted' && !$posted['idempotent_replay'],
        'canonical ledger posts the first request');
    accountingImportAssertJeReplay($tenantId, $intent);
    $replay = accountingPostJe($tenantId, $intent, null, true);
    $assert($replay['idempotent_replay'] && $replay['je_id'] === $posted['je_id'],
        'exact retry returns the same journal');
    $changed = $intent;
    $changed['lines'][0]['debit'] = '1.24';
    $changed['lines'][1]['credit'] = '1.24';
    try {
        accountingImportAssertJeReplay($tenantId, $changed);
        throw new RuntimeException('Changed replay was not refused.');
    } catch (RuntimeException $error) {
        $assert(str_contains($error->getMessage(), 'different journal lines'),
            'changed but balanced retry is refused');
    }
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
$afterJournals = (int) $pdo->query('SELECT COUNT(*) FROM accounting_journal_entries')->fetchColumn();
$assert($afterJournals === $beforeJournals, 'test journal was fully rolled back');
$lookup = $pdo->prepare(
    'SELECT COUNT(*) FROM accounting_posting_idempotency
      WHERE tenant_id = :t AND idempotency_key = :key'
);
$lookup->execute(['t' => $tenantId, 'key' => $intent['idempotency_key']]);
$assert((int) $lookup->fetchColumn() === 0, 'idempotency mapping was fully rolled back');

$pdo->beginTransaction();
try {
    $pdo->prepare(
        'INSERT INTO accounting_periods
            (tenant_id, entity_id, period_number, start_date, end_date, status)
         VALUES (:t, :e, 5, "2097-05-15", "2097-05-31", "open")'
    )->execute(['t' => $tenantId, 'e' => $entityId]);
    $partialId = (int) $pdo->lastInsertId();
    $inside = accountingResolvePeriod($tenantId, $entityId, '2097-05-20');
    $assert((int) $inside['id'] === $partialId, 'defined partial period resolves without a duplicate');
    try {
        accountingResolvePeriod($tenantId, $entityId, '2097-05-01');
        throw new RuntimeException('Overlapping auto-created period was accepted.');
    } catch (RuntimeException $error) {
        $assert(str_contains($error->getMessage(), 'would overlap'),
            'automatic month creation refuses overlap with a defined period');
    }
    $fresh = accountingResolvePeriod($tenantId, $entityId, '2097-06-09');
    $assert($fresh['start_date'] === '2097-06-01' && $fresh['end_date'] === '2097-06-30',
        'a non-overlapping month still auto-creates');

    foreach (['future' => '2097-07', 'locked' => '2097-08'] as $status => $month) {
        $pdo->prepare(
            'INSERT INTO accounting_periods
                (tenant_id, entity_id, period_number, start_date, end_date, status)
             VALUES (:t, :e, :n, :start_date, :end_date, :status)'
        )->execute([
            't' => $tenantId, 'e' => $entityId, 'n' => (int) substr($month, -2),
            'start_date' => $month . '-01', 'end_date' => $month . '-31', 'status' => $status,
        ]);
        $blocked = $journal;
        $blocked['posting_date'] = $month . '-15';
        try {
            accountingPostJe($tenantId, $blocked, null, true);
            throw new RuntimeException("{$status} period accepted a posting.");
        } catch (RuntimeException $error) {
            $assert(str_contains($error->getMessage(), "is {$status}; cannot post"),
                "{$status} period refuses a journal post");
        }
        $validation = accountingValidateJe($tenantId, $blocked);
        $assert(!$validation['ok'] && str_contains(implode(' ', $validation['errors']), "is {$status}; cannot post"),
            "{$status} period fails draft validation");
    }

    $previewOnly = $journal;
    $previewOnly['posting_date'] = '2097-09-15';
    $validation = accountingValidateJe($tenantId, $previewOnly);
    $assert($validation['ok'] && $validation['period']['id'] === null,
        'draft validation previews a missing monthly period');
    $periodCount = $pdo->prepare(
        'SELECT COUNT(*) FROM accounting_periods WHERE tenant_id = :t AND entity_id = :e
          AND start_date = :start_date'
    );
    $periodCount->execute(['t' => $tenantId, 'e' => $entityId, 'start_date' => '2097-09-01']);
    $assert((int) $periodCount->fetchColumn() === 0, 'draft validation did not create a period');
    $invalid = $previewOnly;
    $invalid['lines'][0]['account_code'] = 'NO-SUCH-ACCOUNT';
    try {
        accountingPostJe($tenantId, $invalid, null, true);
        throw new RuntimeException('Invalid journal was posted.');
    } catch (RuntimeException $error) {
        $assert(str_contains($error->getMessage(), 'account not found'),
            'failed journal is rejected before period creation');
    }
    $periodCount->execute(['t' => $tenantId, 'e' => $entityId, 'start_date' => '2097-09-01']);
    $assert((int) $periodCount->fetchColumn() === 0, 'failed journal did not create a period');
    $valid = $journal;
    $valid['posting_date'] = '2097-10-15';
    $posted = accountingPostJe($tenantId, $valid, null, true);
    $periodCount->execute(['t' => $tenantId, 'e' => $entityId, 'start_date' => '2097-10-01']);
    $assert($posted['status'] === 'posted' && (int) $periodCount->fetchColumn() === 1,
        'successful journal creates its missing period in the posting transaction');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
$periodCount->execute(['t' => $tenantId, 'e' => $entityId, 'start_date' => '2097-10-01']);
$assert((int) $periodCount->fetchColumn() === 0,
    'rolling back the journal also rolls back its new period');
echo "Accounting MariaDB import: {$checks} checks passed.\n";
