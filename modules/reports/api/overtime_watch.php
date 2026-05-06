<?php
/**
 * Reports API — Overtime Watch.
 *
 * GET /api/reports/overtime_watch?period=4w
 *
 * OT exposure + cost leakage (spec §Overtime Watch):
 *   totals (ot_hours, ot_pct, ot_revenue, ot_cost, ot_margin),
 *   weekly OT trend,
 *   top employees by OT %,
 *   top clients by OT hours.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/periods.php';

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

$pdo = getDB();
if (!$pdo) api_error('No database connection', 500);

$params = ['t' => $tenantId, 'from_' => $period['from'], 'to' => $period['to']];

// Totals.
$stmt = $pdo->prepare(
    "SELECT
        COALESCE(SUM(hours), 0) AS total_hours,
        COALESCE(SUM(CASE WHEN is_overtime = 1 THEN hours ELSE 0 END), 0) AS ot_hours,
        COALESCE(SUM(CASE WHEN is_overtime = 1 THEN revenue ELSE 0 END), 0) AS ot_revenue,
        COALESCE(SUM(CASE WHEN is_overtime = 1 THEN cost ELSE 0 END), 0) AS ot_cost,
        COALESCE(SUM(CASE WHEN is_overtime = 1 THEN gross_profit ELSE 0 END), 0) AS ot_margin
     FROM v_timesheet_day_fin
     WHERE tenant_id = :t AND work_date BETWEEN :from_ AND :to"
);
$stmt->execute($params);
$tot = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$totals = [
    'ot_hours'   => round((float) ($tot['ot_hours'] ?? 0), 2),
    'total_hours'=> round((float) ($tot['total_hours'] ?? 0), 2),
    'ot_pct'     => ($tot['total_hours'] ?? 0) > 0
        ? round(100 * (float) $tot['ot_hours'] / (float) $tot['total_hours'], 2) : 0,
    'ot_revenue' => round((float) ($tot['ot_revenue'] ?? 0), 2),
    'ot_cost'    => round((float) ($tot['ot_cost'] ?? 0), 2),
    'ot_margin'  => round((float) ($tot['ot_margin'] ?? 0), 2),
];

// Weekly OT trend.
$stmt = $pdo->prepare(
    "SELECT week_start,
            COALESCE(SUM(hours), 0) AS total_hours,
            COALESCE(SUM(CASE WHEN is_overtime = 1 THEN hours ELSE 0 END), 0) AS ot_hours
     FROM v_timesheet_day_fin
     WHERE tenant_id = :t AND work_date BETWEEN :from_ AND :to
     GROUP BY week_start
     ORDER BY week_start"
);
$stmt->execute($params);
$weekly = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $th = (float) $r['total_hours'];
    $oh = (float) $r['ot_hours'];
    $weekly[] = [
        'week_start' => $r['week_start'],
        'ot_hours'   => round($oh, 2),
        'total_hours'=> round($th, 2),
        'ot_pct'     => $th > 0 ? round(100 * $oh / $th, 2) : 0,
    ];
}

// Top employees by OT %.
$stmt = $pdo->prepare(
    "SELECT v.employee_id,
            CONCAT(COALESCE(pe.first_name,''), ' ', COALESCE(pe.last_name,'')) AS worker_name,
            COALESCE(SUM(v.hours), 0) AS total_hours,
            COALESCE(SUM(CASE WHEN v.is_overtime = 1 THEN v.hours ELSE 0 END), 0) AS ot_hours
     FROM v_timesheet_day_fin v
     LEFT JOIN people pe ON pe.id = v.employee_id AND pe.tenant_id = v.tenant_id
     WHERE v.tenant_id = :t AND v.work_date BETWEEN :from_ AND :to
     GROUP BY v.employee_id, worker_name
     HAVING total_hours > 0
     ORDER BY (ot_hours / total_hours) DESC, ot_hours DESC
     LIMIT 20"
);
$stmt->execute($params);
$topEmployees = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $th = (float) $r['total_hours'];
    $oh = (float) $r['ot_hours'];
    $topEmployees[] = [
        'employee_id' => (int) $r['employee_id'],
        'worker'      => trim($r['worker_name'] ?? ''),
        'total_hours' => round($th, 2),
        'ot_hours'    => round($oh, 2),
        'ot_pct'      => $th > 0 ? round(100 * $oh / $th, 2) : 0,
    ];
}

// Top clients by OT hours.
$stmt = $pdo->prepare(
    "SELECT COALESCE(pl.end_client_name, '(unassigned)') AS client,
            COALESCE(SUM(CASE WHEN v.is_overtime = 1 THEN v.hours ELSE 0 END), 0) AS ot_hours,
            COALESCE(SUM(CASE WHEN v.is_overtime = 1 THEN v.gross_profit ELSE 0 END), 0) AS ot_margin
     FROM v_timesheet_day_fin v
     LEFT JOIN placements pl ON pl.id = v.placement_id AND pl.tenant_id = v.tenant_id
     WHERE v.tenant_id = :t AND v.work_date BETWEEN :from_ AND :to
     GROUP BY client
     HAVING ot_hours > 0
     ORDER BY ot_hours DESC
     LIMIT 20"
);
$stmt->execute($params);
$topClients = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $topClients[] = [
        'client'    => $r['client'],
        'ot_hours'  => round((float) $r['ot_hours'], 2),
        'ot_margin' => round((float) $r['ot_margin'], 2),
    ];
}

api_ok([
    'period'        => $period,
    'totals'        => $totals,
    'weekly_ot'     => $weekly,
    'top_employees' => $topEmployees,
    'top_clients'   => $topClients,
]);
