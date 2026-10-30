<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

require_once __DIR__ . '/../core/db.php';

$options = getopt('', ['tenant:', 'line:', 'invoice:', 'mode:', 'expected-due:']);
$tenantId = (int) ($options['tenant'] ?? 0);
$lineId = (int) ($options['line'] ?? 0);
$invoiceId = (int) ($options['invoice'] ?? 0);
$mode = (string) ($options['mode'] ?? '');
if ($tenantId <= 0 || $lineId <= 0 || $invoiceId <= 0 || !in_array($mode, ['prepare', 'verify'], true)
    || ($mode === 'verify' && !isset($options['expected-due']))) {
    fwrite(STDERR, "Usage: php sim/check_bank_receipt_rollback.php --tenant=ID --line=ID --invoice=ID --mode=prepare|verify [--expected-due=AMOUNT]\n");
    exit(2);
}

$pdo = getDB();
$tenant = $pdo->prepare('SELECT is_simulation FROM tenants WHERE id = :id');
$tenant->execute(['id' => $tenantId]);
if ((int) $tenant->fetchColumn() !== 1) {
    fwrite(STDERR, "Refusing to mutate a non-simulation tenant.\n");
    exit(3);
}

$lineStmt = $pdo->prepare(
    'SELECT id, posted_date, match_status, matched_je_id, amount FROM accounting_bank_statement_lines
      WHERE tenant_id = :tenant_id AND id = :id'
);
$lineStmt->execute(['tenant_id' => $tenantId, 'id' => $lineId]);
$line = $lineStmt->fetch(PDO::FETCH_ASSOC);
$invoiceStmt = $pdo->prepare(
    'SELECT id, client_name, client_company_id, currency, amount_due FROM billing_invoices
      WHERE tenant_id = :tenant_id AND id = :id'
);
$invoiceStmt->execute(['tenant_id' => $tenantId, 'id' => $invoiceId]);
$invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);
if (!$line || !$invoice || $line['match_status'] !== 'unmatched' || $line['matched_je_id'] !== null) {
    throw new RuntimeException('Expected an existing unmatched bank line and invoice in this simulation tenant');
}

$groupKey = !empty($invoice['client_company_id'])
    ? 'company:' . (int) $invoice['client_company_id']
    : 'name:' . sha1(strtolower(trim((string) $invoice['client_name'])));
$externalId = 'bank-line:' . $lineId . ':' . substr($groupKey, 0, 80);
$paymentStmt = $pdo->prepare(
    'SELECT id FROM billing_payments WHERE tenant_id = :tenant_id AND external_id = :external_id'
);
$paymentStmt->execute(['tenant_id' => $tenantId, 'external_id' => $externalId]);
$paymentId = $paymentStmt->fetchColumn();

if ($mode === 'prepare') {
    if ($paymentId !== false) throw new RuntimeException('A payment for this test line already exists');
    $insert = $pdo->prepare(
        'INSERT INTO billing_payments
            (tenant_id, client_name, received_at, method, reference, external_id, source_system,
             amount, currency, unallocated_amount, notes)
         VALUES (:tenant_id, :client_name, :received_at, "other", :reference, :external_id, "manual",
                 :amount, :currency, 0, "Simulation rollback sentinel; remove with --mode=verify")'
    );
    $insert->execute([
        'tenant_id' => $tenantId,
        'client_name' => $invoice['client_name'],
        'received_at' => $line['posted_date'],
        'reference' => 'SIM-ROLLBACK-' . $lineId,
        'external_id' => $externalId,
        'amount' => $line['amount'],
        'currency' => $invoice['currency'],
    ]);
    echo json_encode(['prepared' => true, 'line_id' => $lineId, 'invoice_id' => $invoiceId,
        'invoice_due' => (float) $invoice['amount_due']], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}

if ($paymentId === false) throw new RuntimeException('Rollback sentinel payment is missing');
$journal = $pdo->prepare(
    'SELECT COUNT(*) FROM accounting_journal_entries
      WHERE tenant_id = :tenant_id AND source_module = "billing"
        AND source_ref_type = "bank_statement_line" AND source_ref_id = :line_id'
);
$journal->execute(['tenant_id' => $tenantId, 'line_id' => $lineId]);
$journalCount = (int) $journal->fetchColumn();
$allocation = $pdo->prepare('SELECT COUNT(*) FROM billing_payment_allocations WHERE payment_id = :payment_id');
$allocation->execute(['payment_id' => $paymentId]);
$allocationCount = (int) $allocation->fetchColumn();
$checks = [
    'line_still_unmatched' => $line['match_status'] === 'unmatched' && $line['matched_je_id'] === null,
    'receipt_journal_rolled_back' => $journalCount === 0,
    'payment_has_no_allocations' => $allocationCount === 0,
    'invoice_due_unchanged' => abs((float) $invoice['amount_due'] - (float) $options['expected-due']) < 0.005,
];
if (in_array(false, $checks, true)) {
    echo json_encode(['checks' => $checks, 'invoice_due' => (float) $invoice['amount_due']], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}
$delete = $pdo->prepare(
    'DELETE FROM billing_payments
      WHERE tenant_id = :tenant_id AND id = :id AND external_id = :external_id
        AND notes = "Simulation rollback sentinel; remove with --mode=verify"'
);
$delete->execute(['tenant_id' => $tenantId, 'id' => $paymentId, 'external_id' => $externalId]);
$checks['sentinel_removed'] = $delete->rowCount() === 1;
echo json_encode(['checks' => $checks, 'invoice_due' => (float) $invoice['amount_due']], JSON_PRETTY_PRINT) . PHP_EOL;
exit(in_array(false, $checks, true) ? 1 : 0);
