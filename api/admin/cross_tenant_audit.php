<?php
/**
 * /api/admin/cross_tenant_audit.php — chronological feed of every save
 * that crossed tenant boundaries on the accounting surfaces.
 *
 *   GET ?since=YYYY-MM-DD&action=…&limit=200
 *
 * Auth:
 *   - master_admin / is_global_admin → every tenant
 *   - tenant_admin / admin           → only rows where the user's active
 *                                      tenant participates as actor OR
 *                                      either side of the edge
 *
 * tenant-leak-allow: cross-tenant by design; scoped per-user above.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';

$ctx        = api_require_auth(false);
$user       = $ctx['user'];
$role       = (string) ($ctx['role'] ?? 'employee');
$globalRole = (string) ($ctx['global_role'] ?? $role);
$isGlobalAdm= (bool)   ($ctx['is_global_admin'] ?? false);
$activeTid  = $ctx['tenant_id'] ?? null;

$isPlatformMA = ($globalRole === 'master_admin') || $isGlobalAdm;
if (!$isPlatformMA && !in_array($role, ['tenant_admin', 'admin'], true)) {
    api_error('Forbidden — admins only', 403);
}

$pdo = getDB();
if (!$pdo) api_error('No database connection', 500);

$since  = (string) api_query('since',  '');
$action = (string) api_query('action', '');
$limit  = max(1, min(500, (int) api_query('limit', 200)));

$sql = "SELECT a.id, a.acting_tenant_id, a.actor_user_id, a.actor_label,
               a.left_tenant_id, a.right_tenant_id,
               a.left_entity_id, a.right_entity_id,
               a.action, a.payload, a.ip, a.occurred_at,
               lt.name AS left_tenant_name,
               rt.name AS right_tenant_name,
               at.name AS acting_tenant_name
          FROM cross_tenant_accounting_audit a
     LEFT JOIN tenants lt ON lt.id = a.left_tenant_id
     LEFT JOIN tenants rt ON rt.id = a.right_tenant_id
     LEFT JOIN tenants at ON at.id = a.acting_tenant_id";
$params = [];
$where  = [];

if (!$isPlatformMA && $activeTid) {
    // Tenant-admin scope: rows where their tenant is acting OR either side.
    $where[] = '(a.acting_tenant_id = :tid OR a.left_tenant_id = :tid OR a.right_tenant_id = :tid)';
    $params['tid'] = (int) $activeTid;
}
if ($since !== '') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) api_error("Invalid 'since' date", 422);
    $where[] = 'a.occurred_at >= :since';
    $params['since'] = $since . ' 00:00:00';
}
if ($action !== '') {
    $where[] = 'a.action = :act';
    $params['act'] = $action;
}
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY a.occurred_at DESC LIMIT ' . $limit;

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

foreach ($rows as &$r) {
    foreach (['id','acting_tenant_id','actor_user_id','left_tenant_id','right_tenant_id','left_entity_id','right_entity_id'] as $k) {
        if (isset($r[$k]) && $r[$k] !== null) $r[$k] = (int) $r[$k];
    }
    if (!empty($r['payload'])) {
        $decoded = json_decode((string) $r['payload'], true);
        $r['payload'] = is_array($decoded) ? $decoded : null;
    }
}
unset($r);

// Distinct action types for the UI filter dropdown.
$actionsStmt = $pdo->query('SELECT DISTINCT action FROM cross_tenant_accounting_audit ORDER BY action ASC');
$actions = array_column($actionsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'action');

api_ok([
    'rows'    => $rows,
    'actions' => $actions,
    'count'   => count($rows),
    'limit'   => $limit,
]);
