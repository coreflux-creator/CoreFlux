<?php
/**
 * Dev router for PHP's built-in server (`php -S`). Emulates the CoreFlux
 * Apache/.htaccess behaviour just enough to run the API locally inside the
 * Emergent sandbox:
 *
 *   • Real, existing non-PHP files are served as static assets.
 *   • /api/<module>/<endpoint>  → /api/index.php with PATH_INFO set.
 *   • A direct /api/<file>.php   → that file (legacy direct-file callers).
 *
 * This is ONLY used by the local sandbox harness; production uses Apache.
 */
declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Let php -S serve real static files (assets/images/css) directly.
$full = __DIR__ . $uri;
if ($uri !== '/' && is_file($full) && substr($uri, -4) !== '.php') {
    return false;
}

if (preg_match('#^/api/(.+)$#', $uri, $m)) {
    $rest = $m[1];

    // Direct PHP file under /api (e.g. /api/audit_log.php).
    $direct = __DIR__ . '/api/' . $rest;
    if (is_file($direct) && substr($direct, -4) === '.php') {
        require $direct;
        return true;
    }

    // Central router: /api/<module>/<endpoint>[/...]
    $_SERVER['PATH_INFO'] = '/' . $rest;
    require __DIR__ . '/api/index.php';
    return true;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'Not found', 'path' => $uri]);
return true;
