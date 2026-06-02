<?php
/**
 * /api/sub_tenants.php — provision/manage sub-tenants under a master.
 *
 *   GET    /api/sub_tenants.php                    list (current parent's sub-tenants)
 *   POST   /api/sub_tenants.php                    create
 *   PATCH  /api/sub_tenants.php?id=N               update name / slug / branding
 *   DELETE /api/sub_tenants.php?id=N               soft-deactivate
 *   GET    /api/sub_tenants.php?action=scope&id=N  scope map
 *   PATCH  /api/sub_tenants.php?action=scope&id=N  set { module, mode }
 *   POST   /api/sub_tenants.php?action=switch      { tenant_id }  → updates session
 *
 * Permissions: caller MUST be master_admin OR tenant_admin of the parent
 * master tenant. Sub-tenants and their members cannot manage siblings.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/sub_tenants.php';
require_once __DIR__ . '/../core/modules.php';
require_once __DIR__ . '/../core/data.php';

$ctx       = api_require_auth(false);
$user      = $ctx['user'];
$userId    = (int) ($user['id'] ?? 0);
$role      = $ctx['role'] ?? 'employee';
$activeTid = currentTenantId();

$method = api_method();
$action = api_query('action', '');

// ---------- session-only switch action (any membership) ----------
if ($action === 'switch' && $method === 'POST') {
    $body = api_json_body();
    $targetId = (int) ($body['tenant_id'] ?? 0);
    if (!$targetId) api_error('tenant_id required', 422);

    if (!subTenantUserHasMembership($userId, $targetId, $role)) {
        api_error('Forbidden', 403);
    }

    $_SESSION['tenant_id'] = $targetId;
    $_SESSION['active_tenant_id'] = $targetId;     // legacy compat
    $t = subTenantLookup($targetId);
    $_SESSION['tenant'] = $t['name'] ?? null;

    // Refresh the effective role from the membership shim for the new active tenant.
    // Without this, a user who's master_admin on tenant A but tenant_admin on
    // tenant B keeps the role they had at login regardless of which tenant
    // they're currently viewing — the exact bug behind the "Forbidden —
    // master_admin only" report.
    try {
        $rs = getDB()->prepare('SELECT persona_type AS role FROM ' . membershipReadSourceSql() . ' src WHERE src.user_id = :u AND src.tenant_id = :t LIMIT 1');
        $rs->execute(['u' => $userId, 't' => $targetId]);
        $newRole = $rs->fetchColumn();
        if ($newRole && isset($_SESSION['user']) && is_array($_SESSION['user'])) {
            $_SESSION['user']['role'] = (string) $newRole;
            // Refresh available module list to match the new role.
            $_SESSION['modules'] = getUserModules((string) $newRole);
        }
    } catch (\Throwable $_) { /* keep prior role */ }

    subTenantTouchLastActive($userId, $targetId);
    api_ok([
        'tenant_id' => $targetId,
        'tenant'    => $t['name'] ?? null,
        'role'      => $_SESSION['user']['role'] ?? $role,
    ]);
}

// All remaining endpoints need a parent (master) tenant context.
$parentId = subTenantResolveParent($activeTid, $role);
if (!$parentId) api_error('No master tenant context', 400);

// READ (GET list and GET scope) is open to any authenticated member of the
// parent tenant — these are dropdown sources for many screens, and locking
// them to master/tenant_admin makes the entire SPA's "pick a sub-tenant"
// affordance silently empty for ordinary users. Writes (POST/PATCH/DELETE)
// and scope MUTATION still require admin via the existing gate below.
$isReadCall = $method === 'GET';
if (!$isReadCall) {
    if (!subTenantUserCanManageParent($userId, $parentId, $role)) {
        api_error('Forbidden — only master_admin or tenant_admin of the master tenant can manage sub-tenants', 403);
    }
}

// ---------- scope subresource ----------
if ($action === 'scope') {
    $tenantId = (int) api_query('id', 0);
    if (!$tenantId) api_error('id required', 422);
    $t = subTenantLookup($tenantId);
    if (!$t || (int)($t['parent_id'] ?? 0) !== $parentId) api_error('Sub-tenant not found', 404);

    if ($method === 'GET') {
        api_ok([
            'tenant_id' => $tenantId,
            'scope'     => subTenantScopeMap($tenantId),
            'defaults'  => SUBTENANT_MODULE_SCOPE_DEFAULTS,
        ]);
    }
    if ($method === 'PATCH') {
        $body = api_json_body();
        api_require_fields($body, ['module', 'mode']);
        try {
            subTenantScopeSet($tenantId, (string)$body['module'], (string)$body['mode'], $userId);
        } catch (InvalidArgumentException $e) {
            api_error($e->getMessage(), 422);
        }
        api_ok(['scope' => subTenantScopeMap($tenantId)]);
    }
    api_error('Method not allowed', 405);
}

