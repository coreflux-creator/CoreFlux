<?php
/**
 * Tenant-local accounting-entity helpers.
 *
 * Tenant switching is the authoritative global scope. Entity ids remain an
 * internal accounting/posting detail. Cross-tenant entity selection belongs
 * inside explicit Consolidation and Intercompany workflows.
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

    $stmt = $pdo->prepare(
        'SELECT id, tenant_id, code, legal_name, base_currency, country, active, parent_entity_id
           FROM accounting_entities
          WHERE id = :id AND tenant_id = :t LIMIT 1'
    );
    $stmt->execute(['id' => $entityId, 't' => $tenantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !$row['active']) throw new \RuntimeException("Entity not found / inactive");

    initSession();
    $_SESSION["active_entity_id__t{$tenantId}"] = (int) $row['id'];
    return $row;
}

/**
 * List active entities owned by the current tenant only.
 *
 * @return list<array<string,mixed>>
 */
function activeEntityAvailable(int $tenantId, ?int $userId = null): array {
    $pdo = getDB();
    if (!$pdo) return [];

    $sql = "SELECT ae.id, ae.tenant_id, ae.code, ae.legal_name, ae.base_currency,
                   ae.country, ae.parent_entity_id,
                   t.name AS tenant_name, t.tenant_type AS tenant_kind,
                   t.parent_id AS tenant_parent_id
              FROM accounting_entities ae
              JOIN tenants t ON t.id = ae.tenant_id
             WHERE ae.active = 1
               AND ae.tenant_id = :t
             ORDER BY ae.id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['t' => $tenantId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Coerce numeric ids so the SPA gets clean ints.
    foreach ($rows as &$r) {
        $r['id']               = (int) $r['id'];
        $r['tenant_id']        = (int) $r['tenant_id'];
        $r['parent_entity_id'] = $r['parent_entity_id'] !== null ? (int) $r['parent_entity_id'] : null;
        $r['tenant_parent_id'] = $r['tenant_parent_id'] !== null ? (int) $r['tenant_parent_id'] : null;
        $r['is_active_tenant'] = true;
    }
    unset($r);
    return $rows;
}

/**
 * Resolve the tenant-local entity used internally for posting. A valid
 * requested/session entity wins; otherwise use the same deterministic first
 * active entity as the accounting engine's default.
 */
function activeEntityResolveForTenant(int $tenantId, ?int $requestedEntityId = null): ?array {
    $entities = activeEntityAvailable($tenantId);
    if (!$entities) return null;

    $candidateId = $requestedEntityId ?: activeEntityGet($tenantId);
    $resolved = null;
    if ($candidateId) {
        foreach ($entities as $entity) {
            if ((int) $entity['id'] === (int) $candidateId) {
                $resolved = $entity;
                break;
            }
        }
        if ($requestedEntityId && !$resolved) {
            throw new \RuntimeException('Entity does not belong to the active tenant');
        }
    }

    $resolved = $resolved ?: $entities[0];
    initSession();
    $_SESSION["active_entity_id__t{$tenantId}"] = (int) $resolved['id'];
    return $resolved;
}
