<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/accounting/private_storage.php';

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'coreaccounting-private-storage-' . bin2hex(random_bytes(6));
$public = $base . DIRECTORY_SEPARATOR . 'public_html';
$private = $base . DIRECTORY_SEPARATOR . 'private_html';
mkdir($public, 0700, true);
mkdir($private, 0700);

try {
    putenv('COREFLUX_ACCOUNTING_PRIVATE_STORAGE_ROOT');
    if (coreAccountingPrivateStorageRoot($public) !== realpath($private)) {
        throw new RuntimeException('Sibling private storage was not selected.');
    }
    putenv('COREFLUX_ACCOUNTING_PRIVATE_STORAGE_ROOT=' . $public);
    try {
        coreAccountingPrivateStorageRoot($public);
        throw new RuntimeException('Public storage root was accepted.');
    } catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'outside the document root')) throw $error;
    }
    mkdir($public . '/storage', 0700);
    putenv('COREFLUX_ACCOUNTING_PRIVATE_STORAGE_ROOT=' . $public . '/storage');
    try {
        coreAccountingPrivateStorageRoot($public);
        throw new RuntimeException('Nested public storage was accepted.');
    } catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'outside the document root')) throw $error;
    }
    putenv('COREFLUX_ACCOUNTING_PRIVATE_STORAGE_ROOT=relative/path');
    try {
        coreAccountingPrivateStorageRoot($public);
        throw new RuntimeException('Relative private storage was accepted.');
    } catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'absolute')) throw $error;
    }
    echo "Passed: standalone private storage selection and unsafe-path refusals\n";
} finally {
    putenv('COREFLUX_ACCOUNTING_PRIVATE_STORAGE_ROOT');
    $resolved = realpath($base);
    $temp = realpath(sys_get_temp_dir());
    if ($resolved === false || $temp === false
        || !str_starts_with($resolved, $temp . DIRECTORY_SEPARATOR . 'coreaccounting-private-storage-')) {
        throw new RuntimeException('Refusing cleanup outside the private storage fixture.');
    }
    rmdir($public . '/storage');
    rmdir($public);
    rmdir($private);
    rmdir($resolved);
}
