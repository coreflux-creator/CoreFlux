<?php
/** Resolve the standalone app's writable storage outside its document root. */
declare(strict_types=1);

function coreAccountingPrivateStorageRoot(string $webroot): string {
    $public = realpath($webroot);
    if ($public === false || !is_dir($public)) {
        throw new RuntimeException('CoreAccounting document root is unavailable.');
    }
    $configured = trim((string) (getenv('COREFLUX_ACCOUNTING_PRIVATE_STORAGE_ROOT') ?: ''));
    $candidate = $configured !== '' ? $configured : dirname($public) . '/private_html';
    $normalizedCandidate = str_replace('\\', '/', $candidate);
    if (preg_match('~^(?:/|[A-Za-z]:/)~', $normalizedCandidate) !== 1 || is_link($candidate)) {
        throw new RuntimeException('CoreAccounting private storage must be an absolute, non-linked directory.');
    }
    $private = realpath($candidate);
    $normalizedPublic = str_replace('\\', '/', $public);
    $normalizedPrivate = str_replace('\\', '/', (string) $private);
    if ($private === false || !is_dir($private) || !is_writable($private)
        || $normalizedPrivate === $normalizedPublic
        || str_starts_with($normalizedPrivate . '/', $normalizedPublic . '/')) {
        throw new RuntimeException('CoreAccounting private storage must be writable and outside the document root.');
    }
    return $private;
}
