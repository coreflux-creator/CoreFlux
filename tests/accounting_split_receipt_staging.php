<?php
/** Synthetic-only split bank receipt, correction, and replay acceptance. */
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
$otherIncome = qaOne($pdo, 'SELECT id FROM accounting_accounts
    WHERE tenant_id = :t AND code = :code AND active = 1 AND is_postable = 1',
    ['t' => QA_TENANT, 'code' => '4000']);
if ($entityId <= 0 || $bankId <= 0 || !$otherIncome) {
    throw new RuntimeException('Synthetic lifecycle entity, bank, and income account must exist first.');
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

    $run = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
    $date = '2026-10-07';
    $before = qaBalances($pdo, $entityId);
    $invoiceIds = [];
    foreach ([['A', 61.25], ['B', 48.75]] as [$suffix, $price]) {
        $draft = qaRequest('/modules/billing/api/invoices.php', 'POST', [
            'entity_id' => $entityId, 'client_name' => 'Synthetic Split Client ' . $suffix . ' ' . $run,
            'issue_date' => $date, 'due_date' => '2026-11-06', 'currency' => 'USD',
            'tax_rate_pct' => 0, 'notes_internal' => 'Synthetic split receipt ' . $run,
            'lines' => [['description' => 'Invented service', 'quantity' => 1, 'unit' => 'each',
                'unit_price' => $price, 'item_type' => 'fixed_fee', 'gl_revenue_account_code' => '4000']],
        ], $makerCookie);
        $invoiceId = (int) ($draft['id'] ?? 0);
        qaExpect($invoiceId > 0, 'synthetic invoice ' . $suffix . ' drafted');
        qaRequest('/modules/billing/api/invoices.php?action=request_approval&id=' . $invoiceId,
            'POST', [], $makerCookie);
        qaRequest('/modules/billing/api/approval_assignment.php', 'POST', [
            'invoice_id' => $invoiceId, 'reviewer_user_ids' => [(int) $reviewer['id']],
        ], $makerCookie);
        qaRequest('/modules/billing/api/invoices.php?action=approve&id=' . $invoiceId,
            'POST', [], $reviewerCookie);
        $posted = qaRequest('/modules/billing/api/invoices.php?action=post&id=' . $invoiceId,
            'POST', [], $reviewerCookie);
        qaExpect((int) ($posted['journal_entry_id'] ?? 0) > 0,
            'synthetic invoice ' . $suffix . ' posted through the canonical ledger');
        $invoiceIds[] = $invoiceId;
    }
    $afterInvoices = qaBalances($pdo, $entityId);

    $fitid = 'SIM-SPLIT-RECEIPT-' . $run;
    $csv = qaCsv([
        ['Date', 'Description', 'Amount', 'Transaction ID'],
        [$date, 'Synthetic split client deposit ' . $run, '75.00', $fitid],
    ]);
    $import = qaRequest('/modules/accounting/api/bank_statements.php?action=import_csv&bank_account_id=' .
        $bankId, 'POST', ['csv' => $csv], $makerCookie);
    qaExpect((int) ($import['inserted'] ?? 0) === 1, 'one invented bank deposit imported');
    $line = qaOne($pdo, 'SELECT id FROM accounting_bank_statement_lines
        WHERE tenant_id = :t AND bank_account_id = :bank AND fitid = :fitid',
        ['t' => QA_TENANT, 'bank' => $bankId, 'fitid' => $fitid]);
    $lineId = (int) ($line['id'] ?? 0);
    qaExpect($lineId > 0, 'invented bank line found');
    $path = '/modules/accounting/api/bank_statements.php?action=split_match_invoices&line_id=' . $lineId;
    $first = [
        'allocations' => [
            ['invoice_id' => $invoiceIds[0], 'amount' => 31.25],
            ['invoice_id' => $invoiceIds[1], 'amount' => 38.75],
        ],
        'account_splits' => [['account_id' => (int) $otherIncome['id'],
            'amount' => 5.00, 'memo' => 'Invented other income']],
    ];
    $invalid = $first;
    $invalid['allocations'][0]['amount'] = 31.26;
    try {
        qaRequest($path, 'POST', $invalid, $reviewerCookie);
        $totalRejected = false;
    } catch (RuntimeException $error) {
        $totalRejected = str_contains($error->getMessage(), 'HTTP 422');
    }
    qaExpect($totalRejected && qaOne($pdo, 'SELECT match_status FROM accounting_bank_statement_lines
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $lineId])['match_status'] === 'unmatched'
        && qaBalances($pdo, $entityId) === $afterInvoices
        && (int) qaOne($pdo, 'SELECT COUNT(*) AS n FROM billing_payments
            WHERE tenant_id = :t AND external_id LIKE :prefix',
            ['t' => QA_TENANT, 'prefix' => 'bank-line:' . $lineId . ':%'])['n'] === 0,
        'off-by-one-cent split leaves the bank line, payments, and GL untouched');

    $matched = qaRequest($path, 'POST', $first, $reviewerCookie);
    $jeId = (int) ($matched['matched_je_id'] ?? 0);
    $paymentIds = array_map('intval', $matched['payment_ids'] ?? []);
    qaExpect($jeId > 0 && count($paymentIds) === 2
        && abs((float) ($matched['invoice_total'] ?? 0) - 70) < 0.005
        && abs((float) ($matched['account_total'] ?? 0) - 5) < 0.005,
        'one bank deposit becomes two client payments and one balanced journal');
    $balancesMatched = qaBalances($pdo, $entityId);
    qaExpect(qaDelta($before, $balancesMatched, QA_BANK_CODE, 75)
        && qaDelta($before, $balancesMatched, '1100', 40)
        && qaDelta($before, $balancesMatched, '4000', -115)
        && abs(array_sum($balancesMatched)) < 0.005,
        'cash, AR, and revenue agree with both invoices and the split receipt');
    foreach ([$invoiceIds[0] => 30.00, $invoiceIds[1] => 10.00] as $id => $due) {
        $row = qaOne($pdo, 'SELECT amount_due, status FROM billing_invoices
            WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $id]);
        qaExpect(abs((float) $row['amount_due'] - $due) < 0.005
            && $row['status'] === 'partially_paid', 'invoice ' . $id . ' has its own remaining balance');
    }
    $replayed = qaRequest($path, 'POST', $first, $reviewerCookie);
    qaExpect((int) ($replayed['matched_je_id'] ?? 0) === $jeId
        && array_map('intval', $replayed['payment_ids'] ?? []) === $paymentIds,
        'identical split retry returns the original journal and payment IDs');
    $changed = $first;
    $changed['allocations'][0]['amount'] = 25.00;
    $changed['allocations'][1]['amount'] = 45.00;
    try {
        qaRequest($path, 'POST', $changed, $reviewerCookie);
        $changedRejected = false;
    } catch (RuntimeException $error) {
        $changedRejected = str_contains($error->getMessage(), 'HTTP 409');
    }
    qaExpect($changedRejected, 'changed allocation cannot replay an already-matched bank line');

    $correctPath = '/modules/accounting/api/bank_statements.php?action=reverse_receipt&line_id=' . $lineId;
    $corrected = qaRequest($correctPath, 'POST', [
        'reason' => 'Synthetic correction of invented split receipt',
    ], $reviewerCookie);
    $reversalJeId = (int) ($corrected['reversal_je_id'] ?? 0);
    $lineAfter = qaOne($pdo, 'SELECT match_status, matched_je_id FROM accounting_bank_statement_lines
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $lineId]);
    $reversedJournal = qaOne($pdo, 'SELECT status, reversed_by_je_id
        FROM accounting_journal_entries WHERE tenant_id = :t AND id = :id',
        ['t' => QA_TENANT, 'id' => $jeId]);
    $balancesCorrected = qaBalances($pdo, $entityId);
    qaExpect($reversalJeId > 0 && $lineAfter['match_status'] === 'unmatched'
        && $lineAfter['matched_je_id'] === null
        && $reversedJournal['status'] === 'reversed'
        && (int) $reversedJournal['reversed_by_je_id'] === $reversalJeId
        && qaDelta($afterInvoices, $balancesCorrected, QA_BANK_CODE, 0)
        && qaDelta($afterInvoices, $balancesCorrected, '1100', 0)
        && qaDelta($afterInvoices, $balancesCorrected, '4000', 0)
        && abs(array_sum($balancesCorrected)) < 0.005,
        'correction reopens the bank line and reverses all cash, AR, and income movement');
    foreach ([$invoiceIds[0] => 61.25, $invoiceIds[1] => 48.75] as $id => $due) {
        $row = qaOne($pdo, 'SELECT amount_paid, amount_due, status FROM billing_invoices
            WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $id]);
        qaExpect(abs((float) $row['amount_paid']) < 0.005
            && abs((float) $row['amount_due'] - $due) < 0.005
            && $row['status'] === 'approved', 'correction restores invoice ' . $id);
    }
    $activePayments = (int) qaOne($pdo, 'SELECT COUNT(*) AS n FROM billing_payments
        WHERE tenant_id = :t AND id IN (' . implode(',', $paymentIds) . ') AND voided_at IS NULL',
        ['t' => QA_TENANT])['n'];
    qaExpect($activePayments === 0, 'both original client payments are voided');
    try {
        qaRequest($correctPath, 'POST', ['reason' => 'Duplicate synthetic correction'], $reviewerCookie);
        $duplicateRejected = false;
    } catch (RuntimeException $error) {
        $duplicateRejected = str_contains($error->getMessage(), 'HTTP 409');
    }
    qaExpect($duplicateRejected, 'a corrected split cannot be reversed twice');

    $rematched = qaRequest($path, 'POST', $changed, $reviewerCookie);
    $secondJeId = (int) ($rematched['matched_je_id'] ?? 0);
    qaExpect($secondJeId > 0 && $secondJeId !== $jeId
        && count($rematched['payment_ids'] ?? []) === 2,
        'corrected deposit can be re-applied with revised allocations');
    $balancesRematched = qaBalances($pdo, $entityId);
    qaExpect(qaDelta($afterInvoices, $balancesRematched, QA_BANK_CODE, 75)
        && qaDelta($afterInvoices, $balancesRematched, '1100', -70)
        && qaDelta($afterInvoices, $balancesRematched, '4000', -5)
        && abs(array_sum($balancesRematched)) < 0.005,
        're-applied split remains balanced against the original posted invoices');
    $secondReplay = qaRequest($path, 'POST', $changed, $reviewerCookie);
    qaExpect((int) ($secondReplay['matched_je_id'] ?? 0) === $secondJeId,
        'revised split also replays without a duplicate journal');
    $finalLine = qaOne($pdo, 'SELECT match_status, matched_je_id FROM accounting_bank_statement_lines
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $lineId]);
    $secondPaymentIds = array_map('intval', $rematched['payment_ids']);
    $linkedPayments = (int) qaOne($pdo, 'SELECT COUNT(*) AS n FROM billing_payments
        WHERE tenant_id = :t AND id IN (' . implode(',', $secondPaymentIds) . ')
            AND journal_entry_id = :je_id AND voided_at IS NULL',
        ['t' => QA_TENANT, 'je_id' => $secondJeId])['n'];
    qaExpect($finalLine['match_status'] === 'matched'
        && (int) $finalLine['matched_je_id'] === $secondJeId
        && $linkedPayments === 2
        && count(array_intersect($paymentIds, $secondPaymentIds)) === 0,
        'final bank match and both active payments link to the new journal');
    foreach ([$invoiceIds[0] => 36.25, $invoiceIds[1] => 3.75] as $id => $due) {
        $row = qaOne($pdo, 'SELECT amount_due, status FROM billing_invoices
            WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $id]);
        qaExpect(abs((float) $row['amount_due'] - $due) < 0.005
            && $row['status'] === 'partially_paid',
            'revised split leaves invoice ' . $id . ' with the correct open balance');
    }

    echo json_encode(['run' => $run, 'invoice_ids' => $invoiceIds, 'bank_line_id' => $lineId,
        'first_je_id' => $jeId, 'reversal_je_id' => $reversalJeId,
        'reapplied_je_id' => $secondJeId], JSON_PRETTY_PRINT), "\n";
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
