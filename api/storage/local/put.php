<?php
/** Receive one signed, tenant-scoped upload into private local storage. */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/StorageService.php';

$ctx = api_require_auth();
if (api_method() !== 'POST') api_error('Method not allowed', 405);
if (getenv('COREFLUX_ENV') !== 'coreaccounting') api_error('Local storage route is unavailable', 404);

$storage = \Core\StorageService::getInstance();
$driver = $storage->local_driver();
if ($driver === null) api_error('Local storage is unavailable', 404);

$key = (string) ($_POST['key'] ?? '');
$expires = (int) ($_POST['expires'] ?? 0);
$signature = (string) ($_POST['sig'] ?? '');
if (!\Core\Storage\LocalDriver::key_belongs_to_tenant($key, (int) $ctx['tenant_id'])
    || !$driver->verify_signed_post_token($key, $expires, $signature)) {
    api_error('Upload authorization expired or is invalid', 403);
}

$file = $_FILES['file'] ?? null;
if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
    || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
    api_error('Uploaded file is missing or incomplete', 422);
}
$size = (int) ($file['size'] ?? 0);
if ($size < 1 || $size > 25 * 1024 * 1024) api_error('File must be between 1 byte and 25 MB', 413);

$mime = (string) ($file['type'] ?? 'application/octet-stream');
if (strlen($mime) > 100 || !preg_match('~^[a-z0-9.+-]+/[a-z0-9.+-]+$~i', $mime)) {
    $mime = 'application/octet-stream';
}
try {
    $result = $driver->put($key, (string) $file['tmp_name'], [
        'mime' => $mime,
        'if_not_exists' => true,
    ]);
} catch (Throwable $error) {
    error_log('Local storage upload failed: ' . $error->getMessage());
    if ($driver->head($key) !== null) api_error('File was already uploaded', 409);
    api_error('File could not be stored. Retry with a new upload authorization.', 503);
}

api_ok([
    'stored' => true,
    'storage_key' => $key,
    'size_bytes' => $result['size_bytes'],
], 201);
