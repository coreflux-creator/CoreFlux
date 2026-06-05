<?php
/**
 * LayerFi per-tenant access governance.
 *
 * Two independent gates decide whether a tenant may use LayerFi:
 *   1. Global feature flag  — ENABLE_LAYER_SANDBOX (see layer_config.php).
 *   2. Per-tenant access    — resolved here, in priority order:
 *        a. LAYER_TENANT_ALLOWLIST env  → HARD override / lock (ops + security).
 *        b. tenant_layer_enablement DB  → admin toggle (no redeploy needed).
 *        c. LAYER_TENANT_DEFAULT_ENABLED → fallback for tenants with no row.
 *
 * When the env allowlist is set it WINS and the DB toggle is ignored, so a
 * deployment can hard-lock the pilot set regardless of the admin UI.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/layer_config.php';

/** True when the env allowlist is set (DB toggle ignored, admin UI locked). */
function layer_env_locked(): bool
{
    return !empty(layer_tenant_allowlist());
}

/** DB per-tenant toggle value, or null when no row exists. */
function layer_tenant_db_enabled(int $tenantId): ?bool
{
    try {
        $st = getDB()->prepare(
            'SELECT enabled FROM tenant_layer_enablement WHERE tenant_id = :t AND layer_environment = :e LIMIT 1'
        );
        $st->execute(['t' => $tenantId, 'e' => layer_config()['environment']]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row ? (bool) (int) $row['enabled'] : null;
    } catch (\Throwable $e) {
        return null;
    }
}

/** Upsert the DB toggle for a tenant. */
function layer_set_tenant_enabled(int $tenantId, bool $enabled, ?int $userId = null): void
{
    getDB()->prepare(
        'INSERT INTO tenant_layer_enablement (tenant_id, layer_environment, enabled, updated_by, created_at, updated_at)
         VALUES (:t, :e, :en, :u, NOW(), NOW())
         ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_by = VALUES(updated_by), updated_at = NOW()'
    )->execute([
        't'  => $tenantId,
        'e'  => layer_config()['environment'],
        'en' => $enabled ? 1 : 0,
        'u'  => $userId,
    ]);
}

/** Combined gate: env allowlist (hard) → DB toggle → global default. */
function layer_tenant_allowed(int $tenantId): bool
{
    $list = layer_tenant_allowlist();
    if (!empty($list)) return in_array($tenantId, $list, true);

    $db = layer_tenant_db_enabled($tenantId);
    if ($db !== null) return $db;

    return layer_default_tenant_enabled();
}

/** Governance descriptor for the UI / audit. */
function layer_tenant_governance(int $tenantId): array
{
    $envLocked = layer_env_locked();
    $db        = layer_tenant_db_enabled($tenantId);
    $effective = layer_tenant_allowed($tenantId);
    $source    = $envLocked ? 'env' : ($db !== null ? 'db' : 'default');
    return [
        'envLocked'      => $envLocked,
        'dbEnabled'      => $db,
        'defaultEnabled' => layer_default_tenant_enabled(),
        'source'         => $source,
        'effective'      => $effective,
    ];
}
