<?php
/** Verify historical receipt and deposit allocations use their business dates. */
declare(strict_types=1);

if (getenv('COREFLUX_ENV') !== 'staging' || PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This check is restricted to the staging CLI.\n");
    exit(2);
}
require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../modules/billing/lib/posted_receipts.php';

$tenantId = 999;
$invoiceId = 7;
$date = '2026-10-02';
$pdo = getDB();
if ($pdo->query('SELECT name FROM tenants WHERE id = 999')->fetchColumn() !== 'CoreFlux CI Simulation') {
    throw new RuntimeException('Synthetic staging tenant is missing.');
}
$_SESSION['tenant_id'] = $tenantId;
$bankStmt = $pdo->prepare('SELECT id FROM accounting_bank_accounts WHERE tenant_id = :t AND name = :n AND status = "active" LIMIT 1');
$bankStmt->execute(['t' => $tenantId, 'n' => 'Staging Test Operating']);
$bankId = (int) $bankStmt->fetchColumn();
if ($bankId <= 0) throw new RuntimeException('Synthetic staging bank account is missing.');

$direct = billingPostReceivedPayment($tenantId, [
    'bank_account_id' => $bankId, 'client_name' => 'MVP Delivery Test Co',
    'received_at' => $date, 'method' => 'ach', 'reference' => 'STG-DATED-DIRECT',
    'amount' => 1, 'currency' => 'USD', 'request_key' => 'stage-dated-direct-1',
    'allocations' => [['invoice_id' => $invoiceId, 'amount' => 1]],
], null);
$deposit = billingPostReceivedPayment($tenantId, [
    'bank_account_id' => $bankId, 'client_name' => 'MVP Delivery Test Co',
    'received_at' => $date, 'method' => 'ach', 'reference' => 'STG-DATED-DEPOSIT',
    'amount' => 1, 'currency' => 'USD', 'request_key' => 'stage-dated-deposit-1',
    'hold_unapplied' => true,
], null);
$application = billingApplyCustomerDeposit($tenantId, (int) $deposit['id'], [
    'invoice_id' => $invoiceId, 'amount' => 1, 'applied_at' => $date,
    'request_key' => 'stage-dated-deposit-apply-1',
], null);
$allocationStmt = $pdo->prepare(
    'SELECT DATE(applied_at) FROM billing_payment_allocations
      WHERE payment_id = :p AND invoice_id = :i AND reversed_at IS NULL LIMIT 1'
);
$allocationStmt->execute(['p' => (int) $direct['id'], 'i' => $invoiceId]);
$directDate = $allocationStmt->fetchColumn();
$allocationStmt->execute(['p' => (int) $deposit['id'], 'i' => $invoiceId]);
$depositDate = $allocationStmt->fetchColumn();
$jeStmt = $pdo->prepare('SELECT posting_date FROM accounting_journal_entries WHERE tenant_id = :t AND id = :je');
$jeStmt->execute(['t' => $tenantId, 'je' => (int) $direct['journal_entry_id']]);
$directJournalDate = $jeStmt->fetchColumn();
$jeStmt->execute(['t' => $tenantId, 'je' => (int) $application['journal_entry_id']]);
$applicationJournalDate = $jeStmt->fetchColumn();
$checks = [
    'direct_allocation_date' => $directDate === $date,
    'deposit_allocation_date' => $depositDate === $date,
    'direct_journal_date' => $directJournalDate === $date,
    'application_journal_date' => $applicationJournalDate === $date,
];
echo json_encode(['direct_payment_id' => (int) $direct['id'],
    'deposit_payment_id' => (int) $deposit['id'], 'checks' => $checks], JSON_PRETTY_PRINT) . "\n";
exit(in_array(false, $checks, true) ? 1 : 0);
