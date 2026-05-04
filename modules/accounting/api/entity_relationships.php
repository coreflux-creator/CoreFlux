<?php
/**
 * Accounting API — Entity relationships (consolidation setup).
 *
 *   GET    /api/accounting/entity_relationships              → list
 *   POST   /api/accounting/entity_relationships              → upsert
 *   DELETE /api/accounting/entity_relationships?id=N         → deactivate
 *   GET    /api/accounting/entity_relationships?action=descendants&root_entity_id=N&as_of=YYYY-MM-DD
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/accounting.php';
require_once __DIR__ . '/../lib/consolidation.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = (string) ($_GET['action'] ?? '');

if ($method === 'GET' && $action === 'descendants') {
    RBAC::requirePermission($user, 'accounting.entities.view');
    $root = (int) ($_GET['root_entity_id'] ?? 0);
    if ($root <= 0) api_error('root_entity_id required', 400);
    $asOf = (string) ($_GET['as_of'] ?? date('Y-m-d'));
    api_ok(['root' => $root, 'as_of' => $asOf, 'descendants' => entityRelationshipResolveDescendants($tid, $root, $asOf)]);
}
if ($method === 'GET') {
    RBAC::requirePermission($user, 'accounting.entities.view');
    api_ok(['rows' => entityRelationshipList($tid)]);
}
if ($method === 'POST') {
    RBAC::requirePermission($user, 'accounting.entities.manage');
    $body = api_json_body();
    api_require_fields($body, ['parent_entity_id','child_entity_id']);
    try {
        $id = entityRelationshipUpsert($tid, $body);
    } catch (\Throwable $e) { api_error($e->getMessage(), 422); }
    api_ok(['id' => $id], 201);
}
if ($method === 'DELETE') {
    RBAC::requirePermission($user, 'accounting.entities.manage');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    scopedUpdate('accounting_entity_relationships', $id, ['active' => 0]);
    accountingAudit('accounting.consolidation.relationship_deactivated', ['id' => $id], $id);
    api_ok(['ok' => true]);
}
api_error('Method not allowed', 405);
