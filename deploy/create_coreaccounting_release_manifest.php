<?php
/** Record the exact contents of a prepared standalone runtime package. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'coreaccounting'
    || !in_array('--confirm-package-build', $argv, true)) {
    fwrite(STDERR, "Standalone CoreAccounting package CLI only.\n");
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
$output = $option('output');
$parent = $output !== '' ? realpath(dirname($output)) : false;
$commit = $option('commit');
if ($root === false || $privateRoot === false || $parent === false
    || is_link($root) || is_link($privateRootPath) || is_link($output)
    || file_exists($output) || !preg_match('/^[a-f0-9]{40}$/', $commit)
    || !preg_match('~^(?:/|[A-Za-z]:[/\\\\])~', $output)) {
    throw new RuntimeException('Exact public and private roots, new absolute manifest path and commit are required.');
}
$resolvedOutput = $parent . DIRECTORY_SEPARATOR . basename($output);
if (basename($root) !== 'public_html' || basename($privateRoot) !== 'private_runtime'
    || dirname($root) !== dirname($privateRoot) || $root === $privateRoot) {
    throw new RuntimeException('Release requires exact public_html and private_runtime siblings.');
}
if ($parent === $root || str_starts_with($parent, $root . DIRECTORY_SEPARATOR)
    || $parent === $privateRoot || str_starts_with($parent, $privateRoot . DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Release manifest must be outside both runtime roots.');
}
foreach (['.htaccess', 'dashboard/dist/index.html', 'vendor/autoload.php'] as $required) {
    if (!is_file($root . '/' . $required)) {
        throw new RuntimeException("Prepared runtime is missing: $required");
    }
}
foreach (['composer.json', 'composer.lock', 'core/db.local.php', 'core/config.local.php'] as $private) {
    if (file_exists($root . '/' . $private) || is_link($root . '/' . $private)) {
        throw new RuntimeException("Build-only or private file remains in webroot: $private");
    }
}
require_once __DIR__ . '/coreaccounting_vendor_layout.php';
$publicVendor = $root . '/vendor';
$vendorEntries = scandir($publicVendor);
if ($vendorEntries === false || array_values(array_diff($vendorEntries, ['.', '..'])) !== ['autoload.php']
    || file_get_contents($publicVendor . '/autoload.php') !== coreAccountingPortableVendorShim()
    || !is_file($privateRoot . '/vendor/autoload.php')
    || !is_file($privateRoot . '/vendor/dompdf/dompdf/src/Dompdf.php')) {
    throw new RuntimeException('Portable Composer runtime is incomplete or exposed in webroot.');
}

require_once __DIR__ . '/coreaccounting_apache_boundary.php';
$apache = file_get_contents($root . '/.htaccess');
if ($apache === false || coreAccountingStandaloneApacheConfig($apache) !== $apache) {
    throw new RuntimeException('Standalone Apache boundary is not installed.');
}
require_once __DIR__ . '/coreaccounting_public_assets.php';
$public = coreAccountingExpectedPublicFiles($root);

require_once __DIR__ . '/coreaccounting_release_files.php';
$files = coreAccountingReleaseFileHashes($root);
$privateFiles = coreAccountingReleaseFileHashes($privateRoot);
foreach ($public as $relative) {
    if (!isset($files[$relative])) throw new RuntimeException("Public runtime file is missing: $relative");
}
if (!$files || !$privateFiles) throw new RuntimeException('Prepared runtime is empty.');

$manifest = [
    'product' => 'CoreAccounting',
    'source_commit' => $commit,
    'file_count' => count($files),
    'private_file_count' => count($privateFiles),
    'expected_public_files' => $public,
    'files' => $files,
    'private_files' => $privateFiles,
];
$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if (file_put_contents($resolvedOutput, $json, LOCK_EX) !== strlen($json)) {
    throw new RuntimeException('Could not write release manifest.');
}
echo json_encode([
    'source_commit' => $commit,
    'file_count' => count($files),
    'private_file_count' => count($privateFiles),
    'expected_public_files' => count($public),
    'manifest_sha256' => hash('sha256', $json),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
