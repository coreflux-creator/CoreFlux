<?php
/** Read-only verification of an extracted CoreAccounting release package. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'coreaccounting'
    || !in_array('--confirm-read-only-package', $argv, true)) {
    fwrite(STDERR, "Standalone CoreAccounting package verification only.\n");
    exit(2);
}
$option = static function (string $name) use ($argv): string {
    $prefix = "--$name=";
    $matches = array_values(array_filter($argv,
        static fn(string $arg): bool => str_starts_with($arg, $prefix)));
    return count($matches) === 1 ? substr($matches[0], strlen($prefix)) : '';
};
$root = realpath($option('root'));
$privateRootPath = $option('private-root');
$privateRoot = realpath($privateRootPath);
$configuredRoot = realpath((string) getenv('COREFLUX_STANDALONE_WEBROOT'));
$manifestPath = $option('manifest');
$manifestReal = realpath($manifestPath);
$trustedCommit = $option('commit');
$trustedHash = $option('manifest-sha256');
if ($root === false || $privateRoot === false || $configuredRoot === false || $root !== $configuredRoot
    || is_link($option('root')) || $manifestReal === false || !is_file($manifestReal)
    || is_link($privateRootPath) || basename($root) !== 'public_html'
    || basename($privateRoot) !== 'private_runtime' || dirname($root) !== dirname($privateRoot)
    || is_link($manifestPath) || $manifestReal === $root
    || str_starts_with($manifestReal, $root . DIRECTORY_SEPARATOR)
    || $manifestReal === $privateRoot
    || str_starts_with($manifestReal, $privateRoot . DIRECTORY_SEPARATOR)
    || !preg_match('/^[a-f0-9]{40}$/', $trustedCommit)
    || !preg_match('/^[a-f0-9]{64}$/', $trustedHash)) {
    fwrite(STDERR, "Exact public and private roots, external manifest, commit and manifest hash are required.\n");
    exit(2);
}

try {
    $actualManifestHash = hash_file('sha256', $manifestReal);
    if ($actualManifestHash === false || !hash_equals($trustedHash, $actualManifestHash)) {
        throw new RuntimeException('Release manifest hash does not match the trusted build.');
    }
    $manifest = json_decode((string) file_get_contents($manifestReal), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($manifest) || ($manifest['product'] ?? null) !== 'CoreAccounting'
        || ($manifest['source_commit'] ?? null) !== $trustedCommit
        || !is_array($manifest['files'] ?? null)
        || !is_array($manifest['private_files'] ?? null)
        || !is_array($manifest['expected_public_files'] ?? null)) {
        throw new RuntimeException('Release manifest format is invalid.');
    }
    $expected = $manifest['files'];
    $privateExpected = $manifest['private_files'];
    require_once __DIR__ . '/coreaccounting_release_files.php';
    $actual = coreAccountingReleaseFileHashes($root);
    $privateActual = coreAccountingReleaseFileHashes($privateRoot);
    ksort($expected, SORT_STRING);
    ksort($privateExpected, SORT_STRING);
    if (($manifest['file_count'] ?? null) !== count($expected)
        || ($manifest['private_file_count'] ?? null) !== count($privateExpected)
        || !$expected || !$privateExpected) {
        throw new RuntimeException('Release manifest file count is inconsistent.');
    }
    $diff = static function (array $wanted, array $found): array {
        $missing = array_values(array_diff(array_keys($wanted), array_keys($found)));
        $extra = array_values(array_diff(array_keys($found), array_keys($wanted)));
        $changed = [];
        foreach (array_intersect(array_keys($wanted), array_keys($found)) as $path) {
            if (!is_string($wanted[$path]) || !preg_match('/^[a-f0-9]{64}$/', $wanted[$path])
                || !hash_equals($wanted[$path], $found[$path])) {
                $changed[] = $path;
            }
        }
        return [$missing, $extra, $changed];
    };
    [$missing, $extra, $changed] = $diff($expected, $actual);
    [$privateMissing, $privateExtra, $privateChanged] = $diff($privateExpected, $privateActual);
    require_once __DIR__ . '/coreaccounting_vendor_layout.php';
    $vendorEntries = scandir($root . '/vendor');
    if ($vendorEntries === false || array_values(array_diff($vendorEntries, ['.', '..'])) !== ['autoload.php']
        || file_get_contents($root . '/vendor/autoload.php') !== coreAccountingPortableVendorShim()
        || !is_file($privateRoot . '/vendor/autoload.php')
        || !is_file($privateRoot . '/vendor/dompdf/dompdf/src/Dompdf.php')) {
        throw new RuntimeException('Portable Composer runtime is incomplete or exposed in webroot.');
    }
    require_once __DIR__ . '/coreaccounting_public_assets.php';
    $public = coreAccountingExpectedPublicFiles($root);
    if ($public !== $manifest['expected_public_files']) {
        throw new RuntimeException('Active public asset set differs from the release manifest.');
    }
    $result = [
        'source_commit' => $manifest['source_commit'],
        'file_count' => count($actual),
        'private_file_count' => count($privateActual),
        'expected_public_files' => count($public),
        'missing' => array_slice($missing, 0, 20),
        'extra' => array_slice($extra, 0, 20),
        'changed' => array_slice($changed, 0, 20),
        'private_missing' => array_slice($privateMissing, 0, 20),
        'private_extra' => array_slice($privateExtra, 0, 20),
        'private_changed' => array_slice($privateChanged, 0, 20),
    ];
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit($missing || $extra || $changed || $privateMissing || $privateExtra || $privateChanged ? 1 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
