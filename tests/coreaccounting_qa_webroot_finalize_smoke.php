<?php
declare(strict_types=1);

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'coreaccounting-webroot-' . bin2hex(random_bytes(6));
$webroot = $base . DIRECTORY_SEPARATOR . 'public_html';
$privateParent = $base . DIRECTORY_SEPARATOR . 'private';
mkdir($webroot, 0700, true);
mkdir($privateParent, 0700, true);

$put = static function (string $relative) use ($webroot): void {
    $path = $webroot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    file_put_contents($path, 'fixture');
};
foreach ([
    '.htaccess', '.gitignore', 'spa.php', 'login.php', 'session.php', 'index.html',
    '404.html', 'privacy.html', 'terms.html', 'quickbooks-connect.html', 'quickbooks-disconnect.html',
    'dashboard/dist/index.html', 'dashboard/dist/spa-assets/index-old.js',
    'vendor/autoload.php', 'spa-assets/index-current.js', 'spa-assets/index-current.css',
    'spa-assets/index-old.js', 'spa-assets/index-old.css', 'spa-assets/index.html',
    'modules/accounting/api/reports.php', '_deploy_ok.txt', 'robots.txt',
    'about.html', 'login2.html', 'spa.php.tmp', 'data/branding_settings.json',
    'assets/css/signup.html',
    'modules/private_equity/file tree.txt', 'modules/private_equity 2/Data/legacy.png',
    'modules/finance/scripts.js',
    'README.md', 'ssh note.txt', 'install.php', 'bootstrap_debug.php',
    'signup.php', 'signup.html',
    'composer.lock', 'dashboard/package.json',
    'dashboard/src/lib/api.js', 'graphql/router/index.ts',
    'deploy/example.php', 'scripts/example.php', '.github/workflows/example.yml',
    'modules/accounting/ui/journalDimensions.js',
    'admin/custom_fields.php', 'app/index.html', 'approvers/dashboard.php',
    'auth/login.php', 'master_admin_panel/dashboard.php', 'mobile/src/lib/api.ts',
    'people/index.php', 'time_sheet_review/js/review_timesheets.js',
    'timesheets/approve.php', 'views/dashboard_user.php',
    'billing/invoice.php',
] as $file) $put($file);
file_put_contents($webroot . '/dashboard/dist/index.html',
    '<script src="/spa-assets/index-current.js"></script><link href="/spa-assets/index-current.css" rel="stylesheet">');
$sharedApacheConfig = (string) file_get_contents(__DIR__ . '/../.htaccess');
$phpFallback = strpos($sharedApacheConfig, 'RewriteRule \.php$ - [R=404,L]');
$spaFallback = strpos($sharedApacheConfig, 'RewriteRule ^(admin|');
if ($phpFallback === false || $spaFallback === false || $phpFallback >= $spaFallback) {
    throw new RuntimeException('Missing PHP entrypoints must not fall through to SPA routing');
}
file_put_contents($webroot . '/.htaccess', $sharedApacheConfig);

