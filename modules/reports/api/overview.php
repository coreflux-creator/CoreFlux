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
rbac_legacy_require($user, 'reports.view');

$period = reportsResolvePeriod(
    (string) (api_query('period') ?? '4w'),
    api_query('from'),
    api_query('to')
);
$tenantId = (int) $ctx['tenant_id'];

// Sprint 6f — wrap in try/catch so a missing view (`v_timesheet_day_fin` not yet
// migrated) or a stale schema returns a friendly empty payload instead of a
// 500 that kills the whole Reports tab. The UI renders "no data for this
// period" gracefully when totals are zero.
$totals = $weekly = $headcount = $health = $runRate = null;
$dataError = null;
try {
    $totals    = staffingKpiTotals($tenantId, $period['from'], $period['to']);
    $weekly    = staffingWeeklySeries($tenantId, $period['from'], $period['to']);
    $headcount = staffingHeadcount($tenantId, $period['from'], $period['to']);
    $health    = staffingTimesheetHealth($tenantId, $period['from'], $period['to']);
    $runRate   = staffingRunRate($tenantId, $weekly);
} catch (\Throwable $e) {
    error_log('reports/overview SQL failed: ' . $e->getMessage());
    $dataError = 'Reports data view not yet built for this tenant. '
               . 'Run the reports migration (`v_timesheet_day_fin`) and reload.';
    $totals    = ['revenue'=>0,'cost'=>0,'gross_profit'=>0,'hours'=>0,'ot_hours'=>0,'billable_hours'=>0];
    $weekly    = [];
    $headcount = ['active'=>0,'new_starts'=>0,'terminations'=>0,'net_change'=>0];
    $health    = ['median_approval_lag_hours'=>null,'submitted_pending'=>0,'approved'=>0,'rejected'=>0,'draft'=>0];
    $runRate   = staffingRunRate($tenantId, []);
}

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
    'data_warning'   => $dataError,
]);
