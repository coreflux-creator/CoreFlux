<?php
/**
 * Database Schema Explorer
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/core/config.php';

echo "<h2>CoreFlux Database Schema</h2><pre>";

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    // Get all tables
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    
    echo "TABLES IN DATABASE:\n";
    echo "==================\n\n";
    
    foreach ($tables as $table) {
        echo "📋 {$table}\n";
        
        // Get columns
        $cols = $pdo->query("DESCRIBE `{$table}`")->fetchAll();
        foreach ($cols as $col) {
            $key = $col['Key'] === 'PRI' ? ' 🔑' : ($col['Key'] === 'MUL' ? ' 🔗' : '');
            echo "   - {$col['Field']} ({$col['Type']}){$key}\n";
        }
        
        // Get row count
        $count = $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        echo "   [{$count} rows]\n\n";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}

echo "</pre>";
