<?php
/**
 * /api/admin/user_effective_permissions.php — RBAC debug inspector.
 *
 * Answers "why can/can't this user do X?" for an admin who's diagnosing
 * a permissions issue, without forcing them to grep the DB.
 *
 *   GET /api/admin/user_effective_permissions.php?user_id=N
 *
 *   Response:
 *     {
 *       user:       { id, name, email, role, is_global_admin },
 *       tenants:    [
 *         { tenant_id, tenant_name, legacy_role,
 *           memberships: [
 *             { id, persona_label, persona_type, is_primary, status,
 *               module_access: { module_key: level, ... } }
 *           ]
 *         }
 *       ],
 *       can_matrix: { permission_string: { module, action, allowed,
 *                                          legacy_ok, new_ok } },
 *       summary:    { canonical_modules_count, synthetic_modules_count,
 *                     parked_perms_count, total_perms_checked }
 *     }
 *
 * The `can_matrix` runs every permission string in the B4 legacy-map
 * table through rbac_legacy_can() so the admin sees the dual-check
 * verdict on each, plus which layer (legacy / new) denied it. Scoped to
 * the user's primary tenant + persona to keep the response small.
 *
 * Auth: master_admin / tenant_admin / is_global_admin only.
 * Read-only.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';

$ctx = api_require_auth();
if (api_method() !== 'GET') api_error('Method not allowed', 405);

$role          = (string) ($ctx['role'] ?? 'employee');
$isGlobalAdmin = (bool) ($ctx['is_global_admin'] ?? false);
if (!$isGlobalAdmin && !in_array($role, ['master_admin', 'tenant_admin'], true)) {
    api_error('Forbidden — admin only', 403);
}

$userId = (int) (api_query('user_id') ?? 0);
if (!$userId) api_error('user_id is required', 422);

$pdo = getDB();
if (!$pdo) api_error('No database connection', 500);

// ----------------------------------------------------------------- user basics
$u = $pdo->prepare('SELECT id, name, email, role, is_global_admin FROM users WHERE id = :id LIMIT 1');
$u->execute(['id' => $userId]);
$user = $u->fetch(\PDO::FETCH_ASSOC);
if (!$user) api_error('User not found', 404);
$user['id']              = (int) $user['id'];
$user['is_global_admin'] = (int) ($user['is_global_admin'] ?? 0) === 1;

// ----------------------------------------------------------------- tenant + memberships
$tenants = [];
try {
    $st = $pdo->prepare(
        'SELECT ut.tenant_id, ut.role AS legacy_role, t.name AS tenant_name
           FROM user_tenants ut
           JOIN tenants t ON t.id = ut.tenant_id
          WHERE ut.user_id = :u AND ut.status = "active"
       ORDER BY ut.is_default DESC, t.name'
    );
    $st->execute(['u' => $userId]);
    $tenants = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $_) { /* no user_tenants table — leave empty */ }

$membershipsByTenant = [];
try {
    $st = $pdo->prepare(
        'SELECT tm.id, tm.tenant_id, tm.persona_label, tm.persona_type,
                tm.is_primary, tm.status
           FROM tenant_memberships tm
          WHERE tm.user_id = :u AND tm.status IN ("active","pending")'
    );
    $st->execute(['u' => $userId]);
    foreach ($st->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
        $r['id']         = (int) $r['id'];
        $r['tenant_id']  = (int) $r['tenant_id'];
        $r['is_primary'] = (int) $r['is_primary'] === 1;
        $r['module_access'] = [];
        $membershipsByTenant[(int) $r['tenant_id']][] = $r;
    }
} catch (\Throwable $_) { /* no tenant_memberships — leave empty */ }

// Pull access rows per membership.
try {
    foreach ($membershipsByTenant as $tid => &$rows) {
        foreach ($rows as &$m) {
            $st = $pdo->prepare(
                'SELECT module_key, access_level
                   FROM membership_module_access
                  WHERE membership_id = :id'
            );
            $st->execute(['id' => $m['id']]);
            $access = [];
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
                $access[(string) $row['module_key']] = (string) $row['access_level'];
            }
            $m['module_access'] = $access;
        }
        unset($m);
    }
    unset($rows);
} catch (\Throwable $_) { /* skip */ }

// Stitch memberships under tenants.
$tenantsOut = [];
foreach ($tenants as $t) {
    $tid = (int) $t['tenant_id'];
    $tenantsOut[] = [
        'tenant_id'   => $tid,
        'tenant_name' => (string) $t['tenant_name'],
        'legacy_role' => (string) $t['legacy_role'],
        'memberships' => $membershipsByTenant[$tid] ?? [],
    ];
}
// Surface memberships whose tenant isn't in user_tenants (rare but possible).
$listedTenants = array_column($tenantsOut, 'tenant_id');
foreach ($membershipsByTenant as $tid => $rows) {
    if (in_array($tid, $listedTenants, true)) continue;
    $tenantsOut[] = [
        'tenant_id'   => $tid,
        'tenant_name' => '(no user_tenants row)',
        'legacy_role' => null,
        'memberships' => $rows,
    ];
}

// ----------------------------------------------------------------- can_matrix
// Compute the dual-check verdict for every legacy permission string in
// the B4 mapping. Scope the check to this user's primary tenant (first
// tenant in the list) — running it across every tenant would balloon
// the response.
$canMatrix = [];
$summary   = [
    'canonical_modules_count' => 0,
    'synthetic_modules_count' => 0,
    'parked_perms_count'      => 0,
    'total_perms_checked'     => 0,
];
$primaryTenantId = $tenantsOut ? (int) $tenantsOut[0]['tenant_id'] : 0;

if ($primaryTenantId > 0 && class_exists('RbacLegacyMap')) {
    // We need to evaluate as $userId in $primaryTenantId. The bridge reads
    // $_SESSION for that, so temporarily impersonate within this request.
    $savedSession = $_SESSION ?? [];
    $_SESSION['user']      = ['id' => $userId, 'role' => $user['role']];
    $_SESSION['tenant_id'] = $primaryTenantId;
    try {
        $canonical = ['people','placements','time','billing','ap','accounting','payroll','treasury','reports'];
        $synthetic = ['integrations','ai','staffing'];
        foreach (RbacLegacyMap::table() as $perm => $tuple) {
            [$module, $action] = $tuple;
            $isParked = $module === '_platform';
            if ($isParked) $summary['parked_perms_count']++;
            elseif (in_array($module, $canonical, true)) $summary['canonical_modules_count']++;
            elseif (in_array($module, $synthetic, true)) $summary['synthetic_modules_count']++;
            $summary['total_perms_checked']++;

            $legacyOk = false; $newOk = false;
            if (class_exists('RBAC') && method_exists('RBAC', 'hasPermission')) {
                $legacyOk = (bool) RBAC::hasPermission($_SESSION['user'], $perm);
            }
            if (!$isParked && function_exists('api_can')) {
                $newOk = (bool) api_can($module, $action);
            }
            $allowed = $isParked ? $legacyOk : ($legacyOk && $newOk);
            $canMatrix[$perm] = [
                'module'    => $module,
                'action'    => $action,
                'allowed'   => $allowed,
                'legacy_ok' => $legacyOk,
                'new_ok'    => $isParked ? null : $newOk,
                'parked'    => $isParked,
            ];
        }
    } finally {
        $_SESSION = $savedSession;
    }
}

api_ok([
    'user'       => $user,
    'tenants'    => $tenantsOut,
    'can_matrix' => $canMatrix,
    'summary'    => $summary,
]);
