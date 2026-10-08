<?php
/** Invented CSV receipt through deposit, invoice application and bank match. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--execute') {
    fwrite(STDERR, "Run only on isolated CoreFlux staging with --execute.\n");
    exit(2);
}
define('QA_LIFECYCLE_LIBRARY_MODE', true);
require_once __DIR__ . '/accounting_staging_lifecycle.php';

function qaImportedReceiptCsv(string $client, string $externalId, string $amount): string
{
    return qaCsv([
        ['client_name', 'received_at', 'method', 'reference', 'external_id',
            'source_system', 'amount', 'currency'],
        [$client, '2026-10-08', 'ach', $externalId, $externalId,
            'qbo', $amount, 'USD'],
    ]);
}

function qaImportedReceiptListRow(int $paymentId, string $client, string $cookie): ?array
{
    $list = qaRequest('/modules/billing/api/payments.php?client_name=' . rawurlencode($client),
        'GET', null, $cookie);
    foreach (($list['rows'] ?? []) as $row) {
        if ((int) ($row['id'] ?? 0) === $paymentId) return $row;
    }
    return null;
}

$maker = null;
$reviewer = null;
$cookies = [];
try {
    $maker = qaEnsureActor($pdo, 'imported-receipt-maker');
    $reviewer = qaEnsureActor($pdo, 'imported-receipt-reviewer');
    $makerCookie = tempnam(sys_get_temp_dir(), 'cf-qa-receipt-maker-');
    $reviewerCookie = tempnam(sys_get_temp_dir(), 'cf-qa-receipt-reviewer-');
    $cookies = array_values(array_filter([$makerCookie, $reviewerCookie], 'is_string'));
    if ($makerCookie === false || $reviewerCookie === false) {
        throw new RuntimeException('Could not create synthetic sessions');
    }
    qaLogin($maker, $makerCookie);
    qaLogin($reviewer, $reviewerCookie);

    $entity = qaOne($pdo, 'SELECT id FROM accounting_entities
        WHERE tenant_id = :t AND code = :code AND active = 1',
        ['t' => QA_TENANT, 'code' => QA_ENTITY_CODE]);
    $entityId = (int) ($entity['id'] ?? 0);
    $bank = qaOne($pdo, 'SELECT id FROM accounting_bank_accounts
        WHERE tenant_id = :t AND entity_id = :e AND gl_account_code = :code AND status = "active"',
        ['t' => QA_TENANT, 'e' => $entityId, 'code' => QA_BANK_CODE]);
    $bankId = (int) ($bank['id'] ?? 0);
    if ($entityId <= 0 || $bankId <= 0) throw new RuntimeException('Synthetic entity or bank unavailable');
    $before = qaBalances($pdo, $entityId);
    $run = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
    $client = 'Invented Imported Receipt ' . $run;
    $externalId = 'SYN-QBO-RECEIPT-' . $run;

    $invoice = qaRequest('/modules/billing/api/invoices.php', 'POST', [
        'entity_id' => $entityId, 'client_name' => $client,
        'issue_date' => '2026-10-08', 'due_date' => '2026-11-07',
        'currency' => 'USD', 'tax_rate_pct' => 0,
        'lines' => [['description' => 'Invented service', 'quantity' => 1,
            'unit' => 'each', 'unit_price' => 30, 'item_type' => 'fixed_fee',
            'gl_revenue_account_code' => '4000']],
    ], $makerCookie);
    $invoiceId = (int) ($invoice['id'] ?? 0);
    qaExpect($invoiceId > 0, 'invented $30 invoice draft created');
    qaRequest('/modules/billing/api/invoices.php?action=request_approval&id=' . $invoiceId,
        'POST', [], $makerCookie);
    qaRequest('/modules/billing/api/approval_assignment.php', 'POST', [
        'invoice_id' => $invoiceId, 'reviewer_user_ids' => [(int) $reviewer['id']],
    ], $makerCookie);
    qaRequest('/modules/billing/api/invoices.php?action=approve&id=' . $invoiceId,
        'POST', [], $reviewerCookie);
    $invoicePost = qaRequest('/modules/billing/api/invoices.php?action=post&id=' . $invoiceId,
        'POST', [], $reviewerCookie);
    qaExpect((int) ($invoicePost['journal_entry_id'] ?? 0) > 0
        && qaDelta($before, qaBalances($pdo, $entityId), '1100', 30),
        'independent review posted the invoice as a receivable');
    $afterInvoice = qaBalances($pdo, $entityId);

    $badPreview = qaRequest('/modules/billing/api/payments_csv_import.php?action=dry_run',
        'POST', ['csv' => qaImportedReceiptCsv($client, $externalId, '30.001')], $makerCookie);
    qaExpect((int) ($badPreview['error_count'] ?? 0) > 0,
        'receipt CSV preview rejects sub-cent amounts');

    $import = qaRequest('/modules/billing/api/payments_csv_import.php?action=commit',
        'POST', ['csv' => qaImportedReceiptCsv($client, $externalId, '30.00')], $makerCookie);
    $payment = qaOne($pdo, 'SELECT id, source_system, amount, journal_entry_id, bank_account_id
        FROM billing_payments WHERE tenant_id = :t AND source_system = "qbo" AND external_id = :external_id',
        ['t' => QA_TENANT, 'external_id' => $externalId]);
    $paymentId = (int) ($payment['id'] ?? 0);
    qaExpect((int) ($import['imported_count'] ?? 0) === 1 && $paymentId > 0
        && ($payment['source_system'] ?? '') === 'qbo'
        && $payment['journal_entry_id'] === null && $payment['bank_account_id'] === null
        && $afterInvoice === qaBalances($pdo, $entityId),
        'external-source CSV receipt remains pending without a cash journal');
    $pendingRow = qaImportedReceiptListRow($paymentId, $client, $makerCookie);
    qaExpect(($pendingRow['receipt_state'] ?? '') === 'pending'
        && empty($pendingRow['can_apply_deposit']) && empty($pendingRow['can_correct']),
        'pending import exposes posting but no deposit or correction action');

    $posted = qaRequest('/modules/billing/api/payments.php?action=post&id=' . $paymentId,
        'POST', ['bank_account_id' => $bankId, 'hold_unapplied' => true], $makerCookie);
    $receiptJeId = (int) ($posted['journal_entry_id'] ?? 0);
    $afterDeposit = qaBalances($pdo, $entityId);
    qaExpect($receiptJeId > 0
        && qaDelta($afterInvoice, $afterDeposit, QA_BANK_CODE, 30)
        && qaDelta($afterInvoice, $afterDeposit, '2300', -30)
        && qaDelta($afterInvoice, $afterDeposit, '1100', 0),
        'operator posts imported receipt once to cash and customer deposit');
    $depositRow = qaImportedReceiptListRow($paymentId, $client, $makerCookie);
    qaExpect(($depositRow['receipt_state'] ?? '') === 'posted'
        && !empty($depositRow['can_apply_deposit'])
        && !empty($depositRow['can_refund_deposit'])
        && !empty($depositRow['can_correct']),
        'posted imported deposit exposes apply, refund and correction actions');

    $update = qaRequest('/modules/billing/api/payments_csv_import.php?action=commit&update_existing=1',
        'POST', ['csv' => qaImportedReceiptCsv($client, $externalId, '31.00')], $makerCookie);
    $unchanged = qaOne($pdo, 'SELECT amount, journal_entry_id, unallocated_amount
        FROM billing_payments WHERE tenant_id = :t AND id = :id',
        ['t' => QA_TENANT, 'id' => $paymentId]);
    qaExpect((int) ($update['updated_count'] ?? 0) === 0
        && !empty($update['errors'])
        && abs((float) ($unchanged['amount'] ?? 0) - 30) < 0.005
        && abs((float) ($unchanged['unallocated_amount'] ?? 0) - 30) < 0.005
        && (int) ($unchanged['journal_entry_id'] ?? 0) === $receiptJeId
        && qaBalances($pdo, $entityId) === $afterDeposit,
        'CSV cannot alter a posted but still unapplied receipt');

    $application = qaRequest('/modules/billing/api/payments.php?action=apply_deposit&id=' . $paymentId,
        'POST', ['invoice_id' => $invoiceId, 'amount' => 30,
            'applied_at' => '2026-10-08', 'request_key' => 'syn_apply_' . $run], $makerCookie);
    $paid = qaOne($pdo, 'SELECT status, amount_paid, amount_due FROM billing_invoices
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $invoiceId]);
    $afterApplication = qaBalances($pdo, $entityId);
    qaExpect((int) ($application['journal_entry_id'] ?? 0) > 0
        && ($paid['status'] ?? '') === 'paid'
        && abs((float) ($paid['amount_paid'] ?? 0) - 30) < 0.005
        && abs((float) ($paid['amount_due'] ?? 0)) < 0.005
        && qaDelta($afterDeposit, $afterApplication, '2300', 30)
        && qaDelta($afterDeposit, $afterApplication, '1100', -30),
        'imported customer deposit applies to the posted invoice');
    $appliedRow = qaImportedReceiptListRow($paymentId, $client, $makerCookie);
    qaExpect(($appliedRow['receipt_state'] ?? '') === 'posted'
        && empty($appliedRow['can_apply_deposit'])
        && empty($appliedRow['can_refund_deposit'])
        && empty($appliedRow['can_correct'])
        && !empty($appliedRow['has_deposit_activity']),
        'applied imported deposit shows activity without stale action buttons');

    $fitid = 'SYN-QBO-BANK-' . $run;
    qaRequest('/modules/accounting/api/bank_statements.php?action=import_csv&bank_account_id=' . $bankId,
        'POST', ['csv' => qaCsv([
            ['Date', 'Description', 'Amount', 'Transaction ID'],
            ['2026-10-08', 'Invented imported receipt bank line', '30.00', $fitid],
        ])], $makerCookie);
    $line = qaOne($pdo, 'SELECT id, match_status FROM accounting_bank_statement_lines
        WHERE tenant_id = :t AND bank_account_id = :bank AND fitid = :fitid',
        ['t' => QA_TENANT, 'bank' => $bankId, 'fitid' => $fitid]);
    $lineId = (int) ($line['id'] ?? 0);
    if ($lineId <= 0) throw new RuntimeException('Synthetic bank line unavailable');
    $matchPath = '/modules/accounting/api/bank_statements.php?action=match&line_id=' . $lineId;
    $match = qaRequest($matchPath, 'POST', ['je_id' => $receiptJeId], $reviewerCookie);
    $replay = qaRequest($matchPath, 'POST', ['je_id' => $receiptJeId], $reviewerCookie);
    $matched = qaOne($pdo, 'SELECT match_status, matched_je_id FROM accounting_bank_statement_lines
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $lineId]);
    qaExpect((int) ($match['je_id'] ?? 0) === $receiptJeId
        && !empty($replay['idempotent_replay'])
        && ($matched['match_status'] ?? '') === 'matched'
        && (int) ($matched['matched_je_id'] ?? 0) === $receiptJeId
        && qaBalances($pdo, $entityId) === $afterApplication,
        'bank reconciliation reuses the imported receipt journal without posting cash twice');
    $matchedList = qaRequest('/modules/accounting/api/bank_statements.php?bank_account_id=' . $bankId
        . '&match_status=matched&q=' . rawurlencode($fitid), 'GET', null, $reviewerCookie);
    $matchedRow = null;
    foreach (($matchedList['rows'] ?? []) as $row) {
        if ((int) ($row['id'] ?? 0) === $lineId) $matchedRow = $row;
    }
    qaExpect($matchedRow !== null
        && (int) ($matchedRow['applied_invoices'][0]['id'] ?? 0) === $invoiceId
        && abs((float) ($matchedRow['applied_invoices'][0]['amount'] ?? 0) - 30) < 0.005
        && empty($matchedRow['can_correct_receipt']),
        'matched bank row links the imported receipt invoice without offering a bank-owned reversal');
    qaExpect(qaDelta($before, $afterApplication, QA_BANK_CODE, 30)
        && qaDelta($before, $afterApplication, '1100', 0)
        && qaDelta($before, $afterApplication, '2300', 0)
        && qaDelta($before, $afterApplication, '4000', -30),
        'invoice, deposit and bank match leave a balanced $30 net cash/revenue movement');

    echo json_encode(['run' => $run, 'invoice_id' => $invoiceId,
        'payment_id' => $paymentId, 'receipt_journal_id' => $receiptJeId,
        'bank_line_id' => $lineId], JSON_PRETTY_PRINT), "\n";
} finally {
    foreach ($cookies as $cookie) if (file_exists($cookie)) unlink($cookie);
    foreach ([$maker, $reviewer] as $actor) {
        if ($actor && isset($actor['id'])) {
            $pdo->prepare('UPDATE users SET is_active = 0, password_hash = NULL
                WHERE id = :id AND tenant_id = :t')
                ->execute(['id' => $actor['id'], 't' => QA_TENANT]);
        }
    }
}
