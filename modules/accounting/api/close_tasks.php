<?php
/**
 * Accounting API — period close workflow.
 *
 *   GET   /api/accounting/close_tasks?period_id=N         → list checklist tasks
 *   POST  /api/accounting/close_tasks?action=seed         → seed default checklist for a period
 *   POST  /api/accounting/close_tasks?action=complete&id=N
 *   PATCH /api/accounting/close_tasks                     → update task (assignee, due_date, status, notes)
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/close.php';

$ctx      = api_require_auth();
$user     = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
$method   = api_method();
$action   = (string) (api_query('action') ?? '');

if ($method === 'GET') {
    RBAC::requirePermission($user, 'accounting.period.view');
    $periodId = (int) (api_query('period_id') ?? 0);
    if (!$periodId) api_error('period_id required', 422);
    $rows = scopedQuery(
        "SELECT t.*,
                u.name AS assignee_name,
                cu.name AS completed_by_name
           FROM accounting_close_tasks t
           LEFT JOIN users u  ON u.id  = t.assignee_user_id
           LEFT JOIN users cu ON cu.id = t.completed_by_user_id
          WHERE t.tenant_id = :tenant_id AND t.period_id = :p
          ORDER BY t.sort_order, t.id",
        ['p' => $periodId]
    );
    $stats = [
        'total'      => count($rows),
        'done'       => count(array_filter($rows, fn($r) => $r['status'] === 'done')),
        'pending'    => count(array_filter($rows, fn($r) => $r['status'] === 'pending')),
        'in_progress'=> count(array_filter($rows, fn($r) => $r['status'] === 'in_progress')),
        'blocked'    => count(array_filter($rows, fn($r) => $r['status'] === 'blocked')),
    ];
    api_ok(['period_id' => $periodId, 'tasks' => $rows, 'stats' => $stats]);
}

if ($method === 'POST' && $action === 'seed') {
    RBAC::requirePermission($user, 'accounting.close_workflow.manage');
    $body = api_json_body();
    api_require_fields($body, ['period_id']);
    $added = accountingSeedCloseChecklist($tenantId, (int) $body['period_id'], (int) ($user['id'] ?? 0));
    api_ok(['period_id' => (int) $body['period_id'], 'added' => $added]);
}

if ($method === 'POST' && $action === 'complete') {
    RBAC::requirePermission($user, 'accounting.close_task.complete');
    $id = (int) (api_query('id') ?? 0);
    if (!$id) api_error('id required', 422);
    $body = api_json_body();
    $row = accountingCompleteCloseTask($tenantId, $id, (int) ($user['id'] ?? 0), $body['notes'] ?? null);
    api_ok(['task' => $row]);
}

if ($method === 'PATCH') {
    RBAC::requirePermission($user, 'accounting.close_task.assign');
    $body = api_json_body();
    api_require_fields($body, ['id']);
    $update = [];
    foreach (['assignee_user_id','due_date','status','notes','title','description'] as $k) {
        if (array_key_exists($k, $body)) $update[$k] = $body[$k];
    }
    if (!$update) api_error('no fields to update', 422);
    scopedUpdate('accounting_close_tasks', (int) $body['id'], $update);
    api_ok(['ok' => true]);
}

api_error('Unknown method/action', 405);
