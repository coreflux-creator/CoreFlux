<?php
/**
 * Login Debug Script
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/db.php';

echo "<h2>Login Debug</h2><pre>";

// Test DB connection
echo "1. Database connection: ";
$pdo = getDB();
if ($pdo) {
    echo "✓ Connected\n";
} else {
    echo "✗ FAILED\n";
    exit;
}

// Query users
echo "\n2. Users in database:\n";
$stmt = $pdo->query("SELECT id, email, role, is_active, email_verified, 
                     LENGTH(password) as pwd_len, 
                     LENGTH(password_hash) as hash_len 
                     FROM users LIMIT 10");
$users = $stmt->fetchAll();

foreach ($users as $u) {
    echo "   ID: {$u['id']}, Email: {$u['email']}, Role: {$u['role']}, ";
    echo "Active: {$u['is_active']}, Verified: {$u['email_verified']}, ";
    echo "pwd_len: {$u['pwd_len']}, hash_len: {$u['hash_len']}\n";
}

// Test specific user
echo "\n3. Test password verification:\n";
$testEmail = $_GET['email'] ?? $users[0]['email'] ?? '';
echo "   Testing email: {$testEmail}\n";

$stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
$stmt->execute([$testEmail]);
$user = $stmt->fetch();

if ($user) {
    echo "   Found user: {$user['name']}\n";
    echo "   password column: " . substr($user['password'] ?? 'NULL', 0, 20) . "...\n";
    echo "   password_hash column: " . substr($user['password_hash'] ?? 'NULL', 0, 20) . "...\n";
    
    // Test with a known password (enter yours in URL: ?email=x&pwd=y)
    $testPwd = $_GET['pwd'] ?? '';
    if ($testPwd) {
        echo "\n   Testing password...\n";
        
        $hash1 = $user['password'] ?? '';
        $hash2 = $user['password_hash'] ?? '';
        
        echo "   password_verify against 'password': " . (password_verify($testPwd, $hash1) ? '✓ MATCH' : '✗ no match') . "\n";
        echo "   password_verify against 'password_hash': " . (password_verify($testPwd, $hash2) ? '✓ MATCH' : '✗ no match') . "\n";
    } else {
        echo "\n   Add ?email=YOUR_EMAIL&pwd=YOUR_PASSWORD to test verification\n";
    }
} else {
    echo "   ✗ User not found\n";
}

echo "</pre>";
