<?php
/** Inspect manual receipt replay fidelity in the synthetic staging tenant. */
declare(strict_types=1);

if (getenv('COREFLUX_ENV') !== 'staging' || PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This check is restricted to the staging CLI.\n");
    exit(2);
}
require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../modules/billing/lib/posted_receipts.php';

$tenantId = 999;
$pdo = getDB();
if ($pdo->query('SELECT name FROM tenants WHERE id = 999')->fetchColumn() !== 'CoreFlux CI Simulation') {
    throw new RuntimeException('Synthetic staging tenant is missing.');
}
$_SESSION['tenant_id'] = $tenantId;
$bankStmt = $pdo->prepare('SELECT id FROM accounting_bank_accounts WHERE tenant_id = :t AND name = :n LIMIT 1');
$bankStmt->execute(['t' => $tenantId, 'n' => 'Staging Test Operating']);
$bankId = (int) $bankStmt->fetchColumn();
$request = [
    'bank_account_id' => $bankId, 'client_name' => 'MVP Delivery Test Co',
    'received_at' => '2026-10-02', 'method' => 'ach', 'reference' => 'STG-RECEIPT-REPLAY',
    'amount' => 2, 'currency' => 'USD', 'request_key' => 'stage-receipt-hash-1',
    'hold_unapplied' => true,
];
$stmt = $pdo->prepare('SELECT id, journal_entry_id, voided_at, bank_account_id, client_name,
    received_at, amount, receipt_request_hash FROM billing_payments
    WHERE tenant_id = :t AND source_system = "manual" AND external_id = :e LIMIT 1');
$stmt->execute(['t' => $tenantId, 'e' => 'manual-receipt:stage-receipt-hash-1']);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);
$expectedHash = billingReceiptRequestHash($request, $bankId, $request['client_name'],
    $request['received_at'], 2.0, 'ach', 'USD', true);
$checks = [
    'receipt_exists' => is_array($payment),
    'posted' => is_array($payment) && (int) $payment['journal_entry_id'] > 0,
    'active' => is_array($payment) && $payment['voided_at'] === null,
    'bank_matches' => is_array($payment) && (int) $payment['bank_account_id'] === $bankId,
    'client_matches' => is_array($payment) && $payment['client_name'] === $request['client_name'],
    'date_matches' => is_array($payment) && $payment['received_at'] === $request['received_at'],
    'amount_matches' => is_array($payment) && abs((float) $payment['amount'] - 2) < 0.005,
    'hash_matches' => is_array($payment) && !empty($payment['receipt_request_hash'])
        && hash_equals((string) $payment['receipt_request_hash'], $expectedHash),
];
echo json_encode(['payment_id' => $payment['id'] ?? null, 'checks' => $checks], JSON_PRETTY_PRINT) . "\n";
exit(in_array(false, $checks, true) ? 1 : 0);
