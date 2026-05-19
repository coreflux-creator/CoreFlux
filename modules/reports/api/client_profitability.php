<?php
/**
 * Reports API — Client Profitability.
 *
 * GET /api/reports/client_profitability?period=4w
 *
 * Per-client table (spec §Client Profitability):
 *   revenue, pay cost, GP, GP%, hours, OT hours, OT%, spread/hr,
 *   average bill rate, average pay rate.
 *
 * Client identity: uses placements.end_client_name as the grouping key
 * (multi-tier chain support lives in placement_client_chain; the highest-
 * position `end_client` party, falling back to the placement column,
 * is the canonical "client" for invoicing and reporting).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/periods.php';

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

$pdo = getDB();
if (!$pdo) api_error('No database connection', 500);

$sql = "SELECT
          COALESCE(pl.end_client_name, '(unassigned)') AS client,
          COALESCE(SUM(v.revenue), 0)      AS revenue,
          COALESCE(SUM(v.cost), 0)         AS cost,
          COALESCE(SUM(v.gross_profit), 0) AS gross_profit,
          COALESCE(SUM(v.hours), 0)        AS hours,
          COALESCE(SUM(CASE WHEN v.is_overtime = 1 THEN v.hours ELSE 0 END), 0) AS ot_hours,
          COALESCE(SUM(CASE WHEN v.is_billable = 1 THEN v.hours * v.bill_rate ELSE 0 END), 0) AS bill_rate_wsum,
          COALESCE(SUM(CASE WHEN v.is_billable = 1 THEN v.hours ELSE 0 END), 0)               AS billable_hours,
          COALESCE(SUM(v.hours * v.pay_rate), 0) AS pay_rate_wsum
        FROM v_timesheet_day_fin v
        LEFT JOIN placements pl ON pl.id = v.placement_id AND pl.tenant_id = v.tenant_id
        WHERE v.tenant_id = :t AND v.work_date BETWEEN :from_ AND :to
        GROUP BY client
        ORDER BY revenue DESC
        LIMIT 500";

$stmt = $pdo->prepare($sql);
$stmt->execute(['t' => $tenantId, 'from_' => $period['from'], 'to' => $period['to']]);
$rows = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $rev = (float) $r['revenue'];
    $gp  = (float) $r['gross_profit'];
    $hrs = (float) $r['hours'];
    $billHrs = (float) $r['billable_hours'];
    $rows[] = [
        'client'         => $r['client'],
        'revenue'        => round($rev, 2),
        'cost'           => round((float) $r['cost'], 2),
        'gross_profit'   => round($gp, 2),
        'gross_profit_pct' => $rev > 0 ? round(100 * $gp / $rev, 2) : 0,
        'hours'          => round($hrs, 2),
        'ot_hours'       => round((float) $r['ot_hours'], 2),
        'ot_pct'         => $hrs > 0 ? round(100 * (float) $r['ot_hours'] / $hrs, 2) : 0,
        'spread_per_hour'=> $hrs > 0 ? round($gp / $hrs, 2) : 0,
        'avg_bill_rate'  => $billHrs > 0 ? round((float) $r['bill_rate_wsum'] / $billHrs, 2) : 0,
        'avg_pay_rate'   => $hrs > 0 ? round((float) $r['pay_rate_wsum'] / $hrs, 2) : 0,
    ];
}

// Low-margin alert: clients with GP% < 20 AND revenue > 0.
$alerts = array_values(array_filter($rows, fn($r) => $r['revenue'] > 0 && $r['gross_profit_pct'] < 20));

api_ok([
    'period'  => $period,
    'rows'    => $rows,
    'alerts'  => $alerts,
]);
