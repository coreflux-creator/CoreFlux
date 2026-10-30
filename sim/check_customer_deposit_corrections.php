<?php
/** Repeatable source-level deposit correction exercise for synthetic staging only. */
declare(strict_types=1);

if (getenv('COREFLUX_ENV') !== 'staging' || PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This check is restricted to the staging CLI.\n");
    exit(2);
}
require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../modules/billing/lib/posted_receipts.php';
require_once __DIR__ . '/../modules/accounting/lib/bank_rec.php';

$tenantId = 999;
$invoiceId = 7;
$date = '2026-10-02';
$pdo = getDB();
if ($pdo->query('SELECT name FROM tenants WHERE id = 999')->fetchColumn() !== 'CoreFlux CI Simulation') {
    throw new RuntimeException('Synthetic staging tenant is missing.');
}
$_SESSION['tenant_id'] = $tenantId;
$bankStmt = $pdo->prepare(
    'SELECT id FROM accounting_bank_accounts WHERE tenant_id = :t AND name = :name AND status = "active" LIMIT 1'
);
$bankStmt->execute(['t' => $tenantId, 'name' => 'Staging Test Operating']);
$bankId = (int) $bankStmt->fetchColumn();
if ($bankId <= 0) throw new RuntimeException('Synthetic staging bank account is missing.');

$priorDepositStmt = $pdo->prepare(
    'SELECT id, journal_entry_id, voided_at FROM billing_payments
      WHERE tenant_id = :t AND source_system = "manual" AND external_id = :e LIMIT 1'
);
$priorDepositStmt->execute(['t' => $tenantId, 'e' => 'manual-receipt:stage-deposit-correction-1']);
$priorDeposit = $priorDepositStmt->fetch(PDO::FETCH_ASSOC);
if ($priorDeposit) {
    if ((int) $priorDeposit['journal_entry_id'] <= 0 || $priorDeposit['voided_at'] !== null) {
        throw new RuntimeException('The prior synthetic correction deposit is not posted and active.');
    }
    $deposit = ['id' => (int) $priorDeposit['id']];
} else {
    $deposit = billingPostReceivedPayment($tenantId, [
        'bank_account_id' => $bankId, 'client_name' => 'MVP Delivery Test Co',
        'received_at' => $date, 'method' => 'ach', 'reference' => 'STG-DEPOSIT-CORRECTION',
        'amount' => 2, 'currency' => 'USD', 'request_key' => 'stage-deposit-correction-1',
        'hold_unapplied' => true,
    ], null);
}
$paymentId = (int) $deposit['id'];
$invoiceStmt = $pdo->prepare('SELECT amount_due FROM billing_invoices WHERE tenant_id = :t AND id = :id');
$invoiceStmt->execute(['t' => $tenantId, 'id' => $invoiceId]);
$dueBefore = (float) $invoiceStmt->fetchColumn();

$applicationStmt = $pdo->prepare(
    'SELECT id, journal_entry_id, reversed_at, reversal_je_id FROM billing_deposit_applications
      WHERE tenant_id = :t AND payment_id = :p AND request_key = :key LIMIT 1'
);
$applicationStmt->execute(['t' => $tenantId, 'p' => $paymentId, 'key' => 'stage-deposit-correction-apply-1']);
$application = $applicationStmt->fetch(PDO::FETCH_ASSOC);
if (!$application) {
    $created = billingApplyCustomerDeposit($tenantId, $paymentId, [
        'invoice_id' => $invoiceId, 'amount' => 1, 'applied_at' => $date,
        'request_key' => 'stage-deposit-correction-apply-1',
    ], null);
    $applicationStmt->execute(['t' => $tenantId, 'p' => $paymentId, 'key' => 'stage-deposit-correction-apply-1']);
    $application = $applicationStmt->fetch(PDO::FETCH_ASSOC);
    if ((int) $created['application_id'] !== (int) $application['id']) {
        throw new RuntimeException('Created application cannot be located.');
    }
}

$refundStmt = $pdo->prepare(
    'SELECT id, journal_entry_id, reversed_at, reversal_je_id FROM billing_deposit_refunds
      WHERE tenant_id = :t AND payment_id = :p AND request_key = :key LIMIT 1'
);
$refundStmt->execute(['t' => $tenantId, 'p' => $paymentId, 'key' => 'stage-deposit-correction-refund-1']);
$refund = $refundStmt->fetch(PDO::FETCH_ASSOC);
if (!$refund) {
    $created = billingRecordCustomerDepositRefund($tenantId, $paymentId, [
        'bank_account_id' => $bankId, 'amount' => 0.5, 'refunded_at' => $date,
        'reference' => 'STG-DEPOSIT-REFUND-CORRECTION',
        'request_key' => 'stage-deposit-correction-refund-1',
    ], null);
    $refundStmt->execute(['t' => $tenantId, 'p' => $paymentId, 'key' => 'stage-deposit-correction-refund-1']);
    $refund = $refundStmt->fetch(PDO::FETCH_ASSOC);
    if ((int) $created['refund_id'] !== (int) $refund['id']) {
        throw new RuntimeException('Created refund cannot be located.');
    }
}

