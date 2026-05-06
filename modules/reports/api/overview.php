<?php
/**
 * Reports API — Staffing Overview dashboard payload.
 *
 * GET /api/reports/overview?period=4w[&from=YYYY-MM-DD&to=YYYY-MM-DD]
 *
 * Returns the full Staffing Overview dashboard in a single round-trip:
 *   • KPI tiles (Revenue, GP, GP%, Hours, OT%, Spread/hr)
 *   • Weekly Revenue + GP time series
 *   • Headcount tiles + weekly trend
 *   • Run Rate comparison
 *   • Timesheet Health summary
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/periods.php';
require_once __DIR__ . '/../lib/staffing_metrics.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
if (api_method() !== 'GET') api_error('Method not allowed', 405);
RBAC::requirePermission($user, 'reports.view');

$period = reportsResolvePeriod(
    (string) (api_query('period') ?? '4w'),
    api_query('from'),
    api_query('to')
);
$tenantId = (int) $ctx['tenant_id'];

$totals   = staffingKpiTotals($tenantId, $period['from'], $period['to']);
$weekly   = staffingWeeklySeries($tenantId, $period['from'], $period['to']);
$headcount= staffingHeadcount($tenantId, $period['from'], $period['to']);
$health   = staffingTimesheetHealth($tenantId, $period['from'], $period['to']);
$runRate  = staffingRunRate($tenantId, $weekly);

$kpis = [
    'revenue'        => round($totals['revenue'], 2),
    'gross_profit'   => round($totals['gross_profit'], 2),
    'gross_profit_pct' => $totals['revenue'] > 0 ? round(100 * $totals['gross_profit'] / $totals['revenue'], 2) : 0,
    'hours'          => round($totals['hours'], 2),
    'billable_hours' => round($totals['billable_hours'], 2),
    'ot_hours'       => round($totals['ot_hours'], 2),
    'ot_pct'         => $totals['hours'] > 0 ? round(100 * $totals['ot_hours'] / $totals['hours'], 2) : 0,
    'spread_per_hour'=> $totals['hours'] > 0 ? round($totals['gross_profit'] / $totals['hours'], 2) : 0,
];

api_ok([
    'period'         => $period,
    'kpis'           => $kpis,
    'weekly_series'  => $weekly,
    'headcount'      => $headcount,
    'timesheet_health' => $health,
    'run_rate'       => $runRate,
]);
