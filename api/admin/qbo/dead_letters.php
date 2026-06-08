<?php
/**
 * GET  /api/admin/qbo/dead_letters.php
 *      → List dead-lettered + actively-retrying QBO push failures.
 *
 * POST /api/admin/qbo/dead_letters.php
 *      → Requeue a dead-lettered entity. Body:
 *           { tenant_id, entity_type, source_id }
 *      → Resets attempts to 0 and status to 'retrying'; the next
 *        QBO sync cron will pick it up.
 *
 * Read+write — gated to master_admin / tenant_admin. Surfaces the raw
 * vendor body (charter primitive #6) so operators can diagnose the
 * underlying validation error and either fix the source data or
 * requeue + monitor.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/rbac/legacy_map.php';
require_once __DIR__ . '/../../../core/qbo/retry_queue.php';

rbac_legacy_require_any($currentUser ?? api_require_auth(), ['master_admin', 'tenant_admin', '*']);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $tenantId = isset($_GET['tenant_id']) ? (int) $_GET['tenant_id'] : 0;
    $status   = $_GET['status']    ?? 'dead_letter'; // dead_letter | retrying | all
    $entity   = $_GET['entity']    ?? null;
    $limit    = max(1, min(200, (int) ($_GET['limit'] ?? 50)));

    $sql = 'SELECT id, tenant_id, sub_tenant_id, entity_type, source_id,
                   attempts, max_attempts, status,
                   last_error_code, last_error_message, last_http_status,
                   vendor_raw, next_retry_at, first_failed_at, last_failed_at
              FROM qbo_push_failures
             WHERE cleared_at IS NULL';
    $params = [];
    if ($tenantId > 0) {
        $sql .= ' AND tenant_id = :t';
        $params['t'] = $tenantId;
    }
    if ($status !== 'all') {
        $sql .= ' AND status = :s';
        $params['s'] = $status;
    }
    if ($entity) {
        $sql .= ' AND entity_type = :e';
        $params['e'] = $entity;
    }
    $sql .= ' ORDER BY last_failed_at DESC LIMIT ' . (int) $limit;

    try {
        $stmt = getDB()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        // Table missing → empty list (migration 113 not yet applied).
        $rows = [];
    }

    api_ok([
        'items'        => $rows,
        'filters'      => ['tenant_id' => $tenantId, 'status' => $status, 'entity' => $entity, 'limit' => $limit],
        'generated_at' => gmdate('c'),
    ]);
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $tenantId   = (int) ($body['tenant_id'] ?? 0);
    $entityType = (string) ($body['entity_type'] ?? '');
    $sourceId   = (int) ($body['source_id'] ?? 0);
    if (!$tenantId || !$entityType || !$sourceId) {
        http_response_code(400);
        api_err('invalid_request', 'tenant_id, entity_type, source_id required');
    }
    if (!in_array($entityType, ['journal_entry', 'bill', 'invoice'], true)) {
        http_response_code(400);
        api_err('invalid_request', 'entity_type must be journal_entry, bill, or invoice');
    }
    $ok = qboPushFailureRequeue($tenantId, $entityType, $sourceId,
                                isset($body['sub_tenant_id']) ? (int) $body['sub_tenant_id'] : null);
    if (!$ok) {
        http_response_code(404);
        api_err('not_found', 'no dead-lettered row matched');
    }
    api_ok([
        'requeued'   => true,
        'tenant_id'  => $tenantId,
        'entity_type'=> $entityType,
        'source_id'  => $sourceId,
    ]);
}

http_response_code(405);
api_err('method_not_allowed', 'GET or POST only');
