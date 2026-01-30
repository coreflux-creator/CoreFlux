<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/core/config.php';

$pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

echo "<h2>Core Data Inspection</h2><pre>";

// Tenants
echo "TENANTS:\n";
$rows = $pdo->query("SELECT * FROM tenants")->fetchAll();
print_r($rows);

// User-Tenant relationships
echo "\nUSER_TENANTS:\n";
$rows = $pdo->query("SELECT ut.*, u.email, t.name as tenant_name FROM user_tenants ut 
    JOIN users u ON ut.user_id = u.id 
    JOIN tenants t ON ut.tenant_id = t.id")->fetchAll();
print_r($rows);

// Modules
echo "\nMODULES:\n";
$rows = $pdo->query("SELECT * FROM modules")->fetchAll();
print_r($rows);

// Admin Modules
echo "\nADMIN_MODULES:\n";
$rows = $pdo->query("SELECT * FROM admin_modules")->fetchAll();
print_r($rows);

// Permissions
echo "\nPERMISSIONS:\n";
$rows = $pdo->query("SELECT * FROM permissions")->fetchAll();
print_r($rows);

// Sidebar items
echo "\nSIDEBAR_ITEMS (first 10):\n";
$rows = $pdo->query("SELECT * FROM sidebar_items ORDER BY sort_order LIMIT 10")->fetchAll();
print_r($rows);

echo "</pre>";
