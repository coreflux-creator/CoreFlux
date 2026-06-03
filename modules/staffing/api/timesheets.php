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
    $where  = ['t.tenant_id = :tenant_id'];
    $params = [];
    if (!empty($_GET['status']))        { $where[] = 't.status = :s';            $params['s']  = $_GET['status']; }
    if (!empty($_GET['period_start']))  { $where[] = 't.period_start >= :ps';    $params['ps'] = $_GET['period_start']; }
    if (!empty($_GET['period_end']))    { $where[] = 't.period_end <= :pe';      $params['pe'] = $_GET['period_end']; }
    if (!empty($_GET['person_id']))     { $where[] = 't.person_id = :pid';       $params['pid']= (int) $_GET['person_id']; }
    $whereSql = implode(' AND ', $where);

    $rows = scopedQuery(
        "SELECT t.id, t.person_id, t.period_start, t.period_end, t.status,
                t.total_hours, t.submitted_at, t.approved_at, t.rejection_reason,
                p.first_name, p.last_name, p.email_primary
           FROM staffing_timesheets t
           LEFT JOIN people p ON p.id = t.person_id AND p.tenant_id = t.tenant_id
          WHERE {$whereSql}
          ORDER BY t.period_start DESC, t.id DESC
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

// ─── Single-timesheet drill-in (Batch 2 — 2026-02) ─────────────────────
//
// Click into a single timesheet from the list/queue instead of re-deriving
// it from (person_id, period_start, period_end). Returns the full header
// + every entry, with placement title + client name joined for the row
// renderer. Read-only on the API — the existing bulk_save path handles
// edits.
if ($method === 'GET' && $action === 'detail') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $header = scopedFind(
        'SELECT t.id, t.person_id, t.period_start, t.period_end, t.status,
                t.total_hours, t.submitted_at, t.approved_at, t.rejection_reason,
                t.created_at, t.updated_at,
                p.first_name, p.last_name, p.email_primary
           FROM staffing_timesheets t
      LEFT JOIN people p ON p.id = t.person_id AND p.tenant_id = t.tenant_id
          WHERE t.tenant_id = :tenant_id AND t.id = :id LIMIT 1',
        ['id' => $id]
    );
    if (!$header) api_error('timesheet not found', 404);
    $entries = scopedQuery(
        "SELECT te.id, te.placement_id, te.work_date, te.hour_type, te.category,
                te.hours, te.billable, te.payable, te.description, te.status,
                pl.title AS placement_title,
                COALESCE(pl.end_client_name, '') AS client_name
           FROM time_entries te
      LEFT JOIN placements pl ON pl.id = te.placement_id AND pl.tenant_id = te.tenant_id
          WHERE te.tenant_id = :tenant_id
            AND te.timesheet_id = :tid
            AND te.status != 'superseded'
          ORDER BY te.work_date, te.placement_id, te.id",
        ['tid' => $id]
    );
    api_ok(['timesheet' => $header, 'entries' => $entries]);
}

// ─── Placement-scoped timesheets list (Batch 2 — 2026-02) ──────────────
//
// "Show me every timesheet that touched THIS placement." Used by the new
// PlacementDetail → Timesheets tab to surface history + pending + create
// new affordances at the placement granularity.
if ($method === 'GET' && $action === 'list_for_placement') {
    $placementId = (int) ($_GET['placement_id'] ?? 0);
    if ($placementId <= 0) api_error('placement_id required', 400);
    $where  = ['t.tenant_id = :tenant_id', 'te.placement_id = :plid'];
    $params = ['plid' => $placementId];
    if (!empty($_GET['status'])) { $where[] = 't.status = :s'; $params['s'] = $_GET['status']; }
    if (!empty($_GET['period_start']))  { $where[] = 't.period_start >= :ps';    $params['ps'] = $_GET['period_start']; }
    if (!empty($_GET['period_end']))    { $where[] = 't.period_end <= :pe';      $params['pe'] = $_GET['period_end']; }
    $whereSql = implode(' AND ', $where);

    $rows = scopedQuery(
        "SELECT t.id, t.person_id, t.period_start, t.period_end, t.status,
                t.total_hours, t.submitted_at, t.approved_at, t.rejection_reason,
                p.first_name, p.last_name, p.email_primary,
                SUM(te.hours) AS placement_hours,
                SUM(CASE WHEN te.billable = 1 THEN te.hours ELSE 0 END) AS billable_hours
           FROM staffing_timesheets t
           JOIN time_entries te ON te.timesheet_id = t.id AND te.tenant_id = t.tenant_id
                                AND te.status != 'superseded'
      LEFT JOIN people p ON p.id = t.person_id AND p.tenant_id = t.tenant_id
          WHERE {$whereSql}
          GROUP BY t.id, t.person_id, t.period_start, t.period_end, t.status,
                   t.total_hours, t.submitted_at, t.approved_at, t.rejection_reason,
                   p.first_name, p.last_name, p.email_primary
          ORDER BY t.period_start DESC, t.id DESC
          LIMIT 500",
        $params
    );
    api_ok(['rows' => $rows, 'placement_id' => $placementId]);
}

