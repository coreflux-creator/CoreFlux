<?php
declare(strict_types=1);

require_once __DIR__ . '/../deploy/coreaccounting_public_inventory.php';

$base = sys_get_temp_dir() . '/coreaccounting-inventory-' . bin2hex(random_bytes(6));
$root = $base . '/public_html';
$retired = $base . '/retired';
if (!mkdir($root, 0700, true) || !mkdir($retired, 0700, true)) {
    throw new RuntimeException('Could not create inventory fixtures.');
}
$put = static function (string $relative, string $contents = 'fixture') use ($root): void {
    $path = $root . '/' . $relative;
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
        'spa-assets/sw.js', 'terms.html',
    ] as $file) $put($file);
    $put('dashboard/dist/index.html',
        '<script src="/spa-assets/index-main.js"></script>'
        . '<link href="/spa-assets/index-main.css" rel="stylesheet">');
    $put('spa-assets/index-main.js', 'import("./index-extra.js")');
    $put('spa-assets/index-main.css');
    $put('spa-assets/index-extra.js');
    $put('spa-assets/index-old.js');
    $put('core/migrations/private.sql');
    file_put_contents($retired . '/old-page.html', 'retired');

    $expected = coreAccountingExpectedPublicFiles($root);
    $responses = [];
    foreach ($expected as $path) {
        $responses[$path] = [
            'status' => 200,
            'hash' => hash_file('sha256', $root . '/' . $path),
            'error' => '',
        ];
    }
    $responses['spa-assets/index-old.js'] = ['status' => 403, 'hash' => '', 'error' => ''];
    $responses['core/migrations/private.sql'] = ['status' => 403, 'hash' => '', 'error' => ''];
    $responses['old-page.html'] = ['status' => 404, 'hash' => '', 'error' => ''];
    $request = static function (string $path, string $url) use (&$responses): array {
        if (!str_starts_with($url, 'https://accounting.example.test/')) {
            throw new RuntimeException('Inventory used an unexpected origin.');
        }
        return $responses[$path] ?? ['status' => 0, 'hash' => '', 'error' => 'missing fixture'];
    };
    $audit = static fn() => coreAccountingAuditPublicFiles(
        $root, 'https://accounting.example.test', [$retired], $request
    );

    $result = $audit();
    if (!coreAccountingPublicInventoryPassed($result)
        || $result['files'] !== count($expected) + 2
        || $result['retired_files'] !== 1
        || count($result['served']) !== count($expected)) {
        throw new RuntimeException('Expected, denied and retired files did not pass together.');
    }

    $responses['spa-assets/index-old.js']['status'] = 200;
    if ($audit()['unexpected_served'] !== ['spa-assets/index-old.js']) {
        throw new RuntimeException('Unexpected public bundle was not caught.');
    }
    $responses['spa-assets/index-old.js']['status'] = 403;
    $responses['login.html']['status'] = 404;
    if ($audit()['missing_served'] !== ['login.html']) {
        throw new RuntimeException('Missing public login was not caught.');
    }
    $responses['login.html']['status'] = 200;
    $responses['login.html']['hash'] = hash('sha256', 'wrong content');
    if ($audit()['content_mismatch'] !== ['login.html']) {
        throw new RuntimeException('Changed public content was not caught.');
    }
    $responses['login.html']['hash'] = hash_file('sha256', $root . '/login.html');
    $responses['old-page.html']['status'] = 200;
    if ($audit()['stale_retired'] !== [['path' => 'old-page.html', 'status' => 200]]) {
        throw new RuntimeException('Stale retired URL was not caught.');
    }

    try {
        coreAccountingAuditPublicFiles($root, 'http://accounting.example.test', [], $request);
        throw new RuntimeException('Insecure public origin was accepted.');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'An exact release root and HTTPS origin are required.') {
            throw $error;
        }
    }
    echo "Passed: public, denied, retired, hash and origin checks\n";
} finally {
    $resolved = realpath($base);
    $temp = realpath(sys_get_temp_dir());
    if ($resolved === false || $temp === false
        || !str_starts_with($resolved, $temp . DIRECTORY_SEPARATOR . 'coreaccounting-inventory-')) {
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
