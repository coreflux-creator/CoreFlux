<?php
/** Synthetic-only PWP approval and receipt-correction acceptance on isolated staging. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--execute') {
    fwrite(STDERR, "Run only on isolated CoreFlux staging with --execute.\n");
    exit(2);
}

define('QA_LIFECYCLE_LIBRARY_MODE', true);
require_once __DIR__ . '/accounting_staging_lifecycle.php';

$entity = qaOne($pdo, 'SELECT id FROM accounting_entities WHERE tenant_id = :t AND code = :code',
    ['t' => QA_TENANT, 'code' => QA_ENTITY_CODE]);
$entityId = (int) ($entity['id'] ?? 0);
$bank = qaOne($pdo, 'SELECT id FROM accounting_bank_accounts
    WHERE tenant_id = :t AND entity_id = :e AND gl_account_code = :code',
    ['t' => QA_TENANT, 'e' => $entityId, 'code' => QA_BANK_CODE]);
$bankId = (int) ($bank['id'] ?? 0);
if ($entityId <= 0 || $bankId <= 0) {
    throw new RuntimeException('Synthetic lifecycle entity and bank must be set up first.');
}

$maker = null;
$reviewer = null;
$cookies = [];
try {
    $maker = qaEnsureActor($pdo, 'maker');
    $reviewer = qaEnsureActor($pdo, 'reviewer');
    $makerCookie = tempnam(sys_get_temp_dir(), 'cf-maker-');
    $reviewerCookie = tempnam(sys_get_temp_dir(), 'cf-review-');
    $cookies = [$makerCookie, $reviewerCookie];
    qaLogin($maker, $makerCookie);
    qaLogin($reviewer, $reviewerCookie);

    $before = qaBalances($pdo, $entityId);
    $run = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
    $date = '2026-10-07';
    echo "Synthetic PWP run {$run}\n";

    $invoice = qaRequest('/modules/billing/api/invoices.php', 'POST', [
        'entity_id' => $entityId, 'client_name' => 'Synthetic PWP Client ' . $run,
        'issue_date' => $date, 'due_date' => '2026-11-06', 'currency' => 'USD',
        'tax_rate_pct' => 0, 'notes_internal' => 'Synthetic PWP receipt guard ' . $run,
        'lines' => [['description' => 'Invented service', 'quantity' => 1, 'unit' => 'each',
            'unit_price' => 73.25, 'item_type' => 'fixed_fee', 'gl_revenue_account_code' => '4000']],
    ], $makerCookie);
    $invoiceId = (int) ($invoice['id'] ?? 0);
    qaExpect($invoiceId > 0, 'synthetic invoice draft created');
    qaRequest('/modules/billing/api/invoices.php?action=request_approval&id=' . $invoiceId,
        'POST', [], $makerCookie);
    $assignment = qaRequest('/modules/billing/api/approval_assignment.php', 'POST', [
        'invoice_id' => $invoiceId, 'reviewer_user_ids' => [(int) $reviewer['id']],
    ], $makerCookie);
    qaExpect(in_array((int) $reviewer['id'], $assignment['assigned_reviewer_user_ids'] ?? [], true),
        'independent invoice reviewer assigned');
    qaRequest('/modules/billing/api/invoices.php?action=approve&id=' . $invoiceId,
        'POST', [], $reviewerCookie);
    $invoicePost = qaRequest('/modules/billing/api/invoices.php?action=post&id=' . $invoiceId,
        'POST', [], $reviewerCookie);
    qaExpect((int) ($invoicePost['journal_entry_id'] ?? 0) > 0,
        'approved invoice posted without sending');

    $bill = qaRequest('/modules/ap/api/bills.php', 'POST', [
        'entity_id' => $entityId, 'vendor_name' => 'Synthetic PWP Vendor ' . $run,
        'vendor_type' => 'other', 'bill_number' => 'PWP-' . $run,
        'bill_date' => $date, 'received_at' => $date, 'due_date' => '2026-11-06',
        'currency' => 'USD', 'tax_rate_pct' => 0,
        'notes_internal' => 'Synthetic PWP receipt guard ' . $run,
        'lines' => [['description' => 'Invented subcontract service', 'quantity' => 1,
            'unit' => 'each', 'unit_price' => 41.50, 'item_type' => 'expense',
            'gl_expense_account_code' => '5000']],
    ], $makerCookie);
    $billId = (int) ($bill['id'] ?? 0);
    qaExpect($billId > 0, 'synthetic vendor bill created');
    $otherInvoice = qaOne($pdo, 'SELECT i.id FROM billing_invoices i
        JOIN accounting_entities e ON e.id = i.entity_id AND e.tenant_id = i.tenant_id
        WHERE i.tenant_id = :t AND i.entity_id <> :entity_id
            AND i.status <> "void" AND e.code LIKE "SIM-%"
        ORDER BY i.id DESC LIMIT 1', ['t' => QA_TENANT, 'entity_id' => $entityId]);
    qaExpect((int) ($otherInvoice['id'] ?? 0) > 0, 'another synthetic legal entity has an invoice');
    try {
        qaRequest('/modules/ap/api/pwp.php?action=link', 'POST', [
            'bill_id' => $billId, 'ar_invoice_id' => (int) $otherInvoice['id'],
            'payment_terms' => 'PWP',
        ], $makerCookie);
        $crossEntityBlocked = false;
    } catch (RuntimeException $error) {
        $crossEntityBlocked = str_contains($error->getMessage(), 'HTTP 422')
            && str_contains($error->getMessage(), 'same legal entity');
    }
    $unlinkedBill = qaOne($pdo, 'SELECT pwp_status, linked_ar_invoice_id FROM ap_bills
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $billId]);
    qaExpect($crossEntityBlocked && $unlinkedBill['pwp_status'] === 'not_pwp'
        && $unlinkedBill['linked_ar_invoice_id'] === null,
        'cross-entity invoice link is refused without changing the bill');
    $link = qaRequest('/modules/ap/api/pwp.php?action=link', 'POST', [
        'bill_id' => $billId, 'ar_invoice_id' => $invoiceId, 'payment_terms' => 'PWP',
    ], $makerCookie);
    $linkedBill = qaOne($pdo, 'SELECT status, pwp_status, linked_ar_invoice_id
        FROM ap_bills WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $billId]);
    qaExpect((int) ($link['ar_invoice_id'] ?? 0) === $invoiceId
        && $linkedBill['status'] === 'pending_approval'
        && $linkedBill['pwp_status'] === 'awaiting_ar',
        'PWP link holds payment without approving the bill');
    $unlinked = qaRequest('/modules/ap/api/pwp.php?action=unlink', 'POST', [
        'bill_id' => $billId,
    ], $makerCookie);
    $heldBill = qaOne($pdo, 'SELECT pwp_status, linked_ar_invoice_id FROM ap_bills
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $billId]);
    qaExpect(!empty($unlinked['cleared']) && $heldBill['linked_ar_invoice_id'] === null
        && $heldBill['pwp_status'] === 'awaiting_ar',
        'unlinking an invoice leaves the vendor payment on hold');
    $relink = qaRequest('/modules/ap/api/pwp.php?action=link', 'POST', [
        'bill_id' => $billId, 'ar_invoice_id' => $invoiceId, 'payment_terms' => 'PWP',
    ], $makerCookie);
    qaExpect((int) ($relink['ar_invoice_id'] ?? 0) === $invoiceId,
        'an unreleased PWP bill can be relinked to the reviewed invoice');
    $earlyRelease = qaRequest('/modules/ap/api/pwp.php?action=release_for_invoice', 'POST', [
        'ar_invoice_id' => $invoiceId,
    ], $reviewerCookie);
    qaExpect(empty($earlyRelease['released'])
        && qaOne($pdo, 'SELECT pwp_status FROM ap_bills WHERE tenant_id = :t AND id = :id',
            ['t' => QA_TENANT, 'id' => $billId])['pwp_status'] === 'awaiting_ar',
        'PWP hold stays in place before the invoice is paid');
    require_once __DIR__ . '/../modules/ap/lib/pwp.php';
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE billing_invoices SET status = "void", amount_due = 0
            WHERE tenant_id = :t AND id = :id')
            ->execute(['t' => QA_TENANT, 'id' => $invoiceId]);
        $voidRelease = apPwpReleaseForArInvoice(QA_TENANT, $invoiceId);
        qaExpect(empty($voidRelease['released'])
            && qaOne($pdo, 'SELECT pwp_status FROM ap_bills WHERE tenant_id = :t AND id = :id',
                ['t' => QA_TENANT, 'id' => $billId])['pwp_status'] === 'awaiting_ar',
            'a zero-due void invoice cannot release the vendor bill');
        $pdo->rollBack();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    $fitid = 'SIM-PWP-RECEIPT-' . $run;
    $csv = qaCsv([
        ['Date', 'Description', 'Amount', 'Transaction ID'],
        [$date, 'Synthetic PWP customer receipt ' . $run, '73.25', $fitid],
    ]);
    $import = qaRequest('/modules/accounting/api/bank_statements.php?action=import_csv&bank_account_id=' .
        $bankId, 'POST', ['csv' => $csv], $makerCookie);
    qaExpect((int) ($import['inserted'] ?? 0) === 1, 'one synthetic bank receipt imported');
    $line = qaOne($pdo, 'SELECT id FROM accounting_bank_statement_lines
        WHERE tenant_id = :t AND bank_account_id = :bank AND fitid = :fitid',
        ['t' => QA_TENANT, 'bank' => $bankId, 'fitid' => $fitid]);
    $lineId = (int) ($line['id'] ?? 0);
    qaExpect($lineId > 0, 'synthetic receipt line found');
    $match = qaRequest('/modules/accounting/api/bank_statements.php?action=match_invoice&line_id=' .
        $lineId, 'POST', ['invoice_id' => $invoiceId], $reviewerCookie);
    qaExpect((int) ($match['matched_je_id'] ?? 0) > 0
        && (int) ($match['invoice_id'] ?? 0) === $invoiceId,
        'bank receipt matched and applied to invoice');

    $releasedBill = qaOne($pdo, 'SELECT status, pwp_status, pwp_released_at,
            approved_by_user_id, approved_at, amount_due
        FROM ap_bills WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $billId]);
    qaExpect($releasedBill['pwp_status'] === 'triggered'
        && $releasedBill['pwp_released_at'] !== null
        && $releasedBill['status'] === 'pending_approval'
        && $releasedBill['approved_by_user_id'] === null
        && $releasedBill['approved_at'] === null,
        'customer collection lifts PWP hold but does not approve AP');
    try {
        qaRequest('/modules/ap/api/pwp.php?action=link', 'POST', [
            'bill_id' => $billId, 'ar_invoice_id' => $invoiceId,
            'payment_terms' => 'PWP',
        ], $makerCookie);
        $relinkBlocked = false;
    } catch (RuntimeException $error) {
        $relinkBlocked = str_contains($error->getMessage(), 'HTTP 422')
            && str_contains($error->getMessage(), 'already released');
    }
    qaExpect($relinkBlocked, 'released PWP bill cannot be silently put back on hold');
    try {
        qaRequest('/modules/ap/api/pwp.php?action=unlink', 'POST', [
            'bill_id' => $billId,
        ], $makerCookie);
        $unlinkBlocked = false;
    } catch (RuntimeException $error) {
        $unlinkBlocked = str_contains($error->getMessage(), 'HTTP 422')
            && str_contains($error->getMessage(), 'unreleased PWP bill');
    }
    qaExpect($unlinkBlocked, 'released PWP bill cannot have its invoice link cleared');

    $approvedBill = qaRequest('/modules/ap/api/bills.php?action=approve&id=' . $billId,
        'POST', [], $reviewerCookie);
    qaExpect(($approvedBill['workflow_status'] ?? '') === 'approved',
        'independent reviewer approves released PWP bill');
    $billPost = qaRequest('/modules/ap/api/bills.php?action=post&id=' . $billId,
        'POST', [], $reviewerCookie);
    qaExpect((int) ($billPost['journal_entry_id'] ?? 0) > 0,
        'reviewed PWP bill posts to AP without a payment rail');

    $invoiceBefore = qaOne($pdo, 'SELECT status, amount_paid, amount_due FROM billing_invoices
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $invoiceId]);
    $lineBefore = qaOne($pdo, 'SELECT match_status, matched_je_id FROM accounting_bank_statement_lines
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $lineId]);
    $journalBefore = qaOne($pdo, 'SELECT status, reversed_by_je_id FROM accounting_journal_entries
        WHERE tenant_id = :t AND id = :id',
        ['t' => QA_TENANT, 'id' => (int) $lineBefore['matched_je_id']]);
    $correctionsBefore = (int) qaOne($pdo, 'SELECT COUNT(*) AS n FROM billing_receipt_corrections
        WHERE tenant_id = :t AND bank_line_id = :id',
        ['t' => QA_TENANT, 'id' => $lineId])['n'];
    $balancesBefore = qaBalances($pdo, $entityId);
    try {
        qaRequest('/modules/accounting/api/bank_statements.php?action=reverse_receipt&line_id=' .
            $lineId, 'POST', ['reason' => 'Synthetic PWP correction guard test'], $reviewerCookie);
        $blocked = false;
    } catch (RuntimeException $error) {
        $blocked = str_contains($error->getMessage(), 'HTTP 409')
            && str_contains($error->getMessage(), 'Pay-when-paid bills were released');
    }
    qaExpect($blocked, 'released PWP bill blocks receipt correction with a clear conflict');
    $invoiceAfter = qaOne($pdo, 'SELECT status, amount_paid, amount_due FROM billing_invoices
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $invoiceId]);
    $lineAfter = qaOne($pdo, 'SELECT match_status, matched_je_id FROM accounting_bank_statement_lines
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $lineId]);
    $journalAfter = qaOne($pdo, 'SELECT status, reversed_by_je_id FROM accounting_journal_entries
        WHERE tenant_id = :t AND id = :id',
        ['t' => QA_TENANT, 'id' => (int) $lineBefore['matched_je_id']]);
    $correctionsAfter = (int) qaOne($pdo, 'SELECT COUNT(*) AS n FROM billing_receipt_corrections
        WHERE tenant_id = :t AND bank_line_id = :id',
        ['t' => QA_TENANT, 'id' => $lineId])['n'];
    qaExpect($invoiceAfter === $invoiceBefore && $lineAfter === $lineBefore
        && $journalAfter === $journalBefore
        && $correctionsAfter === $correctionsBefore
        && qaBalances($pdo, $entityId) === $balancesBefore,
        'refused correction leaves invoice, bank match, journal, and GL unchanged');
    qaExpect($invoiceAfter['status'] === 'paid'
        && abs((float) $invoiceAfter['amount_due']) < 0.005
        && $lineAfter['match_status'] === 'matched'
        && qaDelta($before, $balancesBefore, QA_BANK_CODE, 73.25)
        && qaDelta($before, $balancesBefore, '1100', 0)
        && qaDelta($before, $balancesBefore, '2000', -41.50)
        && qaDelta($before, $balancesBefore, '4000', -73.25)
        && qaDelta($before, $balancesBefore, '5000', 41.50)
        && abs(array_sum($balancesBefore)) < 0.005,
        'collected invoice and separately approved bill balance on the shared GL');

    $lateBill = qaRequest('/modules/ap/api/bills.php', 'POST', [
        'entity_id' => $entityId, 'vendor_name' => 'Synthetic PWP Vendor ' . $run,
        'vendor_type' => 'other', 'bill_number' => 'PWP-LATE-' . $run,
        'bill_date' => $date, 'received_at' => $date, 'due_date' => '2026-11-06',
        'currency' => 'USD', 'tax_rate_pct' => 0,
        'notes_internal' => 'Synthetic PWP late-link guard ' . $run,
        'lines' => [['description' => 'Invented later subcontract service', 'quantity' => 1,
            'unit' => 'each', 'unit_price' => 5.00, 'item_type' => 'expense',
            'gl_expense_account_code' => '5000']],
    ], $makerCookie);
    $lateBillId = (int) ($lateBill['id'] ?? 0);
    qaExpect($lateBillId > 0, 'a second synthetic bill arrives after customer collection');
    $lateLink = qaRequest('/modules/ap/api/pwp.php?action=link', 'POST', [
        'bill_id' => $lateBillId, 'ar_invoice_id' => $invoiceId, 'payment_terms' => 'PWP',
    ], $makerCookie);
    $lateState = qaOne($pdo, 'SELECT status, pwp_status, approved_at, pwp_released_at
        FROM ap_bills WHERE tenant_id = :t AND id = :id',
        ['t' => QA_TENANT, 'id' => $lateBillId]);
    qaExpect(count($lateLink['released'] ?? []) === 1
        && (int) ($lateLink['released'][0]['bill_id'] ?? 0) === $lateBillId
        && $lateState['pwp_status'] === 'triggered'
        && $lateState['pwp_released_at'] !== null
        && $lateState['status'] === 'pending_approval'
        && $lateState['approved_at'] === null
        && qaBalances($pdo, $entityId) === $balancesBefore,
        'late link releases the collection hold without approving or posting the bill');

    echo json_encode(['run' => $run, 'invoice_id' => $invoiceId, 'bill_id' => $billId,
        'late_bill_id' => $lateBillId, 'bank_line_id' => $lineId,
        'receipt_je_id' => (int) $lineBefore['matched_je_id']],
        JSON_PRETTY_PRINT), "\n";
} finally {
    foreach ($cookies as $cookie) if (is_string($cookie) && file_exists($cookie)) unlink($cookie);
    foreach ([$maker, $reviewer] as $actor) {
        if ($actor && isset($actor['id'])) {
            $pdo->prepare('UPDATE users SET is_active = 0, password_hash = NULL
                WHERE id = :id AND tenant_id = :t')
                ->execute(['id' => $actor['id'], 't' => QA_TENANT]);
        }
    }
}
