<?php
/** Move build-only material out of a disposable CoreAccounting staging webroot. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging'
    || !in_array('--confirm-disposable-staging', $argv, true)) {
    fwrite(STDERR, "Disposable staging CLI only.\n");
    exit(2);
}

$option = static function (string $name) use ($argv): string {
    $prefix = "--$name=";
    $matches = array_values(array_filter($argv, static fn(string $arg): bool => str_starts_with($arg, $prefix)));
    return count($matches) === 1 ? substr($matches[0], strlen($prefix)) : '';
};

$webroot = realpath($option('webroot'));
$expected = realpath($option('expected-webroot'));
$privatePath = $option('private');
$privateParent = $privatePath !== '' ? realpath(dirname($privatePath)) : false;
$absolutePrivate = preg_match('~^(?:/|[A-Za-z]:[\\\\/])~', $privatePath) === 1;
if ($webroot === false || $expected === false || $webroot !== $expected
    || $privateParent === false || !$absolutePrivate) {
    throw new RuntimeException('Exact existing webroot and absolute private destination are required.');
}
$private = $privateParent . DIRECTORY_SEPARATOR . basename($privatePath);
$normalizedRoot = str_replace('\\', '/', $webroot);
$normalizedParent = str_replace('\\', '/', $privateParent);
if ($normalizedParent === $normalizedRoot || str_starts_with($normalizedParent . '/', $normalizedRoot . '/')
    || $privateParent === '/' || file_exists($private) || is_link($private)) {
    throw new RuntimeException('Private destination must be new and outside the webroot.');
}
foreach (['spa.php', 'index.html', '.htaccess', 'dashboard/dist/index.html', 'vendor/autoload.php'] as $required) {
    if (!is_file($webroot . '/' . $required)) {
        throw new RuntimeException("Runtime release is incomplete: $required");
    }
}
if (!mkdir($private, 0700)) throw new RuntimeException('Could not create private source directory.');

$moved = 0;
$move = static function (string $relative) use ($webroot, $private, &$moved): void {
    $source = $webroot . '/' . $relative;
    if (!file_exists($source)) return;
    $target = $private . '/' . $relative;
    $parent = dirname($target);
    if (!is_dir($parent) && !mkdir($parent, 0700, true)) {
        throw new RuntimeException("Could not create private directory for $relative");
    }
    if (file_exists($target) || !rename($source, $target)) {
        throw new RuntimeException("Could not privatize $relative");
    }
    $moved++;
};

foreach (glob($webroot . '/*.md') ?: [] as $source) $move(basename($source));
foreach (glob($webroot . '/*.txt') ?: [] as $source) {
    if (!in_array(basename($source), ['_deploy_ok.txt', 'robots.txt'], true)) $move(basename($source));
}
foreach ([
    'composer.json', 'composer.lock', 'dashboard (1).css', 'eslint.config.js', 'mock-server.js',
    'dashboard/package.json', 'dashboard/package-lock.json', 'dashboard/vite.config.js',
    'dashboard/main.js', 'dashboard/style.css', 'dashboard/index.html', 'dashboard/vite.svg',
    'dashboard/.env.local', 'dashboard/src', 'dashboard/node_modules', 'dashboard/tests',
    'src', 'graphql', '_debug', 'docs', 'legacy', 'memory', 'spec', 'tests',
] as $relative) $move($relative);
foreach (glob($webroot . '/modules/*/ui', GLOB_ONLYDIR) ?: [] as $source) {
    $move('modules/' . basename(dirname($source)) . '/ui');
}

echo json_encode(['private_source_dir' => $private, 'moved_entries' => $moved], JSON_UNESCAPED_SLASHES) . "\n";
