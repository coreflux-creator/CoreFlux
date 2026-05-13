<?php
/**
 * Sprint 6 — Restructure: home dashboard restored, Reports is its own
 * module, real charts + date-range + prior-year comparison + login fix.
 */

declare(strict_types=1);

$pass = 0; $fail = 0;
function _a(string $label, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok  $label\n"; }
    else       { $fail++; echo "  FAIL  $label\n"; }
}

echo "Sprint 6 — restructure\n";

$app   = (string) file_get_contents(__DIR__ . '/../dashboard/src/App.jsx');
$home  = (string) file_get_contents(__DIR__ . '/../dashboard/src/pages/DashboardOverview.jsx');
$reps  = (string) file_get_contents(__DIR__ . '/../dashboard/src/pages/ReportsModule.jsx');
$exec  = (string) file_get_contents(__DIR__ . '/../dashboard/src/pages/ExecutiveDashboard.jsx');
$line  = (string) file_get_contents(__DIR__ . '/../dashboard/src/components/LineChart.jsx');
$api   = (string) file_get_contents(__DIR__ . '/../api/exec_dashboard.php');
$mods  = (string) file_get_contents(__DIR__ . '/../core/modules.php');
$dashPhp = (string) file_get_contents(__DIR__ . '/../dashboard.php');

echo "\n/ → home dashboard restored\n";
_a('App routes / to DashboardOverview',           str_contains($app, '<Route path="/"') && str_contains($app, '<DashboardOverview'));
_a('App removes RoleAwareDashboard helper',       !str_contains($app, 'function RoleAwareDashboard'));
_a('App routes /modules/reports to ReportsModule',str_contains($app, '<Route path="/modules/reports/*"') && str_contains($app, '<ReportsModule'));
_a('App keeps /exec backwards-compat redirect',   str_contains($app, '<Route path="/exec"') && str_contains($app, 'Navigate to="/modules/reports/exec"'));

echo "\nDashboardOverview — module cards + tiny KPI strip\n";
_a('renders ModuleCards (the nav buttons)',       str_contains($home, '<ModuleCards'));
_a('renders KpiSnapshotStrip for managers+',      str_contains($home, 'KpiSnapshotStrip'));
_a('snapshot strip hidden for plain employees',   str_contains($home, 'isManager && <KpiSnapshotStrip'));
_a('"Open full reports" CTA links to module',     str_contains($home, '/modules/reports/exec') && str_contains($home, 'data-testid="dashboard-open-reports"'));
_a('snapshot tiles have testids',                 str_contains($home, 'testid="snapshot-revenue"') && str_contains($home, 'testid="snapshot-headcount"'));
_a('still renders Admin Quick Actions',           str_contains($home, 'Manage Tenants') && str_contains($home, 'Manage Users'));

echo "\nReportsModule — sidebar + child routes\n";
_a('renders sidebar with link to /exec',          str_contains($reps, '/modules/reports/exec') && str_contains($reps, 'reports-link-${'));
_a('default redirects /modules/reports → /exec',  str_contains($reps, 'Navigate to="exec"'));
_a('routes /finance and /staffing',               str_contains($reps, '<Route path="/finance"') && str_contains($reps, '<Route path="/staffing"'));
_a('finance / staffing have their own drill pages',  str_contains($reps, "import FinanceReports") && str_contains($reps, "import StaffingReports") && str_contains($reps, '<FinanceReports') && str_contains($reps, '<StaffingReports'));

echo "\nLineChart — real chart\n";
_a('zero-dep SVG line chart exists',              str_contains($line, 'export default function LineChart'));
_a('renders gridlines + Y-axis labels',           str_contains($line, 'tickValues') && str_contains($line, 'fontSize="10"'));
_a('renders multi-series via series[] prop',      str_contains($line, 'visible.map((s, si)'));
_a('hover crosshair + tooltip',                   str_contains($line, 'data-testid="line-chart-tooltip"') && str_contains($line, 'hoverIdx'));
_a('legend shown when 2+ series',                 str_contains($line, 'series.length > 1'));
_a('prior-year series renders dashed',            str_contains($line, 's.dashed'));