// ─── Placement-scoped single-timesheet detail (Batch 2 — 2026-02) ──────
//
// View a single timesheet's entries filtered to just one placement.
// Useful when the timesheet covers multiple placements but the operator
// only cares about THIS one's hours (e.g. for billing or pay rec).
if ($method === 'GET' && $action === 'detail_for_placement') {
    $id          = (int) ($_GET['id'] ?? 0);
    $placementId = (int) ($_GET['placement_id'] ?? 0);
    if ($id <= 0)          api_error('id required', 400);
    if ($placementId <= 0) api_error('placement_id required', 400);
    $header = scopedFind(
        'SELECT t.id, t.person_id, t.period_start, t.period_end, t.status,
                t.total_hours, t.submitted_at, t.approved_at, t.rejection_reason,
                p.first_name, p.last_name, p.email_primary
           FROM staffing_timesheets t
      LEFT JOIN people p ON p.id = t.person_id AND p.tenant_id = t.tenant_id
          WHERE t.tenant_id = :tenant_id AND t.id = :id LIMIT 1',
        ['id' => $id]
    );
    if (!$header) api_error('timesheet not found', 404);
    $entries = scopedQuery(
        "SELECT te.id, te.placement_id, te.work_date, te.hour_type, te.category,
                te.hours, te.billable, te.payable, te.description, te.status,
                pl.title AS placement_title,
                COALESCE(pl.end_client_name, '') AS client_name
           FROM time_entries te
      LEFT JOIN placements pl ON pl.id = te.placement_id AND pl.tenant_id = te.tenant_id
          WHERE te.tenant_id = :tenant_id
            AND te.timesheet_id = :tid
            AND te.placement_id = :plid
            AND te.status != 'superseded'
          ORDER BY te.work_date, te.id",
        ['tid' => $id, 'plid' => $placementId]
    );
    // Per-placement total so the UI can show "8h of 40h total for this person"
    $plHours = 0.0;
    foreach ($entries as $e) $plHours += (float) $e['hours'];
    api_ok([
        'timesheet'           => $header,
        'entries'             => $entries,
        'placement_id'        => $placementId,
        'placement_hours'     => $plHours,
    ]);
}

// ─── Approved-entry picker (Batch 4 — 2026-02) ─────────────────────────
//
// Surfaces approved/billable time entries matching a placement + date
// range so the operator can hand-pick rows for the new flexible
// invoice/payable creation flow. Returns lightweight rows (no rate
// resolution — that happens server-side at draft time).
if ($method === 'GET' && $action === 'approved_entries') {
    $where  = [
        'te.tenant_id = :tenant_id',
        "te.status IN ('approved','locked','payroll_ready','billing_ready')",
        "te.hours > 0",
    ];
    $params = [];
    if (!empty($_GET['placement_id']))  { $where[] = 'te.placement_id = :plid';  $params['plid'] = (int) $_GET['placement_id']; }
    if (!empty($_GET['person_id']))     { $where[] = 'te.person_id = :pid';      $params['pid']  = (int) $_GET['person_id']; }
    if (!empty($_GET['date_from']))     { $where[] = 'te.work_date >= :df';      $params['df']   = $_GET['date_from']; }
    if (!empty($_GET['date_to']))       { $where[] = 'te.work_date <= :dt';      $params['dt']   = $_GET['date_to']; }
    $purpose = $_GET['purpose'] ?? 'billable';
    if ($purpose === 'payable')   $where[] = 'te.payable = 1';
    elseif ($purpose === 'billable') $where[] = 'te.billable = 1';
    $whereSql = implode(' AND ', $where);

    $rows = scopedQuery(
        "SELECT te.id, te.placement_id, te.person_id, te.work_date, te.hour_type,
                te.hours, te.billable, te.payable, te.status, te.description,
                pl.title AS placement_title,
                COALESCE(pl.end_client_name, '') AS client_name,
                p.first_name, p.last_name, p.email_primary
           FROM time_entries te
      LEFT JOIN placements pl ON pl.id = te.placement_id AND pl.tenant_id = te.tenant_id
      LEFT JOIN people p ON p.id = te.person_id AND p.tenant_id = te.tenant_id
          WHERE {$whereSql}
          ORDER BY te.work_date ASC, te.placement_id, te.id
          LIMIT 500",
        $params
    );
    api_ok(['rows' => $rows, 'purpose' => $purpose]);
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
