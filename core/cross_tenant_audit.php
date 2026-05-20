<?php
/**
 * core/cross_tenant_audit.php — write + read helpers for the
 * `cross_tenant_accounting_audit` table.
 *
 * Used by Consolidation and Intercompany flows to log every save that
 * spans tenants. The helper auto-detects cross-tenant scenarios by
 * inspecting the two entities involved and only writes when their
 * tenant_ids differ — same-tenant saves are recorded by the existing
 * `accountingAudit()` log and don't need a row here.
 *
 * tenant-leak-allow: the audit log is intentionally cross-tenant; only
 * master_admin and the involved-tenant admins can read it.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Record a cross-tenant accounting save. Idempotent insert; failures are
 * swallowed (and error_log'd) so a logging hiccup never blocks the save
 * itself.
 *
 * $leftTenantId / $rightTenantId are required; pass NULL for entity ids
 * if the action isn't entity-pair shaped (e.g. a future "merge sub-tenant
 * into parent" workflow).
 */
function crossTenantAuditLog(
    int $actingTenantId,
    int $leftTenantId,
    int $rightTenantId,
    string $action,
    array $payload = [],
    ?int $leftEntityId = null,
    ?int $rightEntityId = null,
    ?int $actorUserId = null,
    ?string $actorLabel = null
): void {
    // Same-tenant save — not interesting for THIS feed, accountingAudit
    // already captured it. Quietly skip.
    if ($leftTenantId === $rightTenantId) return;

    try {
        $pdo = getDB();
        if (!$pdo) return;
        $pdo->prepare(
            'INSERT INTO cross_tenant_accounting_audit
                (acting_tenant_id, actor_user_id, actor_label,
                 left_tenant_id, right_tenant_id,
                 left_entity_id,  right_entity_id,
                 action, payload, ip, user_agent, occurred_at)
             VALUES (:act, :uid, :ul, :lt, :rt, :le, :re, :a, :p, :ip, :ua, NOW())'
        )->execute([
            'act' => $actingTenantId,
            'uid' => $actorUserId,
            'ul'  => $actorLabel,
            'lt'  => $leftTenantId,
            'rt'  => $rightTenantId,
            'le'  => $leftEntityId,
            're'  => $rightEntityId,
            'a'   => $action,
            'p'   => $payload ? json_encode($payload) : null,
            'ip'  => mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
            'ua'  => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch (\Throwable $e) {
        error_log('[cross_tenant_audit] ' . $e->getMessage());
    }
}

/**
 * Resolve `accounting_entities.tenant_id` for a given entity id. Returns
 * 0 when the entity isn't found (caller should treat as same-tenant and
 * skip the log). Cached per request to keep upsert paths cheap.
 */
function crossTenantAuditEntityTenantId(int $entityId): int {
    static $cache = [];
    if (isset($cache[$entityId])) return $cache[$entityId];
    if ($entityId <= 0) return 0;
    try {
        $pdo = getDB();
        if (!$pdo) return 0;
        $st = $pdo->prepare('SELECT tenant_id FROM accounting_entities WHERE id = :id LIMIT 1');
        $st->execute(['id' => $entityId]);
        $tid = (int) ($st->fetchColumn() ?: 0);
    } catch (\Throwable $_) {
        $tid = 0;
    }
    return $cache[$entityId] = $tid;
}
