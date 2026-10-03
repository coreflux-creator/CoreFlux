<?php
/** Rollback-only staging check: sent AP is reserved, not settled. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging') {
    fwrite(STDERR, "This check is restricted to the staging CLI.\n");
    exit(2);
}
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../modules/ap/lib/ap.php';
require_once __DIR__ . '/../modules/accounting/lib/accounting.php';

$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) continue;
    [$key, $value] = explode('=', substr($arg, 2), 2);
    $args[$key] = $value;
}
if ((int) ($args['tenant'] ?? 0) !== 999 || ($args['rollback-only'] ?? '') !== 'yes') {
    fwrite(STDERR, "Use --tenant=999 --rollback-only=yes.\n");
    exit(2);
}

$tenantId = 999;
$GLOBALS['__cf_request_tenant_id'] = $tenantId;
$pdo = getDB();
$pdo->beginTransaction();
try {
    $entity = $pdo->prepare('SELECT id FROM accounting_entities WHERE tenant_id = :t AND active = 1 ORDER BY id LIMIT 1');
    $entity->execute(['t' => $tenantId]);
    $entityId = (int) $entity->fetchColumn();
    $bank = $pdo->prepare(
        'SELECT id FROM accounting_bank_accounts
          WHERE tenant_id = :t AND entity_id = :e AND gl_account_code = "1000" AND status = "active" LIMIT 1'
    );
    $bank->execute(['t' => $tenantId, 'e' => $entityId]);
    $bankId = (int) $bank->fetchColumn();
    if ($entityId <= 0 || $bankId <= 0) throw new RuntimeException('An active simulation entity and cash bank are required');

    $token = bin2hex(random_bytes(5));
    $vendorName = 'SIM AP Reserve ' . $token;
    $today = date('Y-m-d');
    $bill = $pdo->prepare(
        'INSERT INTO ap_bills
            (tenant_id, entity_id, bill_number, internal_ref, vendor_name, vendor_type,
             received_at, bill_date, due_date, currency, subtotal, tax_total,
             total, amount_paid, amount_due, status, source)
         VALUES
            (:t, :e, :ref, :ref2, :vendor, "other", :d, :d2, :d3,
             "USD", 7.25, 0, 7.25, 0, 7.25, "approved", "manual")'
    );
    $bill->execute([
        't' => $tenantId, 'e' => $entityId, 'ref' => $vendorName,
        'ref2' => $vendorName, 'vendor' => $vendorName,
        'd' => $today, 'd2' => $today, 'd3' => $today,
    ]);
    $billId = (int) $pdo->lastInsertId();
    $billJe = accountingPostJe($tenantId, [
        'entity_id' => $entityId,
        'posting_date' => $today,
        'currency' => 'USD',
        'source_module' => 'ap',
        'source_ref_type' => 'ap_bill',
        'source_ref_id' => $billId,
        'idempotency_key' => 'sim:ap_reserve_bill:' . $token,
        'memo' => 'Rollback-only AP reservation check',
        'lines' => [
            ['account_code' => '6990', 'debit' => 7.25, 'credit' => 0],
            ['account_code' => '2000', 'debit' => 0, 'credit' => 7.25],
        ],
    ], null, true);
    apAttachPostedBillJournal($pdo, $tenantId, $billId, (int) $billJe['je_id']);

    $payment = $pdo->prepare(
        'INSERT INTO ap_payments
            (tenant_id, entity_id, vendor_name, pay_date, method, reference,
             currency, amount, unallocated_amount, status, bank_account_id)
         VALUES (:t, :e, :vendor, :d, "ach", :ref,
                 "USD", 7.25, 0, "draft", :bank_id)'
    );
    $payment->execute([
        't' => $tenantId, 'e' => $entityId, 'vendor' => $vendorName,
        'd' => $today, 'ref' => $vendorName, 'bank_id' => $bankId,
    ]);
    $paymentId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO ap_payment_allocations (payment_id, bill_id, amount_applied, applied_at)
         VALUES (:payment_id, :bill_id, 7.25, NOW())'
    )->execute(['payment_id' => $paymentId, 'bill_id' => $billId]);

    $pdo->prepare(
        'UPDATE ap_payments SET status = "sent", sent_at = NOW()
          WHERE tenant_id = :tenant_id AND id = :id'
    )->execute(['tenant_id' => $tenantId, 'id' => $paymentId]);
    apRefreshReleasedPaymentBillsForPayment($pdo, $tenantId, $paymentId);
    $billState = $pdo->prepare('SELECT status, amount_paid, amount_due FROM ap_bills WHERE tenant_id = :t AND id = :id');
    $billState->execute(['t' => $tenantId, 'id' => $billId]);
    $sentBill = $billState->fetch(PDO::FETCH_ASSOC);
    $sentAging = apComputeAging($tenantId, $today);
    $sentDue = 0.0;
    foreach ($sentAging as $row) {
        if ($row['vendor_name'] === $vendorName) $sentDue = (float) $row['total_due'];
    }
    $reallocationRefused = false;
    try {
        apAllocatePayment($paymentId, ['auto' => 'fifo'], null);
    } catch (RuntimeException $e) {
        $reallocationRefused = str_contains($e->getMessage(), 'A released payment cannot be reallocated');
    }
    $sentHasLedgerActivity = apPaymentHasLedgerActivity($pdo, $tenantId, [
        'id' => $paymentId, 'status' => 'sent', 'journal_entry_id' => null,
    ]);

    $cleared = apClearPayment($tenantId, $paymentId, $today, $bankId, null);
    $billState->execute(['t' => $tenantId, 'id' => $billId]);
    $clearedBill = $billState->fetch(PDO::FETCH_ASSOC);
    $clearedAging = apComputeAging($tenantId, $today);
    $stillAged = array_filter($clearedAging, static fn(array $row): bool => $row['vendor_name'] === $vendorName);

    $fifoBillIds = [];
    foreach (['A', 'B'] as $suffix) {
        $ref = $vendorName . '-' . $suffix;
        $bill->execute([
            't' => $tenantId, 'e' => $entityId, 'ref' => $ref,
            'ref2' => $ref, 'vendor' => $vendorName,
            'd' => $today, 'd2' => $today, 'd3' => $today,
        ]);
        $fifoBillIds[] = (int) $pdo->lastInsertId();
    }
    $fifoPayment = $pdo->prepare(
        'INSERT INTO ap_payments
            (tenant_id, entity_id, vendor_name, pay_date, method, reference,
             currency, amount, unallocated_amount, status, bank_account_id)
         VALUES (:t, :e, :vendor, :d, "ach", :ref,
                 "USD", 7.25, :unallocated, "draft", :bank_id)'
    );
    $fifoPaymentIds = [];
    foreach ([0, 7.25] as $unallocated) {
        $fifoPayment->execute([
            't' => $tenantId, 'e' => $entityId, 'vendor' => $vendorName,
            'd' => $today, 'ref' => $vendorName . '-FIFO-' . count($fifoPaymentIds),
            'unallocated' => $unallocated, 'bank_id' => $bankId,
        ]);
        $fifoPaymentIds[] = (int) $pdo->lastInsertId();
    }
    $pdo->prepare(
        'INSERT INTO ap_payment_allocations (payment_id, bill_id, amount_applied, applied_at)
         VALUES (:payment_id, :bill_id, 7.25, NOW())'
    )->execute(['payment_id' => $fifoPaymentIds[0], 'bill_id' => $fifoBillIds[0]]);
    $fifoResult = apAllocatePayment($fifoPaymentIds[1], ['auto' => 'fifo'], null);

    $checks = [
        'sent_bill_remains_open' => $sentBill['status'] === 'approved'
            && abs((float) $sentBill['amount_paid']) < 0.005
            && abs((float) $sentBill['amount_due'] - 7.25) < 0.005,
        'sent_payment_remains_in_aging' => abs($sentDue - 7.25) < 0.005,
        'sent_payment_has_no_cash_journal' => !$sentHasLedgerActivity,
        'sent_reallocation_refused' => $reallocationRefused,
        'clearance_settles_bill' => $clearedBill['status'] === 'paid'
            && abs((float) $clearedBill['amount_paid'] - 7.25) < 0.005
            && abs((float) $clearedBill['amount_due']) < 0.005,
        'clearance_removes_bill_from_aging' => !$stillAged,
        'clearance_posts_cash_journal' => (int) $cleared['journal_entry_id'] > 0,
        'fifo_skips_fully_reserved_bill' => count($fifoResult['applied']) === 1
            && (int) $fifoResult['applied'][0]['bill_id'] === $fifoBillIds[1]
            && abs((float) $fifoResult['unallocated_remaining']) < 0.005,
        'outer_transaction_preserved' => $pdo->inTransaction(),
    ];
    $pdo->rollBack();

    $paymentExists = $pdo->prepare('SELECT 1 FROM ap_payments WHERE tenant_id = :t AND id = :id');
    $paymentExists->execute(['t' => $tenantId, 'id' => $paymentId]);
    $checks['payment_rolled_back'] = !$paymentExists->fetchColumn();
    $billState->execute(['t' => $tenantId, 'id' => $billId]);
    $checks['bill_rolled_back'] = !$billState->fetchColumn();
    $journal = $pdo->prepare('SELECT 1 FROM accounting_journal_entries WHERE tenant_id = :t AND id = :id');
    $journal->execute(['t' => $tenantId, 'id' => (int) $cleared['journal_entry_id']]);
    $checks['cash_journal_rolled_back'] = !$journal->fetchColumn();
    echo json_encode(['checks' => $checks], JSON_PRETTY_PRINT) . "\n";
    exit(in_array(false, $checks, true) ? 1 : 0);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
