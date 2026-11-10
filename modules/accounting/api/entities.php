<?php
/**
 * Accounting API — Legal entities
 *
 *   GET    /api/accounting/entities
 *   POST   /api/accounting/entities           explicit legal and accounting profile
 *   PATCH  /api/accounting/entities?id=N      legal name or entity type only
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/accounting/entity_profile.php';
require_once __DIR__ . '/../../../core/accounting/entity_setup.php';
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
                    e.entity_type, e.accounting_basis, e.fiscal_year_start_month,
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

    $rows = scopedQuery('SELECT id, code, legal_name, country, base_currency, entity_type,
                               accounting_basis, fiscal_year_start_month, parent_entity_id, active
                          FROM accounting_entities WHERE tenant_id = :tenant_id ORDER BY code', []);
    api_ok(['rows' => $rows, 'scope' => 'tenant']);
}

if ($method === 'POST') {
    rbac_legacy_require($user, 'accounting.entities.manage');
    $body = api_json_body();
    try {
        $year = accountingFirstFiscalYear($body['first_fiscal_year'] ?? null);
        unset($body['first_fiscal_year']);
        $created = accountingCreateEntityWithCalendar(getDB(), $tid, $body, $year);
    } catch (InvalidArgumentException $error) {
        api_error($error->getMessage(), 422);
    } catch (PDOException $error) {
        if ($error->getCode() === '23000') api_error('Entity code is already used in this workspace', 409);
        throw $error;
    }
    $id = $created['entity_id'];
    accountingAudit('accounting.entity.created', [
        'id' => $id, 'code' => strtoupper(trim((string) $body['code'])),
        'calendar_id' => $created['calendar_id'], 'first_fiscal_year' => $year,
    ], $id);
    api_ok(['id' => $id, 'calendar_id' => $created['calendar_id'], 'periods_created' => 12], 201);
}

if ($method === 'PATCH') {
    rbac_legacy_require($user, 'accounting.entities.manage');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $current = scopedFind('SELECT * FROM accounting_entities WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$current) api_error('Not found', 404);
    $body = api_json_body();
    try {
        $changes = accountingReviewEntityProfileUpdate($current, $body);
    } catch (InvalidArgumentException $error) {
        api_error($error->getMessage(), 422);
    }
    if (!$changes) api_ok(['ok' => true, 'unchanged' => true]);
    scopedUpdate('accounting_entities', $id, $changes);
    accountingAudit('accounting.entity.updated', [
        'id' => $id, 'fields' => array_keys($changes),
        'before' => array_intersect_key($current, $changes), 'after' => $changes,
    ], $id);
    api_ok(['ok' => true, 'unchanged' => false]);
}

api_error('Method not allowed', 405);
