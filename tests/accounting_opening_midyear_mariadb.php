<?php
/** Rollback-only cutover: midyear P&L, signed source items, reporting and replay. */
declare(strict_types=1);

$database = (string) getenv('DB_NAME');
$local = preg_match('/^coreaccounting_blank_test\d+$/D', $database);
$ci = $database === 'coreaccounting_ci' && getenv('CI') === 'true'
    && in_array('--confirm-disposable-fixture', $argv, true);
if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging' || (!$local && !$ci)) {
    fwrite(STDERR, "Requires an isolated local or explicitly confirmed CI staging schema.\n");
    exit(2);
}
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../modules/accounting/lib/opening_cutover.php';
require_once __DIR__ . '/../modules/accounting/lib/standard_reports.php';
require_once __DIR__ . '/../modules/accounting/lib/source_control_tie_out.php';
require_once __DIR__ . '/../modules/billing/lib/statement.php';
require_once __DIR__ . '/../modules/billing/lib/invoice_delivery.php';
require_once __DIR__ . '/../core/accounting/entity_setup.php';
require_once __DIR__ . '/../sim/lib/accounting_snapshot.php';

$pdo = getDB();
if (!$pdo || $pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) {
    throw new RuntimeException('Connected database does not match the disposable schema.');
}
$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};
$reject = static function (callable $call, string $message) use ($assert): void {
    try { $call(); } catch (InvalidArgumentException | AccountingOpeningConflict | DomainException $e) {
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
};
$countState = static function () use ($pdo): array {
    $counts = [];
    foreach (['accounting_journal_entries', 'accounting_opening_document_cutovers', 'billing_invoices',
        'ap_bills', 'companies', 'ap_vendors_index', 'accounting_subledger_links'] as $table) {
        $counts[$table] = (int) $pdo->query("SELECT COUNT(*) FROM {$table} WHERE tenant_id = 1")->fetchColumn();
    }
    return $counts;
};
$baseline = $countState();
$pdo->beginTransaction();
try {
    $entity = accountingCreateEntityWithCalendar($pdo, 1, [
        'code' => 'YTDTEST', 'legal_name' => 'Synthetic Midyear LLC', 'country' => 'US',
        'base_currency' => 'USD', 'entity_type' => 'llc', 'accounting_basis' => 'accrual',
        'fiscal_year_start_month' => 1,
    ], 2026);
    $e = (int) $entity['entity_id'];
    $account = $pdo->prepare(
        'INSERT INTO accounting_accounts (tenant_id, code, name, account_type, normal_side, active, is_postable)
         VALUES (1, :code, :name, :type, :side, 1, 1)'
    );
    foreach (['4981' => ['revenue', 'credit'], '4982' => ['contra_revenue', 'debit'],
        '5981' => ['cost_of_goods_sold', 'debit'], '6981' => ['expense', 'debit'],
        '7981' => ['other_income', 'credit'], '9981' => ['other_expense', 'debit']] as $code => [$type, $side]) {
        $account->execute(['code' => $code, 'name' => 'Synthetic ' . $type, 'type' => $type, 'side' => $side]);
    }
    $balances = "Account code,Balance\n1000,1000.00\n4981,1000.00\n4982,50.00\n"
        . "5981,300.00\n6981,200.00\n7981,20.00\n9981,10.00\n";
    $ar = "Invoice number,Client name,Issue date,Due date,Open amount,Source type,Source reference\n"
        . "YTD-AR,Example Client,2026-09-01,2026-10-15,150.00,Invoice,source-invoice-1\n"
        . "YTD-CR,Example Client,2026-09-02,2026-09-02,-25.00,Payment,source-payment-2\n"
        . "YTD-CR2,Example Client,2026-09-03,2026-09-03,-0.01,Journal Entry,source-journal-3\n";
    $ap = "Bill number,Vendor name,Bill date,Due date,Open amount,Source type,Source reference\n"
        . "YTD-AP,Example Vendor,2026-09-01,2026-10-15,80.00,Bill,source-bill-1\n"
        . "YTD-VC,Example Vendor,2026-09-02,2026-09-02,-15.00,Journal Entry,source-journal-4\n";
    $assert(accountingOpeningAmount(-1) === '-0.01'
        && accountingOpeningAmount(-12345) === '-123.45', 'signed formatting preserves cents');
    $default = accountingOpeningContext($pdo, 1, $e);
    $assert($default['posting_date'] === '2025-12-31' && !$default['midyear'], 'legacy default remains first fiscal day');
    foreach (['2026-02-30', '2025-10-01', '2027-01-01'] as $date) {
        $reject(fn() => accountingOpeningContext($pdo, 1, $e, $date), 'invalid or out-of-calendar date rejected');
    }
    $invalid = accountingOpeningReview($pdo, 1, $e, $balances);
    $assert($invalid['error_count'] > 0, 'year-start cannot import income and expenses');
    $invalid = accountingOpeningCutoverReview($pdo, 1, $e, $balances, $ar, $ap, '2026-10-01');
    $assert($invalid['preview_token'] === null, 'midyear requires explicit cumulative-YTD confirmation');
    $invalid = accountingOpeningCutoverReview($pdo, 1, $e, $balances,
        str_replace('Payment,source-payment-2', ',', $ar), $ap, '2026-10-01', true);
    $assert($invalid['preview_token'] === null, 'credit without source provenance is rejected');
    $protected = accountingOpeningReview($pdo, 1, $e, "Account code,Balance\n1100,10.00\n", '2026-10-01', true);
    $assert($protected['preview_token'] === null, 'midyear mode cannot bypass source-owned AR control');
    $p = accountingOpeningCutoverReview($pdo, 1, $e, $balances, $ar, $ap, '2026-10-01', true);
    $assert($p['error_count'] === 0, 'midyear preview valid: ' . json_encode($p['errors']));
    $assert($countState() === $baseline, 'preview writes no financial or party records');
    $assert($p['balances']['posting_date'] === '2026-09-30'
        && $p['balances']['ytd_net_income'] === '460.00', 'September snapshot includes all income/expense types');
    $assert($p['ar_total'] === '124.99' && $p['ap_total'] === '65.00', 'source totals include every credit');
    $assert($p['opening_equity'] === ['amount' => '599.99', 'negative' => false], 'combined equity includes signed controls and YTD');
    $reject(fn() => accountingOpeningCutoverCommit($pdo, 1, $e, $balances, $ar, $ap,
        $p['preview_token'], null, '2026-10-02', true), 'changing start after review invalidates token');
    $reject(fn() => accountingOpeningCutoverCommit($pdo, 1, $e, $balances,
        str_replace('source-payment-2', 'different-source', $ar), $ap, $p['preview_token'], null, '2026-10-01', true),
        'changing credit provenance invalidates token');
    $assert($countState() === $baseline, 'rejected commits leave no financial records');

    // Force an AP failure after the base and AR journals; the whole import must roll back.
    $pdo->exec('SAVEPOINT before_failure');
    $pdo->exec("INSERT INTO ap_vendors_index (tenant_id, vendor_name, company_id, vendor_type, requires_1099)
        VALUES (1, 'Example Vendor', 2147483647, 'other', 0)");
    $failureBaseline = $countState();
    $reject(fn() => accountingOpeningCutoverCommit($pdo, 1, $e, $balances, $ar, $ap,
        $p['preview_token'], null, '2026-10-01', true), 'late AP validation failure rejects full cutover');
    $assert($countState() === $failureBaseline, 'late failure rolls back base journal, all AR items and created customers');
    $pdo->exec('ROLLBACK TO SAVEPOINT before_failure');

    $posted = accountingOpeningCutoverCommit($pdo, 1, $e, $balances, $ar, $ap,
        $p['preview_token'], null, '2026-10-01', true);
    $assert($posted['ar_count'] === 3 && $posted['ap_count'] === 2, 'all positive and negative source items imported');
    $trial = array_column(accountingTrialBalance(1, '2026-09-30', $e), 'balance_signed', 'code');
    $due = simSnapshotDocumentDue(1, $e, '2026-09-30');
    $assert($due === ['ar_due' => 124.99, 'ap_due' => 65.0], 'aging retains signed credit balances');
    $assert((float) $trial['1100'] === $due['ar_due'] && (float) $trial['2000'] === $due['ap_due'],
        'both subledgers agree with their GL control accounts');
    $assert(accountingSourceControlTieOut(1, $e, '2026-09-30')['matched'],
        'the standard control reconciliation also includes credits');
    $assert(simSnapshotDocumentDue(1, $e, '2026-09-29') === ['ar_due' => 0.0, 'ap_due' => 0.0],
        'original document dates cannot leak balances before cutover');
    $statement = billingStatementOpenInvoices(1, 'Example Client', '2026-09-30', $e);
    $assert(count($statement) === 3 && round(billingStatementBucket($statement)['total'], 2) === 124.99,
        'customer statement includes credits and matches aging');
    $income = reportIncomeStatement(1, '2026-01-01', '2026-09-30', $e);
    $assert($income['total_revenue'] === 970.0 && $income['total_expense'] === 510.0
        && $income['net_income'] === 460.0, 'P&L includes COGS, contra-revenue, other income and expense');
    $assert(reportIncomeStatement(1, '2026-10-01', '2026-10-31', $e)['net_income'] === 0.0,
        'opening YTD activity does not inflate October profit');
    $bs = reportBalanceSheet(1, '2026-09-30', $e);
    $assert($bs['balanced'] && $bs['total_assets'] === 1124.99 && $bs['total_liabilities'] === 65.0
        && $bs['total_equity'] === 1059.99, 'balance sheet includes YTD earnings and both signed controls');
    $assert(reportCurrentFiscalUnclosedEarnings(1, '2026-09-30', $e) === 460.0,
        'current-year earnings retain all P&L account types');
    $credit = $pdo->query("SELECT * FROM billing_invoices WHERE tenant_id = 1 AND invoice_number = 'YTD-CR'")->fetch();
    $assert($credit['total'] === '-25.00' && $credit['amount_due'] === '-25.00'
        && str_contains($credit['notes_internal'], 'Payment')
        && str_contains($credit['notes_internal'], 'source-payment-2'), 'stored credit preserves sign and original source');
    $reject(fn() => billingDeliveryAssertPosted($pdo, 1, $credit), 'opening credit cannot be sent as a new invoice');
    $reject(fn() => apValidateManualBillLines([['quantity' => 1, 'unit_price' => -15]], 0),
        'ordinary manual bill creation still rejects negative prices');
    $b = $pdo->query("SELECT * FROM ap_bills WHERE tenant_id = 1 AND bill_number = 'YTD-VC'")->fetch();
    $assert($b['total'] === '-15.00' && $b['amount_due'] === '-15.00'
        && str_contains($b['notes_internal'], 'source-journal-4'), 'vendor credit preserves sign and provenance');
    apRefreshReleasedPaymentBills($pdo, 1, [(int) $b['id']]);
    $refreshed = $pdo->query('SELECT status, amount_due, amount_paid FROM ap_bills WHERE id = ' . (int) $b['id'])->fetch();
    $assert($refreshed['status'] === 'approved' && $refreshed['amount_due'] === '-15.00'
        && $refreshed['amount_paid'] === '0.00', 'payment refresh cannot falsely mark a credit paid');
    $lines = $pdo->query("SELECT SUM(is_1099_eligible) FROM ap_bill_lines WHERE bill_id = " . (int) $b['id'])->fetchColumn();
    $assert((int) $lines === 0, 'credit creates no current 1099 expense');
    $afterPost = $countState();
    $replay = accountingOpeningCutoverCommit($pdo, 1, $e, $balances, $ar, $ap,
        $p['preview_token'], null, '2026-10-01', true);
    $assert($replay['idempotent_replay'] && $replay['cutover_id'] === $posted['cutover_id']
        && $countState() === $afterPost, 'exact retry creates no duplicate journals or source items');
    $changed = accountingOpeningCutoverReview($pdo, 1, $e, $balances,
        str_replace('source-payment-2', 'different-source', $ar), $ap, '2026-10-01', true);
    $assert($changed['preview_token'] === null, 'posted source metadata is immutable on replay');

    $other = accountingCreateEntityWithCalendar($pdo, 1, [
        'code' => 'CRONLY', 'legal_name' => 'Synthetic Credits Only LLC', 'country' => 'US',
        'base_currency' => 'USD', 'entity_type' => 'llc', 'accounting_basis' => 'accrual',
        'fiscal_year_start_month' => 1,
    ], 2026);
    $otherId = (int) $other['entity_id'];
    $creditOnly = "Bill number,Vendor name,Bill date,Due date,Open amount,Source type,Source reference\n"
        . "CREDIT-ONLY,Example Vendor,2026-09-01,2026-09-01,-15.01,Vendor Credit,source-credit-5\n";
    $cp = accountingOpeningCutoverReview($pdo, 1, $otherId, '', '', $creditOnly, '2026-10-01', true);
    $assert($cp['ap_total'] === '-15.01' && $cp['preview_token'] !== null, 'negative-only source total is valid');
    accountingOpeningCutoverCommit($pdo, 1, $otherId, '', '', $creditOnly, $cp['preview_token'], null, '2026-10-01', true);
    $assert(simSnapshotDocumentDue(1, $otherId, '2026-09-30') === ['ar_due' => 0.0, 'ap_due' => -15.01]
        && simSnapshotDocumentDue(1, $e, '2026-09-30') === $due, 'credit-only aging remains entity-scoped');
    $assert(reportBalanceSheet(1, '2026-09-30', $otherId)['balanced'], 'credit-only opening also balances');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
$assert($countState() === $baseline, 'test rolled back every financial and party record');
echo "Midyear opening MariaDB: {$checks} checks passed.\n";
