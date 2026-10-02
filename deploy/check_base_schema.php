<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/installer_helpers.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $missing = installerCheckBaseSchema($pdo);
    echo json_encode([
        'ready_for_migrations' => $missing === [],
        'missing_tables' => $missing,
    ], JSON_PRETTY_PRINT) . "\n";
    exit($missing ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Base-schema check failed: ' . $e->getMessage() . "\n");
    exit(2);
}
