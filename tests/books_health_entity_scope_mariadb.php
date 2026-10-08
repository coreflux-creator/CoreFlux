<?php
/** Rollback-only two-entity Books Health check on disposable local MariaDB. */
declare(strict_types=1);

$database = (string) getenv('DB_NAME');
if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging'
    || !preg_match('/^coreaccounting_blank_test\d+$/D', $database)) {
    fwrite(STDERR, "This test requires an isolated local staging schema.\n");
    exit(2);
}
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/accounting/entity_setup.php';
require_once __DIR__ . '/../core/accounting/books_health_metrics.php';
require_once __DIR__ . '/../modules/accounting/lib/accounting.php';

$pdo = getDB();
if (!$pdo || (string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) {
    throw new RuntimeException('Connected database does not match the isolated test schema.');
}
$mainId = (int) $pdo->query('SELECT id FROM accounting_entities WHERE tenant_id = 1 AND code = "MAIN"')->fetchColumn();
if ($mainId <= 0) throw new RuntimeException('Expected disposable MAIN entity.');
$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};
$tables = ['accounting_entities', 'accounting_bank_accounts', 'accounting_reconciliations',
    'ap_bills', 'treasury_payments', 'treasury_transfers', 'accounting_journal_entries', 'accounting_events'];
$baseline = [];
foreach ($tables as $table) {
    $baseline[$table] = (int) $pdo->query("SELECT COUNT(*) FROM {$table} WHERE tenant_id = 1")->fetchColumn();
}

