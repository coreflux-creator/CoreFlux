<?php
/** Keep the local storage routes and import evidence linked to real bytes. */
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$checks = [
    'upload authenticates' => str_contains($read('api/storage/local/put.php'), 'api_require_auth()'),
    'upload validates signature' => str_contains($read('api/storage/local/put.php'), 'verify_signed_post_token('),
    'upload checks tenant key' => str_contains($read('api/storage/local/put.php'), 'key_belongs_to_tenant('),
    'upload uses immutable create' => str_contains($read('api/storage/local/put.php'), "'if_not_exists' => true"),
    'download authenticates' => str_contains($read('api/storage/local/get.php'), 'api_require_auth()'),
    'download validates signature' => str_contains($read('api/storage/local/get.php'), 'verify_signed_token('),
    'download checks tenant key' => str_contains($read('api/storage/local/get.php'), 'key_belongs_to_tenant('),
    'standalone uses private storage' => str_contains($read('core/StorageService.php'), 'coreAccountingPrivateStorageRoot('),
    'presign checks import owner' => str_contains($read('api/evidence_upload_url.php'), 'created_by_user_id = :user_id'),
    'metadata checks stored object' => str_contains($read('api/accounting/evidence.php'), '->head($key)'),
    'client checks upload receipt' => str_contains($read('dashboard/src/lib/csvAuditAttach.js'), 'receipt.storage_key !== presign.storage_key'),
    'client checks metadata receipt' => str_contains($read('dashboard/src/lib/csvAuditAttach.js'), 'if (!attached?.id)'),
];
$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
if ($failed) {
    fwrite(STDERR, 'Local storage checks failed: ' . implode(', ', $failed) . "\n");
    exit(1);
}
echo 'Passed: ' . count($checks) . " local storage surface checks\n";
