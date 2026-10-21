<?php
/** Prepare private settings only; never install a bridge, activate code or migrate data. */
declare(strict_types=1);
require_once __DIR__ . '/full_core_settings.php';

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--prepare-private-settings') {
    throw new RuntimeException('Explicit private settings preparation CLI required.');
}
$requestedRoot = rtrim((string) getenv('COREFLUX_PRODUCTION_PREFLIGHT_ROOT'), '/');
$root = realpath($requestedRoot);
$package = realpath(dirname(__DIR__));
if (!$root || $root !== $requestedRoot || $root === $package
    || !preg_match('~^/home/[^/]+\.cloudwaysapps\.com/[a-z0-9]+/public_html$~D', $root)
    || basename(dirname($root)) === 'muzqvdvqbx') {
    throw new RuntimeException('Exact production root required; staging is excluded.');
}
$manifest = json_decode((string) file_get_contents($package . '/integration-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
if (($manifest['product'] ?? '') !== 'full-core-staging'
    || !preg_match('/^[a-f0-9]{40}$/D', $manifest['commit'] ?? '')) throw new RuntimeException('Candidate manifest required.');
foreach (['deploy/full_core_settings.php', 'deploy/prepare_full_core_settings.php', 'core/config.php', 'core/db.php'] as $file) {
    if (!isset($manifest['files'][$file]) || is_link($package . '/' . $file)
        || !hash_equals($manifest['files'][$file], hash_file('sha256', $package . '/' . $file))) {
        throw new RuntimeException('Candidate bootstrap integrity failed.');
    }
}
$legacySource = file_get_contents($root . '/core/config.php');
if ($legacySource === false) throw new RuntimeException('Cannot verify live configuration.');
$normalized = str_replace("\r\n", "\n", $legacySource);
if (!hash_equals('296510537cedf7c66433479e922cd636023be9de', hash('sha1', 'blob ' . strlen($normalized) . "\0" . $normalized))) {
    throw new RuntimeException('Live configuration changed since review; preparation stopped.');
}
require_once $root . '/core/db.php';
$db = getDB();
if (!$db || $db->query('SELECT DATABASE()')->fetchColumn() !== basename(dirname($root))) {
    throw new RuntimeException('Live database identity did not match.');
}
$settings = [];
foreach (FULL_CORE_CONNECTION_SETTINGS as $name) {
    if (!defined($name)) throw new RuntimeException('Existing connection settings are incomplete.');
    $settings[$name] = constant($name);
}
$private = dirname($root) . '/private_html';
if (is_link($private) || realpath($private) !== $private || !is_writable($private)) {
    throw new RuntimeException('Existing private application directory is required.');
}
$group = filegroup($root . '/core/config.php');
if ($group === false) throw new RuntimeException('Cannot verify application group.');
$result = fullCorePrepareSettings($private, $root, $package, $manifest['commit'], $settings, $group);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
