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
    
    if (!$dbUser) {
        header("Location: login.html?error=invalid");
        exit;
    }
    
    // Check password - try both password and password_hash columns
    $passwordHash = $dbUser['password_hash'] ?? $dbUser['password'] ?? '';
    if (!password_verify($password, $passwordHash)) {
        // Also try the password column if password_hash failed
        if (!empty($dbUser['password']) && !password_verify($password, $dbUser['password'])) {
            header("Location: login.html?error=invalid");
            exit;
        }
    }
    
    // Check if account is active
    if (isset($dbUser['is_active']) && (int)$dbUser['is_active'] !== 1) {
        header("Location: login.html?error=inactive");
        exit;
    }
    
    // Check email verification (optional - skip if not required)
    // if (isset($dbUser['email_verified']) && (int)$dbUser['email_verified'] !== 1) {
    //     header("Location: login.html?error=unverified");
    //     exit;
    // }
    
    // Parse name into first/last
    $nameParts = explode(' ', $dbUser['name'] ?? 'User', 2);
    $firstName = $nameParts[0];
    $lastName = $nameParts[1] ?? '';
    
    // Get tenant info - for now use tenant_id from user record
    $tenantId = $dbUser['tenant_id'] ?? 1;
    
    // TODO: Query tenants table for tenant name when available
    $tenants = [
        ['id' => $tenantId, 'name' => 'Tenant ' . $tenantId]
    ];
    
    // Map role - your DB uses 'user' as default, map to our roles
    $role = $dbUser['role'] ?? 'user';
    if ($role === 'user') {
        $role = 'employee'; // Map 'user' to 'employee' for module access
    }
    
    // Build user session
    $user = [
        'id' => $dbUser['id'],
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $dbUser['email'],
        'role' => $role,
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
