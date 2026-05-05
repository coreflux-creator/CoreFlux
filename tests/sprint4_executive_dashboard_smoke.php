<?php
/**
 * Sprint 4 — Executive Dashboard contract smoke
 *
 * Asserts the new exec_dashboard.php API + ExecutiveDashboard.jsx +
 * Sparkline shipping (and that the SPA routes the new dashboard to
 * managers+, falling back to the simpler module-cards view for
 * employees).
 */

declare(strict_types=1);

$pass = 0; $fail = 0;
function _a(string $label, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok  $label\n"; }
    else       { $fail++; echo "  FAIL  $label\n"; }
}

echo "Sprint 4 — executive dashboard\n";

$api    = (string) file_get_contents(__DIR__ . '/../api/exec_dashboard.php');
$flt    = (string) file_get_contents(__DIR__ . '/../api/exec_filters.php');
$ui     = (string) file_get_contents(__DIR__ . '/../dashboard/src/pages/ExecutiveDashboard.jsx');
$spark  = (string) file_get_contents(__DIR__ . '/../dashboard/src/components/Sparkline.jsx');
$app    = (string) file_get_contents(__DIR__ . '/../dashboard/src/App.jsx');

echo "\n/api/exec_dashboard.php\n";
_a('requires auth',                     str_contains($api, 'api_require_auth()'));
_a('blocks employee from exec data',    str_contains($api, "['master_admin', 'tenant_admin', 'admin', 'manager']"));
_a('weeks parameter capped 1..104',     str_contains($api, "max(1, min(104,"));
_a('client/recruiter/type filters',     str_contains($api, "'client_id'") && str_contains($api, "'recruiter_id'") && str_contains($api, "'placement_type'"));
_a('worksite_state filter',             str_contains($api, "'worksite_state'"));

echo "\nFinance shape\n";
_a('revenue mtd/qtd/ytd buckets',       str_contains($api, "'revenue'") && str_contains($api, "'mtd' => 0") && str_contains($api, "'ytd' => 0"));
_a('revenue.run_rate_90d annualised',   str_contains($api, "'run_rate_90d'") && str_contains($api, "* 4"));
_a('revenue trendline by week',         str_contains($api, "_execTrendlineFromRows"));
_a('AR aging buckets (current..90+)',   str_contains($api, "'current'") && str_contains($api, "'d30'") && str_contains($api, "'d90_plus'"));
_a('AP aging buckets',                  str_contains($api, "ap_bills") && str_contains($api, "ap_aging"));
_a('margin uses placement_rates spread',str_contains($api, "pr.bill_rate - pr.pay_rate"));
_a('margin gross_pct vs revenue',       str_contains($api, "'gross_pct'"));
_a('payroll mtd/qtd/ytd + last_run_total', str_contains($api, "'last_run_total'"));

echo "\nStaffing shape\n";
_a('headcount split w2/c2c/1099/perm', str_contains($api, "contractors_w2") && str_contains($api, "contractors_c2c") && str_contains($api, "contractors_1099") && str_contains($api, "'perm'"));
_a('new_starts pulled from people.hire_date',     str_contains($api, "WHERE tenant_id = :t AND hire_date >= :s"));
_a('terminations from people.termination_date',   str_contains($api, "termination_date >= :s"));
_a('net_change = starts − terminations',          str_contains($api, "\$staffing['new_starts']['period'] - \$staffing['terminations']['period']"));
_a('active_placements respects filters',          str_contains($api, "p.status = 'active'") && str_contains($api, "\$placementWhereSql"));
_a('ending_soon (30 days)',                       str_contains($api, "+30 days"));
_a('billable_hours filter pipeline',              str_contains($api, "te.category IN ('regular_billable','OT_billable')"));

echo "\n/api/exec_filters.php\n";
_a('returns end-clients',               str_contains($flt, 'tenant_end_clients'));
_a('returns recruiters from commissions',str_contains($flt, "pc.role = 'recruiter'"));
_a('returns placement_types vocabulary',str_contains($flt, "'w2','1099','c2c','direct_hire','temp_to_perm'"));
_a('returns worksite_states list',      str_contains($flt, "DISTINCT worksite_state"));

echo "\nSparkline.jsx\n";
_a('sparkline component exists',         str_contains($spark, 'export default function Sparkline'));
_a('renders SVG path',                   str_contains($spark, '<svg'));
_a('hover tooltip',                      str_contains($spark, 'data-testid="sparkline-tooltip"'));
_a('handles empty data',                 str_contains($spark, 'No data'));

echo "\nExecutiveDashboard.jsx\n";
_a('hits /api/exec_dashboard.php',       str_contains($ui, '/api/exec_dashboard.php?'));
_a('hits /api/exec_filters.php',         str_contains($ui, '/api/exec_filters.php'));
_a('time-window presets (4/12/26/52/104)',str_contains($ui, '4w') && str_contains($ui, '52w') && str_contains($ui, '104w'));
_a('filter pills hide/show',             str_contains($ui, 'data-testid="exec-toggle-filters"'));

foreach (['kpi-revenue','kpi-run-rate','kpi-margin','kpi-payroll',
          'kpi-headcount','kpi-new-starts','kpi-terminations','kpi-net-change',
          'kpi-active-placements','kpi-new-placements','kpi-ending-soon','kpi-billable-hours'] as $tid) {
    _a("renders $tid card",              str_contains($ui, "testid=\"$tid\"") || str_contains($ui, "testid={\"$tid\"}"));
}
_a('AR aging card drilldown',            str_contains($ui, 'aging-ar') && str_contains($ui, '/modules/billing/aging'));
_a('AP aging card drilldown',            str_contains($ui, 'aging-ap') && str_contains($ui, '/modules/ap/aging'));

echo "\nApp.jsx routing\n";
_a('imports ExecutiveDashboard',         str_contains($app, "import ExecutiveDashboard from './pages/ExecutiveDashboard'"));
_a('legacy /exec redirects into Reports module', str_contains($app, '<Route path="/exec"') && str_contains($app, 'Navigate to="/modules/reports/exec"'));
_a('home page is DashboardOverview',     str_contains($app, '<Route path="/"') && str_contains($app, '<DashboardOverview'));
_a('Reports module hosts the executive dashboard', str_contains($app, '<Route path="/modules/reports/*"'));

echo "\n--- $pass assertions, $fail failed ---\n";
exit($fail === 0 ? 0 : 1);
