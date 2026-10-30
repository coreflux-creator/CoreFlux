<?php
/** Rollback-only receipt replay contract on a disposable local schema. */
declare(strict_types=1);

$database = (string) getenv('DB_NAME');
if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging'
    || !preg_match('/^coreaccounting_blank_test\d+$/D', $database)) {
    fwrite(STDERR, "This test requires an isolated local staging schema.\n");
    exit(2);
}
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../modules/accounting/lib/accounting.php';
require_once __DIR__ . '/../modules/billing/lib/bank_receipt_replay.php';

$pdo = getDB();
if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) {
    throw new RuntimeException('Connected database does not match the requested test schema.');
}
$pdo->exec((string) file_get_contents(__DIR__ . '/../modules/billing/migrations/018_bank_receipt_requests.sql'));
$tenantId = (int) $pdo->query('SELECT id FROM tenants ORDER BY id LIMIT 1')->fetchColumn();
$entityId = (int) $pdo->query('SELECT id FROM accounting_entities ORDER BY id LIMIT 1')->fetchColumn();
if ($tenantId <= 0 || $entityId <= 0) throw new RuntimeException('Disposable tenant/entity missing.');
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};
$rejects = static function (callable $action): bool {
    try { $action(); return false; } catch (RuntimeException|InvalidArgumentException $e) { return true; }
};
$before = (int) $pdo->query('SELECT COUNT(*) FROM billing_bank_receipt_requests')->fetchColumn();

