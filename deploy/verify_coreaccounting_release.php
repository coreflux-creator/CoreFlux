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
$configuredRoot = realpath((string) getenv('COREFLUX_STANDALONE_WEBROOT'));
$manifestPath = $option('manifest');
$manifestReal = realpath($manifestPath);
$trustedCommit = $option('commit');
$trustedHash = $option('manifest-sha256');
if ($root === false || $configuredRoot === false || $root !== $configuredRoot
    || is_link($option('root')) || $manifestReal === false || !is_file($manifestReal)
    || is_link($manifestPath) || $manifestReal === $root
    || str_starts_with($manifestReal, $root . DIRECTORY_SEPARATOR)
    || !preg_match('/^[a-f0-9]{40}$/', $trustedCommit)
    || !preg_match('/^[a-f0-9]{64}$/', $trustedHash)) {
    fwrite(STDERR, "Exact standalone webroot, external manifest, commit and manifest hash are required.\n");
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
        || !is_array($manifest['expected_public_files'] ?? null)) {
        throw new RuntimeException('Release manifest format is invalid.');
    }
    $expected = $manifest['files'];
    $actual = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $entry) {
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
        if ($entry->isLink()) throw new RuntimeException("Linked release entry is not accepted: $relative");
        if (!$entry->isFile()) continue;
        $hash = hash_file('sha256', $entry->getPathname());
        if ($hash === false) throw new RuntimeException("Could not hash release entry: $relative");
        $actual[$relative] = $hash;
    }
    ksort($actual, SORT_STRING);
    ksort($expected, SORT_STRING);
    if (($manifest['file_count'] ?? null) !== count($expected)) {
        throw new RuntimeException('Release manifest file count is inconsistent.');
    }
    $missing = array_values(array_diff(array_keys($expected), array_keys($actual)));
    $extra = array_values(array_diff(array_keys($actual), array_keys($expected)));
    $changed = [];
    foreach (array_intersect(array_keys($expected), array_keys($actual)) as $path) {
        if (!is_string($expected[$path]) || !preg_match('/^[a-f0-9]{64}$/', $expected[$path])
            || !hash_equals($expected[$path], $actual[$path])) {
            $changed[] = $path;
        }
    }
    require_once __DIR__ . '/coreaccounting_public_assets.php';
    $public = coreAccountingExpectedPublicFiles($root);
    if ($public !== $manifest['expected_public_files']) {
        throw new RuntimeException('Active public asset set differs from the release manifest.');
    }
    $result = [
        'source_commit' => $manifest['source_commit'],
        'file_count' => count($actual),
        'expected_public_files' => count($public),
        'missing' => array_slice($missing, 0, 20),
        'extra' => array_slice($extra, 0, 20),
        'changed' => array_slice($changed, 0, 20),
    ];
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit($missing || $extra || $changed ? 1 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
