<?php
/**
 * CoreFlux Database Connection
 * PDO-based database connection with tenant scoping
 */

require_once __DIR__ . '/config.php';

$pdo = null;
$coreflux_db_last_error = null;

if (defined('USE_DATABASE') && USE_DATABASE && getenv('COREFLUX_DISABLE_DATABASE') !== '1') {
    if (!class_exists('PDO')) {
        $coreflux_db_last_error = 'PDO extension is not loaded';
        error_log('Database connection failed: ' . $coreflux_db_last_error);
    } else {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $hosts = [DB_HOST];
        if (DB_HOST === 'localhost') {
            $hosts[] = '127.0.0.1';
        } elseif (DB_HOST === '127.0.0.1') {
            $hosts[] = 'localhost';
        }
        $errors = [];
        foreach (array_values(array_unique($hosts)) as $host) {
            try {
                $dsn = "mysql:host=" . $host . ";dbname=" . DB_NAME . ";charset=utf8mb4";
                $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
                $coreflux_db_last_error = null;
                break;
            } catch (\Throwable $e) {
                $errors[] = $host . ': ' . $e->getMessage();
            }
        }
        if (!$pdo) {
            $coreflux_db_last_error = implode(' | ', $errors);
            error_log("Database connection failed: " . $coreflux_db_last_error);
            // Don't expose error details in production
            if (defined('APP_DEBUG') && APP_DEBUG && !empty($errors)) {
                throw new \RuntimeException($coreflux_db_last_error);
            }
        }
    }
} elseif (getenv('COREFLUX_DISABLE_DATABASE') === '1') {
    $coreflux_db_last_error = 'COREFLUX_DISABLE_DATABASE=1';
} elseif (!defined('USE_DATABASE') || !USE_DATABASE) {
    $coreflux_db_last_error = 'USE_DATABASE is disabled';
}

/**
 * Last database bootstrap error, for admin diagnostics/update pages.
 */
function getDBLastError(): ?string {
    global $coreflux_db_last_error;
    return $coreflux_db_last_error;
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
