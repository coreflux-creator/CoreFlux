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
foreach (['spa.php', '.htaccess', 'dashboard/dist/index.html', 'vendor/autoload.php'] as $required) {
    if (!is_file($webroot . '/' . $required)) {
        throw new RuntimeException("Runtime release is incomplete: $required");
    }
}
$distHtml = file_get_contents($webroot . '/dashboard/dist/index.html');
if ($distHtml === false) throw new RuntimeException('Could not read the installed app entry.');
$runtimeSpaAssets = [];
$queue = [];
foreach (['js', 'css'] as $extension) {
    preg_match_all('~/(?:spa-assets|assets)/(index-[A-Za-z0-9_-]+\.' . $extension . ')(?=["\'])~',
        $distHtml, $matches);
    $entryAssets = array_values(array_unique($matches[1] ?? []));
    if (count($entryAssets) !== 1) {
        throw new RuntimeException("Installed app must identify one $extension entry asset.");
    }
    $queue[] = $entryAssets[0];
}
while ($queue) {
    $name = array_shift($queue);
    if (isset($runtimeSpaAssets[$name])) continue;
    $path = $webroot . '/spa-assets/' . $name;
    if (!is_file($path)) throw new RuntimeException("Installed app asset is missing: $name");
    $runtimeSpaAssets[$name] = true;
    if (str_ends_with($name, '.js')) {
        preg_match_all('/index-[A-Za-z0-9_-]+\.(?:js|css)/', (string) file_get_contents($path), $references);
        foreach (array_unique($references[0] ?? []) as $reference) {
            if (!isset($runtimeSpaAssets[$reference])) $queue[] = $reference;
        }
    }
}
$publicAssetReferences = $distHtml;
foreach (array_keys($runtimeSpaAssets) as $name) {
    $publicAssetReferences .= (string) file_get_contents($webroot . '/spa-assets/' . $name);
}
foreach (['spa.php', 'login.html', '404.html', 'privacy.html', 'terms.html',
    'quickbooks-connect.html', 'quickbooks-disconnect.html',
    'assets/css/legal.css', 'assets/css/styles.css'] as $relative) {
    $path = $webroot . '/' . $relative;
    if (is_file($path)) $publicAssetReferences .= (string) file_get_contents($path);
}
$legacyStatic = ['assets/img', 'assets/styles.css', 'css',
    'dashboard/static', 'modules/accounting/assets'];
foreach (glob($webroot . '/assets/css/*') ?: [] as $path) {
    $name = basename($path);
    if (!in_array($name, ['legal.css', 'styles.css'], true)) {
        $legacyStatic[] = 'assets/css/' . $name;
    }
}
foreach ($legacyStatic as $relative) {
    $source = $webroot . '/' . $relative;
    if (!file_exists($source)) continue;
    $reference = '~(?<![A-Za-z0-9_./-])/' . preg_quote($relative, '~')
        . (is_dir($source) ? '/' : '') . '~';
    if (is_link($source) || preg_match($reference, $publicAssetReferences)) {
        throw new RuntimeException("Active app references or links legacy static path: $relative");
    }
}
require_once __DIR__ . '/coreaccounting_apache_boundary.php';
$apacheConfig = file_get_contents($webroot . '/.htaccess');
if ($apacheConfig === false) throw new RuntimeException('Could not read Apache config.');
$standaloneApacheConfig = coreAccountingStandaloneApacheConfig($apacheConfig);
if (!mkdir($private, 0700)) throw new RuntimeException('Could not create private source directory.');

if ($standaloneApacheConfig !== $apacheConfig) {
    $backup = $private . '/htaccess-before-standalone-module-boundary';
    if (file_put_contents($backup, $apacheConfig, LOCK_EX) !== strlen($apacheConfig)) {
        throw new RuntimeException('Could not back up Apache config.');
    }
    $temporary = tempnam($webroot, '.coreaccounting-htaccess-');
    if ($temporary === false) throw new RuntimeException('Could not stage Apache config.');
    try {
        if (file_put_contents($temporary, $standaloneApacheConfig, LOCK_EX) !== strlen($standaloneApacheConfig)
            || !chmod($temporary, fileperms($webroot . '/.htaccess') & 0777)
            || !rename($temporary, $webroot . '/.htaccess')) {
            throw new RuntimeException('Could not install standalone Apache rules.');
        }
    } finally {
        if (is_file($temporary)) unlink($temporary);
    }
}

require_once __DIR__ . '/../core/accounting/standalone_api_boundary.php';

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
foreach (glob($webroot . '/*.php') ?: [] as $source) {
    if (!coreAccountingAllowsPublicApiScript($source, $webroot, 'coreaccounting')) {
        $move(basename($source));
    }
}
$publicRootFiles = array_fill_keys([
    '.htaccess', '.deploy-version', '404.html', 'login.html',
    'privacy.html', 'terms.html', 'quickbooks-connect.html', 'quickbooks-disconnect.html',
    '_deploy_ok.txt', 'robots.txt',
], true);
$rootFiles = [];
foreach (new DirectoryIterator($webroot) as $entry) {
    if (!$entry->isFile() && !$entry->isLink()) continue;
    $rootFiles[] = $entry->getFilename();
}
foreach ($rootFiles as $name) {
    if (isset($publicRootFiles[$name])
        || (str_ends_with($name, '.php')
            && coreAccountingAllowsPublicApiScript($webroot . '/' . $name, $webroot, 'coreaccounting'))) {
        continue;
    }
    $move($name);
}
foreach ([
    'composer.json', 'composer.lock', 'dashboard (1).css', 'eslint.config.js', 'mock-server.js',
    'signup.html',
    'admin', 'app', 'approvers', 'auth', 'master_admin_panel', 'mobile',
    'people', 'time_sheet_review', 'timesheets', 'views',
    'dashboard/package.json', 'dashboard/package-lock.json', 'dashboard/vite.config.js',
    'dashboard/main.js', 'dashboard/style.css', 'dashboard/index.html', 'dashboard/vite.svg',
    'dashboard/.env.local', 'dashboard/src', 'dashboard/node_modules', 'dashboard/tests',
    'src', 'graphql', '_debug', '.github', 'data', 'docs', 'legacy',
    'memory', 'spec', 'tests',
    'modules/private_equity', 'modules/private_equity 2', 'modules/finance',
] as $relative) $move($relative);
$move('assets/css/signup.html');
$legacyStatic = array_values(array_unique($legacyStatic));
foreach ($legacyStatic as $relative) $move($relative);
$move('dashboard/dist/spa-assets');
foreach (glob($webroot . '/spa-assets/*') ?: [] as $source) {
    if (!is_file($source)) continue;
    $name = basename($source);
    if (($name === 'index.html' || preg_match('/^index-.*\.(?:js|css)(?:\.map)?$/', $name))
        && !isset($runtimeSpaAssets[$name])) {
        $move('spa-assets/' . $name);
    }
}
foreach (glob($webroot . '/modules/*/ui', GLOB_ONLYDIR) ?: [] as $source) {
    $move('modules/' . basename(dirname($source)) . '/ui');
}

echo json_encode([
    'private_source_dir' => $private,
    'moved_entries' => $moved,
    'standalone_apache_rules_installed' => $standaloneApacheConfig !== $apacheConfig,
], JSON_UNESCAPED_SLASHES) . "\n";
