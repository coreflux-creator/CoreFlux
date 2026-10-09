<?php
/** Preserve an invoice's pre-standalone PDF caches outside the webroot. */
declare(strict_types=1);

function invoiceArchiveLegacyPdfCache(int $invoiceId, int $tenantId,
    string $legacyRoot, string $privateStorageRoot): int {
    if ($invoiceId <= 0 || $tenantId <= 0) {
        throw new InvalidArgumentException('Invoice and tenant IDs are required.');
    }
    $legacyTenant = $legacyRoot . '/' . $tenantId;
    if (!is_dir($legacyTenant)) return 0;
    $legacy = realpath($legacyRoot);
    $private = realpath($privateStorageRoot);
    if ($legacy === false || $private === false || is_link($legacyRoot)
        || is_link($legacyTenant) || !is_dir($private) || !is_writable($private)) {
        throw new RuntimeException('Legacy invoice cache or private storage is unsafe.');
    }
    $normalizedLegacy = str_replace('\\', '/', $legacy);
    $normalizedPrivate = str_replace('\\', '/', $private);
    if ($normalizedPrivate === $normalizedLegacy
        || str_starts_with($normalizedPrivate . '/', $normalizedLegacy . '/')) {
        throw new RuntimeException('Invoice cache archive must be outside the old cache.');
    }

    $lockPath = $private . '/.invoice-pdf-cache-migration.lock';
    if (is_link($lockPath) || ($lock = fopen($lockPath, 'c')) === false) {
        throw new RuntimeException('Could not open private invoice cache migration lock.');
    }
    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('Could not lock private invoice cache migration.');
        }
        $files = glob($legacyTenant . '/' . $invoiceId . '-*.pdf') ?: [];
        if (!$files) return 0;
        $archive = $private . '/billing/legacy-invoice-cache/' . $tenantId;
        if (is_link($private . '/billing')
            || is_link($private . '/billing/legacy-invoice-cache') || is_link($archive)) {
            throw new RuntimeException('Private invoice cache archive cannot use a linked directory.');
        }
        foreach ($files as $source) {
            $name = basename($source);
            if (!is_file($source) || is_link($source)
                || preg_match('/^' . $invoiceId . '-[a-f0-9]{40}\.pdf$/', $name) !== 1
                || file_exists($archive . '/' . $name)) {
                throw new RuntimeException('Legacy invoice PDF cache contains an unsafe or conflicting file.');
            }
        }
        if (!is_dir($archive) && !mkdir($archive, 0700, true) && !is_dir($archive)) {
            throw new RuntimeException('Could not create private invoice cache archive.');
        }
        $resolvedArchive = realpath($archive);
        if ($resolvedArchive === false || !str_starts_with(
            str_replace('\\', '/', $resolvedArchive) . '/', $normalizedPrivate . '/')) {
            throw new RuntimeException('Private invoice cache archive escaped its storage root.');
        }
        foreach ($files as $source) {
            if (!rename($source, $archive . '/' . basename($source))) {
                throw new RuntimeException('Could not move legacy invoice PDF cache into private storage.');
            }
        }
        return count($files);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