$pdo->beginTransaction();
try {
    $cashCode = '18' . random_int(100000, 999999);
    $pdo->prepare(
        'INSERT INTO accounting_accounts
            (tenant_id, code, name, account_type, normal_side, currency, is_postable, active)
         VALUES (:t, :code, "Rollback-only receipt cash", "asset", "debit", "USD", 1, 1)'
    )->execute(['t' => $tenantId, 'code' => $cashCode]);
    $pdo->prepare(
        'INSERT INTO accounting_bank_accounts (tenant_id, entity_id, name, gl_account_code, currency)
         VALUES (:t, :entity_id, "Rollback-only receipt bank", :code, "USD")'
    )->execute(['t' => $tenantId, 'entity_id' => $entityId, 'code' => $cashCode]);
    $bankId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO accounting_bank_statement_lines
            (tenant_id, bank_account_id, posted_date, description, amount, match_status, fitid)
         VALUES (:t, :bank_id, "2025-06-01", "Rollback-only ACH receipt", 10.00, "unmatched", :fitid)'
    )->execute(['t' => $tenantId, 'bank_id' => $bankId, 'fitid' => 'QA-' . bin2hex(random_bytes(8))]);
    $lineId = (int) $pdo->lastInsertId();
    $journalId = (int) accountingPostJe($tenantId, [
        'entity_id' => $entityId,
        'posting_date' => '2025-06-01',
        'currency' => 'USD',
        'memo' => 'Rollback-only receipt',
        'source_module' => 'billing',
        'source_ref_type' => 'bank_statement_line',
        'source_ref_id' => $lineId,
        'idempotency_key' => 'qa-bank-replay-' . bin2hex(random_bytes(8)),
        'lines' => [
            ['account_code' => $cashCode, 'debit' => 10.00, 'credit' => 0],
            ['account_code' => '1100', 'debit' => 0, 'credit' => 10.00,
                'dims' => ['client' => 'name:rollback-only-client']],
        ],
    ], null, true)['je_id'];
    $pdo->prepare(
        'INSERT INTO billing_payments
            (tenant_id, client_name, received_at, amount, currency, unallocated_amount,
             source_system, external_id, journal_entry_id)
         VALUES (:t, "Rollback-only client", "2025-06-01", 10.00, "USD", 0,
                 "manual", :external_id, :je)'
    )->execute(['t' => $tenantId, 'external_id' => 'qa-bank-replay-' . $lineId, 'je' => $journalId]);
    $paymentId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'UPDATE accounting_bank_statement_lines
            SET match_status = "matched", matched_je_id = :je WHERE id = :id'
    )->execute(['je' => $journalId, 'id' => $lineId]);
    $line = [
        'id' => $lineId, 'bank_account_id' => $bankId, 'bank_entity_id' => $entityId,
        'gl_account_code' => $cashCode, 'bank_currency' => 'USD',
        'posted_date' => '2025-06-01', 'amount' => '10.00',
        'description' => 'Rollback-only ACH receipt',
        'match_status' => 'matched', 'matched_je_id' => $journalId,
    ];
    $requestHash = billingBankReceiptRequestHash('match_invoice', $line, ['invoice_id' => 19]);
    $response = [
        'ok' => true, 'line_id' => $lineId, 'invoice_id' => 19,
        'payment_id' => $paymentId, 'matched_je_id' => $journalId,
        'invoice_status' => 'paid',
    ];
    billingRecordBankReceiptRequest($pdo, $tenantId, $lineId, 0,
        'match_invoice', $requestHash, $response);
    $replay = billingBankReceiptReplay($pdo, $tenantId, $line, 'match_invoice', $requestHash);
    $check($replay !== null && $replay['idempotent_replay'] === true
        && $replay['payment_id'] === $paymentId, 'identical request returns original payment');
    $check($rejects(static fn() => billingBankReceiptReplay($pdo, $tenantId, $line,
        'match_invoice', billingBankReceiptRequestHash('match_invoice', $line, ['invoice_id' => 20]))),
        'different invoice cannot reuse a matched line');
    $check($rejects(static fn() => billingBankReceiptReplay($pdo, $tenantId, $line,
        'split_match_invoices', $requestHash)), 'a different receipt action cannot reuse the match');
    $check(billingBankReceiptReplay($pdo, $tenantId + 1, $line,
        'match_invoice', $requestHash) === null, 'another tenant cannot read the result');
    $wrongLine = $line;
    $wrongLine['matched_je_id'] = $journalId + 1;
    $check($rejects(static fn() => billingBankReceiptReplay($pdo, $tenantId, $wrongLine,
        'match_invoice', $requestHash)), 'journal mismatch refuses replay');
    $unmatchedLine = $line;
    $unmatchedLine['match_status'] = 'unmatched';
    $check(billingBankReceiptReplay($pdo, $tenantId, $unmatchedLine,
        'match_invoice', $requestHash) === null, 'a corrected line never replays an old match');
    $pdo->prepare('UPDATE billing_payments SET voided_at = NOW() WHERE id = :id')
        ->execute(['id' => $paymentId]);
    $check($rejects(static fn() => billingBankReceiptReplay($pdo, $tenantId, $line,
        'match_invoice', $requestHash)), 'voided payment refuses replay');
    $pdo->prepare('UPDATE billing_payments SET voided_at = NULL WHERE id = :id')
        ->execute(['id' => $paymentId]);
    $pdo->prepare('UPDATE accounting_journal_entries SET status = "reversed" WHERE id = :id')
        ->execute(['id' => $journalId]);
    $check($rejects(static fn() => billingBankReceiptReplay($pdo, $tenantId, $line,
        'match_invoice', $requestHash)), 'reversed journal refuses replay');
    $pdo->prepare('UPDATE accounting_journal_entries SET status = "posted" WHERE id = :id')
        ->execute(['id' => $journalId]);

    $splitBody = [
        'allocations' => [['invoice_id' => 20, 'amount' => 3.00], ['invoice_id' => 19, 'amount' => 2.00]],
        'account_splits' => [
            ['account_id' => 8, 'amount' => 3, 'memo' => 'Other'],
            ['account_id' => 9, 'amount' => 2, 'memo' => 'Fee'],
        ],
    ];
    $sameSplit = [
        'allocations' => [['invoice_id' => 19, 'amount' => 2], ['invoice_id' => 20, 'amount' => 3.0]],
        'account_splits' => [
            ['account_id' => 9, 'amount' => 2.00, 'memo' => 'Fee'],
            ['account_id' => 8, 'amount' => 3.00, 'memo' => 'Other'],
        ],
    ];
    $check(billingBankReceiptRequestHash('split_match_invoices', $line, $splitBody)
        === billingBankReceiptRequestHash('split_match_invoices', $line, $sameSplit),
        'equivalent split order and amount formatting have one intent');
    $sameSplit['account_splits'][1]['memo'] = 'Changed';
    $check(billingBankReceiptRequestHash('split_match_invoices', $line, $splitBody)
        !== billingBankReceiptRequestHash('split_match_invoices', $line, $sameSplit),
        'changed GL split memo has a different intent');
    $check($rejects(static fn() => billingBankReceiptRequestHash('split_match_invoices', $line,
        ['allocations' => [['invoice_id' => 19, 'amount' => 'not-a-number']]])),
        'malformed invoice amounts are rejected before posting');
    $check($rejects(static fn() => billingRecordBankReceiptRequest($pdo, $tenantId,
        $lineId, 0, 'match_invoice', $requestHash, $response)),
        'one bank-line attempt has only one saved result');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
$check((int) $pdo->query('SELECT COUNT(*) FROM billing_bank_receipt_requests')->fetchColumn() === $before,
    'all synthetic receipt requests rolled back');
$check($rejects(static fn() => billingRecordBankReceiptRequest($pdo, $tenantId, 1,
    0, 'match_invoice', str_repeat('0', 64), ['matched_je_id' => 1])),
    'receipt request history cannot be saved outside a posting transaction');
echo "Passed: {$checks}\n";
