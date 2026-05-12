<?php
/**
 * /api/staffing/timesheets — Weekly timesheet CRUD + workflow.
 *
 *   GET    ?action=week&person_id=N&period_start=YYYY-MM-DD&period_end=YYYY-MM-DD
 *          → { timesheet, entries, settings }
 *
 *   POST   ?action=bulk_save
 *          body: { person_id, period_start, period_end, rows: [...] }
 *          → updated week snapshot
 *
 *   POST   ?action=submit       body: { person_id, period_start, period_end }
 *   POST   ?action=approve      body: { person_id, period_start, period_end }
 *   POST   ?action=reject       body: { person_id, period_start, period_end, reason }
 *
 *   GET    ?action=list&period_start=...&period_end=...&status=...
 *          → headers list (for approvals queue)
 *
 *   GET    ?action=settings    → tenant staffing settings
 *   POST   ?action=settings    body: { week_starts_on, contracted_hours_per_week, overtime_threshold }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../lib/timesheets.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$action = $_GET['action'] ?? 'week';
$method = api_method();

if ($action === 'settings') {
    if ($method === 'GET') {
        api_ok(['settings' => staffingSettings()]);
    }
    if ($method === 'POST') {
        $body = api_json_body();
        $weekStart = isset($body['week_starts_on']) ? (int) $body['week_starts_on'] : 1;
        if (!in_array($weekStart, [0, 1], true)) api_error('week_starts_on must be 0 (Sun) or 1 (Mon)', 422);
        $contracted = isset($body['contracted_hours_per_week']) ? (float) $body['contracted_hours_per_week'] : 40.0;
        $otThresh   = isset($body['overtime_threshold'])        ? (float) $body['overtime_threshold']        : 40.0;

        $pdo = getDB();
        $stmt = $pdo->prepare(
            "INSERT INTO tenant_staffing_settings (tenant_id, week_starts_on, contracted_hours_per_week, overtime_threshold)
             VALUES (:t, :w, :c, :o)
             ON DUPLICATE KEY UPDATE week_starts_on = :w2, contracted_hours_per_week = :c2, overtime_threshold = :o2"
        );
        $stmt->execute([
            't'  => currentTenantId(),
            'w'  => $weekStart, 'w2' => $weekStart,
            'c'  => $contracted, 'c2' => $contracted,
            'o'  => $otThresh,   'o2' => $otThresh,
        ]);
        api_ok(['settings' => staffingSettings()]);
    }
    api_error('Method not allowed', 405);
}

if ($method === 'GET' && $action === 'week') {
    $personId    = (int) ($_GET['person_id']    ?? ($user['person_id'] ?? 0));
    $periodStart = (string) ($_GET['period_start'] ?? '');
    $periodEnd   = (string) ($_GET['period_end']   ?? '');
    if ($personId <= 0)                api_error('person_id required', 422);
    if (!$periodStart || !$periodEnd)  api_error('period_start / period_end required', 422);

    $snap = staffingTimesheetWeek($personId, $periodStart, $periodEnd);
    api_ok([
        'timesheet' => $snap['timesheet'],
        'entries'   => $snap['entries'],
        'settings'  => staffingSettings(),
    ]);
}

if ($method === 'GET' && $action === 'list') {
    $where  = ['tenant_id = :tenant_id'];
    $params = [];
    if (!empty($_GET['status']))        { $where[] = 'status = :s';            $params['s']  = $_GET['status']; }
    if (!empty($_GET['period_start']))  { $where[] = 'period_start >= :ps';    $params['ps'] = $_GET['period_start']; }
    if (!empty($_GET['period_end']))    { $where[] = 'period_end <= :pe';      $params['pe'] = $_GET['period_end']; }
    if (!empty($_GET['person_id']))     { $where[] = 'person_id = :pid';       $params['pid']= (int) $_GET['person_id']; }
    $whereSql = implode(' AND ', $where);

    $rows = scopedQuery(
        "SELECT t.id, t.person_id, t.period_start, t.period_end, t.status,
                t.total_hours, t.submitted_at, t.approved_at, t.rejection_reason,
                p.first_name, p.last_name, p.email_primary
           FROM staffing_timesheets t
           LEFT JOIN people p ON p.id = t.person_id AND p.tenant_id = t.tenant_id
          WHERE {$whereSql}
          ORDER BY period_start DESC, t.id DESC
          LIMIT 200",
        $params
    );
    api_ok(['rows' => $rows]);
}

if ($method === 'GET' && $action === 'prefill_from_last_week') {
    $personId    = (int) ($_GET['person_id']    ?? ($user['person_id'] ?? 0));
    $periodStart = (string) ($_GET['period_start'] ?? '');
    $periodEnd   = (string) ($_GET['period_end']   ?? '');
    if ($personId <= 0)                api_error('person_id required', 422);
    if (!$periodStart || !$periodEnd)  api_error('period_start / period_end required', 422);

    $template = staffingTimesheetPriorWeekTemplate($personId, $periodStart, $periodEnd);
    api_ok($template);
}

if ($method === 'GET' && $action === 'week_economics') {
    $personId    = (int) ($_GET['person_id']    ?? ($user['person_id'] ?? 0));
    $periodStart = (string) ($_GET['period_start'] ?? '');
    $periodEnd   = (string) ($_GET['period_end']   ?? '');
    if ($personId <= 0)                api_error('person_id required', 422);
    if (!$periodStart || !$periodEnd)  api_error('period_start / period_end required', 422);

    // Read from the staffing reports view if present — gives accurate
    // revenue / cost / GP per row with bill/pay rates joined.
    try {
        $rows = scopedQuery(
            "SELECT v.placement_id,
                    SUM(v.hours)        AS hours,
                    SUM(v.revenue)      AS revenue,
                    SUM(v.cost)         AS cost,
                    SUM(v.gross_profit) AS gp
               FROM v_timesheet_day_fin v
              WHERE v.tenant_id = :tenant_id
                AND v.employee_id = :pid
                AND v.work_date BETWEEN :ps AND :pe
                AND v.entry_status != 'superseded'
              GROUP BY v.placement_id",
            ['pid' => $personId, 'ps' => $periodStart, 'pe' => $periodEnd]
        );
    } catch (\Throwable $_) {
        // View not built yet — return empty economics.
        $rows = [];
    }
    $total = ['revenue' => 0.0, 'cost' => 0.0, 'gp' => 0.0, 'hours' => 0.0];
    foreach ($rows as $r) {
        $total['revenue'] += (float) $r['revenue'];
        $total['cost']    += (float) $r['cost'];
        $total['gp']      += (float) $r['gp'];
        $total['hours']   += (float) $r['hours'];
    }
    $total['gp_pct'] = $total['revenue'] > 0 ? round($total['gp'] / $total['revenue'] * 100, 1) : 0;
    api_ok(['rows' => $rows, 'total' => $total]);
}

if ($method === 'POST' && $action === 'bulk_save') {
    $body = api_json_body();
    try {
        $snap = staffingTimesheetBulkSave((int) ($user['id'] ?? 0), $body);
        api_ok(['ok' => true, 'timesheet' => $snap['timesheet'], 'entries' => $snap['entries']]);
    } catch (\Throwable $e) {
        api_error('Bulk save failed: ' . $e->getMessage(), 422);
    }
}

if ($method === 'POST' && in_array($action, ['submit','approve','reject'], true)) {
    $body = api_json_body();
    $pid  = (int) ($body['person_id'] ?? 0);
    $ps   = (string) ($body['period_start'] ?? '');
    $pe   = (string) ($body['period_end']   ?? '');
    if ($pid <= 0 || !$ps || !$pe) api_error('person_id, period_start, period_end required', 422);

    try {
        if ($action === 'submit') {
            $snap = staffingTimesheetSubmit((int) ($user['id'] ?? 0), $pid, $ps, $pe);
        } elseif ($action === 'approve') {
            $snap = staffingTimesheetApprove((int) ($user['id'] ?? 0), $pid, $ps, $pe);
        } else { // reject
            $reason = trim((string) ($body['reason'] ?? ''));
            if ($reason === '') api_error('reason required', 422);
            $snap = staffingTimesheetReject((int) ($user['id'] ?? 0), $pid, $ps, $pe, $reason);
        }
        api_ok(['ok' => true, 'timesheet' => $snap['timesheet'], 'entries' => $snap['entries']]);
    } catch (\Throwable $e) {
        api_error($e->getMessage(), 422);
    }
}

api_error('Method not allowed or unknown action', 405);
