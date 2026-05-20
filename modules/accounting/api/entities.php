<?php
/**
 * Accounting API — Legal entities
 *
 *   GET    /api/accounting/entities
 *   POST   /api/accounting/entities           {code,legal_name,country?,base_currency?,parent_entity_id?}
 *   PATCH  /api/accounting/entities?id=N
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/accounting.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();

if ($method === 'GET') {
    rbac_legacy_require($user, 'accounting.entities.view');
    $scope = (string) ($_GET['scope'] ?? 'tenant');
    if ($scope === 'hierarchy') {
        // Cross-tenant scope: return entities for the current tenant AND
        // every active sub-tenant beneath it. Used by Consolidation and
        // Intercompany so a master_admin / tenant_admin viewing a parent
        // tenant can wire up consolidation edges across sub-tenants.
        $pdo = getDB();
        $sub = $pdo->prepare(
            'SELECT id FROM tenants WHERE parent_id = :p AND COALESCE(is_active,1) = 1'
        );
        $sub->execute(['p' => $tid]);
        $scopeIds = array_map('intval', array_column($sub->fetchAll(PDO::FETCH_ASSOC), 'id'));
        $scopeIds[] = $tid;
        $place = implode(',', array_fill(0, count($scopeIds), '?'));

        // tenant-leak-allow: explicitly opted in via ?scope=hierarchy AND
        // gated by the same accounting.entities.view permission as the
        // single-tenant path. Result is the user's own tenant + its direct
        // sub-tenants (no upward expansion).
        $stmt = $pdo->prepare(
            "SELECT e.id, e.code, e.legal_name, e.country, e.base_currency,
                    e.parent_entity_id, e.active, e.tenant_id,
                    t.name AS tenant_name,
                    (e.tenant_id = ?) AS is_current_tenant
               FROM accounting_entities e
               JOIN tenants t ON t.id = e.tenant_id
              WHERE e.tenant_id IN ($place)
           ORDER BY is_current_tenant DESC, t.name ASC, e.code ASC"
        );
        $stmt->execute(array_merge([$tid], $scopeIds));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        api_ok(['rows' => $rows, 'scope' => 'hierarchy', 'scope_tenant_ids' => $scopeIds]);
    }

    $rows = scopedQuery('SELECT id, code, legal_name, country, base_currency, parent_entity_id, active FROM accounting_entities WHERE tenant_id = :tenant_id ORDER BY code', []);
    api_ok(['rows' => $rows, 'scope' => 'tenant']);
}

if ($method === 'POST') {
    rbac_legacy_require($user, 'accounting.entities.manage');
    $body = api_json_body();
    api_require_fields($body, ['code','legal_name']);
    $id = scopedInsert('accounting_entities', [
        'tenant_id'        => $tid,
        'code'             => (string) $body['code'],
        'legal_name'       => (string) $body['legal_name'],
        'country'          => (string) ($body['country'] ?? 'US'),
        'base_currency'    => (string) ($body['base_currency'] ?? 'USD'),
        'parent_entity_id' => !empty($body['parent_entity_id']) ? (int) $body['parent_entity_id'] : null,
        'active'           => 1,
    ]);
    accountingAudit('accounting.entity.created', ['id' => $id, 'code' => $body['code']], $id);
    api_ok(['id' => $id], 201);
}

if ($method === 'PATCH') {
    rbac_legacy_require($user, 'accounting.entities.manage');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $body = api_json_body();
    foreach (['id','tenant_id','created_at'] as $k) unset($body[$k]);
    if (!$body) api_error('No fields to update', 422);
    $rows = scopedUpdate('accounting_entities', $id, $body);
    if ($rows === 0) api_error('Not found or no change', 404);
    accountingAudit('accounting.entity.updated', ['id' => $id], $id);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
