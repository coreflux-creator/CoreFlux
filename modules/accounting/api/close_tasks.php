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

function accountingLockEditableClosePeriod(\PDO $pdo, int $tenantId, int $periodId, int $entityId): void {
    $entity = $pdo->prepare('SELECT id FROM accounting_entities
        WHERE tenant_id = :t AND id = :e FOR UPDATE');
    $entity->execute(['t' => $tenantId, 'e' => $entityId]);
    if (!$entity->fetchColumn()) throw new \DomainException('Legal entity not found');

    $period = $pdo->prepare('SELECT entity_id, status FROM accounting_periods
        WHERE tenant_id = :t AND id = :p FOR UPDATE');
    $period->execute(['t' => $tenantId, 'p' => $periodId]);
    $current = $period->fetch(\PDO::FETCH_ASSOC);
    if (!$current || (int) $current['entity_id'] !== $entityId) {
        throw new \DomainException('Period changed; refresh and retry');
    }
    if (!in_array($current['status'], ['open', 'reopened', 'soft_closed'], true)) {
        throw new \DomainException('Reopen the period to change its close checklist');
    }
}

function accountingEditableReviewTask(\PDO $pdo, int $tenantId, int $taskId, int $periodId): void {
    $task = $pdo->prepare('SELECT task_key FROM accounting_close_tasks
        WHERE tenant_id = :t AND id = :id AND period_id = :p FOR UPDATE');
    $task->execute(['t' => $tenantId, 'id' => $taskId, 'p' => $periodId]);
    $key = $task->fetchColumn();
    if (!$key) throw new \DomainException('Close task not found');
    if (in_array($key, ['lock_period', 'build_packet'], true)) {
        throw new \DomainException('This step is completed by saving or locking the period');
    }
}

if ($method === 'GET') {
    rbac_legacy_require($user, 'accounting.period.view');
    $periodId = (int) (api_query('period_id') ?? 0);
    if (!$periodId) api_error('period_id required', 422);
    if (!scopedFind('SELECT id FROM accounting_periods WHERE tenant_id = :tenant_id AND id = :id',
        ['id' => $periodId])) api_error('Period not found', 404);
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
    rbac_legacy_require($user, 'accounting.close_workflow.manage');
    $body = api_json_body();
    api_require_fields($body, ['period_id']);
    $periodId = (int) $body['period_id'];
    $period = scopedFind('SELECT entity_id FROM accounting_periods
        WHERE tenant_id = :tenant_id AND id = :id', ['id' => $periodId]);
    if (!$period) api_error('Period not found', 404);
    $pdo = getDB();
    try {
        $pdo->beginTransaction();
        accountingLockEditableClosePeriod($pdo, $tenantId, $periodId, (int) $period['entity_id']);
        $added = accountingSeedCloseChecklist($tenantId, $periodId, (int) ($user['id'] ?? 0));
        $pdo->commit();
    } catch (\DomainException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        api_error($e->getMessage(), $e->getMessage() === 'Period not found' ? 404 : 409);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[accounting.close_tasks.seed] ' . $e->getMessage());
        api_error('Could not start the close checklist', 500);
    }
    api_ok(['period_id' => (int) $body['period_id'], 'added' => $added]);
}

if ($method === 'POST' && $action === 'complete') {
    rbac_legacy_require($user, 'accounting.close_task.complete');
    $id = (int) (api_query('id') ?? 0);
    if (!$id) api_error('id required', 422);
    $body = api_json_body();
    $task = scopedFind('SELECT t.period_id, p.entity_id FROM accounting_close_tasks t
        JOIN accounting_periods p ON p.id = t.period_id AND p.tenant_id = t.tenant_id
        WHERE t.tenant_id = :tenant_id AND t.id = :id', ['id' => $id]);
    if (!$task) api_error('Close task not found', 404);
    $pdo = getDB();
    try {
        $pdo->beginTransaction();
        accountingLockEditableClosePeriod($pdo, $tenantId, (int) $task['period_id'], (int) $task['entity_id']);
        accountingEditableReviewTask($pdo, $tenantId, $id, (int) $task['period_id']);
        $row = accountingCompleteCloseTask($tenantId, $id, (int) ($user['id'] ?? 0), $body['notes'] ?? null);
        $pdo->commit();
    } catch (\DomainException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        api_error($e->getMessage(), 409);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[accounting.close_tasks.complete] ' . $e->getMessage());
        api_error('Could not complete the close task', 500);
    }
    api_ok(['task' => $row]);
}

if ($method === 'PATCH') {
    rbac_legacy_require($user, 'accounting.close_task.assign');
    $body = api_json_body();
    api_require_fields($body, ['id']);
    $update = [];
    foreach (['assignee_user_id','due_date','status','notes','title','description'] as $k) {
        if (array_key_exists($k, $body)) $update[$k] = $body[$k];
    }
    if (!$update) api_error('no fields to update', 422);
    if (isset($update['status'])) {
        if (!in_array($update['status'], ['pending', 'in_progress', 'skipped', 'blocked'], true)) {
            api_error('Use Complete to finish a review task', 422);
        }
        $update['completed_at'] = null;
        $update['completed_by_user_id'] = null;
    }
    $id = (int) $body['id'];
    $task = scopedFind('SELECT t.period_id, p.entity_id FROM accounting_close_tasks t
        JOIN accounting_periods p ON p.id = t.period_id AND p.tenant_id = t.tenant_id
        WHERE t.tenant_id = :tenant_id AND t.id = :id', ['id' => $id]);
    if (!$task) api_error('Close task not found', 404);
    $pdo = getDB();
    try {
        $pdo->beginTransaction();
        accountingLockEditableClosePeriod($pdo, $tenantId, (int) $task['period_id'], (int) $task['entity_id']);
        accountingEditableReviewTask($pdo, $tenantId, $id, (int) $task['period_id']);
        scopedUpdate('accounting_close_tasks', $id, $update);
        $pdo->commit();
    } catch (\DomainException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        api_error($e->getMessage(), 409);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[accounting.close_tasks.update] ' . $e->getMessage());
        api_error('Could not update the close task', 500);
    }
    api_ok(['ok' => true]);
}

api_error('Unknown method/action', 405);
