<?php
/**
 * /api/admin/manageable_tenants.php — authoritative "what tenants can I
 * switch into?" list for the SPA header dropdown.
 *
 *   GET /api/admin/manageable_tenants.php
 *
 *   Response:
 *     {
 *       active_tenant_id : <int|null>,
 *       global_role      : 'master_admin' | 'tenant_admin' | …,
 *       platform_mode    : <bool>,   // master_admin not pinned to a tenant
 *       tenants: [
 *         { id, name, slug, parent_id, tenant_type,
 *           role,                      // user's effective role at that tenant
 *           is_default,                // their primary tenant
 *           access:'direct'|'via_parent'|'platform',
 *           sub_tenants: [ { id, name, parent_id, role, access } ] // nested
 *         }, …
 *       ]
 *     }
 *
 * Membership rules:
 *   - master_admin / is_global_admin → every active tenant (`access='platform'`).
 *   - Direct membership (legacy or new) → `access='direct'`.
 *   - Sub-tenant of a tenant where the user has tenant_admin/admin
 *     membership at the *parent* → `access='via_parent'`. This is the case
 *     the SPA header was missing — a tenant_admin who has no row in the
 *     sub-tenant table still needs to be able to jump in to manage it.
 *
 * Authentication: any authenticated user. Result is scoped to them.
 *
 * tenant-leak-allow: cross-tenant by design — surfaces the per-user "what
 * can I see?" inventory; result rows are filtered by the user's identity.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/memberships.php';

$ctx          = api_require_auth(false); // platform-mode allowed
$user         = $ctx['user'];
$userId       = (int) ($user['id'] ?? 0);
$globalRole   = (string) ($ctx['global_role'] ?? $user['global_role'] ?? $user['role'] ?? 'employee');
$isGlobalAdm  = (bool) ($ctx['is_global_admin'] ?? false);
$activeTid    = $ctx['tenant_id'] ?? null;

$pdo = getDB();
if (!$pdo) api_error('No database connection', 500);

$isPlatformMA = ($globalRole === 'master_admin') || $isGlobalAdm;

// ----------------- Pull every tenant the user has any link to. ------------------
$tenants = [];           // keyed by tenant_id
$roleByTenant = [];      // tenant_id → 'tenant_admin' | 'admin' | 'employee' | ...

if ($isPlatformMA) {
    // master_admin sees every active tenant as 'platform' access.
    $stmt = $pdo->query(
        "SELECT id, name, slug, parent_id, tenant_type, logo_url, is_active
           FROM tenants
          WHERE is_active = 1
       ORDER BY (parent_id IS NULL) DESC, name ASC"
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $tenants[(int)$t['id']] = [
            'id'          => (int) $t['id'],
            'name'        => $t['name'],
            'slug'        => $t['slug'] ?? null,
            'parent_id'   => $t['parent_id'] ? (int)$t['parent_id'] : null,
            'tenant_type' => $t['tenant_type'] ?? 'master',
            'logo_url'    => $t['logo_url'] ?? null,
            'role'        => 'master_admin',
            'is_default'  => 0,
            'access'      => 'platform',
        ];
        $roleByTenant[(int)$t['id']] = 'master_admin';
    }
} else {
    // Direct memberships (UNION via shim covers both tables until backfill clears).
    $stmt = $pdo->prepare(
        "SELECT src.tenant_id, src.persona_type AS role, src.is_primary,
                t.name, t.slug, t.parent_id, t.tenant_type, t.logo_url
           FROM " . membershipReadSourceSql() . " src
           JOIN tenants t ON t.id = src.tenant_id AND t.is_active = 1
          WHERE src.user_id = :u
       ORDER BY src.is_primary DESC, t.name ASC"
    );
    $stmt->execute(['u' => $userId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $tid = (int) $t['tenant_id'];
        $tenants[$tid] = [
            'id'          => $tid,
            'name'        => $t['name'],
            'slug'        => $t['slug'] ?? null,
            'parent_id'   => $t['parent_id'] ? (int)$t['parent_id'] : null,
            'tenant_type' => $t['tenant_type'] ?? 'master',
            'logo_url'    => $t['logo_url'] ?? null,
            'role'        => (string) ($t['role'] ?? 'employee'),
            'is_default'  => (int) ($t['is_primary'] ?? 0) === 1 ? 1 : 0,
            'access'      => 'direct',
        ];
        $roleByTenant[$tid] = (string) ($t['role'] ?? 'employee');
    }

    // "via_parent" extension — if the user is tenant_admin or admin at any
    // parent (master) tenant in their direct list, every sub-tenant of that
    // parent becomes manageable even without a direct membership row.
    $adminParentIds = [];
    foreach ($roleByTenant as $tid => $role) {
        if (in_array($role, ['tenant_admin', 'admin', 'master_admin'], true)) {
            $adminParentIds[] = $tid;
        }
    }
    if ($adminParentIds) {
        $place = implode(',', array_fill(0, count($adminParentIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, name, slug, parent_id, tenant_type, logo_url
               FROM tenants
              WHERE is_active = 1
                AND tenant_type = 'sub'
                AND parent_id IN ($place)"
        );
        $stmt->execute($adminParentIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $tid = (int) $t['id'];
            if (isset($tenants[$tid])) continue;   // direct membership wins
            $parentRole = $roleByTenant[(int) $t['parent_id']] ?? 'tenant_admin';
            $tenants[$tid] = [
                'id'          => $tid,
                'name'        => $t['name'],
                'slug'        => $t['slug'] ?? null,
                'parent_id'   => (int) $t['parent_id'],
                'tenant_type' => 'sub',
                'logo_url'    => $t['logo_url'] ?? null,
                'role'        => $parentRole,         // inherited from parent admin status
                'is_default'  => 0,
                'access'      => 'via_parent',
            ];
        }
    }
}

// -------- Build hierarchical view: masters at top, sub-tenants nested. ---------
$tree = [];
foreach ($tenants as $tid => $t) {
    if ($t['parent_id'] === null || !isset($tenants[$t['parent_id']])) {
        $tree[$tid] = $t + ['sub_tenants' => []];
    }
}
foreach ($tenants as $tid => $t) {
    if ($t['parent_id'] !== null && isset($tree[$t['parent_id']])) {
        $tree[$t['parent_id']]['sub_tenants'][] = $t;
    } elseif ($t['parent_id'] !== null && !isset($tree[$t['parent_id']])) {
        // Orphan sub-tenant whose parent the user can't see — surface as
        // top-level so they can still switch.
        $tree[$tid] = $t + ['sub_tenants' => []];
    }
}

// Stable order: name ASC at each level.
usort($tree, fn($a, $b) => strcmp((string)$a['name'], (string)$b['name']));
foreach ($tree as &$node) {
    usort($node['sub_tenants'], fn($a, $b) => strcmp((string)$a['name'], (string)$b['name']));
}
unset($node);

// -------- "Recently viewed" strip — top 5 tenants by last_active_at -----------
// Uses the same shim source so legacy `user_tenants.last_active_at` heartbeats
// are honoured pre-backfill.
$recentlyViewed = [];
if ($userId > 0) {
    try {
        $rv = $pdo->prepare(
            'SELECT src.tenant_id, src.last_active_at, t.name, t.parent_id, t.tenant_type
               FROM ' . membershipReadSourceSql() . ' src
               JOIN tenants t ON t.id = src.tenant_id AND t.is_active = 1
              WHERE src.user_id = :u
                AND src.last_active_at IS NOT NULL
           ORDER BY src.last_active_at DESC
              LIMIT 5'
        );
        $rv->execute(['u' => $userId]);
        foreach ($rv->fetchAll(PDO::FETCH_ASSOC) as $row) {
            // Skip the active tenant from the "recently viewed" pill strip
            // (it'd be misleading to suggest you "recently viewed" the page
            // you're currently on).
            if ($activeTid && (int) $row['tenant_id'] === (int) $activeTid) continue;
            $recentlyViewed[] = [
                'id'              => (int) $row['tenant_id'],
                'name'            => $row['name'],
                'parent_id'       => $row['parent_id'] ? (int) $row['parent_id'] : null,
                'tenant_type'     => $row['tenant_type'] ?? 'master',
                'last_active_at'  => $row['last_active_at'],
            ];
        }
    } catch (\Throwable $_) { /* table missing or DB hiccup — skip strip */ }
}

api_ok([
    'active_tenant_id' => $activeTid,
    'global_role'      => $globalRole,
    'platform_mode'    => $isPlatformMA && !$activeTid,
    'recently_viewed'  => $recentlyViewed,
    'tenants'          => array_values($tree),
]);
