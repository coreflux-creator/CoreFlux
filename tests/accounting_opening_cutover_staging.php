<?php
/** Hosted synthetic-only acceptance for first-run cash, AR, and AP cutover. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--execute') {
    fwrite(STDERR, "Run from CLI with --execute on the isolated CoreFlux staging host only.\n");
    exit(2);
}

define('QA_LIFECYCLE_LIBRARY_MODE', true);
require_once __DIR__ . '/accounting_staging_lifecycle.php';
require_once __DIR__ . '/../modules/accounting/lib/standard_reports.php';

$actor = null;
$cookie = null;
$reviewer = null;
$reviewerCookie = null;
try {
    $run = gmdate('ymdHis') . bin2hex(random_bytes(2));
    $bankCode = 'SIMC' . $run;
    $entity = accountingCreateEntityWithCalendar($pdo, QA_TENANT, [
        'code' => 'SIM-OPEN-' . $run,
        'legal_name' => 'Synthetic Opening Cutover ' . $run,
        'country' => 'US', 'base_currency' => 'USD', 'entity_type' => 'llc',
        'accounting_basis' => 'accrual', 'fiscal_year_start_month' => 1,
    ], 2026);
    $entityId = (int) $entity['entity_id'];
    qaExpect($entityId > 0, 'fresh synthetic legal entity created');

    $pdo->prepare('INSERT INTO accounting_accounts
        (tenant_id, code, name, account_type, subtype, statement_section, normal_side,
         is_postable, is_system_account, currency, active)
        VALUES (:t, :c, "Synthetic Opening Cash", "asset", "current_asset", "current_assets",
            "debit", 1, 0, "USD", 1)')
        ->execute(['t' => QA_TENANT, 'c' => $bankCode]);
    $pdo->prepare('INSERT INTO accounting_bank_accounts
        (tenant_id, entity_id, name, gl_account_code, bank_name, currency, status)
        VALUES (:t, :e, :name, :code, "Synthetic Bank", "USD", "active")')
        ->execute(['t' => QA_TENANT, 'e' => $entityId,
            'name' => 'Synthetic Opening Bank ' . $run, 'code' => $bankCode]);
    $bankId = (int) $pdo->lastInsertId();

    $actor = qaEnsureActor($pdo, 'opening-cutover');
    $cookie = tempnam(sys_get_temp_dir(), 'cf-open-');
    qaLogin($actor, $cookie);
    $reviewer = qaEnsureActor($pdo, 'opening-cutover-reviewer');
    $reviewerCookie = tempnam(sys_get_temp_dir(), 'cf-open-review-');
    qaLogin($reviewer, $reviewerCookie);
    $abandoned = $pdo->prepare('SELECT p.id FROM ap_payments p
        JOIN accounting_entities e ON e.id = p.entity_id AND e.tenant_id = p.tenant_id
        WHERE p.tenant_id = :t AND p.created_by_user_id = :u AND p.status = "draft"
          AND p.reference LIKE "SIM-OPEN-PAYMENT-%" AND e.code LIKE "SIM-OPEN-%"
          AND p.journal_entry_id IS NULL');
    $abandoned->execute(['t' => QA_TENANT, 'u' => $actor['id']]);
    foreach ($abandoned->fetchAll(PDO::FETCH_COLUMN) as $draftId) {
        qaRequest('/modules/ap/api/payments.php?action=void&id=' . (int) $draftId,
            'POST', ['reason' => 'Unfinished synthetic cutover acceptance attempt'], $cookie);
    }

    $invoiceNumber = 'SIM-OPEN-AR-' . $run;
    $billNumber = 'SIM-OPEN-AP-' . $run;
    $client = 'Synthetic Opening Client ' . $run;
    $vendor = 'Synthetic Opening Vendor ' . $run;
    $balancesCsv = qaCsv([['Account code', 'Balance'], [$bankCode, '1000.00']]);
    $arCsv = qaCsv([['Invoice number', 'Client name', 'Issue date', 'Due date', 'Open amount'],
        [$invoiceNumber, $client, '2025-11-30', '2026-01-15', '150.00']]);
    $apCsv = qaCsv([['Bill number', 'Vendor name', 'Bill date', 'Due date', 'Open amount'],
        [$billNumber, $vendor, '2025-11-30', '2026-01-15', '80.00']]);
    $request = ['entity_id' => $entityId, 'csv' => $balancesCsv,
        'ar_csv' => $arCsv, 'ap_csv' => $apCsv];

    $preview = qaRequest('/modules/accounting/api/opening_balances.php?action=preview',
        'POST', $request, $cookie);
    qaExpect((int) ($preview['error_count'] ?? -1) === 0
        && ($preview['balances']['posting_date'] ?? '') === '2025-12-31'
        && ($preview['ar_total'] ?? '') === '150.00'
        && ($preview['ap_total'] ?? '') === '80.00'
        && ($preview['opening_equity']['amount'] ?? '') === '1070.00'
        && !empty($preview['preview_token']), 'combined preview gives exact source totals and opening equity');
    qaExpect(!qaOne($pdo, 'SELECT id FROM accounting_journal_entries WHERE tenant_id = :t AND entity_id = :e',
        ['t' => QA_TENANT, 'e' => $entityId])
        && !qaOne($pdo, 'SELECT id FROM billing_invoices WHERE tenant_id = :t AND entity_id = :e',
            ['t' => QA_TENANT, 'e' => $entityId])
        && !qaOne($pdo, 'SELECT id FROM ap_bills WHERE tenant_id = :t AND entity_id = :e',
            ['t' => QA_TENANT, 'e' => $entityId]), 'preview has no financial side effects');

    $posted = qaRequest('/modules/accounting/api/opening_balances.php?action=commit',
        'POST', $request + ['preview_token' => $preview['preview_token']], $cookie);
    $invoiceId = (int) ($posted['invoices'][0]['id'] ?? 0);
    $billId = (int) ($posted['bills'][0]['id'] ?? 0);
    qaExpect($invoiceId > 0 && $billId > 0 && (int) ($posted['cutover_id'] ?? 0) > 0
        && empty($posted['idempotent_replay']), 'opening source documents committed atomically');
    $journalCount = (int) qaOne($pdo, 'SELECT COUNT(*) AS n FROM accounting_journal_entries
        WHERE tenant_id = :t AND entity_id = :e', ['t' => QA_TENANT, 'e' => $entityId])['n'];
    qaExpect($journalCount === 3, 'cash, open AR, and open AP each have one journal');
    $replay = qaRequest('/modules/accounting/api/opening_balances.php?action=commit',
        'POST', $request + ['preview_token' => $preview['preview_token']], $cookie);
    qaExpect(!empty($replay['idempotent_replay'])
        && (int) $replay['cutover_id'] === (int) $posted['cutover_id']
        && (int) qaOne($pdo, 'SELECT COUNT(*) AS n FROM accounting_journal_entries
            WHERE tenant_id = :t AND entity_id = :e', ['t' => QA_TENANT, 'e' => $entityId])['n'] === 3,
        'exact cutover retry creates no duplicate documents or journals');

    $openingInvoice = qaOne($pdo, 'SELECT status, amount_due, sent_at, journal_entry_id, opening_cutover_id
        FROM billing_invoices WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $invoiceId]);
    $openingBill = qaOne($pdo, 'SELECT status, amount_due, journal_entry_id, opening_cutover_id
        FROM ap_bills WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $billId]);
    qaExpect($openingInvoice['status'] === 'approved' && $openingInvoice['sent_at'] === null
        && abs((float) $openingInvoice['amount_due'] - 150) < 0.005
        && (int) $openingInvoice['opening_cutover_id'] === (int) $posted['cutover_id']
        && (int) $openingInvoice['journal_entry_id'] > 0
        && $openingBill['status'] === 'approved'
        && abs((float) $openingBill['amount_due'] - 80) < 0.005
        && (int) $openingBill['opening_cutover_id'] === (int) $posted['cutover_id']
        && (int) $openingBill['journal_entry_id'] > 0,
        'opening documents are collectible/payable but not sent to a customer');
    $openingBalances = qaBalances($pdo, $entityId);
    foreach ([$bankCode => 1000, '1100' => 150, '2000' => -80, '3000' => -1070] as $code => $value) {
        qaExpect(abs((float) ($openingBalances[$code] ?? 0) - $value) < 0.005,
            "opening GL {$code} = {$value}");
    }
    $openingReports = qaReports($entityId, $cookie);
    qaExpect(!empty($openingReports['balance_sheet']['balanced'])
        && abs((float) $openingReports['balance_sheet']['total_assets'] - 1150) < 0.005
        && abs((float) $openingReports['balance_sheet']['total_liabilities'] - 80) < 0.005
        && abs((float) $openingReports['balance_sheet']['total_equity'] - 1070) < 0.005,
        'opening balance sheet balances with source-owned AR and AP');
    qaExpect(abs((float) $openingReports['income_statement']['total_revenue']) < 0.005
        && abs((float) $openingReports['income_statement']['total_expense']) < 0.005,
        'opening source documents do not create current-year revenue or expense');

    $date = '2026-10-07';
    $receiptRequest = [
        'bank_account_id' => $bankId, 'client_name' => $client, 'received_at' => $date,
        'amount' => 40, 'currency' => 'USD', 'method' => 'other',
        'reference' => 'SIM-OPEN-RECEIPT-' . $run,
        'request_key' => 'sim_open_receipt_' . $run,
        'allocations' => [['invoice_id' => $invoiceId, 'amount' => 40]],
    ];
    $receipt = qaRequest('/modules/billing/api/payments.php', 'POST', $receiptRequest, $cookie);
    qaExpect((int) ($receipt['journal_entry_id'] ?? 0) > 0, 'partial receipt allocated to opening invoice');
    $receiptReplay = qaRequest('/modules/billing/api/payments.php', 'POST', $receiptRequest, $cookie);
    qaExpect(!empty($receiptReplay['idempotent_replay'])
        && (int) $receiptReplay['journal_entry_id'] === (int) $receipt['journal_entry_id'],
        'opening invoice receipt retry is idempotent');

    $payment = qaRequest('/modules/ap/api/payments.php', 'POST', [
        'entity_id' => $entityId, 'bank_account_id' => $bankId,
        'vendor_name' => $vendor, 'pay_date' => $date, 'method' => 'check',
        'reference' => 'SIM-OPEN-PAYMENT-' . $run,
        'amount' => 30, 'currency' => 'USD',
    ], $cookie);
    $paymentId = (int) ($payment['id'] ?? 0);
    qaExpect($paymentId > 0, 'manual payment drafted for opening bill');
    $allocation = qaRequest('/modules/ap/api/payments.php?action=allocate&id=' . $paymentId,
        'POST', ['allocations' => [['bill_id' => $billId, 'amount' => 30]]], $cookie);
    qaExpect(abs((float) ($allocation['unallocated_remaining'] ?? -1)) < 0.005,
        'manual payment allocated to opening bill');
    qaRequest('/modules/ap/api/payments.php?action=send&id=' . $paymentId,
        'POST', [], $reviewerCookie);
    $cleared = qaRequest('/modules/ap/api/payments.php?action=clear&id=' . $paymentId,
        'POST', ['bank_account_id' => $bankId, 'cleared_date' => $date], $reviewerCookie);
    qaExpect((int) ($cleared['journal_entry_id'] ?? 0) > 0,
        'manual opening-bill payment cleared without a payment rail');
    $paymentRow = qaOne($pdo, 'SELECT disbursement_rail, rail_external_ref FROM ap_payments
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $paymentId]);
    qaExpect($paymentRow['disbursement_rail'] === null && $paymentRow['rail_external_ref'] === null,
        'no external payment rail was invoked');

    $invoice = qaOne($pdo, 'SELECT amount_paid, amount_due FROM billing_invoices
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $invoiceId]);
    $bill = qaOne($pdo, 'SELECT amount_paid, amount_due FROM ap_bills
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $billId]);
    qaExpect(abs((float) $invoice['amount_paid'] - 40) < 0.005
        && abs((float) $invoice['amount_due'] - 110) < 0.005
        && abs((float) $bill['amount_paid'] - 30) < 0.005
        && abs((float) $bill['amount_due'] - 50) < 0.005,
        'partial receipt and payment leave correct source balances');
    $agingAr = billingComputeAging(QA_TENANT, $date, $entityId);
    $agingAp = apComputeAging(QA_TENANT, $date, $entityId);
    qaExpect(count($agingAr) === 1 && abs((float) $agingAr[0]['total_due'] - 110) < 0.005
        && count($agingAp) === 1 && abs((float) $agingAp[0]['total_due'] - 50) < 0.005,
        'AR and AP aging agree with partially settled source documents');
    $balances = qaBalances($pdo, $entityId);
    foreach ([$bankCode => 1010, '1100' => 110, '2000' => -50, '3000' => -1070] as $code => $value) {
        qaExpect(abs((float) ($balances[$code] ?? 0) - $value) < 0.005,
            "settled GL {$code} = {$value}");
    }
    qaExpect(abs(array_sum($balances)) < 0.005, 'all entity journal lines balance');
    $reports = qaReports($entityId, $cookie);
    qaExpect(!empty($reports['balance_sheet']['balanced'])
        && abs((float) $reports['balance_sheet']['total_assets'] - 1120) < 0.005
        && abs((float) $reports['balance_sheet']['total_liabilities'] - 50) < 0.005
        && abs((float) $reports['balance_sheet']['total_equity'] - 1070) < 0.005,
        'balance sheet agrees with remaining AR, AP, and cash');
    qaExpect(abs((float) $reports['income_statement']['total_revenue']) < 0.005
        && abs((float) $reports['income_statement']['total_expense']) < 0.005,
        'settling opening documents adds no revenue or expense');
    qaExpect(!empty($reports['cash_flow_indirect']['balanced'])
        && abs((float) $reports['cash_flow_indirect']['net_change_in_cash'] - 10) < 0.005
        && abs((float) $reports['cash_flow_indirect']['cash_change_from_gl'] - 10) < 0.005
        && abs((float) $reports['cash_flow_indirect']['reconciliation_diff']) < 0.005,
        'cash flow reflects only current-year net receipt less payment');
    echo "Synthetic cutover entity {$entityId}; invoice {$invoiceId}; bill {$billId}; payment {$paymentId}.\n";
} finally {
    if ($actor) {
        $pdo->prepare('UPDATE users SET is_active = 0 WHERE tenant_id = :t AND id = :id')
            ->execute(['t' => QA_TENANT, 'id' => $actor['id']]);
    }
    if ($reviewer) {
        $pdo->prepare('UPDATE users SET is_active = 0 WHERE tenant_id = :t AND id = :id')
            ->execute(['t' => QA_TENANT, 'id' => $reviewer['id']]);
    }
    if ($cookie && is_file($cookie)) unlink($cookie);
    if ($reviewerCookie && is_file($reviewerCookie)) unlink($reviewerCookie);
}
