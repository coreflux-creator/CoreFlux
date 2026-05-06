<?php
/**
 * Reports API — Executive Snapshot.
 *
 * GET /api/reports/executive_snapshot?period=4w
 *
 * One-page leadership-ready summary. Aggregates the Overview KPIs + headcount
 * + run rate + approval health into a single printable payload (spec §Executive Snapshot).
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

$totals    = staffingKpiTotals($tenantId, $period['from'], $period['to']);
$weekly    = staffingWeeklySeries($tenantId, $period['from'], $period['to']);
$headcount = staffingHeadcount($tenantId, $period['from'], $period['to']);
$health    = staffingTimesheetHealth($tenantId, $period['from'], $period['to']);
$runRate   = staffingRunRate($tenantId, $weekly);

api_ok([
    'period' => $period,
    'snapshot' => [
        'revenue'          => round($totals['revenue'], 2),
        'gross_profit'     => round($totals['gross_profit'], 2),
        'gross_profit_pct' => $totals['revenue'] > 0 ? round(100 * $totals['gross_profit'] / $totals['revenue'], 2) : 0,
        'hours'            => round($totals['hours'], 2),
        'ot_hours'         => round($totals['ot_hours'], 2),
        'ot_pct'           => $totals['hours'] > 0 ? round(100 * $totals['ot_hours'] / $totals['hours'], 2) : 0,
        'spread_per_hour'  => $totals['hours'] > 0 ? round($totals['gross_profit'] / $totals['hours'], 2) : 0,
        'headcount_active'        => $headcount['active'],
        'new_starts'              => $headcount['new_starts'],
        'terminations'            => $headcount['terminations'],
        'net_headcount_change'    => $headcount['net_change'],
        'revenue_run_rate_now'    => round($runRate['revenue_run_rate_now'], 2),
        'revenue_run_rate_delta_pct' => $runRate['revenue_run_rate_delta_pct'],
        'gp_run_rate_now'         => round($runRate['gp_run_rate_now'], 2),
        'gp_run_rate_delta_pct'   => $runRate['gp_run_rate_delta_pct'],
        'median_approval_lag_hours' => $health['median_approval_lag_hours'],
        'submitted_pending'       => $health['submitted_pending'],
        'approved'                => $health['approved'],
        'rejected'                => $health['rejected'],
    ],
]);
