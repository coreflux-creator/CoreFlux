<?php
/**
 * Login Debug Script
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Login Debug</h2><pre>";

// Show config values (masked)
echo "1. Configuration:\n";
require_once __DIR__ . '/core/config.php';

echo "   DB_HOST: " . DB_HOST . "\n";
echo "   DB_NAME: " . DB_NAME . "\n";
echo "   DB_USER: " . DB_USER . "\n";
echo "   DB_PASS: " . str_repeat('*', strlen(DB_PASS)) . "\n";
echo "   USE_DATABASE: " . (USE_DATABASE ? 'true' : 'false') . "\n";

// Try to connect with error details
echo "\n2. Database connection test:\n";

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    echo "   ✓ Connected successfully!\n";
    
    // Query users
    echo "\n3. Users in database:\n";
    $stmt = $pdo->query("SELECT id, email, role, is_active FROM users LIMIT 5");
    $users = $stmt->fetchAll();
    foreach ($users as $u) {
        echo "   - {$u['email']} (role: {$u['role']})\n";
    }
    
} catch (PDOException $e) {
    echo "   ✗ Connection FAILED\n";
    echo "   Error: " . $e->getMessage() . "\n";
    echo "   Code: " . $e->getCode() . "\n";
}

// Also try the old config
echo "\n4. Trying old config (includes/config.php):\n";
if (file_exists(__DIR__ . '/includes/config.php')) {
    include __DIR__ . '/includes/config.php';
    echo "   host: " . ($host ?? 'not set') . "\n";
    echo "   admin_db: " . ($admin_db ?? 'not set') . "\n";
    echo "   db_user: " . ($db_user ?? 'not set') . "\n";
    
    try {
        $dsn2 = "mysql:host={$host};dbname={$admin_db};charset=utf8mb4";
        $pdo2 = new PDO($dsn2, $db_user, $db_pass);
        echo "   ✓ Old config works!\n";
    } catch (PDOException $e) {
        echo "   ✗ Old config also fails: " . $e->getMessage() . "\n";
    }
} else {
    echo "   File not found\n";
}

echo "</pre>";
