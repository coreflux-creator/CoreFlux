<?php
/**
 * Accounting API — Periods + close workflow
 *
 *   GET    /api/accounting/periods?entity_id=N&from=YYYY-MM-DD&to=YYYY-MM-DD
 *   POST   /api/accounting/periods?action=soft_close&id=N
 *   POST   /api/accounting/periods?action=close&id=N
 *   POST   /api/accounting/periods?action=reopen&id=N      body: {reason}
 *
 * Lifecycle per SPEC:
 *   open → soft_closed (draftable + reportable, no posting) → closed
 *                                                             ↓
 *                                                          reopened (audit-required)
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
    RBAC::requirePermission($user, 'accounting.coa.view');
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

if ($method === 'POST' && in_array($action, ['soft_close','close','reopen'], true)) {
    $perm = $action === 'reopen' ? 'accounting.period.reopen' : 'accounting.period.close';
    RBAC::requirePermission($user, $perm);
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
        $pdo->prepare(
            'UPDATE accounting_periods
             SET status = "closed", closed_at = :ts, closed_by_user_id = :u
             WHERE id = :id'
        )->execute(['ts' => $now, 'u' => $user['id'] ?? null, 'id' => $id]);
        accountingAudit('accounting.period.closed', ['period_id' => $id, 'period_number' => (int) $row['period_number']], $id);
    }
    if ($action === 'reopen') {
        if ($reason === '') api_error('reason required to reopen a closed period', 422);
        if (!in_array($row['status'], ['closed','soft_closed'], true)) {
            api_error("Cannot reopen from status {$row['status']}", 409);
        }
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
