<?php
/**
 * CoreFlux Login Handler
 */

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/modules.php';

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

if (defined('USE_DATABASE') && USE_DATABASE) {
    require_once __DIR__ . '/core/db.php';
    $pdo = getDB();
    
    if (!$pdo) {
        header("Location: login.html?error=db");
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $dbUser = $stmt->fetch();
    
    if (!$dbUser) {
        header("Location: login.html?error=invalid");
        exit;
    }
    
    // Check password - try password column first, then password_hash
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
    
    // Check active
    if (isset($dbUser['is_active']) && (int)$dbUser['is_active'] !== 1) {
        header("Location: login.html?error=inactive");
        exit;
    }
    
    // Parse name
    $nameParts = explode(' ', $dbUser['name'] ?? 'User', 2);
    
    // Build user
    $user = [
        'id' => $dbUser['id'],
        'first_name' => $nameParts[0],
        'last_name' => $nameParts[1] ?? '',
        'email' => $dbUser['email'],
        'role' => $dbUser['role'] ?? 'employee',
        'avatar' => $dbUser['avatar'] ?? null,
        'tenants' => [
            ['id' => $dbUser['tenant_id'] ?? 1, 'name' => 'Default Tenant']
        ],
    ];
    
} else {
    // Demo mode
    $testUsers = [
        'admin@coreflux.demo' => ['password' => 'admin123', 'role' => 'admin', 'name' => 'Demo Admin'],
    ];
    
    if (!isset($testUsers[$email]) || $testUsers[$email]['password'] !== $password) {
        header("Location: login.html?error=invalid");
        exit;
    }
    
    $testUser = $testUsers[$email];
    $user = [
        'id' => 1,
        'first_name' => explode(' ', $testUser['name'])[0],
        'last_name' => '',
        'email' => $email,
        'role' => $testUser['role'],
        'avatar' => null,
        'tenants' => [['id' => 1, 'name' => 'Demo Tenant']],
    ];
}

// Set session
$modules = getUserModules($user['role']);
$_SESSION['user'] = $user;
$_SESSION['modules'] = $modules;
$_SESSION['tenant'] = $user['tenants'][0]['name'];
$_SESSION['tenant_id'] = $user['tenants'][0]['id'];
$_SESSION['active_module'] = $modules[0] ?? null;

header("Location: dashboard.php");
exit;
