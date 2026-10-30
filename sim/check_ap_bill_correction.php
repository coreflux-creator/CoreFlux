<?php
/** Rollback-only integration check for a synthetic posted AP bill. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging') {
    fwrite(STDERR, "This check is restricted to the staging CLI.\n");
    exit(2);
}
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../modules/ap/lib/ap.php';
require_once __DIR__ . '/../modules/ap/lib/bill_correction.php';

$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) continue;
    [$key, $value] = explode('=', substr($arg, 2), 2);
    $args[$key] = $value;
}
$tenantId = (int) ($args['tenant'] ?? 0);
if ($tenantId <= 0 || ($args['rollback-only'] ?? '') !== 'yes') {
    fwrite(STDERR, "Use --tenant=ID --rollback-only=yes.\n");
    exit(2);
}

$pdo = getDB();
$pdo->beginTransaction();
try {
    $entityStmt = $pdo->prepare('SELECT id FROM accounting_entities WHERE tenant_id = :t AND active = 1 ORDER BY id LIMIT 1');
    $entityStmt->execute(['t' => $tenantId]);
    $entityId = (int) $entityStmt->fetchColumn();
    if ($entityId <= 0) throw new RuntimeException('No active legal entity in staging tenant');

    $token = bin2hex(random_bytes(5));
    $vendor = 'SIM Correction ' . $token;
    $internalRef = 'SIM-CORR-' . $token;
    $amount = 7.25;
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $today = date('Y-m-d');
    $create = $pdo->prepare(
        'INSERT INTO ap_bills
            (tenant_id, entity_id, bill_number, internal_ref, vendor_name, vendor_type,
             received_at, bill_date, due_date, currency, subtotal, tax_total,
             total, amount_paid, amount_due, status, source)
         VALUES
            (:tenant_id, :entity_id, :bill_number, :internal_ref, :vendor_name, "other",
             :received_at, :bill_date, :due_date, "USD", :subtotal, 0,
             :total, 0, :amount_due, "approved", "manual")'
    );
    $create->execute([
        'tenant_id' => $tenantId, 'entity_id' => $entityId,
        'bill_number' => $internalRef, 'internal_ref' => $internalRef, 'vendor_name' => $vendor,
        'received_at' => $yesterday, 'bill_date' => $yesterday, 'due_date' => $yesterday,
        'subtotal' => $amount, 'total' => $amount, 'amount_due' => $amount,
    ]);
    $billId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO ap_bill_lines
            (bill_id, line_no, source_type, description, quantity, unit,
             unit_price, subtotal, tax_rate_pct, tax_amount, total, gl_expense_account_code)
         VALUES (:bill_id, 1, "manual", "Rollback-only AP correction check", 1, "item",
                 :unit_price, :subtotal, 0, 0, :total, "6990")'
    )->execute([
        'bill_id' => $billId, 'unit_price' => $amount, 'subtotal' => $amount, 'total' => $amount,
    ]);
    $posted = accountingPostJe($tenantId, [
        'entity_id' => $entityId,
        'posting_date' => $yesterday,
        'currency' => 'USD',
        'source_module' => 'ap',
        'source_ref_type' => 'ap_bill',
        'source_ref_id' => $billId,
        'idempotency_key' => 'sim:ap_correction:' . $token,
        'memo' => 'Rollback-only AP correction check',
        'lines' => [
            ['account_code' => '6990', 'debit' => $amount, 'credit' => 0, 'description' => 'Synthetic bill'],
            ['account_code' => '2000', 'debit' => 0, 'credit' => $amount, 'description' => 'Synthetic AP'],
        ],
    ], null, true);
    $firstAttach = apAttachPostedBillJournal($pdo, $tenantId, $billId, (int) $posted['je_id']);
    $repeatedAttach = apAttachPostedBillJournal($pdo, $tenantId, $billId, (int) $posted['je_id']);
    $pdo->prepare(
        'INSERT INTO accounting_subledger_links
            (tenant_id, source_module, source_record_id, journal_entry_id, link_kind)
         VALUES (:t, "ap", :sr, :je, "primary")'
    )->execute(['t' => $tenantId, 'sr' => 'ap_bill:' . $billId, 'je' => (int) $posted['je_id']]);

    $paidBillRefused = false;
    $pdo->prepare('UPDATE ap_bills SET amount_paid = 1, amount_due = :due WHERE tenant_id = :t AND id = :id')
        ->execute(['due' => $amount - 1, 't' => $tenantId, 'id' => $billId]);
    try {
        $checkBill = $pdo->prepare('SELECT * FROM ap_bills WHERE tenant_id = :t AND id = :id');
        $checkBill->execute(['t' => $tenantId, 'id' => $billId]);
        apAssertPostedManualBillCorrectable($pdo, $tenantId, $checkBill->fetch(PDO::FETCH_ASSOC));
    } catch (RuntimeException $_) {
        $paidBillRefused = true;
    }
    $pdo->prepare('UPDATE ap_bills SET amount_paid = 0, amount_due = :due WHERE tenant_id = :t AND id = :id')
        ->execute(['due' => $amount, 't' => $tenantId, 'id' => $billId]);

    $wrongJournalRefused = false;
    $pdo->prepare('UPDATE accounting_journal_entries SET source_module = "manual" WHERE tenant_id = :t AND id = :id')
        ->execute(['t' => $tenantId, 'id' => (int) $posted['je_id']]);
    try {
        $checkBill->execute(['t' => $tenantId, 'id' => $billId]);
        apAssertPostedManualBillCorrectable($pdo, $tenantId, $checkBill->fetch(PDO::FETCH_ASSOC));
    } catch (RuntimeException $_) {
        $wrongJournalRefused = true;
    }
    $pdo->prepare('UPDATE accounting_journal_entries SET source_module = "ap" WHERE tenant_id = :t AND id = :id')
        ->execute(['t' => $tenantId, 'id' => (int) $posted['je_id']]);

    $voidedAttachRefused = false;
    $pdo->prepare('UPDATE ap_bills SET status = "void" WHERE tenant_id = :t AND id = :id')
        ->execute(['t' => $tenantId, 'id' => $billId]);
    try {
        apAttachPostedBillJournal($pdo, $tenantId, $billId, (int) $posted['je_id']);
    } catch (RuntimeException $_) {
        $voidedAttachRefused = true;
    }
    $pdo->prepare('UPDATE ap_bills SET status = "approved" WHERE tenant_id = :t AND id = :id')
        ->execute(['t' => $tenantId, 'id' => $billId]);

    $billStmt = $pdo->prepare('SELECT status, total, amount_due, journal_entry_id FROM ap_bills WHERE tenant_id = :t AND id = :id');
    $billStmt->execute(['t' => $tenantId, 'id' => $billId]);
    $before = $billStmt->fetch(PDO::FETCH_ASSOC);
    $priorAging = apComputeAging($tenantId, $yesterday);
    $corrected = apCorrectPostedManualBill($tenantId, $billId, 'Rollback-only staging check', null);
    $billStmt->execute(['t' => $tenantId, 'id' => $billId]);
    $during = $billStmt->fetch(PDO::FETCH_ASSOC);
    $journalStmt = $pdo->prepare(
        'SELECT id, status, reversed_by_je_id FROM accounting_journal_entries
          WHERE tenant_id = :t AND id = :id'
    );
    $journalStmt->execute(['t' => $tenantId, 'id' => (int) $before['journal_entry_id']]);
    $original = $journalStmt->fetch(PDO::FETCH_ASSOC);
    $journalStmt->execute(['t' => $tenantId, 'id' => $corrected['reversal_je_id']]);
    $reversal = $journalStmt->fetch(PDO::FETCH_ASSOC);
    $unreversedAccount = $pdo->prepare(
        'SELECT account_id FROM accounting_journal_entry_lines
          WHERE tenant_id = :t AND je_id IN (:original_id, :reversal_id)
          GROUP BY account_id HAVING ABS(SUM(debit - credit)) > 0.005 LIMIT 1'
    );
    $unreversedAccount->execute([
        't' => $tenantId, 'original_id' => (int) $before['journal_entry_id'],
        'reversal_id' => $corrected['reversal_je_id'],
    ]);
    $reversalLink = $pdo->prepare(
        'SELECT 1 FROM accounting_subledger_links
          WHERE tenant_id = :t AND source_module = "ap" AND source_record_id = :ref
            AND journal_entry_id = :je AND link_kind = "reversal"'
    );
    $reversalLink->execute([
        't' => $tenantId, 'ref' => 'ap_bill:' . $billId, 'je' => $corrected['reversal_je_id'],
    ]);
    $currentAging = apComputeAging($tenantId, $today);
    $historicalAgingAfterCorrection = apComputeAging($tenantId, $yesterday);
    $vendorDue = static function (array $rows) use ($vendor): float {
        foreach ($rows as $row) {
            if ($row['vendor_name'] === $vendor) return round((float) $row['total_due'], 2);
        }
        return 0.0;
    };
    $replayRefused = false;
    try {
        apCorrectPostedManualBill($tenantId, $billId, 'Duplicate check', null);
    } catch (RuntimeException $_) {
        $replayRefused = true;
    }
    $checks = [
        'bill_void_in_transaction' => $during['status'] === 'void',
        'due_cleared' => (float) $during['amount_due'] === 0.0,
        'original_reversed' => $original['status'] === 'reversed'
            && (int) $original['reversed_by_je_id'] === $corrected['reversal_je_id'],
        'reversal_posted' => $reversal['status'] === 'posted',
        'journal_pair_balanced_by_account' => !$unreversedAccount->fetchColumn(),
        'reversal_source_linked' => (bool) $reversalLink->fetchColumn(),
        'historical_aging_preserved' => abs($vendorDue($priorAging) - $amount) < 0.005,
        'historical_aging_after_correction_preserved' => abs($vendorDue($historicalAgingAfterCorrection) - $amount) < 0.005,
        'current_aging_removed' => $vendorDue($currentAging) === 0.0,
        'paid_bill_refused' => $paidBillRefused,
        'wrong_journal_source_refused' => $wrongJournalRefused,
        'first_attachment_new' => $firstAttach === false,
        'repeat_attachment_idempotent' => $repeatedAttach === true,
        'voided_bill_attachment_refused' => $voidedAttachRefused,
        'duplicate_refused' => $replayRefused,
    ];
    $pdo->rollBack();
    $find = $pdo->prepare('SELECT 1 FROM ap_bills WHERE tenant_id = :t AND internal_ref = :ref');
    $find->execute(['t' => $tenantId, 'ref' => $internalRef]);
    $checks['rollback_removed_synthetic_bill'] = !$find->fetchColumn();
    $journalStmt->execute(['t' => $tenantId, 'id' => (int) $posted['je_id']]);
    $checks['rollback_removed_synthetic_journal'] = !$journalStmt->fetchColumn();
    echo json_encode(['checks' => $checks], JSON_PRETTY_PRINT) . "\n";
    exit(in_array(false, $checks, true) ? 1 : 0);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
