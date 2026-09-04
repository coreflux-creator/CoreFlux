<?php
/**
 * PHP built-in server router for local API contract testing.
 *
 *   php -S 127.0.0.1:8080 -t . scripts/api_dev_router.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$path = rawurldecode((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));
$candidate = realpath($root . $path);

if ($candidate !== false && is_file($candidate) && str_starts_with($candidate, $root)) {
    return false;
}

if (str_starts_with($path, '/api/')) {
    $_SERVER['PATH_INFO'] = '/' . ltrim(substr($path, strlen('/api/')), '/');
    require $root . '/api/index.php';
    return true;
}

http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['error' => 'Not found', 'status' => 404], JSON_UNESCAPED_SLASHES);
return true;

