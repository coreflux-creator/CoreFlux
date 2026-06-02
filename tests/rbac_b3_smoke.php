<?php
/**
 * RBAC B3 smoke — verifies the admin endpoints (/api/admin/memberships.php,
 * membership_access.php, membership_audit.php) and the React UI files
 * compile cleanly + reference the right backend routes.
 *
 * No live DB required: each endpoint catches missing tables and emits a
 * structured 503 or `configured:false` payload.
 *
 *   php -d zend.assertions=1 /app/tests/rbac_b3_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ----------------------------------------------------------------- endpoint files
echo "Admin endpoint files\n";
$auditPath  = $ROOT . '/api/admin/membership_audit.php';
$memsPath   = $ROOT . '/api/admin/memberships.php';
$accessPath = $ROOT . '/api/admin/membership_access.php';
$audit  = (string) file_get_contents($auditPath);
$mems   = (string) file_get_contents($memsPath);
$access = (string) file_get_contents($accessPath);
$a('membership_audit.php exists',                $audit !== '');
$a('memberships.php exists',                     $mems !== '');
$a('membership_access.php exists',               $access !== '');

// ----------------------------------------------------------------- syntax
echo "\nSyntax sanity\n";
foreach ([
    '/api/admin/membership_audit.php',
    '/api/admin/memberships.php',
    '/api/admin/membership_access.php',
] as $rel) {
    $rc = 0; $o = [];
    exec('php -l ' . escapeshellarg($ROOT . $rel) . ' 2>&1', $o, $rc);
    $a("php -l {$rel}", $rc === 0);
}

// ----------------------------------------------------------------- membership_audit.php
echo "\nmembership_audit.php contract\n";
$a('requires api_bootstrap',                     $c($audit, "require_once __DIR__ . '/../../core/api_bootstrap.php'"));
$a('GET-only',                                   $c($audit, "api_method() !== 'GET'"));
$a('admin gate (tenant_admin / master_admin / global)',
    $c($audit, "in_array(\$role, ['master_admin', 'tenant_admin']") && $c($audit, '$isGlobalAdmin'));
$a('scopes by ma.tenant_id',                     $c($audit, 'ma.tenant_id = :t'));
$a('left joins users for actor + target',        $c($audit, 'LEFT JOIN users au') && $c($audit, 'LEFT JOIN users tu'));
$a('joins tenant_memberships for persona',       $c($audit, 'LEFT JOIN tenant_memberships tm'));
$a('orders DESC by occurred_at',                 $c($audit, 'ORDER BY ma.occurred_at DESC'));
$a('limit clamped 1..100',                       $c($audit, 'max(1, min(100,'));
$a('returns entries[] payload',                  $c($audit, "'entries'    => \$entries"));
$a('handles missing migration (configured:false)', $c($audit, "'configured' => false"));
$a('supports sub_tenant filter param',           $c($audit, "api_query('sub_tenant')"));
$a('sub_tenant filter uses JSON_CONTAINS',       $c($audit, 'JSON_CONTAINS'));
$a('sub_tenant filter scopes to membership actions',
    $c($audit, "ma.action IN ('module_grant','module_revoke','permissions_copied'"));

// ----------------------------------------------------------------- memberships.php
echo "\nmemberships.php contract\n";
$a('requires api_bootstrap',                     $c($mems, "require_once __DIR__ . '/../../core/api_bootstrap.php'"));
$a('admin gate',                                 $c($mems, "in_array(\$role, ['master_admin', 'tenant_admin']"));
$a('supports GET (list)',                        $c($mems, "if (\$method === 'GET')"));
$a('supports POST (create)',                     $c($mems, "if (\$method === 'POST')"));
$a('supports PATCH (update)',                    $c($mems, "if (\$method === 'PATCH')"));
$a('supports DELETE (soft-revoke)',              $c($mems, "if (\$method === 'DELETE')"));
$a('upserts via ON DUPLICATE KEY UPDATE',        $c($mems, 'ON DUPLICATE KEY UPDATE'));
$a('persona_type whitelist',                     $c($mems, '_ALLOWED_PERSONA_TYPES'));
$a('status whitelist',                           $c($mems, '_ALLOWED_STATUS'));
$a('joins users table on list',                  $c($mems, 'JOIN users u ON u.id = tm.user_id'));
$a('includes modules_count subselect',           $c($mems, 'modules_count'));
$a('enforces single-primary per (user,tenant)',  $c($mems, 'SET is_primary = 0'));
$a('soft delete sets status=revoked',            $c($mems, "SET status = 'revoked'"));
$a('audits via RBACResolver::auditMembership',   substr_count($mems, 'RBACResolver::auditMembership') >= 3);
$a('uses class_exists guard? no — direct call expected after bootstrap require',
    $c($mems, 'RBACResolver::'));
$a('scope check: tenant id matches on PATCH/DELETE',
    substr_count($mems, "AND tenant_id = :t") >= 1);

// ----------------------------------------------------------------- membership_access.php
echo "\nmembership_access.php contract\n";
$a('requires api_bootstrap',                     $c($access, "require_once __DIR__ . '/../../core/api_bootstrap.php'"));
$a('admin gate',                                 $c($access, "in_array(\$role, ['master_admin', 'tenant_admin']"));
$a('GET returns membership access rows',         $c($access, "if (\$method === 'GET')"));
$a('POST supports op:grant',                     $c($access, "\$op === 'grant'"));
$a('POST supports op:revoke',                    $c($access, "\$op === 'revoke'"));
$a('POST supports op:copy',                      $c($access, "\$op === 'copy'"));
$a('access_level whitelist (none/read/write/admin)',
    $c($access, "['none','read','write','admin']"));
$a('grant calls RBACResolver::grantModule',      $c($access, 'RBACResolver::grantModule'));
$a('revoke calls RBACResolver::revokeModule',    $c($access, 'RBACResolver::revokeModule'));
$a('copy calls RBACResolver::copyPermissions',   $c($access, 'RBACResolver::copyPermissions'));
$a('confirms membership in tenant before write', $c($access, '_ma_membership_in_tenant'));
$a('sub_tenant_scope accepted as array',         $c($access, 'sub_tenant_scope'));
$a('returns 422 on bad access_level',            $c($access, "api_error('Invalid access_level'"));

// ----------------------------------------------------------------- React UI files
echo "\nReact UI files\n";
$adminMod = (string) file_get_contents($ROOT . '/dashboard/src/pages/AdminModule.jsx');
$rbacPage = (string) file_get_contents($ROOT . '/dashboard/src/pages/RbacMembershipsAdmin.jsx');
$panel    = (string) file_get_contents($ROOT . '/dashboard/src/pages/RecentAccessChangesPanel.jsx');
$a('RbacMembershipsAdmin.jsx present',           $rbacPage !== '');
$a('RecentAccessChangesPanel.jsx present',       $panel !== '');

$a('AdminModule imports RbacMembershipsAdmin',   $c($adminMod, "import RbacMembershipsAdmin from './RbacMembershipsAdmin'"));
$a('AdminModule imports RecentAccessChangesPanel', $c($adminMod, "import RecentAccessChangesPanel from './RecentAccessChangesPanel'"));
$a('AdminModule registers /memberships route',   $c($adminMod, 'path="/memberships"'));
$a('AdminModule adds sidebar link',              $c($adminMod, "to: '/admin/memberships'"));
$a('AdminModule overview embeds panel',          $c($adminMod, '<RecentAccessChangesPanel'));

$a('RbacMembershipsAdmin hits /api/admin/memberships.php',
    $c($rbacPage, "'/api/admin/memberships.php'") || $c($rbacPage, '`/api/admin/memberships.php'));
$a('RbacMembershipsAdmin hits /api/admin/membership_access.php',
    $c($rbacPage, "'/api/admin/membership_access.php'"));
$a('RbacMembershipsAdmin exposes copy-permissions UI',
    $c($rbacPage, "op: 'copy'"));
$a('Access grid issues op:grant',                $c($rbacPage, "op: 'grant'"));
$a('Access grid issues op:revoke',               $c($rbacPage, "op: 'revoke'"));
$a('Page has data-testid root',                  $c($rbacPage, 'data-testid="rbac-memberships-admin"'));

// B3 sub-tenant scope picker — backend already accepted sub_tenant_scope;
// these assertions lock the UI delta that surfaces it (per-grant scope
// chooser, "All entities" default, GET /api/sub_tenants.php load).
// Parent-as-entity applies everywhere — the parent keeps its own books and
// must be selectable as a scope target, not just sub-tenants.
$a('Access grid loads sub-tenants list',         $c($rbacPage, "'/api/sub_tenants.php'"));
$a('Access grid exposes scope toggle button',    $c($rbacPage, 'data-testid={`access-scope-toggle-${m}`}'));
$a('Access grid renders ScopePicker component',  $c($rbacPage, 'function ScopePicker'));
$a('Scope picker has "All entities" option',     $c($rbacPage, 'data-testid={`${testIdPrefix}-all`}')
                                              && $c($rbacPage, '>All entities</strong>'));
$a('Scope picker emits per-entity testids',      $c($rbacPage, 'data-testid={`${testIdPrefix}-st-${st.id}`}'));
$a('Scope picker labels parent entity',          $c($rbacPage, "st.kind === 'parent'")
                                              && $c($rbacPage, '(parent)</em>'));
$a('Access grid column reads "Entity scope"',    $c($rbacPage, '>Entity scope</th>'));
$a('Grant body forwards sub_tenant_scope',       $c($rbacPage, 'body.sub_tenant_scope = scope'));

$a('Panel calls /api/admin/membership_audit.php',
    $c($panel, '/api/admin/membership_audit.php'));
$a('Panel has data-testid root',                 $c($panel, 'data-testid="recent-access-changes"'));
$a('Panel renders empty state',                  $c($panel, 'data-testid="recent-access-empty"'));
$a('Panel supports sub-tenant filter prop',      $c($panel, 'showSubTenantFilter'));
$a('Panel exposes sub-tenant filter testid',     $c($panel, 'recent-access-subtenant-filter'));
$a('Panel filter includes parent entity',        $c($panel, "kind: 'parent'")
                                              && $c($panel, "ent.kind === 'parent' ? ' — parent' : ''"));
$a('Panel filter "All entities" label',          $c($panel, '<option value="">All entities</option>'));
$a('Membership page enables sub-tenant filter',  $c($rbacPage, 'showSubTenantFilter={true}'));

// ----------------------------------------------------------------- summary
echo "\n=========================================\n";
echo "RBAC B3 smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