$originalEnvironment = getenv('COREFLUX_ENV');
try {
    putenv('COREFLUX_ENV=staging');
    file_put_contents($webroot . '/spa-assets/index-current.js', 'import("./index-missing.js")');
    $argv = [
        'finalize_coreaccounting_qa_webroot.php', '--confirm-disposable-staging',
        '--webroot=' . $webroot, '--expected-webroot=' . $webroot,
        '--private=' . $privateParent . '/invalid-entry',
    ];
    try {
        require __DIR__ . '/../deploy/finalize_coreaccounting_qa_webroot.php';
        throw new RuntimeException('Incomplete app bundle was accepted');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'Installed app asset is missing: index-missing.js') throw $error;
    }
    if (file_exists($privateParent . '/invalid-entry')
        || !is_file($webroot . '/index.html')
        || (string) file_get_contents($webroot . '/.htaccess') !== $sharedApacheConfig) {
        throw new RuntimeException('Bundle preflight mutated an incomplete release.');
    }
    file_put_contents($webroot . '/spa-assets/index-current.js', 'fixture');

    $private = $privateParent . DIRECTORY_SEPARATOR . 'release-qa';
    $argv = [
        'finalize_coreaccounting_qa_webroot.php', '--confirm-disposable-staging',
        '--webroot=' . $webroot, '--expected-webroot=' . $webroot,
        '--private=' . $private,
    ];
    ob_start();
    require __DIR__ . '/../deploy/finalize_coreaccounting_qa_webroot.php';
    $result = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);

    require_once __DIR__ . '/../deploy/coreaccounting_apache_boundary.php';
    $installedApacheConfig = (string) file_get_contents($webroot . '/.htaccess');
    if (($result['standalone_apache_rules_installed'] ?? null) !== true
        || coreAccountingStandaloneApacheConfig($sharedApacheConfig) !== $installedApacheConfig
        || coreAccountingStandaloneApacheConfig($installedApacheConfig) !== $installedApacheConfig
        || (string) file_get_contents($private . '/htaccess-before-standalone-module-boundary') !== $sharedApacheConfig
        || (string) file_get_contents(__DIR__ . '/../.htaccess') !== $sharedApacheConfig) {
        throw new RuntimeException('Standalone Apache rules were not installed safely and idempotently.');
    }
    $upgradedConfig = str_replace(
        'RedirectMatch 404 ^/modules/(?!accounting/|billing/|ap/|treasury/|people/api/companies\.php$)[^/]+/api/.*\.php$',
        'RedirectMatch 404 ^/modules/(?!accounting/|billing/|ap/|treasury/)[^/]+/api/.*\.php$',
        $installedApacheConfig
    );
    if ($upgradedConfig === $installedApacheConfig
        || coreAccountingStandaloneApacheConfig($upgradedConfig) !== $installedApacheConfig) {
        throw new RuntimeException('Existing standalone Apache rules did not upgrade cleanly.');
    }
    $denies = static function (string $path) use ($installedApacheConfig): bool {
        foreach (explode("\n", $installedApacheConfig) as $line) {
            if (preg_match('/^RedirectMatch 404 (.+)$/', trim($line), $match)
                && preg_match('~' . $match[1] . '~', $path)) return true;
        }
        return false;
    };
    foreach (['/modules/people/api/companies.php', '/modules/billing/api/items.php',
        '/modules/accounting/reports', '/modules/treasury'] as $path) {
        if ($denies($path)) throw new RuntimeException("Required standalone API was blocked: $path");
    }
    foreach (['/modules/people/api/persons.php', '/modules/people/index.php',
        '/modules/people/overview', '/modules/private_equity/pe_scenarios.txt',
        '/modules/finance/scripts.js', '/data/branding_settings.json'] as $path) {
        if (!$denies($path)) throw new RuntimeException("Unrelated route was exposed: $path");
    }
    $lineEnding = str_contains($installedApacheConfig, "\r\n") ? "\r\n" : "\n";
    $withoutDataRule = str_replace('RedirectMatch 404 ^/data(?:/|$)' . $lineEnding, '', $installedApacheConfig);
    if ($withoutDataRule === $installedApacheConfig
        || coreAccountingStandaloneApacheConfig($withoutDataRule) !== $installedApacheConfig) {
        throw new RuntimeException('Existing standalone Apache rules did not add the data denial.');
    }
    $withoutModuleRule = str_replace(
        'RedirectMatch 404 ^/modules/(?!accounting(?:/|$)|billing(?:/|$)|ap(?:/|$)|treasury(?:/|$)|people/api/companies\.php$)[^/]+(?:/|$)' . $lineEnding,
        '', $installedApacheConfig
    );
    if ($withoutModuleRule === $installedApacheConfig
        || coreAccountingStandaloneApacheConfig($withoutModuleRule) !== $installedApacheConfig) {
        throw new RuntimeException('Existing standalone Apache rules did not add the module denial.');
    }
    $lfConfig = str_replace("\r\n", "\n", $sharedApacheConfig);
    $lfInstalled = coreAccountingStandaloneApacheConfig($lfConfig);
    if (coreAccountingStandaloneApacheConfig($lfInstalled) !== $lfInstalled
        || !str_contains($lfInstalled, "# Sensible defaults\n")
        || str_contains($lfInstalled, "\r\n")) {
        throw new RuntimeException('Standalone Apache rules failed on LF line endings.');
    }
    try {
        coreAccountingStandaloneApacheConfig(str_replace(
            'RedirectMatch 404 ^/modules/[^/]+/(?!api/).*\.php$', '', $installedApacheConfig
        ));
        throw new RuntimeException('Partial standalone Apache rules were accepted.');
    } catch (RuntimeException $error) {
        if ($error->getMessage() === 'Partial standalone Apache rules were accepted.') throw $error;
    }
    try {
        coreAccountingStandaloneApacheConfig($installedApacheConfig
            . 'RedirectMatch 404 ^/modules/[^/]+/(?!api/).*\.php$');
        throw new RuntimeException('Duplicate standalone Apache rule was accepted.');
    } catch (RuntimeException $error) {
        if ($error->getMessage() === 'Duplicate standalone Apache rule was accepted.') throw $error;
    }

    $moved = [
        '.gitignore', 'README.md', 'ssh note.txt', 'install.php', 'bootstrap_debug.php',
        'index.html', 'about.html', 'login2.html', 'spa.php.tmp',
        'signup.php', 'signup.html',
        'composer.lock', 'dashboard/package.json',
        'dashboard/src/lib/api.js', 'graphql/router/index.ts',
        'dashboard/dist/spa-assets/index-old.js',
        'data/branding_settings.json', 'assets/css/signup.html',
        'modules/private_equity/file tree.txt', 'modules/private_equity 2/Data/legacy.png',
        'modules/finance/scripts.js',
        'spa-assets/index-old.js', 'spa-assets/index-old.css', 'spa-assets/index.html',
        '.github/workflows/example.yml',
        'modules/accounting/ui/journalDimensions.js',
        'admin/custom_fields.php', 'app/index.html', 'approvers/dashboard.php',
        'auth/login.php', 'master_admin_panel/dashboard.php', 'mobile/src/lib/api.ts',
        'people/index.php', 'time_sheet_review/js/review_timesheets.js',
        'timesheets/approve.php', 'views/dashboard_user.php',
    ];
    $retained = [
        '.htaccess', 'spa.php', 'login.php', 'session.php', 'dashboard/dist/index.html',
        '404.html', 'privacy.html', 'terms.html', 'quickbooks-connect.html', 'quickbooks-disconnect.html',
        'deploy/example.php', 'scripts/example.php',
        'vendor/autoload.php', 'spa-assets/index-current.js', 'spa-assets/index-current.css',
        'modules/accounting/api/reports.php', '_deploy_ok.txt', 'robots.txt',
        'billing/invoice.php',
    ];
    foreach ($moved as $relative) {
        if (file_exists($webroot . '/' . $relative) || !is_file($private . '/' . $relative)) {
            throw new RuntimeException("Not privatized: $relative");
        }
    }
    foreach ($retained as $relative) {
        if (!is_file($webroot . '/' . $relative)) throw new RuntimeException("Runtime file moved: $relative");
    }
    if (($result['moved_entries'] ?? null) !== 36) throw new RuntimeException('Unexpected move count');
    require_once __DIR__ . '/../core/installer_helpers.php';
    $bundleChecks = spaBundleStatus($webroot);
    if (($bundleChecks[1]['detail'] ?? '') !== 'runtime-only package; compare installed bundle hashes with the release manifest') {
        throw new RuntimeException('Runtime-only bundle check claimed source freshness');
    }

    $secondPrivate = $privateParent . DIRECTORY_SEPARATOR . 'release-qa-rerun';
    $argv = [
        'finalize_coreaccounting_qa_webroot.php', '--confirm-disposable-staging',
        '--webroot=' . $webroot, '--expected-webroot=' . $webroot,
        '--private=' . $secondPrivate,
    ];
    ob_start();
    require __DIR__ . '/../deploy/finalize_coreaccounting_qa_webroot.php';
    $rerun = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
    if (($rerun['moved_entries'] ?? null) !== 0
        || ($rerun['standalone_apache_rules_installed'] ?? null) !== false
        || (string) file_get_contents($webroot . '/.htaccess') !== $installedApacheConfig
        || !is_file($webroot . '/billing/invoice.php')) {
        throw new RuntimeException('Re-running the staging finalizer changed the runtime.');
    }

    foreach ([
        ['--expected-webroot=' . $privateParent, '--private=' . $privateParent . '/wrong-root'],
        ['--expected-webroot=' . $webroot, '--private=' . $webroot . '/inside-public'],
    ] as [$expectedArg, $privateArg]) {
        $argv = [
            'finalize_coreaccounting_qa_webroot.php', '--confirm-disposable-staging',
            '--webroot=' . $webroot, $expectedArg, $privateArg,
        ];
        try {
            require __DIR__ . '/../deploy/finalize_coreaccounting_qa_webroot.php';
            throw new RuntimeException('Unsafe target was accepted');
        } catch (RuntimeException $error) {
            if ($error->getMessage() === 'Unsafe target was accepted') throw $error;
        }
    }
    echo 'Passed: staged source moves, standalone Apache rules, runtime preservation, and unsafe-target refusals' . PHP_EOL;
} finally {
    if ($originalEnvironment === false) putenv('COREFLUX_ENV');
    else putenv('COREFLUX_ENV=' . $originalEnvironment);
    $resolved = realpath($base);
    $temp = realpath(sys_get_temp_dir());
    if ($resolved === false || $temp === false
        || !str_starts_with($resolved, $temp . DIRECTORY_SEPARATOR . 'coreaccounting-webroot-')) {
        throw new RuntimeException('Refusing cleanup outside the test directory');
    }
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        if ($file->isDir()) rmdir($file->getPathname());
        else unlink($file->getPathname());
    }
    rmdir($resolved);
}