// ---------- collection / item ----------
if ($method === 'GET') {
    $pdo = getDB();
    $stmt = $pdo->prepare(
        "SELECT id, name, slug, tenant_type, is_active, primary_color, logo_url,
                created_at, updated_at
           FROM tenants
          WHERE parent_id = :p AND tenant_type = 'sub'
       ORDER BY is_active DESC, name ASC"
    );
    $stmt->execute(['p' => $parentId]);
    $subs = $stmt->fetchAll();

    // Also surface the parent tenant's own row so the SPA can treat it as
    // a selectable legal entity (the parent keeps its own books — it is
    // NOT just a consolidation layer over the sub-tenants).
    $pstmt = $pdo->prepare(
        "SELECT id, name, slug, tenant_type, is_active, primary_color, logo_url
           FROM tenants
          WHERE id = :p LIMIT 1"
    );
    $pstmt->execute(['p' => $parentId]);
    $parentRow = $pstmt->fetch() ?: null;

    api_ok([
        'parent_tenant_id' => $parentId,
        'parent'           => $parentRow,
        'sub_tenants'      => $subs,
    ]);
}

if ($method === 'POST') {
    $body = api_json_body();
    api_require_fields($body, ['name']);
    try {
        $newId = subTenantProvision($parentId, $body, $userId);
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    }
    api_ok(['id' => $newId, 'scope' => subTenantScopeMap($newId)], 201);
}

if ($method === 'PATCH') {
    $tenantId = (int) api_query('id', 0);
    if (!$tenantId) api_error('id required', 422);
    $t = subTenantLookup($tenantId);
    if (!$t || (int)($t['parent_id'] ?? 0) !== $parentId) api_error('Sub-tenant not found', 404);

    $body = api_json_body();
    $sets = [];
    $params = ['id' => $tenantId];
    foreach (['name','slug','primary_color','logo_url'] as $f) {
        if (array_key_exists($f, $body)) {
            $sets[]      = "`$f` = :$f";
            $params[$f]  = $body[$f] === '' ? null : (string)$body[$f];
        }
    }
    if (!$sets) api_error('No updatable fields supplied', 422);

    $pdo = getDB();
    $pdo->prepare('UPDATE tenants SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = :id')
        ->execute($params);

    subTenantAudit($parentId, $tenantId, $userId, 'sub_tenant.updated', $body);
    api_ok(['id' => $tenantId]);
}

if ($method === 'DELETE') {
    $tenantId = (int) api_query('id', 0);
    if (!$tenantId) api_error('id required', 422);
    $t = subTenantLookup($tenantId);
    if (!$t || (int)($t['parent_id'] ?? 0) !== $parentId) api_error('Sub-tenant not found', 404);
    subTenantDeactivate($tenantId, $userId);
    api_ok(['id' => $tenantId, 'is_active' => 0]);
}

api_error('Method not allowed', 405);

// -------------------- helpers --------------------

function subTenantResolveParent(?int $activeTid, string $role): ?int {
    if (!$activeTid) return null;
    $t = subTenantLookup($activeTid);
    if (!$t) return null;
    if ($t['tenant_type'] === 'master') return (int) $t['id'];
    if (!empty($t['parent_id']))        return (int) $t['parent_id'];
    return null;
}

function subTenantUserCanManageParent(int $userId, int $parentId, string $role): bool {
    if ($role === 'master_admin') return true;
    $pdo = getDB();
    if (!$pdo) return false;
    $stmt = $pdo->prepare(
        "SELECT persona_type AS role FROM " . membershipReadSourceSql() . " src
          WHERE src.user_id = :u AND src.tenant_id = :t LIMIT 1"
    );
    $stmt->execute(['u' => $userId, 't' => $parentId]);
    $r = $stmt->fetch();
    return $r && in_array($r['role'], ['tenant_admin', 'master_admin'], true);
}

function subTenantUserHasMembership(int $userId, int $targetTenantId, string $globalRole): bool {
    if ($globalRole === 'master_admin') return true;
    $pdo = getDB();
    if (!$pdo) return false;

    // Direct membership
    $stmt = $pdo->prepare(
        "SELECT 1 FROM " . membershipReadSourceSql() . " src
          WHERE src.user_id = :u AND src.tenant_id = :t LIMIT 1"
    );
    $stmt->execute(['u' => $userId, 't' => $targetTenantId]);
    if ($stmt->fetch()) return true;

    // Membership in the parent of the target gives access to its sub-tenants
    $tenant = subTenantLookup($targetTenantId);
    if ($tenant && !empty($tenant['parent_id'])) {
        $stmt = $pdo->prepare(
            "SELECT persona_type AS role FROM " . membershipReadSourceSql() . " src
              WHERE src.user_id = :u AND src.tenant_id = :t LIMIT 1"
        );
        $stmt->execute(['u' => $userId, 't' => (int)$tenant['parent_id']]);
        $r = $stmt->fetch();
        if ($r && in_array($r['role'], ['tenant_admin', 'master_admin'], true)) {
            return true;
        }
    }
    return false;
}
