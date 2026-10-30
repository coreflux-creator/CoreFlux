<?php
/** Repeatable real-MySQL customer-deposit exercise for the synthetic staging tenant. */
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
$receiptDate = '2026-10-02';
$pdo = getDB();
if ($pdo->query('SELECT name FROM tenants WHERE id = 999')->fetchColumn() !== 'CoreFlux CI Simulation') {
    throw new RuntimeException('Synthetic staging tenant is missing.');
}
$_SESSION['tenant_id'] = $tenantId;
$bankStmt = $pdo->prepare(
    'SELECT id, gl_account_code FROM accounting_bank_accounts
      WHERE tenant_id = :t AND name = :name AND status = "active" LIMIT 1'
);
$bankStmt->execute(['t' => $tenantId, 'name' => 'Staging Test Operating']);
$bank = $bankStmt->fetch(PDO::FETCH_ASSOC);
if (!$bank) throw new RuntimeException('Synthetic staging bank account is missing.');

$replayRequest = [
    'bank_account_id' => (int) $bank['id'], 'client_name' => 'MVP Delivery Test Co',
    'received_at' => $receiptDate, 'method' => 'ach', 'reference' => 'STG-RECEIPT-REPLAY',
    'amount' => 2, 'currency' => 'USD', 'request_key' => 'stage-receipt-hash-1',
    'hold_unapplied' => true,
];
$freshReceipt = billingPostReceivedPayment($tenantId, $replayRequest, null);
$exactReceiptReplay = billingPostReceivedPayment($tenantId, $replayRequest, null);
$changedReceiptRejected = false;
try {
    billingPostReceivedPayment($tenantId, array_replace($replayRequest, ['hold_unapplied' => false]), null);
} catch (RuntimeException $e) {
    $changedReceiptRejected = true;
}

$priorDepositStmt = $pdo->prepare(
    'SELECT id, journal_entry_id, voided_at FROM billing_payments
      WHERE tenant_id = :t AND source_system = "manual" AND external_id = :e LIMIT 1'
);
$priorDepositStmt->execute(['t' => $tenantId, 'e' => 'manual-receipt:stage-customer-deposit-1']);
$priorDeposit = $priorDepositStmt->fetch(PDO::FETCH_ASSOC);
if ($priorDeposit) {
    if ((int) $priorDeposit['journal_entry_id'] <= 0 || $priorDeposit['voided_at'] !== null) {
        throw new RuntimeException('The prior synthetic deposit is not posted and active.');
    }
    $deposit = ['id' => (int) $priorDeposit['id']];
} else {
    $deposit = billingPostReceivedPayment($tenantId, [
        'bank_account_id' => (int) $bank['id'], 'client_name' => 'MVP Delivery Test Co',
        'received_at' => $receiptDate, 'method' => 'ach', 'reference' => 'STG-DEPOSIT-5',
        'amount' => 5, 'currency' => 'USD', 'request_key' => 'stage-customer-deposit-1',
        'hold_unapplied' => true,
    ], null);
}
$paymentId = (int) $deposit['id'];
$application = billingApplyCustomerDeposit($tenantId, $paymentId, [
    'invoice_id' => $invoiceId, 'amount' => 3, 'applied_at' => $receiptDate,
    'request_key' => 'stage-deposit-apply-1',
], null);
$replay = billingApplyCustomerDeposit($tenantId, $paymentId, [
    'invoice_id' => $invoiceId, 'amount' => 3, 'applied_at' => $receiptDate,
    'request_key' => 'stage-deposit-apply-1',
], null);
$rejects = static function (array $request) use ($tenantId, $paymentId): bool {
    try {
        billingApplyCustomerDeposit($tenantId, $paymentId, $request, null);
        return false;
    } catch (RuntimeException $e) {
        return true;
    }
};
$overApplicationRejected = $rejects([
    'invoice_id' => $invoiceId, 'amount' => 3, 'applied_at' => $receiptDate,
    'request_key' => 'stage-deposit-too-much-1',
]);
$earlyApplicationRejected = $rejects([
    'invoice_id' => $invoiceId, 'amount' => 1, 'applied_at' => '2026-10-01',
    'request_key' => 'stage-deposit-too-early-1',
]);
$changedReplayRejected = $rejects([
    'invoice_id' => $invoiceId, 'amount' => 2, 'applied_at' => $receiptDate,
    'request_key' => 'stage-deposit-apply-1',
]);
$otherInvoiceStmt = $pdo->prepare(
    'SELECT i.id FROM billing_invoices i
       JOIN accounting_journal_entries je ON je.tenant_id = i.tenant_id AND je.id = i.journal_entry_id
      WHERE i.tenant_id = :t AND i.id <> :id AND i.client_name <> :client
        AND i.amount_due >= 1 AND je.status = "posted" LIMIT 1'
);
$otherInvoiceStmt->execute(['t' => $tenantId, 'id' => $invoiceId, 'client' => 'MVP Delivery Test Co']);
$otherInvoiceId = (int) $otherInvoiceStmt->fetchColumn();
$otherClientRejected = $otherInvoiceId > 0 && $rejects([
    'invoice_id' => $otherInvoiceId, 'amount' => 1, 'applied_at' => $receiptDate,
    'request_key' => 'stage-deposit-other-client-1',
]);

