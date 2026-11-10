<?php
/** Rollback-only staging check for a manual AP payment correction. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging') {
    fwrite(STDERR, "This check is restricted to the staging CLI.\n");
    exit(2);
}
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../modules/ap/lib/payment_correction.php';
require_once __DIR__ . '/../modules/accounting/lib/bank_rec.php';
require_once __DIR__ . '/audit_ap_payment_lineage.php';

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
    $vendorName = 'SIM AP Correct ' . $token;
    $priorDate = date('Y-m-d', strtotime('-1 day'));
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
        'd' => $priorDate, 'd2' => $priorDate, 'd3' => $priorDate,
    ]);
    $billId = (int) $pdo->lastInsertId();
    $billJe = accountingPostJe($tenantId, [
        'entity_id' => $entityId,
        'posting_date' => $priorDate,
        'currency' => 'USD',
        'source_module' => 'ap',
        'source_ref_type' => 'ap_bill',
        'source_ref_id' => $billId,
        'idempotency_key' => 'sim:ap_correct_bill:' . $token,
        'memo' => 'Rollback-only AP payment correction check',
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
                 "USD", 7.25, 0, "sent", :bank_id)'
    );
    $payment->execute([
        't' => $tenantId, 'e' => $entityId, 'vendor' => $vendorName,
        'd' => $priorDate, 'ref' => $vendorName, 'bank_id' => $bankId,
    ]);
    $paymentId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO ap_payment_allocations (payment_id, bill_id, amount_applied, applied_at)
         VALUES (:payment_id, :bill_id, 7.25, NOW())'
    )->execute(['payment_id' => $paymentId, 'bill_id' => $billId]);
    $cleared = apClearPayment($tenantId, $paymentId, $priorDate, $bankId, null);
    $originalJeId = (int) $cleared['journal_entry_id'];

    $line = $pdo->prepare(
        'INSERT INTO accounting_bank_statement_lines
            (tenant_id, bank_account_id, posted_date, description, amount, bank_reference, fitid, match_status)
         VALUES (:t, :bank_id, :d, :description, -7.25, :ref, :fitid, "unmatched")'
    );
    $line->execute([
        't' => $tenantId, 'bank_id' => $bankId, 'd' => $priorDate,
        'description' => $vendorName, 'ref' => $vendorName, 'fitid' => $vendorName,
    ]);
    $lineId = (int) $pdo->lastInsertId();
    bankRecMatchLine($tenantId, $lineId, $originalJeId, null);
    $paymentStmt = $pdo->prepare('SELECT * FROM ap_payments WHERE tenant_id = :t AND id = :id');
    $paymentStmt->execute(['t' => $tenantId, 'id' => $paymentId]);
    $paymentRow = $paymentStmt->fetch(PDO::FETCH_ASSOC);
    $review = apInspectClearedManualPayment($pdo, $tenantId, $paymentRow);
    $auditBefore = simAuditApPaymentLineage($pdo, $tenantId);

    $providerRefused = false;
    $providerRow = $paymentRow;
    $providerRow['disbursement_rail'] = 'nacha';
    try {
        apInspectClearedManualPayment($pdo, $tenantId, $providerRow);
    } catch (RuntimeException $e) {
        $providerRefused = str_contains($e->getMessage(), 'Provider or bank-file payouts');
    }
    $closed = $pdo->prepare(
        'INSERT INTO accounting_reconciliations
            (tenant_id, bank_account_id, period_end, statement_balance, gl_balance, difference, status)
         VALUES (:t, :bank_id, :d, 0, 0, 0, "closed")'
    );
    $closed->execute(['t' => $tenantId, 'bank_id' => $bankId, 'd' => $priorDate]);
    $closedId = (int) $pdo->lastInsertId();
    $closedRefused = false;
    try {
        apInspectClearedManualPayment($pdo, $tenantId, $paymentRow);
    } catch (RuntimeException $e) {
        $closedRefused = str_contains($e->getMessage(), 'closed bank reconciliation');
    }
    $pdo->prepare('DELETE FROM accounting_reconciliations WHERE tenant_id = :t AND id = :id')
        ->execute(['t' => $tenantId, 'id' => $closedId]);

    $result = apCorrectClearedManualPayment($tenantId, $paymentId, 'Simulation correction', null);
    $auditAfter = simAuditApPaymentLineage($pdo, $tenantId);
    $paymentStmt->execute(['t' => $tenantId, 'id' => $paymentId]);
    $correctedPayment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
    $billState = $pdo->prepare('SELECT status, amount_paid, amount_due FROM ap_bills WHERE tenant_id = :t AND id = :id');
    $billState->execute(['t' => $tenantId, 'id' => $billId]);
    $reopenedBill = $billState->fetch(PDO::FETCH_ASSOC);
    $lineState = $pdo->prepare('SELECT match_status, matched_je_id FROM accounting_bank_statement_lines WHERE tenant_id = :t AND id = :id');
    $lineState->execute(['t' => $tenantId, 'id' => $lineId]);
    $reopenedLine = $lineState->fetch(PDO::FETCH_ASSOC);
    $originalStmt = $pdo->prepare('SELECT status, reversed_by_je_id FROM accounting_journal_entries WHERE tenant_id = :t AND id = :id');
    $originalStmt->execute(['t' => $tenantId, 'id' => $originalJeId]);
    $original = $originalStmt->fetch(PDO::FETCH_ASSOC);
    $reversalStmt = $pdo->prepare('SELECT status, reverses_je_id FROM accounting_journal_entries WHERE tenant_id = :t AND id = :id');
    $reversalStmt->execute(['t' => $tenantId, 'id' => $result['reversal_je_id']]);
    $reversal = $reversalStmt->fetch(PDO::FETCH_ASSOC);
    $linkStmt = $pdo->prepare(
        'SELECT 1 FROM accounting_subledger_links
          WHERE tenant_id = :t AND source_module = "ap" AND source_record_id = :ref
            AND journal_entry_id = :je_id AND link_kind = "reversal"'
    );
    $linkStmt->execute([
        't' => $tenantId, 'ref' => 'ap_payment:' . $paymentId, 'je_id' => $result['reversal_je_id'],
    ]);
    $agingToday = apComputeAging($tenantId, $today);
    $agingPrior = apComputeAging($tenantId, $priorDate);
    $dueToday = 0.0;
    $duePrior = 0.0;
    foreach ($agingToday as $row) if ($row['vendor_name'] === $vendorName) $dueToday = (float) $row['total_due'];
    foreach ($agingPrior as $row) if ($row['vendor_name'] === $vendorName) $duePrior = (float) $row['total_due'];
    $duplicateRefused = false;
    try {
        apCorrectClearedManualPayment($tenantId, $paymentId, 'Repeat correction', null);
    } catch (RuntimeException $e) {
        $duplicateRefused = str_contains($e->getMessage(), 'Only a fully allocated, posted manual payment');
    }

    $checks = [
        'fresh_clearance_passes_lineage_audit' => !array_filter($auditBefore['issues'], static fn(array $issue): bool =>
            ($issue['payment_id'] ?? null) === $paymentId || ($issue['bill_id'] ?? null) === $billId
        ) && !array_filter($auditBefore['manual_correction_blockers'], static fn(array $blocker): bool =>
            $blocker['payment_id'] === $paymentId
        ),
        'matched_manual_payment_eligible' => $review['bank_line_id'] === $lineId
            && $review['original_je_id'] === $originalJeId,
        'provider_payment_refused' => $providerRefused,
        'closed_reconciliation_refused' => $closedRefused,
        'payment_voided_with_reason' => $correctedPayment['status'] === 'void'
            && $correctedPayment['void_reason'] === 'Simulation correction',
        'original_journal_reversed' => $original['status'] === 'reversed'
            && (int) $original['reversed_by_je_id'] === (int) $result['reversal_je_id'],
        'reversal_journal_posted' => $reversal['status'] === 'posted'
            && (int) $reversal['reverses_je_id'] === $originalJeId,
        'reversal_source_linked' => (bool) $linkStmt->fetchColumn(),
        'bill_reopened' => $reopenedBill['status'] === 'approved'
            && abs((float) $reopenedBill['amount_paid']) < 0.005
            && abs((float) $reopenedBill['amount_due'] - 7.25) < 0.005,
        'bank_line_reopened' => $reopenedLine['match_status'] === 'unmatched'
            && !$reopenedLine['matched_je_id'],
        'corrected_payment_passes_lineage_audit' => !array_filter($auditAfter['issues'], static fn(array $issue): bool =>
            ($issue['payment_id'] ?? null) === $paymentId || ($issue['bill_id'] ?? null) === $billId
        ),
        'current_aging_restored' => abs($dueToday - 7.25) < 0.005,
        'historical_aging_preserved' => abs($duePrior) < 0.005,
        'duplicate_correction_refused' => $duplicateRefused,
        'outer_transaction_preserved' => $pdo->inTransaction(),
    ];
    $pdo->rollBack();
    $paymentStmt->execute(['t' => $tenantId, 'id' => $paymentId]);
    $checks['payment_rolled_back'] = !$paymentStmt->fetchColumn();
    $billState->execute(['t' => $tenantId, 'id' => $billId]);
    $checks['bill_rolled_back'] = !$billState->fetchColumn();
    $lineState->execute(['t' => $tenantId, 'id' => $lineId]);
    $checks['bank_line_rolled_back'] = !$lineState->fetchColumn();
    $originalStmt->execute(['t' => $tenantId, 'id' => $originalJeId]);
    $checks['original_journal_rolled_back'] = !$originalStmt->fetchColumn();
    $reversalStmt->execute(['t' => $tenantId, 'id' => $result['reversal_je_id']]);
    $checks['reversal_journal_rolled_back'] = !$reversalStmt->fetchColumn();
    echo json_encode(['checks' => $checks], JSON_PRETTY_PRINT) . "\n";
    exit(in_array(false, $checks, true) ? 1 : 0);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
