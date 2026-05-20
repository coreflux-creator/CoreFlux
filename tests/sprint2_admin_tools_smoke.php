<?php
/**
 * Sprint 2 — Real admin tools (users + tenant module access)
 *
 * Static-source assertions verifying:
 *   1. /api/users.php declares all required actions, RBAC gates,
 *      validates input, and audits via subTenantAudit.
 *   2. /api/tenant_modules.php toggles tenant_modules with idempotent UPSERT
 *      and rejects unknown modules.
 *   3. UsersAdmin.jsx + ModuleAccessAdmin.jsx render real CRUD UIs hitting
 *      those APIs, with the right testids.
 *   4. AdminModule.jsx no longer carries the mock UsersPage / ModulesPage.
 */

declare(strict_types=1);

$pass = 0; $fail = 0;
function _a(string $label, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok  $label\n"; }
    else       { $fail++; echo "  FAIL  $label\n"; }
}

echo "Sprint 2 — admin tools\n";

$users    = (string) file_get_contents(__DIR__ . '/../api/users.php');
$tmods    = (string) file_get_contents(__DIR__ . '/../api/tenant_modules.php');
$usersUI  = (string) file_get_contents(__DIR__ . '/../dashboard/src/pages/UsersAdmin.jsx');
$tmodsUI  = (string) file_get_contents(__DIR__ . '/../dashboard/src/pages/ModuleAccessAdmin.jsx');
$admin    = (string) file_get_contents(__DIR__ . '/../dashboard/src/pages/AdminModule.jsx');

echo "\n/api/users.php\n";
_a('requires auth via api_require_auth',       str_contains($users, 'api_require_auth()'));
_a('blocks employee/manager from admin',       str_contains($users, "['master_admin', 'tenant_admin', 'admin']"));
_a('GET list returns tenant_count',            str_contains($users, 'tenant_count'));
_a('POST creates with bcrypt password_hash',   str_contains($users, 'password_hash($pwd, PASSWORD_DEFAULT)'));
_a('POST seeds membership via provisionMembership',    str_contains($users, '_usersBootstrapMembership(') && str_contains($users, 'memberships.php'));
_a('PATCH password action gated to ≥ 8 chars', str_contains($users, "action === 'password'") && str_contains($users, 'strlen($pwd) < 8'));
_a('PATCH tenant assignment upsert via provisionMembership',  str_contains($users, 'provisionMembership(') && str_contains($users, "action === 'tenant'"));
_a('master_admin role gate enforced',          str_contains($users, "Only master_admin can assign master_admin role"));
_a('tenant_admin scoped to active tenant',     str_contains($users, "WHERE ut.tenant_id = :scope_t"));
_a('cannot deactivate self',                   str_contains($users, 'Cannot deactivate yourself'));
_a('audits user.created / updated / deactivated', str_contains($users, "'user.created'") && str_contains($users, "'user.updated'") && str_contains($users, "'user.deactivated'"));
_a('soft-deactivate (sets is_active=0)',       str_contains($users, 'SET is_active = 0'));

echo "\n/api/tenant_modules.php\n";
_a('requires auth',                            str_contains($tmods, 'api_require_auth()'));
_a('reads getModuleDefinitions for the matrix',str_contains($tmods, 'getModuleDefinitions()'));
_a('greenfield tenants default-on',            str_contains($tmods, 'array_key_exists($key, $existing) ? $existing[$key] : true'));
_a('PATCH idempotent UPSERT',                  str_contains($tmods, 'ON DUPLICATE KEY UPDATE'));
_a('PATCH tracks enabled_at/disabled_at',      str_contains($tmods, 'enabled_at') && str_contains($tmods, 'disabled_at'));
_a('rejects unknown module_key',               str_contains($tmods, "Unknown module"));
_a('audits module_enabled / module_disabled',  str_contains($tmods, "'tenant.module_enabled'") && str_contains($tmods, "'tenant.module_disabled'"));
_a('manage gate covers parent tenant_admin',   str_contains($tmods, '$t[\'parent_id\']'));

echo "\nUsersAdmin.jsx\n";
_a('hits /api/users.php',                      str_contains($usersUI, "useApi('/api/users.php')"));
_a('search input wired to filter',             str_contains($usersUI, 'data-testid="users-search"'));
_a('new user button wired',                    str_contains($usersUI, 'data-testid="users-new-btn"'));
_a('reset-password modal exists',              str_contains($usersUI, 'PasswordResetModal'));
_a('deactivate confirms then DELETEs',         str_contains($usersUI, 'api.delete(`/api/users.php?id='));
_a('save dispatches POST/PATCH correctly',     str_contains($usersUI, "api.post('/api/users.php'") && str_contains($usersUI, 'api.patch(`/api/users.php?id='));
_a('master_admin role hidden for non-masters', str_contains($usersUI, 'isMaster && <option value="master_admin">'));

echo "\nModuleAccessAdmin.jsx\n";
_a('hits /api/tenant_modules.php',             str_contains($tmodsUI, '/api/tenant_modules.php'));
_a('tenant picker wired',                      str_contains($tmodsUI, 'data-testid="modules-tenant-picker"'));
_a('toggle button per row',                    str_contains($tmodsUI, 'data-testid={`modules-toggle-${m.module_key}`}'));
_a('master_admin loads /api/tenants.php',      str_contains($tmodsUI, "useApi(isMaster ? '/api/tenants.php'"));
_a('PATCH flips is_enabled',                   str_contains($tmodsUI, 'is_enabled: !mod.is_enabled'));

echo "\nAdminModule.jsx — no more mocks\n";
_a('imports the real UsersAdmin',              str_contains($admin, "import UsersAdmin from './UsersAdmin'"));
_a('imports the real ModuleAccessAdmin',       str_contains($admin, "import ModuleAccessAdmin from './ModuleAccessAdmin'"));
_a('mock users array gone',                    !str_contains($admin, "[{ id: 1, name: 'Kunal'"));
_a('mock module access array gone',            !str_contains($admin, "moduleAccess"));
_a('routes /admin/users to UsersAdmin',        str_contains($admin, '<Route path="/users"') && str_contains($admin, '<UsersAdmin'));
_a('routes /admin/modules to ModuleAccessAdmin', str_contains($admin, '<Route path="/modules"') && str_contains($admin, '<ModuleAccessAdmin'));

echo "\n--- $pass assertions, $fail failed ---\n";
exit($fail === 0 ? 0 : 1);
