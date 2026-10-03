<?php
/** Staging-only, replayable check of the real MySQL capture-to-ledger path. */
declare(strict_types=1);

if (getenv('COREFLUX_ENV') !== 'staging') {
    fwrite(STDERR, "Set COREFLUX_ENV=staging; this check will not run elsewhere.\n");
    exit(2);
}

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/qbo/payments_client.php';

$tenantId = 999;
$invoiceId = 7;
$chargeId = 'STG-COREACCT-QBO-001';
$charge = ['id' => $chargeId, 'amount' => '1.00', 'currency' => 'USD', 'status' => 'CAPTURED'];
$pdo = getDB();
$stmt = $pdo->prepare(
    'SELECT i.id, i.amount_due, i.status, je.status AS je_status
       FROM billing_invoices i
       JOIN accounting_journal_entries je ON je.tenant_id = i.tenant_id AND je.id = i.journal_entry_id
      WHERE i.tenant_id = :t AND i.id = :i'
);
$stmt->execute(['t' => $tenantId, 'i' => $invoiceId]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$invoice || $invoice['je_status'] !== 'posted') {
    throw new RuntimeException('Synthetic staging invoice is missing or unposted.');
}

$existing = $pdo->prepare(
    'SELECT id FROM qbo_payment_charges WHERE tenant_id = :t AND qbo_charge_id = :c'
);
$existing->execute(['t' => $tenantId, 'c' => $chargeId]);
$firstRun = !$existing->fetchColumn();
if ($firstRun && (!in_array($invoice['status'], ['approved', 'sent', 'partially_paid'], true)
    || (float) $invoice['amount_due'] < 1)) {
    throw new RuntimeException('Synthetic staging invoice has no room for the $1 capture.');
}

qboRecordChargeShadow($tenantId, $charge, [
    'charge_type' => 'card', 'coreflux_invoice_id' => $invoiceId,
    'context_token' => 'stg-coreacct-qbo-001',
]);
$first = qboApplyCapturedPayment($tenantId, $charge, ['coreflux_invoice_id' => $invoiceId]);
$replay = qboApplyCapturedPayment($tenantId, $charge, ['coreflux_invoice_id' => $invoiceId]);
$paymentId = (int) ($first['payment_id'] ?? 0);

$payStmt = $pdo->prepare(
    'SELECT p.id, p.amount, p.unallocated_amount, p.journal_entry_id,
            je.status AS je_status, je.entity_id
       FROM billing_payments p
       JOIN accounting_journal_entries je ON je.tenant_id = p.tenant_id AND je.id = p.journal_entry_id
      WHERE p.tenant_id = :t AND p.id = :p'
);
$payStmt->execute(['t' => $tenantId, 'p' => $paymentId]);
$payment = $payStmt->fetch(PDO::FETCH_ASSOC);
$allocationStmt = $pdo->prepare(
    'SELECT COUNT(*) AS n, ROUND(SUM(amount_applied), 2) AS total
       FROM billing_payment_allocations
      WHERE payment_id = :p AND invoice_id = :i AND reversed_at IS NULL'
);
$allocationStmt->execute(['p' => $paymentId, 'i' => $invoiceId]);
$allocation = $allocationStmt->fetch(PDO::FETCH_ASSOC);
$linesStmt = $pdo->prepare(
    'SELECT a.code, ROUND(SUM(l.debit), 2) AS debit, ROUND(SUM(l.credit), 2) AS credit
       FROM accounting_journal_entry_lines l
       JOIN accounting_accounts a ON a.tenant_id = l.tenant_id AND a.id = l.account_id
      WHERE l.tenant_id = :t AND l.je_id = :je GROUP BY a.code'
);
$linesStmt->execute(['t' => $tenantId, 'je' => (int) ($payment['journal_entry_id'] ?? 0)]);
$lines = [];
foreach ($linesStmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
    $lines[$line['code']] = $line;
}
$checks = [
    'posted_receipt' => $payment && $payment['je_status'] === 'posted',
    'fully_applied_once' => (int) ($allocation['n'] ?? 0) === 1
        && abs((float) ($allocation['total'] ?? 0) - 1) < 0.005
        && abs((float) ($payment['unallocated_amount'] ?? 0)) < 0.005,
    'processor_clearing_debit' => abs((float) ($lines['1010']['debit'] ?? 0) - 1) < 0.005,
    'ar_credit' => abs((float) ($lines['1100']['credit'] ?? 0) - 1) < 0.005,
    'exact_replay' => !empty($replay['reused']) && (int) ($replay['payment_id'] ?? 0) === $paymentId,
];
echo json_encode([
    'synthetic_tenant_id' => $tenantId, 'invoice_id' => $invoiceId,
    'charge_id' => $chargeId, 'payment_id' => $paymentId,
    'journal_entry_id' => (int) ($payment['journal_entry_id'] ?? 0),
    'first_run' => $firstRun, 'checks' => $checks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit(in_array(false, $checks, true) ? 1 : 0);
