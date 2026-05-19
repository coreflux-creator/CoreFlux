<?php
/**
 * Reports API — Rate & Spread Monitor.
 *
 * GET /api/reports/rate_spread?period=4w
 *
 * Per-placement profitability (spec §Rate & Spread Monitor):
 *   effective bill rate, effective pay rate, spread/hr, GP %, revenue, cost, hours.
 *   Flags underpriced/negative-spread placements.
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
          v.placement_id,
          pl.title,
          pl.end_client_name AS client,
          pl.engagement_type,
          CONCAT(COALESCE(pe.first_name,''), ' ', COALESCE(pe.last_name,'')) AS worker_name,
          COALESCE(SUM(v.revenue), 0)      AS revenue,
          COALESCE(SUM(v.cost), 0)         AS cost,
          COALESCE(SUM(v.gross_profit), 0) AS gross_profit,
          COALESCE(SUM(v.hours), 0)        AS hours,
          COALESCE(SUM(CASE WHEN v.is_billable = 1 THEN v.hours ELSE 0 END), 0) AS billable_hours,
          COALESCE(SUM(CASE WHEN v.is_billable = 1 THEN v.hours * v.bill_rate ELSE 0 END), 0) AS bill_rate_wsum,
          COALESCE(SUM(v.hours * v.pay_rate), 0) AS pay_rate_wsum
        FROM v_timesheet_day_fin v
        LEFT JOIN placements pl ON pl.id = v.placement_id AND pl.tenant_id = v.tenant_id
        LEFT JOIN people pe     ON pe.id = v.employee_id  AND pe.tenant_id = v.tenant_id
        WHERE v.tenant_id = :t AND v.work_date BETWEEN :from_ AND :to
        GROUP BY v.placement_id, pl.title, pl.end_client_name, pl.engagement_type, worker_name
        HAVING hours > 0
        ORDER BY gross_profit ASC
        LIMIT 500";

$stmt = $pdo->prepare($sql);
$stmt->execute(['t' => $tenantId, 'from_' => $period['from'], 'to' => $period['to']]);

$rows = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $rev = (float) $r['revenue'];
    $gp  = (float) $r['gross_profit'];
    $hrs = (float) $r['hours'];
    $billHrs = (float) $r['billable_hours'];
    $effBill = $billHrs > 0 ? round((float) $r['bill_rate_wsum'] / $billHrs, 2) : 0;
    $effPay  = $hrs > 0 ? round((float) $r['pay_rate_wsum'] / $hrs, 2) : 0;
    $spread  = round($effBill - $effPay, 2);
    $rows[] = [
        'placement_id'   => (int) $r['placement_id'],
        'title'          => $r['title'],
        'client'         => $r['client'],
        'worker'         => trim($r['worker_name'] ?? ''),
        'engagement_type'=> $r['engagement_type'],
        'effective_bill_rate' => $effBill,
        'effective_pay_rate'  => $effPay,
        'spread_per_hour'     => $spread,
        'gross_profit_pct'    => $rev > 0 ? round(100 * $gp / $rev, 2) : 0,
        'revenue'             => round($rev, 2),
        'cost'                => round((float) $r['cost'], 2),
        'hours'               => round($hrs, 2),
        'flag'                => $spread < 0 ? 'negative_spread' : ($spread < 10 ? 'low_spread' : null),
    ];
}

// Sort: negative spreads first, then by low spread.
usort($rows, function ($a, $b) {
    return ($a['spread_per_hour'] <=> $b['spread_per_hour']);
});

api_ok([
    'period' => $period,
    'rows'   => $rows,
]);
