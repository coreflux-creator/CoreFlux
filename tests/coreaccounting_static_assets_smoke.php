<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$login = (string) file_get_contents($root . '/login.html');
$dashboard = (string) file_get_contents($root . '/dashboard/index.html');
$spa = (string) file_get_contents($root . '/spa.php');
$worker = (string) file_get_contents($root . '/spa-assets/sw.js');
$paths = [
    '/assets/brand/coreflux-logo.png',
    '/assets/brand/coreflux-mark.png',
];
foreach ($paths as $path) {
    if (!str_contains($login, $path) || !is_file($root . $path)) {
        throw new RuntimeException("Login image is missing: $path");
    }
}
foreach (['privacy.html', 'terms.html', 'quickbooks-connect.html', 'quickbooks-disconnect.html'] as $page) {
    $html = (string) file_get_contents($root . '/' . $page);
    if (!str_contains($html, '/assets/brand/coreflux-mark.png')
        || str_contains($html, '/assets/icons/')) {
        throw new RuntimeException("Public legal page uses an unshipped icon: $page");
    }
}
$notFound = (string) file_get_contents($root . '/404.html');
if (!str_contains($notFound, '/assets/brand/coreflux-logo.png')
    || !str_contains($notFound, '/assets/brand/coreflux-mark.png')
    || !str_contains($notFound, 'href="/"')
    || str_contains($notFound, 'assets/icons/')) {
    throw new RuntimeException('404 page must use shipped branding and the workspace route.');
}
if (preg_match('/href="(?:index|pricing|people|finance|accounting|tax|wealth|reporting|crm)\.html"/', $login)) {
    throw new RuntimeException('Standalone sign-in must not link to retired marketing pages.');
}
if (!str_contains($dashboard, 'href="/assets/brand/coreflux-mark.png"')) {
    throw new RuntimeException('Dashboard favicon must use the packaged brand mark.');
}
if (!str_contains($spa, "getenv('COREFLUX_ENV') !== 'coreaccounting'")
    || !str_contains($spa, 'href="/spa-assets/manifest.webmanifest"')
    || !str_contains($spa, 'href="/assets/brand/coreflux-mark.png"')) {
    throw new RuntimeException('Standalone must not advertise the ERP manifest or missing favicon.');
}
if (str_contains($worker, "'/manifest.webmanifest'")
    || str_contains($worker, "'/spa-assets/manifest.webmanifest'")) {
    throw new RuntimeException('Service-worker shell must not precache an optional manifest.');
}
echo "Passed: standalone login, legal and 404 pages use shipped assets and routes\n";
