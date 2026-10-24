<?php
/** Move build-only material out of an isolated CoreAccounting webroot. */
declare(strict_types=1);

$disposableStaging = getenv('COREFLUX_ENV') === 'staging'
    && in_array('--confirm-disposable-staging', $argv, true);
$standalone = getenv('COREFLUX_ENV') === 'coreaccounting'
    && in_array('--confirm-standalone-webroot', $argv, true);
if (PHP_SAPI !== 'cli' || (!$disposableStaging && !$standalone)) {
    fwrite(STDERR, "Isolated CoreAccounting CLI only.\n");
    exit(2);
}
$dryRun = in_array('--dry-run', $argv, true);

$option = static function (string $name) use ($argv): string {
    $prefix = "--$name=";
    $matches = array_values(array_filter($argv, static fn(string $arg): bool => str_starts_with($arg, $prefix)));
    return count($matches) === 1 ? substr($matches[0], strlen($prefix)) : '';
};

$webroot = realpath($option('webroot'));
$expected = realpath($option('expected-webroot'));
$configuredRoot = $standalone ? realpath((string) getenv('COREFLUX_STANDALONE_WEBROOT')) : false;
$origin = $option('origin');
$database = $option('database');
if ($standalone) {
    $parts = parse_url($origin);
    if ($webroot === false || $configuredRoot === false || $webroot !== $configuredRoot
        || $origin === '' || $origin !== getenv('COREFLUX_PUBLIC_ORIGIN')
        || !is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
        || empty($parts['host'])
        || array_intersect(['user', 'pass', 'port', 'path', 'query', 'fragment'], array_keys($parts))
        || $database === '' || $database !== getenv('COREFLUX_STANDALONE_DATABASE')) {
        throw new RuntimeException('Standalone webroot, HTTPS origin and database identity must match host settings.');
    }
}
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
require_once __DIR__ . '/coreaccounting_public_assets.php';
$expectedPublicFiles = coreAccountingExpectedPublicFiles($webroot);
$runtimeSpaAssets = [];
foreach ($expectedPublicFiles as $relative) {
    if (str_starts_with($relative, 'spa-assets/index-')) {
        $runtimeSpaAssets[basename($relative)] = true;
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
    if (!is_file($path)) continue;
    $source = (string) file_get_contents($path);
    if ($standalone && $relative === 'spa.php') {
        $source = preg_replace(
            '~<\?php if \(getenv\([\'\"]COREFLUX_ENV[\'\"]\) !== [\'\"]coreaccounting[\'\"]\): \?>\s*'
            . '<link rel="manifest" href="/spa-assets/manifest\.webmanifest"\s*/>\s*<\?php endif; \?>~',
            '', $source, 1
        );
        if ($source === null) throw new RuntimeException('Could not inspect standalone app shell.');
    }
    $publicAssetReferences .= $source;
}
$legacyStatic = ['assets/img', 'assets/logo.png', 'assets/styles.css', 'css',
    'dashboard/assets', 'dashboard/static', 'modules/accounting/assets',
    'spa-assets/manifest.webmanifest'];
foreach (glob($webroot . '/assets/icons/*') ?: [] as $path) {
    $relative = 'assets/icons/' . basename($path);
    $needed = false;
    foreach ($expectedPublicFiles as $expectedFile) {
        if ($expectedFile === $relative || str_starts_with($expectedFile, $relative . '/')) {
            $needed = true;
            break;
        }
    }
    if (!$needed) $legacyStatic[] = $relative;
}
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
if (!$dryRun && !mkdir($private, 0700)) {
    throw new RuntimeException('Could not create private source directory.');
}

if (!$dryRun && $standaloneApacheConfig !== $apacheConfig) {
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
$planned = [];
$move = static function (string $relative) use ($webroot, $private, $dryRun, &$moved, &$planned): void {
    $source = $webroot . '/' . $relative;
    if (!file_exists($source) && !is_link($source)) return;
    if ($dryRun) {
        foreach ($planned as $parent) {
            if ($relative === $parent || str_starts_with($relative, $parent . '/')) return;
        }
        $planned[] = $relative;
        return;
    }
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
    'dry_run' => $dryRun,
    'planned_entries' => $dryRun ? $planned : [],
    'moved_entries' => $moved,
    'standalone_apache_rules_installed' => !$dryRun && $standaloneApacheConfig !== $apacheConfig,
    'standalone_apache_rules_needed' => $standaloneApacheConfig !== $apacheConfig,
], JSON_UNESCAPED_SLASHES) . "\n";
