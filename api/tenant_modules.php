<?php
/**
 * /api/tenant_modules.php — toggle module subscriptions per tenant.
 *
 *   GET    /api/tenant_modules.php                   list (current tenant's matrix)
 *   GET    /api/tenant_modules.php?tenant_id=N       list (specific tenant)
 *   PATCH  /api/tenant_modules.php?tenant_id=N       { module_key, is_enabled }
 *
 * Permission: master_admin OR tenant_admin of the target tenant (or its parent).
 *
 * The list endpoint returns every module from getModuleDefinitions() with
 * its current `is_enabled` for the requested tenant. Tenants with NO rows
 * default to all-enabled (matches session.php greenfield fallback).
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/sub_tenants.php';
require_once __DIR__ . '/../core/modules.php';

$ctx       = api_require_auth();
$user      = $ctx['user'];
$actorId   = (int) ($user['id'] ?? 0);
$role      = $ctx['role'] ?? 'employee';
$activeTid = currentTenantId() ?: null;

$method   = api_method();
$tenantId = (int) (api_query('tenant_id', $activeTid ?? 0));
if (!$tenantId) api_error('tenant_id required', 422);

$pdo = getDB();
if (!$pdo) api_error('No database connection', 500);

// --- permission gate ---
function _tenantModulesCanManage(PDO $pdo, int $userId, string $globalRole, int $tenantId, ?int $activeTid): bool {
    if ($globalRole === 'master_admin') return true;
    // tenant_admin of the target OR of the target's parent.
    $checkRoleAt = function (int $tid) use ($pdo, $userId): bool {
        $stmt = $pdo->prepare(
            "SELECT persona_type AS role FROM " . membershipReadSourceSql() . " src WHERE src.user_id = :u AND src.tenant_id = :t LIMIT 1"
        );
        $stmt->execute(['u' => $userId, 't' => $tid]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r && in_array($r['role'], ['tenant_admin', 'master_admin'], true);
    };
    if ($checkRoleAt($tenantId)) return true;
    $t = subTenantLookup($tenantId);
    if ($t && !empty($t['parent_id']) && $checkRoleAt((int) $t['parent_id'])) return true;
    return false;
}

if (!_tenantModulesCanManage($pdo, $actorId, $role, $tenantId, $activeTid)) {
    api_error('Forbidden — only master_admin or tenant_admin of the tenant can manage modules', 403);
}

if ($method === 'GET') {
    $defs = getModuleDefinitions();
    $stmt = $pdo->prepare("SELECT module_key, is_enabled FROM tenant_modules WHERE tenant_id = :t");
    $stmt->execute(['t' => $tenantId]);
    $existing = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $existing[(string) $r['module_key']] = (int) $r['is_enabled'] === 1;
    }

    $modules = [];
    foreach ($defs as $key => $def) {
        $modules[] = [
            'module_key' => $key,
            'name'       => $def['name']        ?? ucfirst($key),
            'description'=> $def['description'] ?? '',
            'icon'       => $def['icon']        ?? null,
            // Default: enabled when no row (greenfield).
            'is_enabled' => array_key_exists($key, $existing) ? $existing[$key] : true,
            'has_row'    => array_key_exists($key, $existing),
        ];
    }

    $tenant = subTenantLookup($tenantId);
    api_ok([
        'tenant_id'   => $tenantId,
        'tenant_name' => $tenant['name'] ?? null,
        'modules'     => $modules,
    ]);
}

if ($method === 'PATCH') {
    $body = api_json_body();
    api_require_fields($body, ['module_key']);
    $key = (string) $body['module_key'];
    $on  = (int) (bool) ($body['is_enabled'] ?? 0);

    $defs = getModuleDefinitions();
    if (!isset($defs[$key])) api_error("Unknown module '{$key}'", 422);

    $pdo->prepare(
        "INSERT INTO tenant_modules (tenant_id, module_key, is_enabled, enabled_at)
         VALUES (:t, :k, :e, IF(:e2 = 1, NOW(), NULL))
         ON DUPLICATE KEY UPDATE
            is_enabled  = VALUES(is_enabled),
            enabled_at  = IF(VALUES(is_enabled) = 1 AND enabled_at IS NULL, NOW(), enabled_at),
            disabled_at = IF(VALUES(is_enabled) = 0, NOW(), disabled_at)"
    )->execute(['t' => $tenantId, 'k' => $key, 'e' => $on, 'e2' => $on]);

    subTenantAudit(0, $tenantId, $actorId,
        $on ? 'tenant.module_enabled' : 'tenant.module_disabled',
        ['module_key' => $key]
    );
    api_ok(['tenant_id' => $tenantId, 'module_key' => $key, 'is_enabled' => $on === 1]);
}

api_error('Method not allowed', 405);
