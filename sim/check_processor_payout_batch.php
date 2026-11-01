<?php
/** Replayable two-capture/net-payout check on the isolated synthetic tenant. */
declare(strict_types=1);

if (getenv('COREFLUX_ENV') !== 'staging' || PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This check is restricted to the staging CLI.\n");
    exit(2);
}
require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/qbo/payments_client.php';
require_once __DIR__ . '/../modules/billing/lib/processor_payouts.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
});

$pdo = getDB();
$tenantId = 999;
$invoiceId = 7;
if ($pdo->query('SELECT name FROM tenants WHERE id = 999')->fetchColumn() !== 'CoreFlux CI Simulation') {
    throw new RuntimeException('Synthetic staging tenant is missing.');
}
$_SESSION['tenant_id'] = $tenantId;
$charges = [
    ['id' => 'STG-COREACCT-QBO-BATCH-1', 'amount' => '2.00', 'currency' => 'USD', 'status' => 'CAPTURED'],
    ['id' => 'STG-COREACCT-QBO-BATCH-2', 'amount' => '3.00', 'currency' => 'USD', 'status' => 'CAPTURED'],
];
$invoiceStmt = $pdo->prepare(
    'SELECT i.amount_due, i.amount_paid, je.status AS journal_status
       FROM billing_invoices i JOIN accounting_journal_entries je
         ON je.tenant_id = i.tenant_id AND je.id = i.journal_entry_id
      WHERE i.tenant_id = :tenant_id AND i.id = :invoice_id'
);
$invoiceStmt->execute(['tenant_id' => $tenantId, 'invoice_id' => $invoiceId]);
$invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);
if (!$invoice || $invoice['journal_status'] !== 'posted') {
    throw new RuntimeException('Synthetic invoice is missing or not posted.');
}
$existingStmt = $pdo->prepare(
    'SELECT id FROM qbo_payment_charges WHERE tenant_id = :tenant_id AND qbo_charge_id = :charge_id'
);
$neededCents = 0;
foreach ($charges as $charge) {
    $existingStmt->execute(['tenant_id' => $tenantId, 'charge_id' => $charge['id']]);
    if (!$existingStmt->fetchColumn()) $neededCents += (int) round((float) $charge['amount'] * 100);
}
if ((int) round((float) $invoice['amount_due'] * 100) < $neededCents) {
    throw new RuntimeException('Synthetic invoice lacks room for both new captures.');
}

$paymentIds = [];
foreach ($charges as $charge) {
    qboRecordChargeShadow($tenantId, $charge, [
        'charge_type' => 'card', 'coreflux_invoice_id' => $invoiceId,
        'context_token' => strtolower($charge['id']),
    ]);
    $applied = qboApplyCapturedPayment($tenantId, $charge, ['coreflux_invoice_id' => $invoiceId]);
    if (empty($applied['payment_id']) || empty($applied['applied'])) {
        throw new RuntimeException('Synthetic capture was not applied to its invoice.');
    }
    $paymentIds[] = (int) $applied['payment_id'];
}
sort($paymentIds, SORT_NUMERIC);

$bankStmt = $pdo->prepare(
    'SELECT id, gl_account_code FROM accounting_bank_accounts
      WHERE tenant_id = :tenant_id AND name = :name AND status = "active" LIMIT 1'
);
$bankStmt->execute(['tenant_id' => $tenantId, 'name' => 'Staging Test Operating']);
$bank = $bankStmt->fetch(PDO::FETCH_ASSOC);
if (!$bank) throw new RuntimeException('Synthetic bank account is missing.');
$feeStmt = $pdo->prepare(
    'SELECT id, code FROM accounting_accounts
      WHERE tenant_id = :tenant_id AND account_type = "expense" AND active = 1 AND is_postable = 1
      ORDER BY id LIMIT 1'
);
$feeStmt->execute(['tenant_id' => $tenantId]);
$feeAccount = $feeStmt->fetch(PDO::FETCH_ASSOC);
if (!$feeAccount) throw new RuntimeException('Synthetic fee account is missing.');
$fitid = 'stg-processor-payout-qbo-batch-1';
$lineStmt = $pdo->prepare(
    'SELECT id, match_status, matched_je_id FROM accounting_bank_statement_lines
      WHERE tenant_id = :tenant_id AND bank_account_id = :bank_account_id AND fitid = :fitid LIMIT 1'
);
$lineStmt->execute(['tenant_id' => $tenantId, 'bank_account_id' => (int) $bank['id'], 'fitid' => $fitid]);
$line = $lineStmt->fetch(PDO::FETCH_ASSOC);
if (!$line) {
    $today = date('Y-m-d');
    bankRecImportCsv($tenantId, (int) $bank['id'],
        "Date,Description,Amount,Transaction ID\n{$today},Synthetic two-capture QBO payout,4.85,{$fitid}\n",
        null, null);
    $lineStmt->execute(['tenant_id' => $tenantId, 'bank_account_id' => (int) $bank['id'], 'fitid' => $fitid]);
    $line = $lineStmt->fetch(PDO::FETCH_ASSOC);
}
if (!$line) throw new RuntimeException('Synthetic payout bank line was not imported.');
$lineId = (int) $line['id'];
$invoiceStmt->execute(['tenant_id' => $tenantId, 'invoice_id' => $invoiceId]);
$paidBeforePayout = (float) $invoiceStmt->fetch(PDO::FETCH_ASSOC)['amount_paid'];

