<?php
/** Exercise new Billing event lineage and source-owned corrections on synthetic staging only. */
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
$bankStmt = $pdo->prepare(
    'SELECT id FROM accounting_bank_accounts WHERE tenant_id = :t AND name = :n AND status = "active" LIMIT 1'
);
$bankStmt->execute(['t' => $tenantId, 'n' => 'Staging Test Operating']);
$bankId = (int) $bankStmt->fetchColumn();
if ($bankId <= 0) throw new RuntimeException('Synthetic staging bank account is missing.');
$invoiceStmt = $pdo->prepare('SELECT amount_due FROM billing_invoices WHERE tenant_id = :t AND id = :id');
$invoiceStmt->execute(['t' => $tenantId, 'id' => $invoiceId]);
$dueBefore = (float) $invoiceStmt->fetchColumn();
if ($dueBefore < 2) throw new RuntimeException('Synthetic invoice has insufficient open balance.');

$suffix = bin2hex(random_bytes(5));
$checks = [];
$check = static function (string $name, bool $passed) use (&$checks): void {
    $checks[$name] = $passed;
};
$eventRow = static function (string $eventType, string $sourceId) use ($pdo, $tenantId): ?array {
    $stmt = $pdo->prepare(
        'SELECT e.id, e.status, e.journal_entry_id, je.status AS journal_status,
                je.source_module, je.source_ref_type, je.source_ref_id,
                link.accounting_event_id AS linked_event_id
           FROM accounting_events e
           JOIN accounting_journal_entries je ON je.tenant_id = e.tenant_id AND je.id = e.journal_entry_id
      LEFT JOIN accounting_subledger_links link ON link.tenant_id = e.tenant_id
            AND link.source_module = e.source_module AND link.source_record_id = e.source_record_id
            AND link.journal_entry_id = e.journal_entry_id AND link.link_kind = "primary"
          WHERE e.tenant_id = :t AND e.source_module = "billing"
            AND e.event_type = :event_type AND e.source_record_id = :source_id LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'event_type' => $eventType, 'source_id' => $sourceId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
};
$hasLineage = static function (int $parentId, int $childId, string $relationship) use ($pdo, $tenantId): bool {
    $stmt = $pdo->prepare(
        'SELECT id FROM event_lineage WHERE tenant_id = :t AND parent_event_id = :parent
            AND child_event_id = :child AND relationship_type = :relationship LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'parent' => $parentId,
        'child' => $childId, 'relationship' => $relationship]);
    return (bool) $stmt->fetchColumn();
};

$direct = null;
$deposit = null;
$application = null;
$refund = null;
$mainError = null;
try {
    $direct = billingPostReceivedPayment($tenantId, [
        'bank_account_id' => $bankId, 'client_name' => 'MVP Delivery Test Co',
        'received_at' => $date, 'method' => 'ach', 'amount' => 1, 'currency' => 'USD',
        'request_key' => 'stage-event-direct-' . $suffix,
        'allocations' => [['invoice_id' => $invoiceId, 'amount' => 1]],
    ], null);
    $directRow = $eventRow('billing.manual_receipt.posted', 'payment:' . $direct['id']);
    $check('direct receipt has posted event and primary link', $directRow !== null
        && $directRow['status'] === 'posted' && $directRow['journal_status'] === 'posted'
        && (int) $directRow['journal_entry_id'] === (int) $direct['journal_entry_id']
        && (int) $directRow['linked_event_id'] === (int) $directRow['id']);
    $check('direct receipt preserves payment journal ownership', $directRow !== null
        && $directRow['source_module'] === 'billing'
        && $directRow['source_ref_type'] === 'billing_payment'
        && (int) $directRow['source_ref_id'] === (int) $direct['id']);
    $directReplay = billingPostReceivedPayment($tenantId, [
        'bank_account_id' => $bankId, 'client_name' => 'MVP Delivery Test Co',
        'received_at' => $date, 'method' => 'ach', 'amount' => 1, 'currency' => 'USD',
        'request_key' => 'stage-event-direct-' . $suffix,
        'allocations' => [['invoice_id' => $invoiceId, 'amount' => 1]],
    ], null);
    $check('exact direct receipt retry keeps original journal', !empty($directReplay['idempotent_replay'])
        && (int) $directReplay['journal_entry_id'] === (int) $direct['journal_entry_id']);

    $deposit = billingPostReceivedPayment($tenantId, [
        'bank_account_id' => $bankId, 'client_name' => 'MVP Delivery Test Co',
        'received_at' => $date, 'method' => 'ach', 'amount' => 2, 'currency' => 'USD',
        'request_key' => 'stage-event-deposit-' . $suffix, 'hold_unapplied' => true,
    ], null);
    $paymentId = (int) $deposit['id'];
    $depositRow = $eventRow('billing.manual_receipt.posted', 'payment:' . $paymentId);
    $check('unapplied deposit has posted receipt event', $depositRow !== null
        && $depositRow['status'] === 'posted'
        && (int) $depositRow['linked_event_id'] === (int) $depositRow['id']);

    $applicationKey = 'stage-event-apply-' . $suffix;
    $application = billingApplyCustomerDeposit($tenantId, $paymentId, [
        'invoice_id' => $invoiceId, 'amount' => 1, 'applied_at' => $date,
        'request_key' => $applicationKey,
    ], null);
    $applicationRow = $eventRow('billing.customer_deposit.applied',
        'deposit_apply:' . $paymentId . ':' . $applicationKey);
    $check('deposit application has posted event and primary link', $applicationRow !== null
        && $applicationRow['status'] === 'posted'
        && (int) $applicationRow['journal_entry_id'] === (int) $application['journal_entry_id']
        && (int) $applicationRow['linked_event_id'] === (int) $applicationRow['id']);
    $check('deposit application keeps source reference', $applicationRow !== null
        && $applicationRow['source_ref_type'] === 'billing_deposit_application'
        && (int) $applicationRow['source_ref_id'] === $paymentId);
    $check('application points back to its receipt event', $depositRow !== null
        && $applicationRow !== null && $hasLineage((int) $depositRow['id'],
            (int) $applicationRow['id'], 'applies_to'));

    $refundKey = 'stage-event-refund-' . $suffix;
    $refund = billingRecordCustomerDepositRefund($tenantId, $paymentId, [
        'bank_account_id' => $bankId, 'amount' => 0.5, 'refunded_at' => $date,
        'request_key' => $refundKey,
    ], null);
    $refundRow = $eventRow('billing.customer_deposit.refunded',
        'deposit_refund:' . $paymentId . ':' . $refundKey);
    $check('deposit refund has posted event and primary link', $refundRow !== null
        && $refundRow['status'] === 'posted'
        && (int) $refundRow['journal_entry_id'] === (int) $refund['journal_entry_id']
        && (int) $refundRow['linked_event_id'] === (int) $refundRow['id']);
    $check('deposit refund keeps source reference', $refundRow !== null
        && $refundRow['source_ref_type'] === 'billing_deposit_refund'
        && (int) $refundRow['source_ref_id'] === $paymentId);
    $check('refund points back to its receipt event', $depositRow !== null
        && $refundRow !== null && $hasLineage((int) $depositRow['id'],
            (int) $refundRow['id'], 'spawned_by'));
} catch (Throwable $e) {
    $mainError = $e;
}