$paymentStmt = $pdo->prepare('SELECT unallocated_amount, journal_entry_id FROM billing_payments WHERE tenant_id = :t AND id = :p');
$paymentStmt->execute(['t' => $tenantId, 'p' => $paymentId]);
$payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
$appStmt = $pdo->prepare(
    'SELECT a.id, a.journal_entry_id, alloc.application_je_id, alloc.amount_applied
       FROM billing_deposit_applications a
       JOIN billing_payment_allocations alloc ON alloc.id = a.allocation_id
      WHERE a.tenant_id = :t AND a.payment_id = :p'
);
$appStmt->execute(['t' => $tenantId, 'p' => $paymentId]);
$apps = $appStmt->fetchAll(PDO::FETCH_ASSOC);
$journalLines = static function (int $jeId) use ($pdo, $tenantId): array {
    $stmt = $pdo->prepare(
        'SELECT a.code, ROUND(SUM(l.debit), 2) AS debit, ROUND(SUM(l.credit), 2) AS credit
           FROM accounting_journal_entry_lines l
           JOIN accounting_accounts a ON a.tenant_id = l.tenant_id AND a.id = l.account_id
          WHERE l.tenant_id = :t AND l.je_id = :je GROUP BY a.code'
    );
    $stmt->execute(['t' => $tenantId, 'je' => $jeId]);
    $lines = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $line) $lines[$line['code']] = $line;
    return $lines;
};
$receiptLines = $journalLines((int) $payment['journal_entry_id']);
$applicationLines = $journalLines((int) $application['journal_entry_id']);
$refundRequest = [
    'bank_account_id' => (int) $bank['id'], 'amount' => 1,
    'refunded_at' => $receiptDate, 'reference' => 'STG-REFUND-1',
    'request_key' => 'stage-deposit-refund-1',
];
$refund = billingRecordCustomerDepositRefund($tenantId, $paymentId, $refundRequest, null);
$refundReplay = billingRecordCustomerDepositRefund($tenantId, $paymentId, $refundRequest, null);
$refundLines = $journalLines((int) $refund['journal_entry_id']);
$refundRejects = static function (array $request) use ($tenantId, $paymentId): bool {
    try {
        billingRecordCustomerDepositRefund($tenantId, $paymentId, $request, null);
        return false;
    } catch (RuntimeException $e) {
        return true;
    }
};
$overRefundRejected = $refundRejects(array_replace($refundRequest, [
    'amount' => 999, 'request_key' => 'stage-deposit-over-refund-1',
]));
$earlyRefundRejected = $refundRejects(array_replace($refundRequest, [
    'refunded_at' => '2026-10-01', 'request_key' => 'stage-deposit-early-refund-1',
]));
$otherBankStmt = $pdo->prepare(
    'SELECT id FROM accounting_bank_accounts
      WHERE tenant_id = :t AND id <> :id AND entity_id <> :entity LIMIT 1'
);
$otherBankStmt->execute(['t' => $tenantId, 'id' => (int) $bank['id'], 'entity' => 1]);
$otherBankId = (int) $otherBankStmt->fetchColumn();
$unavailableBankRejected = $otherBankId > 0 && $refundRejects(array_replace($refundRequest, [
    'bank_account_id' => $otherBankId, 'request_key' => 'stage-deposit-other-bank-1',
]));
$bankLineStmt = $pdo->prepare(
    'SELECT id, match_status, matched_je_id FROM accounting_bank_statement_lines
      WHERE tenant_id = :t AND bank_account_id = :b AND fitid = :fitid LIMIT 1'
);
$lineKey = 'STG-DEP-REFUND-LINE-1';
$bankLineStmt->execute(['t' => $tenantId, 'b' => (int) $bank['id'], 'fitid' => $lineKey]);
$refundBankLine = $bankLineStmt->fetch(PDO::FETCH_ASSOC);
if (!$refundBankLine) {
    bankRecImportCsv($tenantId, (int) $bank['id'],
        "Date,Description,Amount,Transaction ID\n2026-10-02,STG-REFUND-1,-1.00,$lineKey\n", null, null);
    $bankLineStmt->execute(['t' => $tenantId, 'b' => (int) $bank['id'], 'fitid' => $lineKey]);
    $refundBankLine = $bankLineStmt->fetch(PDO::FETCH_ASSOC);
}
if (!$refundBankLine) throw new RuntimeException('Synthetic refund bank line was not imported.');
$refundMatch = bankRecMatchLine($tenantId, (int) $refundBankLine['id'], (int) $refund['journal_entry_id'], null);
$bankLineStmt->execute(['t' => $tenantId, 'b' => (int) $bank['id'], 'fitid' => $lineKey]);
$refundBankLine = $bankLineStmt->fetch(PDO::FETCH_ASSOC);
$finalPaymentStmt = $pdo->prepare(
    'SELECT unallocated_amount FROM billing_payments WHERE tenant_id = :t AND id = :p'
);
$finalPaymentStmt->execute(['t' => $tenantId, 'p' => $paymentId]);
$finalRemaining = (float) $finalPaymentStmt->fetchColumn();

