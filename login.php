<?php
/**
 * CoreFlux Login Handler
 */

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/data.php';
require_once __DIR__ . '/core/memberships.php';

initSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: login.html");
    exit;
}

$email = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if (!$email || !$password) {
    header("Location: login.html?error=missing");
    exit;
}

require_once __DIR__ . '/core/db.php';
$pdo = getDB();

if (!$pdo) {
    header("Location: login.html?error=db");
    exit;
}

// Look up user
$stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
$stmt->execute([$email]);
$dbUser = $stmt->fetch();

if (!$dbUser) {
    header("Location: login.html?error=invalid");
    exit;
}

// Check password
$validPassword = false;
if (!empty($dbUser['password']) && password_verify($password, $dbUser['password'])) {
    $validPassword = true;
} elseif (!empty($dbUser['password_hash']) && password_verify($password, $dbUser['password_hash'])) {
    $validPassword = true;
}

if (!$validPassword) {
    header("Location: login.html?error=invalid");
    exit;
}

if (isset($dbUser['is_active']) && (int)$dbUser['is_active'] !== 1) {
    header("Location: login.html?error=inactive");
    exit;
}

// Get user's global role (from users table)
$globalRole = $dbUser['role'] ?? 'employee';
$isGlobalAdmin = (int) ($dbUser['is_global_admin'] ?? 0) === 1;
$isPlatformMA  = ($globalRole === 'master_admin') || $isGlobalAdmin;

// Self-healing backfill — quietly migrate any legacy `user_tenants` rows
// for this user into `tenant_memberships` before we hand off to the rest
// of the request. Best-effort: any failure is logged, never blocks login.
try { healMembershipsForUser((int) $dbUser['id']); } catch (\Throwable $e) {
    error_log('[login] healMembershipsForUser failed: ' . $e->getMessage());
}

// Get user's tenants
$tenants = getUserTenants($dbUser['id']);

// Landing-tenant resolution (2026-02 rebuild) — per /app/memory/PRD.md
// "Tenant routing — role-based landing" section.
//
//   • Platform master_admin / is_global_admin → land in PLATFORM MODE
//     (no tenant pinned). Header dropdown picks any tenant to view as.
//   • Single-tenant users → straight into their tenant.
//   • Multi-tenant users   → primary tenant if flagged, else first.
//   • Sub-tenant-only admin (no parent membership) → land in that sub.
$defaultTenant = null;
$platformMode  = false;

if ($isPlatformMA) {
    // Platform mode: don't pin a tenant by default. The SPA reads
    // global_role + tenants[] from session.php and routes to /admin.
    $platformMode  = true;
    $defaultTenant = null;
} elseif (!empty($tenants)) {
    // Prefer the user's primary membership.
    foreach ($tenants as $t) {
        if (!empty($t['is_default'])) { $defaultTenant = $t; break; }
    }
    // Fallback: first row (already ordered is_default DESC, name ASC).
    if ($defaultTenant === null) $defaultTenant = $tenants[0];
}

// Hard fallback to keep the login flow robust even with zero memberships.
if ($defaultTenant === null && !$platformMode) {
    $tenants = [['id' => 1, 'name' => 'Default', 'role' => 'employee', 'is_default' => 1, 'parent_id' => null]];
    $defaultTenant = $tenants[0];
}

// Get user's role in this tenant (platform mode keeps the global role).
$tenantRole = $platformMode
    ? 'master_admin'
    : ($defaultTenant['role'] ?? 'employee');

// Parse name
$nameParts = explode(' ', $dbUser['name'] ?? 'User', 2);