$pdo->beginTransaction();
try {
    $other = accountingCreateEntityWithCalendar($pdo, 1, [
        'code' => 'BHEALTH', 'legal_name' => 'Rollback-only other entity',
        'country' => 'US', 'base_currency' => 'USD', 'entity_type' => 'llc',
        'accounting_basis' => 'accrual', 'fiscal_year_start_month' => 1,
    ], 2025);
    $otherId = (int) $other['entity_id'];
    $assert(booksHealthResolveEntity($pdo, 1, null) === null
        && booksHealthResolveEntity($pdo, 1, (string) $mainId) === $mainId
        && booksHealthResolveEntity($pdo, 1, (string) $otherId) === $otherId,
        'legal-entity selector accepts only configured tenant entities');
    try {
        booksHealthResolveEntity($pdo, 1, '0');
        throw new RuntimeException('Zero entity was accepted.');
    } catch (InvalidArgumentException $error) {
        $assert(str_contains($error->getMessage(), 'valid legal entity'), 'zero entity is rejected');
    }
    try {
        booksHealthResolveEntity($pdo, 1, '999999999');
        throw new RuntimeException('Unknown entity was accepted.');
    } catch (OutOfBoundsException $error) {
        $assert(str_contains($error->getMessage(), 'not found'), 'unknown entity is rejected');
    }

    $bank = $pdo->prepare(
        'INSERT INTO accounting_bank_accounts (tenant_id, entity_id, name, gl_account_code)
         VALUES (1, :e, :name, :code)'
    );
    $bank->execute(['e' => $mainId, 'name' => 'Main QA bank', 'code' => '1000']);
    $mainBank = (int) $pdo->lastInsertId();
    $bank->execute(['e' => $otherId, 'name' => 'Other QA bank', 'code' => '1010']);
    $otherBank = (int) $pdo->lastInsertId();
    $recon = $pdo->prepare(
        'INSERT INTO accounting_reconciliations
           (tenant_id, bank_account_id, period_end, statement_end_date, statement_balance, gl_balance, status)
         VALUES (1, :bank, :date, :date2, 0, 0, "closed")'
    );
    $recon->execute(['bank' => $mainBank, 'date' => '2025-05-31', 'date2' => '2025-05-31']);
    $recon->execute(['bank' => $otherBank, 'date' => '2025-06-30', 'date2' => '2025-06-30']);
    $assert(booksHealthLastReconciledDate($pdo, 1, $mainId) === '2025-05-31'
        && booksHealthLastReconciledDate($pdo, 1, $otherId) === '2025-06-30'
        && booksHealthLastReconciledDate($pdo, 1, null) === '2025-06-30',
        'last reconciliation follows each entity bank, not another bank in the tenant');

    $bill = $pdo->prepare(
        'INSERT INTO ap_bills
           (tenant_id, entity_id, bill_number, internal_ref, vendor_name, received_at, bill_date, due_date, status)
         VALUES (1, :e, :number, :ref, "Synthetic vendor", "2025-06-01", "2025-06-01", "2025-07-01", :status)'
    );
    foreach ([[$mainId, 'MAIN-REVIEW', 'pending_review'], [$mainId, 'MAIN-PART', 'partially_paid'],
        [$mainId, 'MAIN-PAID', 'paid'], [$otherId, 'OTHER-APPROVED', 'approved']] as [$entity, $ref, $status]) {
        $bill->execute(['e' => $entity, 'number' => $ref, 'ref' => $ref, 'status' => $status]);
    }
    $payment = $pdo->prepare(
        'INSERT INTO treasury_payments
           (tenant_id, entity_id, payment_number, payee_name, amount, payment_date, bank_account_id, status)
         VALUES (1, :e, :number, "Synthetic payee", 1, "2025-06-15", :bank, :status)'
    );
    $payment->execute(['e' => $mainId, 'number' => 'MAIN-DRAFT', 'bank' => $mainBank, 'status' => 'draft']);
    $payment->execute(['e' => $otherId, 'number' => 'OTHER-APPROVED', 'bank' => $otherBank, 'status' => 'approved']);
    $transfer = $pdo->prepare(
        'INSERT INTO treasury_transfers
           (tenant_id, transfer_number, transfer_kind, source_bank_account_id, destination_bank_account_id,
            source_entity_id, destination_entity_id, amount, transfer_date, status)
         VALUES (1, :number, :kind, :source_bank, :destination_bank, :source_e, :destination_e,
                 1, "2025-06-15", "draft")'
    );
    $transfer->execute(['number' => 'CROSS-ENTITY', 'kind' => 'intercompany',
        'source_bank' => $mainBank, 'destination_bank' => $otherBank,
        'source_e' => $mainId, 'destination_e' => $otherId]);
    $transfer->execute(['number' => 'OTHER-INTERNAL', 'kind' => 'internal',
        'source_bank' => $otherBank, 'destination_bank' => $otherBank,
        'source_e' => $otherId, 'destination_e' => $otherId]);
    $mainTasks = booksHealthFinancialTasks($pdo, 1, $mainId);
    $otherTasks = booksHealthFinancialTasks($pdo, 1, $otherId);
    $allTasks = booksHealthFinancialTasks($pdo, 1, null);
    $assert($mainTasks === ['bills_pending' => 2, 'payments_pending' => 1, 'transfers_pending' => 1],
        'main entity includes actual open AP statuses and its own pending money movement');
    $assert($otherTasks === ['bills_pending' => 1, 'payments_pending' => 1, 'transfers_pending' => 2],
        'other entity includes shared intercompany transfer once');
    $assert($allTasks === ['bills_pending' => 3, 'payments_pending' => 2, 'transfers_pending' => 2],
        'tenant-wide overview counts each task once');

    $post = static function (int $entityId, string $amount, string $key): int {
        $entry = accountingPostJe(1, [
            'entity_id' => $entityId, 'posting_date' => '2025-06-20', 'currency' => 'USD',
            'memo' => 'Rollback-only Books Health revenue', 'idempotency_key' => $key,
            'lines' => [
                ['account_code' => '1000', 'debit' => $amount, 'credit' => '0.00'],
                ['account_code' => '4000', 'debit' => '0.00', 'credit' => $amount],
            ],
        ], null, true);
        return (int) $entry['je_id'];
    };
    $mainJe = $post($mainId, '100.00', 'books-health-main-' . bin2hex(random_bytes(6)));
    $otherJe = $post($otherId, '200.00', 'books-health-other-' . bin2hex(random_bytes(6)));
    $month = static function (?int $entityId) use ($pdo): array {
        $rows = booksHealthMonthlyPl($pdo, 1, $entityId, '2025-07-01');
        return array_values(array_filter($rows, static fn (array $row): bool => $row['month'] === '2025-06'))[0];
    };
    $assert($month($mainId)['net'] === 100.0 && $month($otherId)['net'] === 200.0
        && $month(null)['net'] === 300.0,
        'monthly P&L isolates each entity and sums to the tenant-wide result');

    $event = $pdo->prepare(
        'INSERT INTO accounting_events
           (tenant_id, entity_id, event_type, source_module, source_record_id, event_date, payload,
            status, journal_entry_id, posted_at)
         VALUES (1, :e, "qa.books_health", "test", :source, "2025-06-20", "{}", "posted", :je, NOW())'
    );
    $event->execute(['e' => $mainId, 'source' => 'books-health-main', 'je' => $mainJe]);
    $event->execute(['e' => $otherId, 'source' => 'books-health-other', 'je' => $otherJe]);
    $mainEvents = booksHealthRecentEvents($pdo, 1, $mainId);
    $otherEvents = booksHealthRecentEvents($pdo, 1, $otherId);
    $allEvents = booksHealthRecentEvents($pdo, 1, null);
    $assert(count($mainEvents) === 1 && $mainEvents[0]['source_record_id'] === 'books-health-main'
        && count($otherEvents) === 1 && $otherEvents[0]['source_record_id'] === 'books-health-other'
        && count($allEvents) === 2,
        'recent events do not leak another entity into the selected overview');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
foreach ($baseline as $table => $count) {
    $after = (int) $pdo->query("SELECT COUNT(*) FROM {$table} WHERE tenant_id = 1")->fetchColumn();
    $assert($after === $count, "rollback restored {$table}");
}
echo "Books Health entity scope MariaDB: {$checks} checks passed.\n";
