<?php
/**
 * Active-entity session helpers (Sprint 4 / B1, extended 2026-02 for the
 * cross-tenant entity dropdown).
 *
 * The dropdown used to scope to a single tenant's `accounting_entities`
 * rows.  When the user is on a PARENT tenant they now see entities
 * across the entire tenant tree (parent + every active sub-tenant) so
 * Consolidation / intercompany dropdowns can pick between siblings.
 * When the user is on a SUB-TENANT we still scope down to that sub's
 * own entities (no parent-side leakage).
 *
 * Public surface:
 *   activeEntityGet(int $tenantId): ?int
 *   activeEntitySet(int $tenantId, int $entityId): array
 *   activeEntityAvailable(int $tenantId, ?int $userId = null): list<array>
 *
 * VERTICAL-AGNOSTIC.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

function activeEntityGet(int $tenantId): ?int {
    initSession();
    $key = "active_entity_id__t{$tenantId}";
    return isset($_SESSION[$key]) ? (int) $_SESSION[$key] : null;
}

function activeEntitySet(int $tenantId, int $entityId): array {
    $pdo = getDB();
    if (!$pdo) throw new \RuntimeException('No DB');

    // The picker now spans the whole tenant tree, so the lookup has to
    // accept entities that belong to the parent OR any sub-tenant.  We
    // resolve the tenant set ONCE then look up the entity inside it.
    $allowedTenantIds = activeEntityResolveAllowedTenantIds($tenantId);
    if (!$allowedTenantIds) {
        $allowedTenantIds = [$tenantId];
    }
    $placeholders = implode(',', array_fill(0, count($allowedTenantIds), '?'));
    $sql = "SELECT id, tenant_id, code, legal_name, base_currency, country, active, parent_entity_id
              FROM accounting_entities
             WHERE id = ? AND tenant_id IN ($placeholders) LIMIT 1";
    $args = array_merge([$entityId], $allowedTenantIds);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !$row['active']) throw new \RuntimeException("Entity not found / inactive");

    initSession();
    $_SESSION["active_entity_id__t{$tenantId}"] = (int) $row['id'];
    return $row;
}

/**
 * List every entity the current user can pick from given they're sitting
 * on $tenantId today.
 *
 * Rules:
 *   - If $tenantId is a PARENT (master) tenant: include its own entities
 *     PLUS one row per active sub-tenant's entities.  This lets the
 *     parent's admins consolidate, post intercompany JEs, etc.
 *   - If $tenantId is a SUB tenant: include ONLY its own entities (don't
 *     leak parent's books into a sub-tenant's session).
 *
 * Each row is enriched with `tenant_id`, `tenant_name`, `tenant_kind`
 * (`master` | `sub`) so the SPA can render labels like
 * "Seven Generations · Main Entity" and group the dropdown by tenant.
 *
 * @return list<array<string,mixed>>
 */
function activeEntityAvailable(int $tenantId, ?int $userId = null): array {
    $pdo = getDB();
    if (!$pdo) return [];

    $allowedTenantIds = activeEntityResolveAllowedTenantIds($tenantId);
    if (!$allowedTenantIds) $allowedTenantIds = [$tenantId];

    // tenant-leak-allow: cross-tenant by design — the allowed list
    // above gates by the active session's tenant chain.
    $placeholders = implode(',', array_fill(0, count($allowedTenantIds), '?'));
    $sql = "SELECT ae.id, ae.tenant_id, ae.code, ae.legal_name, ae.base_currency,
                   ae.country, ae.parent_entity_id,
                   t.name AS tenant_name, t.tenant_type AS tenant_kind,
                   t.parent_id AS tenant_parent_id
              FROM accounting_entities ae
              JOIN tenants t ON t.id = ae.tenant_id
             WHERE ae.active = 1
               AND ae.tenant_id IN ($placeholders)
             ORDER BY
                  /* Active session's own tenant first */
                  CASE WHEN ae.tenant_id = ? THEN 0 ELSE 1 END,
                  /* Master tenants before sub-tenants */
                  CASE WHEN t.tenant_type = 'master' THEN 0 ELSE 1 END,
                  t.name ASC,
                  ae.code ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($allowedTenantIds, [$tenantId]));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Coerce numeric ids so the SPA gets clean ints.
    foreach ($rows as &$r) {
        $r['id']               = (int) $r['id'];
        $r['tenant_id']        = (int) $r['tenant_id'];
        $r['parent_entity_id'] = $r['parent_entity_id'] !== null ? (int) $r['parent_entity_id'] : null;
        $r['tenant_parent_id'] = $r['tenant_parent_id'] !== null ? (int) $r['tenant_parent_id'] : null;
        $r['is_active_tenant'] = $r['tenant_id'] === $tenantId;
    }
    unset($r);
    return $rows;
}

/**
 * Resolve the set of tenant_ids whose entities should be selectable from
 * the active $tenantId.  Pulled into its own helper so both
 * activeEntitySet() (validation) and activeEntityAvailable() (rendering)
 * stay in sync.
 *
 * Returns an array of POSITIVE integers, never empty for a valid input.
 *
 * @return list<int>
 */
function activeEntityResolveAllowedTenantIds(int $tenantId): array {
    if ($tenantId <= 0) return [];
    $pdo = getDB();
    if (!$pdo) return [$tenantId];

    try {
        $row = $pdo->prepare(
            'SELECT id, parent_id, tenant_type, is_active FROM tenants WHERE id = :id LIMIT 1'
        );
        $row->execute(['id' => $tenantId]);
        $t = $row->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $_) {
        return [$tenantId];
    }
    if (!$t) return [$tenantId];

    // Master tenant: include all active sub-tenants.
    if (((string) ($t['tenant_type'] ?? '')) === 'master') {
        $ids = [(int) $t['id']];
        try {
            $subs = $pdo->prepare(
                'SELECT id FROM tenants
                  WHERE parent_id = :p AND is_active = 1
                    AND tenant_type IN ("sub", "master")
                  ORDER BY name ASC'
            );
            $subs->execute(['p' => (int) $t['id']]);
            foreach (($subs->fetchAll(PDO::FETCH_COLUMN) ?: []) as $sid) {
                $ids[] = (int) $sid;
            }
        } catch (\Throwable $_) { /* graceful */ }
        return array_values(array_unique($ids));
    }

    // Sub-tenant: only its own entities by default.  We intentionally
    // do NOT leak the parent's entities into the sub's session — the
    // sub-tenant operator should switch to the parent to see them.
    return [(int) $t['id']];
}
