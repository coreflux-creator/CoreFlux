<?php
/** Rollback-only staging check: failed bank match cannot retain an AP cash journal. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging') {
    fwrite(STDERR, "This check is restricted to the staging CLI.\n");
    exit(2);
}
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../modules/ap/lib/ap.php';
require_once __DIR__ . '/../modules/accounting/lib/accounting.php';
require_once __DIR__ . '/../modules/accounting/lib/bank_rec.php';

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
    $reference = 'SIM-AP-MATCH-' . $token;
    $today = date('Y-m-d');
    $bill = $pdo->prepare(
        'INSERT INTO ap_bills
            (tenant_id, entity_id, bill_number, internal_ref, vendor_name, vendor_type,
             received_at, bill_date, due_date, currency, subtotal, tax_total,
             total, amount_paid, amount_due, status, source)
         VALUES
            (:t, :e, :ref, :ref2, "SIM AP Match", "other", :d, :d2, :d3,
             "USD", 7.25, 0, 7.25, 0, 7.25, "approved", "manual")'
    );
    $bill->execute([
        't' => $tenantId, 'e' => $entityId, 'ref' => $reference, 'ref2' => $reference,
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
        'idempotency_key' => 'sim:ap_match_bill:' . $token,
        'memo' => 'Rollback-only AP bank match check',
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
         VALUES (:t, :e, "SIM AP Match", :d, "ach", :ref,
                 "USD", 7.25, 0, "sent", :bank_id)'
    );
    $payment->execute([
        't' => $tenantId, 'e' => $entityId, 'd' => $today,
        'ref' => $reference, 'bank_id' => $bankId,
    ]);
    $paymentId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO ap_payment_allocations (payment_id, bill_id, amount_applied, applied_at)
         VALUES (:payment_id, :bill_id, 7.25, NOW())'
    )->execute(['payment_id' => $paymentId, 'bill_id' => $billId]);
    $line = $pdo->prepare(
        'INSERT INTO accounting_bank_statement_lines
            (tenant_id, bank_account_id, posted_date, description, amount, bank_reference, fitid, match_status)
         VALUES (:t, :bank_id, :d, "Deliberately different amount", -7.24, :ref, :fitid, "unmatched")'
    );
    $line->execute([
        't' => $tenantId, 'bank_id' => $bankId, 'd' => $today,
        'ref' => $reference, 'fitid' => $reference,
    ]);
    $lineId = (int) $pdo->lastInsertId();

    $cleared = apClearPayment($tenantId, $paymentId, $today, $bankId, null);
    $posted = $pdo->prepare('SELECT status, journal_entry_id FROM ap_payments WHERE tenant_id = :t AND id = :id');
    $posted->execute(['t' => $tenantId, 'id' => $paymentId]);
    $during = $posted->fetch(PDO::FETCH_ASSOC);
    $mismatchRefused = false;
    try {
        bankRecMatchLine($tenantId, $lineId, (int) $cleared['journal_entry_id'], null);
    } catch (RuntimeException $e) {
        $mismatchRefused = str_contains($e->getMessage(), 'does not contain the matching cash movement');
    }
    $bankLine = $pdo->prepare('SELECT match_status, matched_je_id FROM accounting_bank_statement_lines WHERE tenant_id = :t AND id = :id');
    $bankLine->execute(['t' => $tenantId, 'id' => $lineId]);
    $lineDuring = $bankLine->fetch(PDO::FETCH_ASSOC);
    $checks = [
        'clear_posted_inside_outer_transaction' => $during['status'] === 'cleared'
            && (int) $during['journal_entry_id'] === (int) $cleared['journal_entry_id'],
        'payment_ledger_detected' => apPaymentHasLedgerActivity($pdo, $tenantId, ['id' => $paymentId] + $during),
        'mismatched_bank_amount_refused' => $mismatchRefused,
        'bank_line_stayed_unmatched' => $lineDuring['match_status'] === 'unmatched' && !$lineDuring['matched_je_id'],
        'caller_transaction_still_open' => $pdo->inTransaction(),
    ];
    $pdo->rollBack();

    $posted->execute(['t' => $tenantId, 'id' => $paymentId]);
    $checks['payment_rolled_back'] = !$posted->fetchColumn();
    $billExists = $pdo->prepare('SELECT 1 FROM ap_bills WHERE tenant_id = :t AND id = :id');
    $billExists->execute(['t' => $tenantId, 'id' => $billId]);
    $checks['bill_rolled_back'] = !$billExists->fetchColumn();
    $bankLine->execute(['t' => $tenantId, 'id' => $lineId]);
    $checks['bank_line_rolled_back'] = !$bankLine->fetchColumn();
    $journal = $pdo->prepare('SELECT 1 FROM accounting_journal_entries WHERE tenant_id = :t AND id = :id');
    $journal->execute(['t' => $tenantId, 'id' => (int) $cleared['journal_entry_id']]);
    $checks['cash_journal_rolled_back'] = !$journal->fetchColumn();
    $event = $pdo->prepare(
        'SELECT 1 FROM accounting_events
          WHERE tenant_id = :t AND source_module = "ap" AND source_record_id = :ref LIMIT 1'
    );
    $event->execute(['t' => $tenantId, 'ref' => 'ap_payment:' . $paymentId]);
    $checks['payment_event_rolled_back'] = !$event->fetchColumn();
    echo json_encode(['checks' => $checks], JSON_PRETTY_PRINT) . "\n";
    exit(in_array(false, $checks, true) ? 1 : 0);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
