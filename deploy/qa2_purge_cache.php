<?php
/** Purge only the isolated CoreAccounting QA hostname from local Varnish. */
declare(strict_types=1);

const QA_ROOT = '/home/1516771.cloudwaysapps.com/aqdcpvafpj/public_html';
const QA_HOST = 'phpstack-1516771-6717961.cloudwaysapps.com';

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging'
    || !in_array('--confirm-disposable-qa', $argv, true)
    || realpath(QA_ROOT) !== QA_ROOT) {
    fwrite(STDERR, "Disposable QA CLI on its own server only.\n");
    exit(2);
}

$paths = [
    '/', '/index.html', '/spa-assets/index.html', '/login2.html',
    '/assets/css/signup.html', '/data/branding_settings.json', '/spa.php.tmp',
    '/signup.php', '/signup.html',
    '/storage/billing/invoices/1/2-c7a9de7248ae5522e376360833dc9e6b8ec974b2.pdf',
    '/storage/billing/invoices/1/2-b4b68644dad3112caeb92d401cb8a99656a616c9.pdf',
    '/storage/billing/invoices/1/17-7088bf9989fb9ded382e7238ba81bb778c0386cc.pdf',
];
$publicAssets = [
    '404.html', '_deploy_ok.txt',
    'assets/brand/coreflux-logo.png', 'assets/brand/coreflux-mark.png',
    'assets/css/legal.css', 'assets/css/styles.css',
    'dashboard/dist/index.html', 'login.html', 'privacy.html',
    'quickbooks-connect.html', 'quickbooks-disconnect.html',
    'spa-assets/index-CJR12EpM.js', 'spa-assets/index-DUIIybQx.js',
    'spa-assets/index-SRkxIh9x.css', 'spa-assets/index-hTtQmukx.css',
    'spa-assets/sw.js', 'terms.html',
];
foreach ($publicAssets as $relative) {
    $file = QA_ROOT . '/' . $relative;
    if (!is_file($file) || is_link($file)) {
        throw new RuntimeException("Pinned QA public asset is unavailable: $relative");
    }
    $paths[] = '/' . $relative;
}
$roots = [
    '/home/master/.coreaccounting-cleanqa/static-prune-20261009',
    '/home/master/.coreaccounting-cleanqa/vendor-private-20261009',
];
foreach ($roots as $root) {
    if (realpath($root) !== $root) {
        throw new RuntimeException("Pinned QA package or archive is unavailable: $root");
    }
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($files as $file) {
        if (!$file->isFile() || $file->isLink()) continue;
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        if (strtolower(pathinfo($relative, PATHINFO_EXTENSION)) === 'php') continue;
        $paths[] = '/' . $relative;
    }
}
$paths = array_values(array_unique($paths));
sort($paths, SORT_STRING);
$failed = [];
foreach ($paths as $path) {
    $encoded = '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
    $curl = curl_init('http://127.0.0.1' . $encoded);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => 'PURGE',
        CURLOPT_HTTPHEADER => ['Host: ' . QA_HOST],
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_USERAGENT => 'CoreAccounting-QA-app-cache-purge/1',
        CURLOPT_WRITEFUNCTION => static fn($handle, string $chunk): int => strlen($chunk),
    ]);
    curl_exec($curl);
    $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if (curl_errno($curl) !== 0 || !in_array($code, [200, 204], true)) {
        $failed[] = ['path' => $path, 'status' => $code, 'error' => curl_error($curl)];
    }
    curl_close($curl);
}
echo json_encode(['host' => QA_HOST, 'purged_paths' => count($paths),
    'failed_count' => count($failed), 'failed' => array_slice($failed, 0, 10)],
    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
exit($failed ? 1 : 0);
