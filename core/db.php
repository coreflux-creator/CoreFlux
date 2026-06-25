<?php
/**
 * CoreFlux Database Connection
 * PDO-based database connection with tenant scoping
 */

require_once __DIR__ . '/config.php';

$pdo = null;

if (defined('USE_DATABASE') && USE_DATABASE && getenv('COREFLUX_DISABLE_DATABASE') !== '1') {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        // Don't expose error details in production
        if (defined('APP_DEBUG') && APP_DEBUG) {
            throw $e;
        }
    }
}

/**
 * Get database connection
 */
function getDB(): ?PDO {
    global $pdo;
    return $pdo;
}

/**
 * Execute a tenant-scoped query
 */
function tenantQuery(string $sql, array $params = [], ?int $tenantId = null): array {
    global $pdo;
    if (!$pdo) return [];
    
    $tenantId = $tenantId ?? ($_SESSION['tenant_id'] ?? null);
    if ($tenantId) {
        $params['tenant_id'] = $tenantId;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
