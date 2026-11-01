<?php
/** Serve a short-lived, tenant-scoped private local-storage download. */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/StorageService.php';

$ctx = api_require_auth();
if (api_method() !== 'GET') api_error('Method not allowed', 405);
if (getenv('COREFLUX_ENV') !== 'coreaccounting') api_error('Local storage route is unavailable', 404);

$storage = \Core\StorageService::getInstance();
$driver = $storage->local_driver();
if ($driver === null) api_error('Local storage is unavailable', 404);

$key = (string) api_query('k', '');
$expires = (int) api_query('e', 0);
$signature = (string) api_query('sig', '');
if (!\Core\Storage\LocalDriver::key_belongs_to_tenant($key, (int) $ctx['tenant_id'])
    || !$driver->verify_signed_token($key, $expires, $signature)) {
    api_error('Download authorization expired or is invalid', 403);
}

try {
    $head = $driver->head($key);
    if ($head === null) api_error('File not found', 404);
    $path = $driver->abs($key);
} catch (Throwable $error) {
    error_log('Local storage download failed: ' . $error->getMessage());
    api_error('File is unavailable', 503);
}

$mime = (string) ($head['mime'] ?? 'application/octet-stream');
if (!preg_match('~^[a-z0-9.+-]+/[a-z0-9.+-]+$~i', $mime)) $mime = 'application/octet-stream';
$requestedName = (string) api_query('d', '');
$name = $requestedName !== '' ? $requestedName : basename($key);
$name = preg_replace('/[^A-Za-z0-9._-]/', '_', basename(str_replace('\\', '/', $name))) ?: 'download';
$inline = api_query('i', '') === '1'
    && in_array($mime, ['application/pdf', 'image/png', 'image/jpeg', 'image/webp'], true);

header('Content-Type: ' . $mime);
header('Content-Length: ' . (int) $head['size_bytes']);
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"');
header('Content-Security-Policy: sandbox');
readfile($path);