$lineKey = 'STG-DEP-REFUND-CORRECTION-LINE-1';
$lineStmt = $pdo->prepare(
    'SELECT id, match_status, matched_je_id FROM accounting_bank_statement_lines
      WHERE tenant_id = :t AND bank_account_id = :b AND fitid = :key LIMIT 1'
);
$lineStmt->execute(['t' => $tenantId, 'b' => $bankId, 'key' => $lineKey]);
$line = $lineStmt->fetch(PDO::FETCH_ASSOC);
if (!$line) {
    bankRecImportCsv($tenantId, $bankId,
        "Date,Description,Amount,Transaction ID\n$date,STG-DEPOSIT-REFUND-CORRECTION,-0.50,$lineKey\n", null, null);
    $lineStmt->execute(['t' => $tenantId, 'b' => $bankId, 'key' => $lineKey]);
    $line = $lineStmt->fetch(PDO::FETCH_ASSOC);
}
if (!$line) throw new RuntimeException('Synthetic refund bank line is missing.');
if ($refund['reversed_at'] === null && $line['match_status'] === 'unmatched') {
    bankRecMatchLine($tenantId, (int) $line['id'], (int) $refund['journal_entry_id'], null);
}

if ($application['reversed_at'] === null) {
    billingCorrectCustomerDepositApplication(
        $tenantId, (int) $application['id'], 'Synthetic wrong invoice application', null
    );
}
if ($refund['reversed_at'] === null) {
    billingCorrectCustomerDepositRefund(
        $tenantId, (int) $refund['id'], 'Synthetic erroneous refund record', null
    );
}
$rejects = static function (callable $action): bool {
    try { $action(); return false; }
    catch (RuntimeException $e) { return true; }
};
$secondApplicationCorrectionRejected = $rejects(static function () use ($tenantId, $application): void {
    billingCorrectCustomerDepositApplication($tenantId, (int) $application['id'], 'Duplicate correction', null);
});
$secondRefundCorrectionRejected = $rejects(static function () use ($tenantId, $refund): void {
    billingCorrectCustomerDepositRefund($tenantId, (int) $refund['id'], 'Duplicate correction', null);
});

$applicationStmt->execute(['t' => $tenantId, 'p' => $paymentId, 'key' => 'stage-deposit-correction-apply-1']);
$application = $applicationStmt->fetch(PDO::FETCH_ASSOC);
$refundStmt->execute(['t' => $tenantId, 'p' => $paymentId, 'key' => 'stage-deposit-correction-refund-1']);
$refund = $refundStmt->fetch(PDO::FETCH_ASSOC);
$lineStmt->execute(['t' => $tenantId, 'b' => $bankId, 'key' => $lineKey]);
$line = $lineStmt->fetch(PDO::FETCH_ASSOC);
$paymentStmt = $pdo->prepare('SELECT unallocated_amount FROM billing_payments WHERE tenant_id = :t AND id = :p');
$paymentStmt->execute(['t' => $tenantId, 'p' => $paymentId]);
$remaining = (float) $paymentStmt->fetchColumn();
$invoiceStmt->execute(['t' => $tenantId, 'id' => $invoiceId]);
$dueAfter = (float) $invoiceStmt->fetchColumn();
$jeStmt = $pdo->prepare('SELECT status FROM accounting_journal_entries WHERE tenant_id = :t AND id = :id');
$jeStmt->execute(['t' => $tenantId, 'id' => (int) $application['journal_entry_id']]);
$appJeStatus = $jeStmt->fetchColumn();
$jeStmt->execute(['t' => $tenantId, 'id' => (int) $refund['journal_entry_id']]);
$refundJeStatus = $jeStmt->fetchColumn();

$checks = [
    'deposit_restored' => abs($remaining - 2) < 0.005,
    'invoice_restored' => abs($dueAfter - $dueBefore) < 0.005,
    'application_reversed' => $application['reversed_at'] !== null
        && (int) $application['reversal_je_id'] > 0 && $appJeStatus === 'reversed',
    'refund_reversed' => $refund['reversed_at'] !== null
        && (int) $refund['reversal_je_id'] > 0 && $refundJeStatus === 'reversed',
    'bank_line_reopened' => $line['match_status'] === 'unmatched' && $line['matched_je_id'] === null,
    'duplicate_application_correction_rejected' => $secondApplicationCorrectionRejected,
    'duplicate_refund_correction_rejected' => $secondRefundCorrectionRejected,
];
echo json_encode(['tenant_id' => $tenantId, 'payment_id' => $paymentId,
    'application_id' => (int) $application['id'], 'refund_id' => (int) $refund['id'],
    'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit(in_array(false, $checks, true) ? 1 : 0);
