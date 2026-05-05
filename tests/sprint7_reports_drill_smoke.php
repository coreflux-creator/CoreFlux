<?php
/**
 * Sprint 7 — Reports drill pages (Finance + Staffing)
 */

declare(strict_types=1);

$pass = 0; $fail = 0;
function _a(string $label, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok  $label\n"; }
    else       { $fail++; echo "  FAIL  $label\n"; }
}

echo "Sprint 7 — Reports drill pages\n";

$apiF = (string) file_get_contents(__DIR__ . '/../api/reports_finance.php');
$apiS = (string) file_get_contents(__DIR__ . '/../api/reports_staffing.php');
$uiF  = (string) file_get_contents(__DIR__ . '/../dashboard/src/pages/FinanceReports.jsx');
$uiS  = (string) file_get_contents(__DIR__ . '/../dashboard/src/pages/StaffingReports.jsx');
$mod  = (string) file_get_contents(__DIR__ . '/../dashboard/src/pages/ReportsModule.jsx');

echo "\n/api/reports_finance.php\n";
_a('manager+ gate',                          str_contains($apiF, "['master_admin', 'tenant_admin', 'admin', 'manager']"));
_a('reads ?from / ?to',                      str_contains($apiF, "api_query('from'") && str_contains($apiF, "api_query('to'"));
_a('compare=prior_year shifts -1 year',      str_contains($apiF, "modify('-1 year')"));
_a('P&L: revenue from billing_invoices',     str_contains($apiF, 'FROM billing_invoices') && str_contains($apiF, 'IN (\'sent\',\'partially_paid\',\'paid\')'));
_a('P&L: direct_cost via placement_rates',   str_contains($apiF, 'pr.pay_rate'));
_a('P&L: indirect via ap_bills',             str_contains($apiF, 'FROM ap_bills') && str_contains($apiF, 'indirect'));
_a('P&L: gross_margin / net_income computed',str_contains($apiF, "'gross_margin'") && str_contains($apiF, "'net_income'"));
_a('P&L: prior_period when compare on',      str_contains($apiF, "'prev_period'"));
_a('Cash flow: beginning from plaid_accounts',str_contains($apiF, 'FROM plaid_accounts'));
_a('Cash flow: receipts from billing_payments',str_contains($apiF, 'FROM billing_payments'));
_a('Cash flow: operating from ap_payments',  str_contains($apiF, 'FROM ap_payments'));
_a('Cash flow: payroll from payroll_runs',   str_contains($apiF, 'FROM payroll_runs'));
_a('Cash flow: weekly trend',                str_contains($apiF, 'week_start'));
_a('AR detail: outstanding + days_overdue',  str_contains($apiF, "DATEDIFF(:today, i.due_date)") && str_contains($apiF, "outstanding"));
_a('AP detail: outstanding + days_overdue',  str_contains($apiF, "DATEDIFF(:today, b.due_date)"));

echo "\n/api/reports_staffing.php\n";
_a('manager+ gate',                          str_contains($apiS, "['master_admin', 'tenant_admin', 'admin', 'manager']"));
_a('placement_margin: bill rate + pay rate', str_contains($apiS, 'pr.bill_rate') && str_contains($apiS, 'pr.pay_rate'));
_a('placement_margin: recruiter join',       str_contains($apiS, "pc.role = 'recruiter'"));
_a('placement_margin: period + lifetime hrs',str_contains($apiS, 'billable_hours_period') && str_contains($apiS, 'billable_hours_lifetime'));
_a('recruiter board aggregates margins',     str_contains($apiS, 'recruiter_board') && str_contains($apiS, 'avg_margin_per_hour'));
_a('headcount by classification + state',    str_contains($apiS, 'by_classification') && str_contains($apiS, 'by_state'));
_a('filter: client_id / recruiter_id',       str_contains($apiS, 'client_id') && str_contains($apiS, 'recruiter_id'));
_a('filter: placement_type / worksite_state',str_contains($apiS, 'placement_type') && str_contains($apiS, 'worksite_state'));

echo "\nFinanceReports.jsx\n";
_a('hits /api/reports_finance.php',          str_contains($uiF, '/api/reports_finance.php?'));
_a('date presets MTD/QTD/YTD/LQ/LY',         str_contains($uiF, "['mtd', 'MTD']") && str_contains($uiF, "['ytd', 'YTD']") && str_contains($uiF, 'last_quarter') && str_contains($uiF, 'last_year'));
_a('vs prior year toggle',                   str_contains($uiF, 'data-testid="finance-toggle-compare"'));
_a('PnlCard renders 5 rows',                 str_contains($uiF, 'function PnlCard') && str_contains($uiF, '"Revenue"') && str_contains($uiF, '"Direct cost"') && str_contains($uiF, '"Gross margin"') && str_contains($uiF, '"Indirect costs"') && str_contains($uiF, '"Net income"'));
_a('PnlCard prior-year column when compare', str_contains($uiF, '{compareEnabled && <th') || str_contains($uiF, 'compareEnabled &&'));
_a('CashFlowCard waterfall 5 tiles',         str_contains($uiF, 'data-testid="cash-flow-waterfall"') && str_contains($uiF, "'+ Receipts'") && str_contains($uiF, "'− Operating'") && str_contains($uiF, "'− Payroll'"));
_a('CashFlowCard renders weekly chart',      str_contains($uiF, '<LineChart'));
_a('AR table sortable + filterable',         str_contains($uiF, 'data-testid="ar-detail-table"') && str_contains($uiF, 'data-testid="ar-filter"'));
_a('AR row links to invoice detail',         str_contains($uiF, '/modules/billing/invoices/'));
_a('AP table sortable + filterable',         str_contains($uiF, 'data-testid="ap-detail-table"') && str_contains($uiF, 'data-testid="ap-filter"'));
_a('AP row links to bill detail',            str_contains($uiF, '/modules/ap/bills/'));

echo "\nStaffingReports.jsx\n";
_a('hits /api/reports_staffing.php',         str_contains($uiS, '/api/reports_staffing.php?'));
_a('date presets MTD/QTD/YTD/12w/52w',       str_contains($uiS, "['mtd', 'MTD']") && str_contains($uiS, "'12w'") && str_contains($uiS, "'52w'"));
_a('RecruiterBoard renders + sorts by margin',str_contains($uiS, 'data-testid="recruiter-board"') && str_contains($uiS, 'period_margin'));
_a('RecruiterBoard ranks #1..N',             str_contains($uiS, 'sorted.map((r, i)') && str_contains($uiS, 'i + 1'));
_a('PlacementMarginTable filterable',        str_contains($uiS, 'data-testid="placement-margin-filter"'));
_a('PlacementMarginTable totals footer',     str_contains($uiS, 'data-testid="placement-margin-totals"'));
_a('PlacementMarginTable rows link to placement', str_contains($uiS, '/modules/placements/list/'));
_a('HeadcountBreakdown 2 panels',            str_contains($uiS, 'data-testid="headcount-classification"') && str_contains($uiS, 'data-testid="headcount-state"'));

echo "\nReportsModule.jsx — wires the new pages\n";
_a('imports FinanceReports component',       str_contains($mod, "import FinanceReports from './FinanceReports'"));
_a('imports StaffingReports component',      str_contains($mod, "import StaffingReports from './StaffingReports'"));
_a('no longer uses bandFilter stub',         !str_contains($mod, 'bandFilter='));

echo "\n--- $pass assertions, $fail failed ---\n";
exit($fail === 0 ? 0 : 1);
