<?php
/**
 * Accounting API — Periods + close workflow
 *
 *   GET    /api/accounting/periods?entity_id=N&from=YYYY-MM-DD&to=YYYY-MM-DD
 *   POST   /api/accounting/periods?action=soft_close&id=N
 *   POST   /api/accounting/periods?action=close&id=N
 *   POST   /api/accounting/periods?action=lock&id=N         body: {reason}
 *   POST   /api/accounting/periods?action=reopen&id=N      body: {reason}
 *
 * Lifecycle per SPEC §6:
 *   open → soft_closed (reportable, draftable, no posting) → closed → locked
 *                                                             ↓        ↓
 *                                                           reopen    reopen
 *                                                                  (master_admin only)
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/accounting.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET') {
    rbac_legacy_require($user, 'accounting.coa.view');
    $where  = ['tenant_id = :tenant_id'];
    $params = [];
    if (!empty($_GET['entity_id'])) { $where[] = 'entity_id = :e'; $params['e'] = (int) $_GET['entity_id']; }
    if (!empty($_GET['from']))      { $where[] = 'end_date   >= :f'; $params['f'] = $_GET['from']; }
    if (!empty($_GET['to']))        { $where[] = 'start_date <= :t'; $params['t'] = $_GET['to']; }
    $rows = scopedQuery(
        'SELECT id, entity_id, period_number, start_date, end_date, status, closed_at, closed_by_user_id, reopened_at, reopen_reason
         FROM accounting_periods WHERE ' . implode(' AND ', $where) . '
         ORDER BY start_date DESC LIMIT 200',
        $params
    );
    api_ok(['rows' => $rows]);
}

if ($method === 'POST' && $action === 'create') {
    // Explicit period creation — operators wanted "Define a period" UI
    // alongside the auto-create-on-post path that `accountingResolvePeriod`
    // uses. Tenant-scoped, audit-logged, idempotent on overlap.
    rbac_legacy_require($user, 'accounting.period.close'); // same gate as close — admin-ish
    $body = api_json_body();
    $entityId  = (int) ($body['entity_id'] ?? 0);
    $startDate = trim((string) ($body['start_date'] ?? ''));
    $endDate   = trim((string) ($body['end_date']   ?? ''));
    $statusIn  = trim((string) ($body['status']     ?? 'open'));
    $pnumIn    = (int) ($body['period_number'] ?? 0);
    if ($entityId <= 0)                                api_error('entity_id required', 422);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) api_error('start_date must be YYYY-MM-DD', 422);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate))   api_error('end_date must be YYYY-MM-DD',   422);
    if ($startDate > $endDate)                          api_error('start_date must be <= end_date', 422);
    $allowed = ['open','soft_closed','closed','locked','reopened'];
    if (!in_array($statusIn, $allowed, true)) api_error('Invalid status', 422, ['allowed' => $allowed]);

    $pdo = getDB();

    // Reject overlap on the same entity (overlap = any date intersection).
    $overlap = $pdo->prepare(
        'SELECT id, period_number, start_date, end_date, status
           FROM accounting_periods
          WHERE tenant_id = :t AND entity_id = :e
            AND start_date <= :ed AND end_date >= :sd
          LIMIT 1'
    );
    $overlap->execute(['t' => $tid, 'e' => $entityId, 'sd' => $startDate, 'ed' => $endDate]);
    if ($existing = $overlap->fetch(\PDO::FETCH_ASSOC)) {
        api_error('Overlapping period already defined', 409, ['existing' => $existing]);
    }

    // Auto-compute period_number from end_date.month if not supplied.
    $periodNumber = $pnumIn > 0 ? $pnumIn : (int) date('n', strtotime($endDate));

    try {
        $pdo->prepare(
            'INSERT INTO accounting_periods
               (tenant_id, entity_id, period_number, start_date, end_date, status, created_at)
             VALUES (:t, :e, :n, :s, :x, :st, NOW())'
        )->execute([
            't'  => $tid, 'e' => $entityId, 'n' => $periodNumber,
            's'  => $startDate, 'x' => $endDate, 'st' => $statusIn,
        ]);
    } catch (\Throwable $e) {
        // Older schemas may not have a created_at column — retry without it.
        $pdo->prepare(
            'INSERT INTO accounting_periods
               (tenant_id, entity_id, period_number, start_date, end_date, status)
             VALUES (:t, :e, :n, :s, :x, :st)'
        )->execute([
            't'  => $tid, 'e' => $entityId, 'n' => $periodNumber,
            's'  => $startDate, 'x' => $endDate, 'st' => $statusIn,
        ]);
    }
    $newId = (int) $pdo->lastInsertId();
    accountingAudit('accounting.period.created', [
        'period_id' => $newId, 'entity_id' => $entityId,
        'start_date' => $startDate, 'end_date' => $endDate, 'status' => $statusIn,
    ], $newId);
    api_ok(['id' => $newId, 'period_number' => $periodNumber, 'status' => $statusIn], 201);
}

if ($method === 'POST' && in_array($action, ['soft_close','close','lock','reopen'], true)) {
    $perm = $action === 'reopen' ? 'accounting.period.reopen'
          : ($action === 'lock'  ? 'accounting.period.lock'
          : 'accounting.period.close');
    rbac_legacy_require($user, $perm);
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $row = scopedFind('SELECT * FROM accounting_periods WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);

    $body   = api_json_body();
    $reason = trim((string) ($body['reason'] ?? ''));

    $pdo = getDB();
    $now = date('Y-m-d H:i:s');

    if ($action === 'soft_close') {
        if (!in_array($row['status'], ['open','reopened'], true)) {
            api_error("Cannot soft-close from status {$row['status']}", 409);
        }
        // P1.8 — close-packet blocking gate. Spec re-audit:
        // "Accounting close packets must be wired into the actual close
        //  workflow (not just data files). Owners + due-dates + blocking
        //  gates active." We refuse soft-close while any close task
        //  is still pending / in_progress / blocked. Tasks explicitly
        //  marked 'skipped' or 'done' don't block. Override is allowed
        //  with body.close_with_open_tasks=true + reason — audit-logged.
        $blockers = $pdo->prepare(
            "SELECT id, task_key, title, status, assignee_user_id, due_date
               FROM accounting_close_tasks
              WHERE tenant_id = :t AND period_id = :p
                AND status IN ('pending','in_progress','blocked')
              ORDER BY due_date IS NULL, due_date ASC, sort_order ASC"
        );
        $blockers->execute(['t' => $tid, 'p' => $id]);
        $openTasks = $blockers->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        if ($openTasks) {
            $override = !empty($body['close_with_open_tasks']);
            if (!$override || $reason === '') {
                api_error(
                    'Soft-close blocked: ' . count($openTasks)
                    . ' close task(s) still open. Resolve them, mark them skipped,'
                    . ' OR re-submit with close_with_open_tasks=true + reason.',
                    409,
                    ['code' => 'close_tasks_open', 'open_tasks' => $openTasks]
                );
            }
            accountingAudit('accounting.period.soft_close_open_tasks_override', [
                'period_id' => $id, 'open_count' => count($openTasks),
                'reason'    => $reason, 'task_keys' => array_column($openTasks, 'task_key'),
            ], $id);
        }
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare(
            'UPDATE accounting_periods
             SET status = "soft_closed", closed_at = :ts, closed_by_user_id = :u
             WHERE id = :id'
        )->execute(['ts' => $now, 'u' => $user['id'] ?? null, 'id' => $id]);
        accountingAudit('accounting.period.soft_closed', ['period_id' => $id, 'period_number' => (int) $row['period_number']], $id);
    }
    if ($action === 'close') {
        if (!in_array($row['status'], ['open','soft_closed','reopened'], true)) {
            api_error("Cannot close from status {$row['status']}", 409);
        }
        // P1.8 — same blocking gate on hard close.
        $blockers = $pdo->prepare(
            "SELECT id, task_key, title, status, assignee_user_id, due_date
               FROM accounting_close_tasks
              WHERE tenant_id = :t AND period_id = :p
                AND status IN ('pending','in_progress','blocked')
              ORDER BY due_date IS NULL, due_date ASC, sort_order ASC"
        );
        $blockers->execute(['t' => $tid, 'p' => $id]);
        $openTasks = $blockers->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        if ($openTasks) {
            $override = !empty($body['close_with_open_tasks']);
            if (!$override || $reason === '') {
                api_error(
                    'Period close blocked: ' . count($openTasks)
                    . ' close task(s) still open. Resolve them, mark them skipped,'
                    . ' OR re-submit with close_with_open_tasks=true + reason.',
                    409,
                    ['code' => 'close_tasks_open', 'open_tasks' => $openTasks]
                );
            }
            accountingAudit('accounting.period.close_open_tasks_override', [
                'period_id' => $id, 'open_count' => count($openTasks),
                'reason'    => $reason, 'task_keys' => array_column($openTasks, 'task_key'),
            ], $id);
        }
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare(
            'UPDATE accounting_periods
             SET status = "closed", closed_at = :ts, closed_by_user_id = :u
             WHERE id = :id'
        )->execute(['ts' => $now, 'u' => $user['id'] ?? null, 'id' => $id]);
        accountingAudit('accounting.period.closed', ['period_id' => $id, 'period_number' => (int) $row['period_number']], $id);
    }
    if ($action === 'lock') {
        if ($reason === '') api_error('reason required to lock a period', 422);
        if ($row['status'] !== 'closed') {
            api_error("Period must be 'closed' before lock; current: {$row['status']}", 409);
        }
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare(
            'UPDATE accounting_periods
             SET status = "locked", locked_at = :ts, locked_by_user_id = :u
             WHERE id = :id'
        )->execute(['ts' => $now, 'u' => $user['id'] ?? null, 'id' => $id]);
        accountingAudit('accounting.period.locked', [
            'period_id' => $id, 'period_number' => (int) $row['period_number'], 'reason' => $reason,
        ], $id);
    }
    if ($action === 'reopen') {
        if ($reason === '') api_error('reason required to reopen a closed period', 422);
        if (!in_array($row['status'], ['closed','soft_closed','locked'], true)) {
            api_error("Cannot reopen from status {$row['status']}", 409);
        }
        // Reopening a locked period requires master_admin per spec §6.
        if ($row['status'] === 'locked' && ($user['role'] ?? '') !== 'master_admin') {
            api_error('Only master_admin can reopen a locked period', 403);
        }
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare(
            'UPDATE accounting_periods
             SET status = "reopened", reopened_at = :ts, reopened_by_user_id = :u, reopen_reason = :r
             WHERE id = :id'
        )->execute(['ts' => $now, 'u' => $user['id'] ?? null, 'r' => $reason, 'id' => $id]);
        accountingAudit('accounting.period.reopened', ['period_id' => $id, 'period_number' => (int) $row['period_number'], 'reason' => $reason], $id);

        // Auto-reverse every locked consolidation run whose period_to
        // falls inside the reopened period. Audit trail is preserved —
        // no deletion, just status = reversed.
        require_once __DIR__ . '/../lib/consolidation.php';
        $runsStmt = $pdo->prepare(
            'SELECT id FROM accounting_consolidation_runs
             WHERE tenant_id = :t AND status = "locked"
               AND period_to >= :sd AND period_to <= :ed'
        );
        $runsStmt->execute(['t' => $tid, 'sd' => $row['start_date'], 'ed' => $row['end_date']]);
        $affected = 0;
        foreach ($runsStmt->fetchAll(\PDO::FETCH_ASSOC) as $rr) {
            try {
                consolidationReverseRun($tid, (int) $rr['id'], 'Period reopened: ' . $reason, $user['id'] ?? null);
                $affected++;
            } catch (\Throwable $_) { /* already reversed */ }
        }
        if ($affected > 0) {
            accountingAudit('accounting.consolidation.runs_auto_reversed', [
                'period_id' => $id, 'reversed_count' => $affected,
            ], $id);
        }
    }
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
