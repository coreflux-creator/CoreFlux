<?php
/**
 * Core accounting reports trust smoke.
 *
 * Locks in sensible cash-flow defaults, ledger-backed historical aging,
 * readable comparisons, and one discoverable report library.
 */
declare(strict_types=1);

$pass = 0;
$fail = 0;
$assert = static function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "  PASS {$name}\n";
        return;
    }
    $fail++;
    echo "  FAIL {$name}\n";
};

$root = dirname(__DIR__);
require_once $root . '/modules/accounting/lib/standard_reports.php';

echo "Cash-flow classification defaults\n";
$cases = [
    'explicit tag wins' => [
        ['code' => '1000', 'name' => 'Cash', 'account_type' => 'asset', 'cash_flow_tag' => 'investing_custom', 'is_bank_linked' => 1],
        'investing_custom', 'configured',
    ],
    'linked bank account is cash' => [
        ['code' => '1000-6649', 'name' => 'First Citizens operating', 'account_type' => 'asset', 'cash_flow_tag' => null, 'is_bank_linked' => 1],
        'cash_and_equivalents', 'inferred',
    ],
    'accounts receivable is operating' => [
        ['code' => '1100', 'name' => 'Accounts Receivable', 'account_type' => 'asset'],
        'operating_wc_ar', 'inferred',
    ],
    'AR abbreviation is operating' => [
        ['code' => '1100', 'name' => 'AR', 'account_type' => 'asset'],
        'operating_wc_ar', 'inferred',
    ],
    'accounts payable is operating' => [
        ['code' => '2000', 'name' => 'Accounts Payable', 'account_type' => 'liability'],
        'operating_wc_ap', 'inferred',
    ],
    'asset LOC is investing' => [
        ['code' => '1203', 'name' => 'Thunderhawk LOC', 'account_type' => 'asset'],
        'investing_loans', 'inferred',
    ],
    'owner contribution is financing' => [
        ['code' => '3101', 'name' => 'Owner Contributions', 'account_type' => 'equity'],
        'financing_equity', 'inferred',
    ],
    'ambiguous account remains reviewable' => [
        ['code' => '1999', 'name' => 'Other asset', 'account_type' => 'asset'],
        'untagged', 'unclassified',
    ],
];

foreach ($cases as $name => [$account, $tag, $source]) {
    $actual = reportCashFlowClassification($account);
    $assert($name, $actual['tag'] === $tag && $actual['source'] === $source);
}

echo "\nAging and report presentation\n";
$billing = (string) file_get_contents($root . '/modules/billing/lib/billing.php');
$ap = (string) file_get_contents($root . '/modules/ap/lib/ap.php');
$standard = (string) file_get_contents($root . '/modules/accounting/ui/StandardReports.jsx');
$library = (string) file_get_contents($root . '/dashboard/src/components/FinancialReportLibrary.jsx');
$reportsHome = (string) file_get_contents($root . '/modules/reports/ui/StaffingOverview.jsx');
$period = (string) file_get_contents($root . '/dashboard/src/lib/useReportPeriod.js');
$comparison = (string) file_get_contents($root . '/dashboard/src/components/ComparisonTable.jsx');
$metric = (string) file_get_contents($root . '/dashboard/src/components/MetricCard.jsx');
$lightWorkspaceDeployPath = $root . '/.github/workflows/deploy-light-workspace.yml';

$assert('AR aging is ledger-backed', str_contains($billing, 'JOIN accounting_journal_entries je'));
$assert('AR aging excludes future invoices and payments', str_contains($billing, 'i.issue_date <= :document_as_of') && str_contains($billing, 'p.received_at <= :payment_as_of'));
$assert('AP aging is ledger-backed', str_contains($ap, 'JOIN accounting_journal_entries je'));
$assert('AP aging excludes future bills and undisbursed payments',
    str_contains($ap, 'b.bill_date <= :document_as_of')
    && str_contains($ap, 'p.pay_date <= :payment_as_of')
    && str_contains($ap, 'p.status IN ("sent", "cleared")'));
$assert('report library links all core statements',
    str_contains($library, '/modules/accounting/pnl')
    && str_contains($library, '/modules/accounting/balance')
    && str_contains($library, '/modules/accounting/cash-flow')
    && str_contains($library, '/modules/accounting/trial'));
$assert('report library links AR and AP aging',
    str_contains($library, '/modules/billing/aging')
    && str_contains($library, '/modules/ap/aging'));
$assert('accounting and top-level Reports share the financial report library',
    str_contains($standard, '<FinancialReportLibrary session={session} />')
    && str_contains($reportsHome, '<FinancialReportLibrary session={session} prominent />'));
if (is_file($lightWorkspaceDeployPath)) {
    $lightWorkspaceDeploy = (string) file_get_contents($lightWorkspaceDeployPath);
    $assert('light workspace release packages the financial report library',
        str_contains($lightWorkspaceDeploy, 'dashboard/src/components/FinancialReportLibrary.jsx'));
}
$assert('default comparison is prior period only', str_contains($period, "defaultCompare = 'prior_period'"));
$assert('zero baselines render as New, not infinity',
    str_contains($comparison, "v.pct === null ? 'New'")
    && str_contains($metric, "v.pct === null ? 'New'"));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
