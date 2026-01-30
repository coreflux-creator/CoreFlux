<?php
/**
 * Login Debug Script
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Login Debug</h2><pre>";

require_once __DIR__ . '/core/config.php';

echo "1. Database connection:\n";

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    echo "   ✓ Connected\n";
} catch (PDOException $e) {
    echo "   ✗ FAILED: " . $e->getMessage() . "\n";
    exit;
}

// Get test email from URL or use first user
$testEmail = $_GET['email'] ?? '';
$testPwd = $_GET['pwd'] ?? '';

if ($testEmail && $testPwd) {
    echo "\n2. Testing login for: {$testEmail}\n";
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$testEmail]);
    $user = $stmt->fetch();
    
    if (!$user) {
        echo "   ✗ User NOT FOUND in database\n";
    } else {
        echo "   ✓ User found: {$user['name']}\n";
        echo "   Role: {$user['role']}\n";
        echo "   is_active: {$user['is_active']}\n";
        echo "   email_verified: {$user['email_verified']}\n";
        
        // Check which password column has data
        $pwd = $user['password'] ?? '';
        $pwdHash = $user['password_hash'] ?? '';
        
        echo "\n   Password columns:\n";
        echo "   - password: " . (strlen($pwd) > 0 ? strlen($pwd) . " chars, starts with: " . substr($pwd, 0, 10) . "..." : "EMPTY") . "\n";
        echo "   - password_hash: " . (strlen($pwdHash) > 0 ? strlen($pwdHash) . " chars, starts with: " . substr($pwdHash, 0, 10) . "..." : "EMPTY") . "\n";
        
        echo "\n   Password verification:\n";
        
        if (strlen($pwd) > 0) {
            $match1 = password_verify($testPwd, $pwd);
            echo "   - Against 'password' column: " . ($match1 ? "✓ MATCH!" : "✗ no match") . "\n";
        }
        
        if (strlen($pwdHash) > 0) {
            $match2 = password_verify($testPwd, $pwdHash);
            echo "   - Against 'password_hash' column: " . ($match2 ? "✓ MATCH!" : "✗ no match") . "\n";
        }
        
        if ((isset($match1) && $match1) || (isset($match2) && $match2)) {
            echo "\n   ✓ LOGIN SHOULD WORK - try the login page now\n";
        } else {
            echo "\n   ✗ PASSWORD DOES NOT MATCH\n";
            echo "   Either the password is wrong, or it's not hashed with password_hash()\n";
        }
    }
} else {
    echo "\n2. Users in database:\n";
    $stmt = $pdo->query("SELECT id, email, name, role FROM users LIMIT 5");
    while ($row = $stmt->fetch()) {
        echo "   - {$row['email']} ({$row['role']})\n";
    }
    
    echo "\n\nTo test password, add to URL:\n";
    echo "?email=YOUR_EMAIL&pwd=YOUR_PASSWORD\n";
}

echo "</pre>";
