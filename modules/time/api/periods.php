<?php
/**
 * Time API — periods
 *
 *   GET  /api/time/periods                      → list (optionally with status filter)
 *   GET  /api/time/periods?action=preview_close&id=N → bundle preview (no writes)
 *   POST /api/time/periods                      → create period
 *   POST /api/time/periods?action=close&id=N    → close period + build downstream bundles
 *   POST /api/time/periods?action=reopen&id=N   → reopen (if no consumed bundles)
 *   POST /api/time/periods?action=generate&weeks=8 → auto-generate N weekly periods forward
 *
 * SPEC: /app/modules/time/SPEC.md §5.4
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/time.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'preview_close') {
    rbac_legacy_require($user, 'time.period.close');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $period = scopedFind('SELECT id, status FROM time_periods WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$period) api_error('Not found', 404);
    if ($period['status'] === 'closed') api_error('Already closed', 409);
    api_ok(timePreviewBundlesForPeriod($id));
}

if ($method === 'GET') {
    rbac_legacy_require($user, 'time.view');
    $where = ['tenant_id = :tenant_id'];
    $params = [];
    if (!empty($_GET['status'])) { $where[] = 'status = :status'; $params['status'] = $_GET['status']; }
    $rows = scopedQuery(
        'SELECT * FROM time_periods WHERE ' . implode(' AND ', $where) . ' ORDER BY start_date DESC LIMIT 100',
        $params
    );
    api_ok(['rows' => $rows]);
}

if ($method === 'POST' && $action === 'build_bundles') {
    // Operator request: "how can a period be closed before we've
    // invoiced, booked payables, run payroll, etc?" — exactly right.
    // Bundle build was historically chained to ?action=close, which
    // created a deadlock: you couldn't invoice until you closed, and
    // closing is by definition the LAST step. This endpoint lets the
    // bundle build run on any *open* (or locked) period so downstream
    // modules can consume approved hours throughout the period.
    //
    // Idempotent: re-running replaces `ready` bundles in place;
    // bundles already `consumed` by an invoice are left untouched —
    // the underlying `timeBuildBundlesForPeriod` helper has handled
    // this semantics since day one.
    rbac_legacy_require($user, 'time.period.close');
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);

    $period = scopedFind('SELECT * FROM time_periods WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$period) api_error('Not found', 404);
    if ($period['status'] === 'closed') {
        api_error('Period is closed — bundles are immutable. Reopen first if you need to rebuild.', 409);
    }

    $built = timeBuildBundlesForPeriod($id);
    timeAudit('time.period.bundles_rebuilt', [
        'period_id' => $id,
        'period_status' => $period['status'],
        'bundles_built' => count($built),
    ], $id);
    foreach ($built as $b) timeAudit('time.feed.bundle_built', $b, (int) $b['id']);
    api_ok(['ok' => true, 'bundles_built' => count($built), 'bundles' => $built, 'period_status' => $period['status']]);
}

if ($method === 'POST' && $action === 'close') {
    rbac_legacy_require($user, 'time.period.close');
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);

    $period = scopedFind('SELECT * FROM time_periods WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$period) api_error('Not found', 404);
    if ($period['status'] === 'closed') api_error('Already closed', 409);

    // Cannot close while pending_review exists
    $pending = scopedFind(
        'SELECT COUNT(*) AS c FROM time_entries WHERE tenant_id = :tenant_id AND period_id = :pid AND status = "pending_review"',
        ['pid' => $id]
    );
    if ((int) ($pending['c'] ?? 0) > 0) {
        api_error('Cannot close: ' . (int) $pending['c'] . ' entries still pending_review', 422);
    }

    $built = timeBuildBundlesForPeriod($id);
    scopedUpdate('time_periods', $id, [
        'status'           => 'closed',
        'closed_at'        => date('Y-m-d H:i:s'),
        'closed_by_user_id'=> $user['id'] ?? null,
    ]);
    timeAudit('time.period.closed', ['period_id' => $id, 'bundles_built' => count($built)], $id);
    foreach ($built as $b) timeAudit('time.feed.bundle_built', $b, (int) $b['id']);
    api_ok(['ok' => true, 'bundles_built' => count($built), 'bundles' => $built]);
}

if ($method === 'POST' && $action === 'reopen') {
    rbac_legacy_require($user, 'time.period.close');
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);

    $consumed = scopedFind(
        'SELECT COUNT(*) AS c FROM time_downstream_feed
         WHERE tenant_id = :tenant_id AND period_id = :pid AND status = "consumed"',
        ['pid' => $id]
    );
    if ((int) ($consumed['c'] ?? 0) > 0) {
        api_error('Cannot reopen: ' . (int) $consumed['c'] . ' downstream bundles already consumed. Use corrections instead.', 409);
    }
    scopedUpdate('time_periods', $id, ['status' => 'open', 'closed_at' => null, 'closed_by_user_id' => null]);
    timeAudit('time.period.reopened', ['period_id' => $id], $id);
    api_ok(['ok' => true]);
}

if ($method === 'POST' && $action === 'generate') {
    rbac_legacy_require($user, 'time.period.close');
    $weeks = max(1, min(52, (int) ($_GET['weeks'] ?? 8)));
    // Generate weekly periods starting from the Monday of the current week.
    $today = new DateTime();
    $dow = (int) $today->format('N'); // Mon=1..Sun=7
    $monday = $today->modify('-' . ($dow - 1) . ' days');
    $created = [];
    for ($i = 0; $i < $weeks; $i++) {
        $start = (clone $monday)->modify("+{$i} week");
        $end   = (clone $start)->modify('+6 days');
        $startStr = $start->format('Y-m-d');
        $endStr   = $end->format('Y-m-d');
        $label    = $start->format('o-\WW');
        $exists = scopedFind(
            'SELECT id FROM time_periods WHERE tenant_id = :tenant_id AND start_date = :sd AND end_date = :ed',
            ['sd' => $startStr, 'ed' => $endStr]
        );
        if ($exists) continue;
        $newId = scopedInsert('time_periods', [
            'period_type' => 'weekly',
            'start_date'  => $startStr,
            'end_date'    => $endStr,
            'label'       => $label,
            'status'      => 'open',
        ]);
        $created[] = ['id' => $newId, 'label' => $label, 'start_date' => $startStr, 'end_date' => $endStr];
        timeAudit('time.period.opened', ['period_id' => $newId, 'label' => $label], $newId);
    }
    api_ok(['created' => $created, 'count' => count($created)]);
}

if ($method === 'POST') {
    rbac_legacy_require($user, 'time.period.close');
    $body = api_json_body();
    api_require_fields($body, ['start_date','end_date','label']);
    $id = scopedInsert('time_periods', [
        'period_type' => $body['period_type'] ?? 'weekly',
        'start_date'  => $body['start_date'],
        'end_date'    => $body['end_date'],
        'label'       => $body['label'],
        'status'      => 'open',
    ]);
    timeAudit('time.period.opened', ['period_id' => $id, 'label' => $body['label']], $id);
    api_ok(['id' => $id], 201);
}

api_error('Method not allowed', 405);
