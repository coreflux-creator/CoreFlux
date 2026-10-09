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
$output = $option('output');
$parent = $output !== '' ? realpath(dirname($output)) : false;
$commit = $option('commit');
if ($root === false || $parent === false || is_link($root) || is_link($output)
    || file_exists($output) || !preg_match('/^[a-f0-9]{40}$/', $commit)
    || !preg_match('~^(?:/|[A-Za-z]:[/\\\\])~', $output)) {
    throw new RuntimeException('Exact runtime root, new absolute manifest path and commit are required.');
}
$resolvedOutput = $parent . DIRECTORY_SEPARATOR . basename($output);
if ($parent === $root || str_starts_with($parent, $root . DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Release manifest must be outside the public webroot.');
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

require_once __DIR__ . '/coreaccounting_apache_boundary.php';
$apache = file_get_contents($root . '/.htaccess');
if ($apache === false || coreAccountingStandaloneApacheConfig($apache) !== $apache) {
    throw new RuntimeException('Standalone Apache boundary is not installed.');
}
require_once __DIR__ . '/coreaccounting_public_assets.php';
$public = coreAccountingExpectedPublicFiles($root);

$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($iterator as $entry) {
    $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
    if ($entry->isLink()) throw new RuntimeException("Linked package entry is not accepted: $relative");
    if (!$entry->isFile()) continue;
    $hash = hash_file('sha256', $entry->getPathname());
    if ($hash === false) throw new RuntimeException("Could not hash package entry: $relative");
    $files[$relative] = $hash;
}
ksort($files, SORT_STRING);
foreach ($public as $relative) {
    if (!isset($files[$relative])) throw new RuntimeException("Public runtime file is missing: $relative");
}
if (!$files) throw new RuntimeException('Prepared runtime is empty.');

$manifest = [
    'product' => 'CoreAccounting',
    'source_commit' => $commit,
    'file_count' => count($files),
    'expected_public_files' => $public,
    'files' => $files,
];
$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if (file_put_contents($resolvedOutput, $json, LOCK_EX) !== strlen($json)) {
    throw new RuntimeException('Could not write release manifest.');
}
echo json_encode([
    'source_commit' => $commit,
    'file_count' => count($files),
    'expected_public_files' => count($public),
    'manifest_sha256' => hash('sha256', $json),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