// For platform admins, the user.tenants list is the FULL tenant inventory
// (so the header dropdown can offer everything to switch into). For everyone
// else it's their direct memberships only. The authoritative list comes from
// /api/admin/manageable_tenants.php at runtime, but seeding the session up
// front keeps the very first SPA paint correct.
if ($platformMode) {
    require_once __DIR__ . '/core/data.php';
    $sessionTenants = array_map(function($row) {
        return [
            'id'        => (int) $row['id'],
            'name'      => $row['name'],
            'role'      => 'master_admin',
            'logo_url'  => $row['logo_url'] ?? null,
            'parent_id' => isset($row['parent_id']) && $row['parent_id'] ? (int) $row['parent_id'] : null,
        ];
    }, getAllTenants());
} else {
    $sessionTenants = array_map(function($t) {
        return [
            'id'        => $t['id'],
            'name'      => $t['name'],
            'role'      => $t['role'],
            'logo_url'  => $t['logo_url'] ?? null,
            'parent_id' => $t['parent_id'] ?? null,
        ];
    }, $tenants);
}

// Build user object
$user = [
    'id' => $dbUser['id'],
    'first_name' => $nameParts[0],
    'last_name' => $nameParts[1] ?? '',
    'email' => $dbUser['email'],
    'role' => $tenantRole,
    'global_role' => $globalRole,
    'is_global_admin' => $isGlobalAdmin ? 1 : 0,
    'avatar' => $dbUser['avatar'] ?? null,
    'platform_mode' => $platformMode,
    'tenants' => $sessionTenants,
];

// Get modules for this user in this tenant context.
// Platform mode (master_admin not pinned to a tenant) gets the full module
// list — they can navigate every module on any tenant via the picker.
if ($platformMode) {
    $modules = getModulesForUserInTenant($dbUser['id'], 0, $globalRole, $tenantRole);
} else {
    $modules = getModulesForUserInTenant(
        $dbUser['id'],
        $defaultTenant['id'],
        $globalRole,
        $tenantRole
    );
}

// Add actions to each module
foreach ($modules as &$mod) {
    $mod['actions'] = getModuleSidebarItems($mod['name']);
}
unset($mod);

// Set session
$_SESSION['user'] = $user;
$_SESSION['modules'] = $modules;
if ($platformMode) {
    // Platform mode: no tenant pinned. The SPA reads global_role and routes
    // to /admin. User can still pick a tenant from the header dropdown.
    $_SESSION['tenant']     = null;
    $_SESSION['tenant_id']  = null;
    $_SESSION['platform_mode'] = true;
} else {
    $_SESSION['tenant']     = $defaultTenant['name'];
    $_SESSION['tenant_id']  = $defaultTenant['id'];
    $_SESSION['platform_mode'] = false;
}
$_SESSION['tenant_role'] = $tenantRole;
$_SESSION['global_role'] = $globalRole;
$_SESSION['active_module'] = $modules[0] ?? null;

// Check for redirect parameter (for SPA + admin ops pages).
// Default destination is the React SPA (spa.php). The legacy PHP dashboard
// (dashboard.php) is preserved and reachable via ?redirect=dashboard but
// MUST NOT be deleted — it is the pre-React fallback per project hard rule
// (see /app/memory/HARD_RULES.md).
$redirect = $_GET['redirect'] ?? $_POST['redirect'] ?? 'spa';
$next     = $_POST['next']    ?? $_GET['next']    ?? '';
$adminOps = ['install', 'update', 'diagnostics'];

// SPA deep-link return: only honour local paths (no scheme/host) so we can't
// be tricked into open-redirect to a phishing host.
$isLocalPath = is_string($next) && $next !== '' && strncmp($next, '/', 1) === 0
            && strncmp($next, '//', 2) !== 0;

if (in_array($redirect, $adminOps, true)) {
    header("Location: /{$redirect}.php");
} elseif ($redirect === 'dashboard') {
    header("Location: dashboard.php");
} elseif ($isLocalPath) {
    // Land back on the deep route the SPA bounced from.
    header("Location: /spa.php" . (str_contains($next, '#') ? $next : '#' . $next));
} elseif ($platformMode) {
    // Platform mode default landing — the admin dashboard.
    header("Location: spa.php#/admin");
} else {
    header("Location: spa.php");
}
exit;
