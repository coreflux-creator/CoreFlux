<?php
/**
 * /api/ai/exceptions.php — accounting_exceptions reviewer ops
 * (Slice 6).
 *
 *   GET                          — list open exceptions (default) or
 *                                  filtered by status (open|assigned|
 *                                  resolved|dismissed)
 *   POST ?action=resolve         — body {id, resolution_note?}
 *   POST ?action=dismiss         — body {id, resolution_note?}
 *   POST ?action=assign          — body {id, user_id}
 *
 * RBAC: `ai.audit.view` OR `accounting.review` for list + resolve +
 *       dismiss. `accounting.approve` for assign.
 *
 * Every mutation writes a spec-§15 audit_log event:
 *   ai_exception_resolved / ai_exception_dismissed / ai_exception_assigned.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';
require_once __DIR__ . '/../../core/rbac/legacy_map.php';
require_once __DIR__ . '/../../core/ai/gateway.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$uid    = (int) ($user['id'] ?? 0) ?: null;
$method = api_method();
$action = (string) ($_GET['action'] ?? '');

$canView = rbac_legacy_can($user, 'ai.audit.view') || rbac_legacy_can($user, 'accounting.review');

if ($method === 'GET') {
    if (!$canView) api_error('Forbidden', 403);
    $status = (string) ($_GET['status'] ?? 'open');
    if (!in_array($status, ['open','assigned','resolved','dismissed','all'], true)) {
        api_error('invalid status', 422);
    }
    $sql = "SELECT id, exception_type, severity, status, summary,
                   related_ref_type, related_ref_id,
                   workflow_run_id, ai_run_id,
                   assigned_to_user_id, resolved_by_user_id,
                   resolved_at, created_at, updated_at
              FROM accounting_exceptions
             WHERE tenant_id = :t";
    $params = ['t' => $tid];
    if ($status !== 'all') { $sql .= ' AND status = :s'; $params['s'] = $status; }
    $sql .= " ORDER BY FIELD(severity, 'critical','high','medium','low'), id DESC LIMIT 200";
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['id']                  = (int)  $r['id'];
        $r['related_ref_id']      = $r['related_ref_id']      !== null ? (int) $r['related_ref_id']      : null;
        $r['assigned_to_user_id'] = $r['assigned_to_user_id'] !== null ? (int) $r['assigned_to_user_id'] : null;
        $r['resolved_by_user_id'] = $r['resolved_by_user_id'] !== null ? (int) $r['resolved_by_user_id'] : null;
    } unset($r);
    api_ok(['exceptions' => $rows, 'count' => count($rows), 'status' => $status]);
}

if ($method === 'GET' && $action === 'detail') {
    if (!$canView) api_error('Forbidden', 403);
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 422);

    $stmt = getDB()->prepare(
        'SELECT id, tenant_id, sub_tenant_id, workflow_run_id, ai_run_id,
                exception_type, severity, status,
                related_ref_type, related_ref_id, summary, detail_json,
                assigned_to_user_id, resolved_by_user_id, resolved_at,
                created_by_user_id, created_at, updated_at
           FROM accounting_exceptions
          WHERE id = :id AND tenant_id = :t LIMIT 1'
    );
    $stmt->execute(['id' => $id, 't' => $tid]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) api_error('exception not found', 404);

    foreach (['id', 'tenant_id', 'sub_tenant_id', 'related_ref_id',
              'assigned_to_user_id', 'resolved_by_user_id',
              'created_by_user_id'] as $k) {
        if ($row[$k] !== null) $row[$k] = (int) $row[$k];
    }
    $row['detail'] = $row['detail_json'] ? json_decode((string) $row['detail_json'], true) : null;
    unset($row['detail_json']);
    api_ok(['exception' => $row]);
}

if ($method === 'POST' && in_array($action, ['resolve','dismiss'], true)) {
    if (!$canView) api_error('Forbidden', 403);
    $body = api_json_body();
    $id   = (int) ($body['id'] ?? 0);
    if ($id <= 0) api_error('id required', 422);
    $note = isset($body['resolution_note']) ? mb_substr((string) $body['resolution_note'], 0, 500) : null;

    // Confirm row exists + is tenant-scoped + open.
    $stmt = getDB()->prepare(
        'SELECT id, status FROM accounting_exceptions
          WHERE id = :id AND tenant_id = :t LIMIT 1'
    );
    $stmt->execute(['id' => $id, 't' => $tid]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) api_error('exception not found', 404);
    if (!in_array($row['status'], ['open','assigned'], true)) {
        api_error("cannot {$action} exception in status '{$row['status']}'", 422);
    }

    $newStatus = $action === 'resolve' ? 'resolved' : 'dismissed';
    $stmt = getDB()->prepare(
        'UPDATE accounting_exceptions
            SET status = :s,
                resolved_by_user_id = :u,
                resolved_at = NOW(),
                detail_json = JSON_SET(COALESCE(detail_json, JSON_OBJECT()), "$.resolution_note", :note)
          WHERE id = :id AND tenant_id = :t'
    );
    $stmt->execute(['s' => $newStatus, 'u' => $uid, 'note' => $note, 'id' => $id, 't' => $tid]);

    aiGatewayAuditEvent($tid, $uid, "ai_exception_{$newStatus}", [
        'exception_id'    => $id,
        'resolution_note' => $note,
    ]);
    api_ok(['exception_id' => $id, 'status' => $newStatus]);
}

if ($method === 'POST' && $action === 'assign') {
    if (!rbac_legacy_can($user, 'accounting.approve')) {
        api_error('Forbidden', 403);
    }
    $body  = api_json_body();
    $id    = (int) ($body['id']      ?? 0);
    $assignTo = (int) ($body['user_id'] ?? 0);
    if ($id <= 0 || $assignTo <= 0) api_error('id + user_id required', 422);

    $stmt = getDB()->prepare(
        'UPDATE accounting_exceptions
            SET status = "assigned",
                assigned_to_user_id = :u
          WHERE id = :id AND tenant_id = :t AND status IN ("open","assigned")'
    );
    $stmt->execute(['u' => $assignTo, 'id' => $id, 't' => $tid]);
    if ($stmt->rowCount() === 0) api_error('exception not assignable', 422);

    aiGatewayAuditEvent($tid, $uid, 'ai_exception_assigned', [
        'exception_id'     => $id,
        'assigned_to_user' => $assignTo,
    ]);
    api_ok(['exception_id' => $id, 'status' => 'assigned', 'assigned_to_user_id' => $assignTo]);
}

api_error("unknown action '{$action}' or wrong HTTP method", 400);