// Each source correction owns its transaction; unwind in dependency order.
foreach ([
    ['application', $application, static fn() => billingCorrectCustomerDepositApplication(
        $tenantId, (int) $application['application_id'], 'Synthetic event lineage exercise', null)],
    ['refund', $refund, static fn() => billingCorrectCustomerDepositRefund(
        $tenantId, (int) $refund['refund_id'], 'Synthetic event lineage exercise', null)],
    ['deposit', $deposit, static fn() => billingCorrectPostedPayment(
        $tenantId, (int) $deposit['id'], 'Synthetic event lineage exercise', null)],
    ['direct', $direct, static fn() => billingCorrectPostedPayment(
        $tenantId, (int) $direct['id'], 'Synthetic event lineage exercise', null)],
] as [$name, $record, $correct]) {
    if ($record === null) continue;
    try {
        $correct();
        $check($name . ' source correction succeeded', true);
    } catch (Throwable $e) {
        $check($name . ' source correction succeeded', false);
        if ($mainError === null) $mainError = $e;
    }
}
if ($direct !== null) {
    $row = $eventRow('billing.manual_receipt.posted', 'payment:' . $direct['id']);
    $check('direct receipt event and journal reversed', $row !== null
        && $row['status'] === 'reversed' && $row['journal_status'] === 'reversed');
}
if ($deposit !== null) {
    $row = $eventRow('billing.manual_receipt.posted', 'payment:' . $deposit['id']);
    $check('deposit receipt event and journal reversed', $row !== null
        && $row['status'] === 'reversed' && $row['journal_status'] === 'reversed');
}
if ($applicationRow ?? null) {
    $row = $eventRow('billing.customer_deposit.applied',
        'deposit_apply:' . $paymentId . ':' . $applicationKey);
    $check('application event and journal reversed', $row !== null
        && $row['status'] === 'reversed' && $row['journal_status'] === 'reversed');
}
if ($refundRow ?? null) {
    $row = $eventRow('billing.customer_deposit.refunded',
        'deposit_refund:' . $paymentId . ':' . $refundKey);
    $check('refund event and journal reversed', $row !== null
        && $row['status'] === 'reversed' && $row['journal_status'] === 'reversed');
}
$invoiceStmt->execute(['t' => $tenantId, 'id' => $invoiceId]);
$check('invoice due restored after corrections',
    abs((float) $invoiceStmt->fetchColumn() - $dueBefore) < 0.005);

echo json_encode(['checks' => $checks, 'passed' => count(array_filter($checks)),
    'failed' => count($checks) - count(array_filter($checks)),
    'error' => $mainError?->getMessage()], JSON_PRETTY_PRINT) . "\n";
exit($mainError !== null || in_array(false, $checks, true) ? 1 : 0);
