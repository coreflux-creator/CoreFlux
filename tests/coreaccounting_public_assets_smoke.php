<?php
declare(strict_types=1);

require_once __DIR__ . '/../deploy/coreaccounting_public_assets.php';

$base = sys_get_temp_dir() . '/coreaccounting-assets-' . bin2hex(random_bytes(6));
if (!mkdir($base, 0700)) throw new RuntimeException('Could not create test release root.');
$put = static function (string $relative, string $contents = 'fixture') use ($base): void {
    $path = $base . '/' . $relative;
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true)) {
        throw new RuntimeException("Could not create fixture directory: $relative");
    }
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException("Could not create fixture file: $relative");
    }
};

try {
    foreach ([
        '404.html', 'assets/brand/coreflux-logo.png', 'assets/brand/coreflux-mark.png',
        'assets/css/legal.css', 'assets/css/styles.css', 'login.html', 'privacy.html',
        'quickbooks-connect.html', 'quickbooks-disconnect.html',
        'spa-assets/sw.js', 'terms.html', 'assets/icons/icon-billing.png',
        'assets/icons/unused.png',
    ] as $file) $put($file);
    $put('dashboard/dist/index.html',
        '<script src="/spa-assets/index-main.js"></script>'
        . '<link href="/spa-assets/index-main.css" rel="stylesheet">');
    $put('spa-assets/index-main.js',
        'import("./index-feature.js");import("./index-feature.css");const icon="/assets/icons/icon-billing.png"');
    $put('spa-assets/index-main.css');
    $put('spa-assets/index-feature.js', 'const done=true');
    $put('spa-assets/index-feature.css', '@import "./index-theme.css";');
    $put('spa-assets/index-theme.css');
    $put('spa-assets/index-old.js');

    $expected = coreAccountingExpectedPublicFiles($base);
    foreach (['spa-assets/index-main.js', 'spa-assets/index-main.css',
        'spa-assets/index-feature.js', 'spa-assets/index-feature.css',
        'spa-assets/index-theme.css', 'assets/icons/icon-billing.png'] as $asset) {
        if (!in_array($asset, $expected, true)) {
            throw new RuntimeException("Active asset was omitted: $asset");
        }
    }
    if (in_array('spa-assets/index-old.js', $expected, true)
        || in_array('assets/icons/unused.png', $expected, true)
        || in_array('_deploy_ok.txt', $expected, true)) {
        throw new RuntimeException('Unreferenced or absent files were treated as public.');
    }

    $put('_deploy_ok.txt');
    if (!in_array('_deploy_ok.txt', coreAccountingExpectedPublicFiles($base), true)) {
        throw new RuntimeException('Present release marker was omitted.');
    }

    unlink($base . '/spa-assets/index-feature.css');
    try {
        coreAccountingExpectedPublicFiles($base);
        throw new RuntimeException('Missing imported chunk was accepted.');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'Installed app asset is missing or linked: index-feature.css') {
            throw $error;
        }
    }
    $put('spa-assets/index-feature.css', '@import "./index-theme.css";');

    unlink($base . '/assets/icons/icon-billing.png');
    try {
        coreAccountingExpectedPublicFiles($base);
        throw new RuntimeException('Missing referenced icon was accepted.');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'Referenced public icon is missing or linked: assets/icons/icon-billing.png') {
            throw $error;
        }
    }
    $put('assets/icons/icon-billing.png');

    $put('dashboard/dist/index.html',
        '<script src="/spa-assets/index-main.js"></script>'
        . '<script src="/spa-assets/index-old.js"></script>'
        . '<link href="/spa-assets/index-main.css" rel="stylesheet">');
    try {
        coreAccountingExpectedPublicFiles($base);
        throw new RuntimeException('Duplicate JS entry was accepted.');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'Installed app must identify one js entry asset.') {
            throw $error;
        }
    }

    echo "Passed: active release assets discovered, stale files excluded, incomplete bundles refused\n";
} finally {
    $resolved = realpath($base);
    $temp = realpath(sys_get_temp_dir());
    if ($resolved === false || $temp === false
        || !str_starts_with($resolved, $temp . DIRECTORY_SEPARATOR . 'coreaccounting-assets-')) {
        throw new RuntimeException('Refusing cleanup outside the test directory.');
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
