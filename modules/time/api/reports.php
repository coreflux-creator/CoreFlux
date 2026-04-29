<?php
/**
 * Time API — reports (SPEC §5.8)
 *   GET /api/time/reports?type=by_placement&period_id=N
 *   GET /api/time/reports?type=by_person&period_id=N
 *   GET /api/time/reports?type=utilization&period_id=N
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/time.php';

$ctx = api_require_auth();
$user = $ctx['user'];
if (api_method() !== 'GET') api_error('Method not allowed', 405);
RBAC::requirePermission($user, 'time.view');

$type = $_GET['type'] ?? '';
$periodId = (int) ($_GET['period_id'] ?? 0);
if ($periodId <= 0) api_error('period_id required', 400);

if ($type === 'by_placement') {
    $rows = scopedQuery(
        'SELECT te.placement_id, pl.title, pl.end_client_name,
                SUM(CASE WHEN te.category IN ("regular_billable","OT_billable") THEN te.hours ELSE 0 END) AS billable,
                SUM(CASE WHEN te.category IN ("regular_nonbillable","OT_nonbillable") THEN te.hours ELSE 0 END) AS nonbillable,
                SUM(CASE WHEN te.category IN ("holiday","vacation","sick","bereavement") THEN te.hours ELSE 0 END) AS pto,
                SUM(CASE WHEN te.category = "unpaid_leave" THEN te.hours ELSE 0 END) AS unpaid,
                SUM(te.hours) AS total,
                COUNT(*) AS entry_count
         FROM time_entries te
         LEFT JOIN placements pl ON pl.id = te.placement_id AND pl.tenant_id = te.tenant_id
         WHERE te.tenant_id = :tenant_id AND te.period_id = :pid AND te.status != "superseded"
         GROUP BY te.placement_id
         ORDER BY billable DESC',
        ['pid' => $periodId]
    );
    api_ok(['rows' => $rows]);
}

if ($type === 'by_person') {
    $rows = scopedQuery(
        'SELECT te.person_id, pe.first_name, pe.last_name, pe.email_primary,
                SUM(CASE WHEN te.category IN ("regular_billable","OT_billable") THEN te.hours ELSE 0 END) AS billable,
                SUM(CASE WHEN te.category IN ("regular_nonbillable","OT_nonbillable") THEN te.hours ELSE 0 END) AS nonbillable,
                SUM(CASE WHEN te.category IN ("holiday","vacation","sick","bereavement") THEN te.hours ELSE 0 END) AS pto,
                SUM(te.hours) AS total
         FROM time_entries te
         LEFT JOIN people pe ON pe.id = te.person_id AND pe.tenant_id = te.tenant_id
         WHERE te.tenant_id = :tenant_id AND te.period_id = :pid AND te.status != "superseded"
         GROUP BY te.person_id
         ORDER BY billable DESC',
        ['pid' => $periodId]
    );
    api_ok(['rows' => $rows]);
}

if ($type === 'utilization') {
    $row = scopedFind(
        'SELECT
            SUM(CASE WHEN category IN ("regular_billable","OT_billable") THEN hours ELSE 0 END) AS billable,
            SUM(CASE WHEN category IN ("regular_nonbillable","OT_nonbillable") THEN hours ELSE 0 END) AS nonbillable,
            SUM(CASE WHEN category IN ("holiday","vacation","sick","bereavement") THEN hours ELSE 0 END) AS pto,
            SUM(CASE WHEN category = "unpaid_leave" THEN hours ELSE 0 END) AS unpaid,
            SUM(hours) AS total
         FROM time_entries
         WHERE tenant_id = :tenant_id AND period_id = :pid AND status != "superseded"',
        ['pid' => $periodId]
    );
    $total = (float) ($row['total'] ?? 0);
    $billable_pct    = $total > 0 ? round(100 * (float) $row['billable']    / $total, 2) : 0;
    $nonbillable_pct = $total > 0 ? round(100 * (float) $row['nonbillable'] / $total, 2) : 0;
    $pto_pct         = $total > 0 ? round(100 * (float) $row['pto']         / $total, 2) : 0;
    api_ok([
        'totals'   => $row,
        'billable_pct'    => $billable_pct,
        'nonbillable_pct' => $nonbillable_pct,
        'pto_pct'         => $pto_pct,
    ]);
}

api_error('Unknown type. Use ?type=by_placement|by_person|utilization', 400);