echo "\nExecutiveDashboard — date range + compare + chart band\n";
_a('imports LineChart',                           str_contains($exec, "import LineChart from '../components/LineChart'"));
_a('accepts bandFilter prop',                     str_contains($exec, 'bandFilter = null'));
_a('honours bandFilter for finance band',         str_contains($exec, "(!bandFilter || bandFilter === 'finance')"));
_a('honours bandFilter for staffing band',        str_contains($exec, "(!bandFilter || bandFilter === 'staffing')"));
_a('renders revenue chart with prior-year overlay',str_contains($exec, "f.revenue?.prev_period"));
_a('renders margin chart',                        str_contains($exec, 'data-testid="chart-margin"') || str_contains($exec, 'testid="chart-margin"'));
_a('renders headcount-flow chart (starts vs term)',str_contains($exec, 'chart-headcount-flow'));
_a('renders billable-hours chart',                str_contains($exec, 'chart-billable-hours'));
_a('date-range picker control',                   str_contains($exec, 'data-testid="exec-date-picker-toggle"'));
_a('date picker has from/to inputs',              str_contains($exec, 'data-testid="exec-date-from"') && str_contains($exec, 'data-testid="exec-date-to"'));
_a('date presets MTD / QTD / YTD / last quarter / last year',
                                                  str_contains($exec, "['mtd',") && str_contains($exec, "['qtd',") && str_contains($exec, "['ytd',") && str_contains($exec, "'last_quarter'") && str_contains($exec, "'last_year'"));
_a('date "Clear range" returns to weeks preset',  str_contains($exec, 'data-testid="exec-date-clear"'));
_a('vs. prior year toggle button',                str_contains($exec, 'data-testid="exec-toggle-compare"'));
_a('filters carry to/from + compare in saved view',str_contains($exec, 'compare_prior_year:'));

echo "\nAPI exec_dashboard.php — date range + compare\n";
_a('reads ?from=, ?to=',                          str_contains($api, "api_query('from'") && str_contains($api, "api_query('to'"));
_a('falls back to weeks if invalid range',        str_contains($api, '$customRange = false'));
_a('compare=prior_year shifts -52 weeks',         str_contains($api, "modify('-52 weeks')"));
_a('revenue.prev_period emitted when compare on', str_contains($api, "'prev_period'") || str_contains($api, "['prev_period']"));
_a('compare metadata in response',                str_contains($api, "'mode'      => \$compare"));
_a('range.custom flag echoed',                    str_contains($api, "'custom' => \$customRange"));

echo "\ncore/modules.php — Reports registered\n";
_a('reports module declared',                     str_contains($mods, "'reports' => ["));
_a('reports actions: overview / executive_snapshot / client_profitability',
    str_contains($mods, "'route' => 'overview'") &&
    str_contains($mods, "'route' => 'executive_snapshot'") &&
    str_contains($mods, "'route' => 'client_profitability'"));
_a('manager+ role grants reports access',         str_contains($mods, "'manager'                     => ['people', 'placements', 'time', 'billing', 'ap', 'reports']"));
_a('admin role grants reports access',            str_contains($mods, "'tenant_admin', 'admin'       => ['people', 'placements', 'time', 'billing', 'ap', 'accounting', 'payroll', 'treasury', 'reports']"));

echo "\nLogin → SPA fix\n";
_a('legacy dashboard.php bounces to /spa.php',    str_contains($dashPhp, "header('Location: /spa.php')"));
_a('?legacy=1 escape hatch preserved',            str_contains($dashPhp, "\$_GET['legacy'] === '1'"));
_a('?admin=1 master-only preserved',              str_contains($dashPhp, "\$_GET['admin']") && str_contains($dashPhp, "'master_admin'"));

echo "\n--- $pass assertions, $fail failed ---\n";
exit($fail === 0 ? 0 : 1);
