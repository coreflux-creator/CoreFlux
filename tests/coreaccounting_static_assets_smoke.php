<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$login = (string) file_get_contents($root . '/login.html');
$dashboard = (string) file_get_contents($root . '/dashboard/index.html');
$spa = (string) file_get_contents($root . '/spa.php');
$worker = (string) file_get_contents($root . '/spa-assets/sw.js');
$paths = [
    '/assets/brand/coreflux-logo.png',
    '/assets/img/hero-login.png',
    '/assets/brand/coreflux-mark.png',
];
foreach ($paths as $path) {
    if (!str_contains($login, $path) || !is_file($root . $path)) {
        throw new RuntimeException("Login image is missing: $path");
    }
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
echo "Passed: login images, favicon, and standalone app shell use available assets\n";
