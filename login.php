<?php
/**
 * CoreFlux Login Handler
 */

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/data.php';

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

// Get user's tenants
$tenants = getUserTenants($dbUser['id']);

if (empty($tenants)) {
    $tenants = [['id' => 1, 'name' => 'Default', 'role' => 'employee', 'is_default' => 1, 'parent_id' => null]];
}

// Select default tenant
$defaultTenant = $tenants[0];
foreach ($tenants as $t) {
    if ($t['is_default']) {
        $defaultTenant = $t;
        break;
    }
}

// Get user's role in this tenant
$tenantRole = $defaultTenant['role'] ?? 'employee';

// Parse name
$nameParts = explode(' ', $dbUser['name'] ?? 'User', 2);

// Build user object
$user = [
    'id' => $dbUser['id'],
    'first_name' => $nameParts[0],
    'last_name' => $nameParts[1] ?? '',
    'email' => $dbUser['email'],
    'role' => $tenantRole,
    'global_role' => $globalRole,
    'avatar' => $dbUser['avatar'] ?? null,
    'tenants' => array_map(function($t) {
        return [
            'id' => $t['id'],
            'name' => $t['name'],
            'role' => $t['role'],
            'logo_url' => $t['logo_url'] ?? null,
            'parent_id' => $t['parent_id'] ?? null,
        ];
    }, $tenants),
];

// Get modules for this user in this tenant context
$modules = getModulesForUserInTenant(
    $dbUser['id'],
    $defaultTenant['id'],
    $globalRole,
    $tenantRole
);

// Add actions to each module
foreach ($modules as &$mod) {
    $mod['actions'] = getModuleSidebarItems($mod['name']);
}
unset($mod);

// Set session
$_SESSION['user'] = $user;
$_SESSION['modules'] = $modules;
$_SESSION['tenant'] = $defaultTenant['name'];
$_SESSION['tenant_id'] = $defaultTenant['id'];
$_SESSION['tenant_role'] = $tenantRole;
$_SESSION['global_role'] = $globalRole;
$_SESSION['active_module'] = $modules[0] ?? null;

// Check for redirect parameter (for SPA login flow)
$redirect = $_GET['redirect'] ?? $_POST['redirect'] ?? 'dashboard';
if ($redirect === 'spa') {
    header("Location: spa.php");
} else {
    header("Location: dashboard.php");
}
exit;