$checks = [];
if ($line['match_status'] === 'unmatched') {
    $candidates = billingProcessorPayoutCandidates($tenantId, $lineId);
    $offeredIds = array_map('intval', array_column($candidates['payments'], 'payment_id'));
    $checks['both captures available'] = !array_diff($paymentIds, $offeredIds);
    $posted = billingSettleProcessorPayout($tenantId, $lineId, $paymentIds, 0.15,
        (int) $feeAccount['id'], null);
    $checks['first settlement posted'] = empty($posted['idempotent_replay']);
} else {
    $posted = billingSettleProcessorPayout($tenantId, $lineId, $paymentIds, 0.15,
        (int) $feeAccount['id'], null);
    $checks['settlement replayed'] = !empty($posted['idempotent_replay']);
}
$replay = billingSettleProcessorPayout($tenantId, $lineId, $paymentIds, 0.15,
    (int) $feeAccount['id'], null);
$checks['retry is exact'] = !empty($replay['idempotent_replay'])
    && (int) $replay['payout_id'] === (int) $posted['payout_id'];
$linkedStmt = $pdo->prepare(
    'SELECT payment_id FROM billing_processor_payout_payments
      WHERE tenant_id = :tenant_id AND payout_id = :payout_id ORDER BY payment_id'
);
$linkedStmt->execute(['tenant_id' => $tenantId, 'payout_id' => (int) $posted['payout_id']]);
$checks['both captures linked once'] = array_map('intval', $linkedStmt->fetchAll(PDO::FETCH_COLUMN)) === $paymentIds;
$journalStmt = $pdo->prepare(
    'SELECT a.code, ROUND(SUM(l.debit), 2) AS debit, ROUND(SUM(l.credit), 2) AS credit
       FROM accounting_journal_entry_lines l
       JOIN accounting_accounts a ON a.tenant_id = l.tenant_id AND a.id = l.account_id
      WHERE l.tenant_id = :tenant_id AND l.je_id = :journal_id GROUP BY a.code'
);
$journalStmt->execute(['tenant_id' => $tenantId, 'journal_id' => (int) $posted['journal_entry_id']]);
$journalLines = [];
foreach ($journalStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $journalLines[$row['code']] = $row;
$checks['bank gets net'] = abs((float) ($journalLines[$bank['gl_account_code']]['debit'] ?? 0) - 4.85) < 0.005;
$checks['fee expensed'] = abs((float) ($journalLines[$feeAccount['code']]['debit'] ?? 0) - 0.15) < 0.005;
$checks['clearing loses gross'] = abs((float) ($journalLines['1010']['credit'] ?? 0) - 5.0) < 0.005;
$invoiceStmt->execute(['tenant_id' => $tenantId, 'invoice_id' => $invoiceId]);
$checks['settlement does not reapply invoice'] = abs((float) $invoiceStmt->fetch(PDO::FETCH_ASSOC)['amount_paid'] - $paidBeforePayout) < 0.005;
$lineStmt->execute(['tenant_id' => $tenantId, 'bank_account_id' => (int) $bank['id'], 'fitid' => $fitid]);
$checks['bank line matched'] = $lineStmt->fetch(PDO::FETCH_ASSOC)['match_status'] === 'matched';
echo json_encode(['tenant_id' => $tenantId, 'payout_id' => (int) $posted['payout_id'],
    'checks' => $checks, 'passed' => count(array_filter($checks)), 'total' => count($checks)],
    JSON_PRETTY_PRINT) . "\n";
exit(count(array_filter($checks)) === count($checks) ? 0 : 1);
