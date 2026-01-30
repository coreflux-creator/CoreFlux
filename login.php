<?php
/**
 * CoreFlux Login Handler
 * Authenticates users and creates session using core auth
 */

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/modules.php';

// Initialize session
initSession();

// Only process POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: login.html");
    exit;
}

// Get form input
$email = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if (!$email || !$password) {
    header("Location: login.html?error=missing");
    exit;
}

// Check if database is enabled
if (defined('USE_DATABASE') && USE_DATABASE) {
    // Database authentication
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
    
    if (!$dbUser || !password_verify($password, $dbUser['password'])) {
        header("Location: login.html?error=invalid");
        exit;
    }
    
    // Check email verification if column exists
    if (isset($dbUser['email_verified']) && (int)$dbUser['email_verified'] !== 1) {
        header("Location: login.html?error=unverified");
        exit;
    }
    
    // Get user's tenants from database (or default)
    // TODO: Query user_tenants table when available
    $tenants = [
        ['id' => 1, 'name' => $dbUser['tenant_name'] ?? 'Default Tenant']
    ];
    
    // Build user session
    $user = [
        'id' => $dbUser['id'],
        'first_name' => $dbUser['first_name'] ?? $dbUser['name'] ?? 'User',
        'last_name' => $dbUser['last_name'] ?? '',
        'email' => $dbUser['email'],
        'role' => $dbUser['role'] ?? 'employee',
        'avatar' => $dbUser['avatar'] ?? null,
        'tenants' => $tenants,
    ];
    
} else {
    // Demo/development mode - accept test credentials
    $testUsers = [
        'admin@coreflux.demo' => ['password' => 'admin123', 'role' => 'admin', 'name' => 'Demo Admin'],
        'employee@coreflux.demo' => ['password' => 'employee123', 'role' => 'employee', 'name' => 'Demo Employee'],
        'manager@coreflux.demo' => ['password' => 'manager123', 'role' => 'manager', 'name' => 'Demo Manager'],
    ];
    
    if (!isset($testUsers[$email]) || $testUsers[$email]['password'] !== $password) {
        header("Location: login.html?error=invalid");
        exit;
    }
    
    $testUser = $testUsers[$email];
    $user = [
        'id' => 1,
        'first_name' => explode(' ', $testUser['name'])[0],
        'last_name' => explode(' ', $testUser['name'])[1] ?? '',
        'email' => $email,
        'role' => $testUser['role'],
        'avatar' => null,
        'tenants' => [
            ['id' => 1, 'name' => 'Acme Corp'],
            ['id' => 2, 'name' => 'Beta Industries'],
        ],
    ];
}

// Get modules based on role (from core/modules.php)
$modules = getUserModules($user['role']);

// Set up session
$_SESSION['user'] = $user;
$_SESSION['modules'] = $modules;
$_SESSION['tenant'] = $user['tenants'][0]['name'];
$_SESSION['tenant_id'] = $user['tenants'][0]['id'];
$_SESSION['active_module'] = $modules[0] ?? null;

// Redirect to dashboard
header("Location: dashboard.php");
exit;
