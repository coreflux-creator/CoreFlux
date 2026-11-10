<?php
/** Inert, expiring template. Render once for one reviewed maintenance operation. */
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
header('Cache-Control: no-store, private, max-age=0');
header('Content-Type: application/json');
header('X-Robots-Tag: noindex, nofollow');
$spec = json_decode((string) base64_decode('__COREFLUX_MAINTENANCE_SPEC__', true), true);
if (!is_array($spec) || PHP_SAPI !== 'fpm-fcgi' || !function_exists('posix_geteuid')
    || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
    || ($_SERVER['HTTP_HOST'] ?? '') !== ($spec['host'] ?? null)
    || ($_SERVER['REQUEST_URI'] ?? '') !== '/' . ($spec['path'] ?? '')
    || ($_SERVER['QUERY_STRING'] ?? '') !== '' || ($_SERVER['PATH_INFO'] ?? '') !== ''
    || ($_SERVER['SCRIPT_FILENAME'] ?? '') !== ($spec['root'] ?? '') . '/' . ($spec['path'] ?? '')
    || realpath(__DIR__) !== ($spec['root'] ?? null) || time() < ($spec['issued_at'] ?? PHP_INT_MAX)
    || time() >= ($spec['expires'] ?? 0)
    || !is_string($spec['token_sha256'] ?? null)
    || !hash_equals($spec['token_sha256'], hash('sha256', (string) ($_SERVER['HTTP_X_COREFLUX_MAINTENANCE'] ?? '')))) {
    http_response_code(404); exit;
}
try {
    $directory = $spec['private_directory'];
    $library = $directory . '/full_core_maintenance_channel.php';
    clearstatcache(true, $library); $stat = @lstat($library);
    if (realpath($directory) !== $directory || is_link($directory) || fileowner($directory) !== posix_geteuid()
        || (fileperms($directory) & 07777) !== 0700 || !$stat || !is_file($library) || is_link($library)
        || realpath($library) !== $library || $stat['nlink'] !== 1 || $stat['uid'] !== posix_geteuid()
        || ($stat['mode'] & 07777) !== 0600 || !hash_equals($spec['library_sha256'], hash_file('sha256', $library))) {
        throw new RuntimeException('Pinned channel unavailable.');
    }
    $input = file_get_contents('php://input', false, null, 0, 4097);
    if (!is_string($input) || strlen($input) > 4096) throw new RuntimeException('Invalid request size.');
    $request = json_decode($input, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($request)) throw new RuntimeException('Invalid request.');
    require $library;
    echo json_encode(fullCoreMaintenanceChannelRun($spec, $request), JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(503);
    echo '{"error":"Maintenance diagnostic unavailable; keep the hold in place."}';
}