// A separate untouched deposit should be fully reversible without an invoice allocation.
$voidKey = 'stage-deposit-void-1';
$priorVoidStmt = $pdo->prepare(
    'SELECT id, voided_at, void_je_id FROM billing_payments
      WHERE tenant_id = :t AND source_system = "manual" AND external_id = :e'
);
$priorVoidStmt->execute(['t' => $tenantId, 'e' => 'manual-receipt:' . $voidKey]);
$voidPayment = $priorVoidStmt->fetch(PDO::FETCH_ASSOC);
if (!$voidPayment) {
    $postedVoid = billingPostReceivedPayment($tenantId, [
        'bank_account_id' => (int) $bank['id'], 'client_name' => 'MVP Delivery Test Co',
        'received_at' => $receiptDate, 'method' => 'ach', 'reference' => 'STG-DEPOSIT-VOID',
        'amount' => 2, 'currency' => 'USD', 'request_key' => $voidKey,
        'hold_unapplied' => true,
    ], null);
    billingCorrectPostedPayment($tenantId, (int) $postedVoid['id'], 'Synthetic staging deposit correction', null);
    $priorVoidStmt->execute(['t' => $tenantId, 'e' => 'manual-receipt:' . $voidKey]);
    $voidPayment = $priorVoidStmt->fetch(PDO::FETCH_ASSOC);
}

$checks = [
    'receipt_exact_replay' => !empty($exactReceiptReplay['idempotent_replay'])
        && (int) $exactReceiptReplay['id'] === (int) $freshReceipt['id'],
    'receipt_changed_intent_rejected' => $changedReceiptRejected,
    'receipt_bank_debit' => abs((float) ($receiptLines[$bank['gl_account_code']]['debit'] ?? 0) - 5) < 0.005,
    'receipt_deposit_credit' => abs((float) ($receiptLines['2300']['credit'] ?? 0) - 5) < 0.005,
    'application_deposit_debit' => abs((float) ($applicationLines['2300']['debit'] ?? 0) - 3) < 0.005,
    'application_ar_credit' => abs((float) ($applicationLines['1100']['credit'] ?? 0) - 3) < 0.005,
    'one_linked_allocation' => count($apps) === 1
        && abs((float) $apps[0]['amount_applied'] - 3) < 0.005
        && (int) $apps[0]['application_je_id'] === (int) $apps[0]['journal_entry_id'],
    'exact_replay' => !empty($replay['idempotent_replay'])
        && (int) $replay['application_id'] === (int) $application['application_id'],
    'over_application_rejected' => $overApplicationRejected,
    'early_application_rejected' => $earlyApplicationRejected,
    'changed_replay_rejected' => $changedReplayRejected,
    'other_client_rejected' => $otherClientRejected,
    'refund_deposit_debit' => abs((float) ($refundLines['2300']['debit'] ?? 0) - 1) < 0.005,
    'refund_bank_credit' => abs((float) ($refundLines[$bank['gl_account_code']]['credit'] ?? 0) - 1) < 0.005,
    'refund_remaining' => abs($finalRemaining - 1) < 0.005,
    'refund_exact_replay' => !empty($refundReplay['idempotent_replay'])
        && (int) $refundReplay['refund_id'] === (int) $refund['refund_id'],
    'refund_bank_line_matched' => !empty($refundMatch['ok'])
        && $refundBankLine['match_status'] === 'matched'
        && (int) $refundBankLine['matched_je_id'] === (int) $refund['journal_entry_id'],
    'over_refund_rejected' => $overRefundRejected,
    'early_refund_rejected' => $earlyRefundRejected,
    'unavailable_bank_rejected' => $unavailableBankRejected,
    'unused_deposit_corrected' => !empty($voidPayment['voided_at']) && !empty($voidPayment['void_je_id']),
];
echo json_encode(['tenant_id' => $tenantId, 'payment_id' => $paymentId,
    'application_id' => (int) $application['application_id'], 'checks' => $checks],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit(in_array(false, $checks, true) ? 1 : 0);
