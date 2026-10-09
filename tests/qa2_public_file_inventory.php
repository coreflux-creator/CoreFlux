<?php
/** Read-only HTTP inventory of non-PHP files in the disposable QA webroot. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging'
    || !in_array('--confirm-disposable-qa', $argv, true)) {
    fwrite(STDERR, "Disposable QA CLI only.\n");
    exit(2);
}

$option = static function (string $name) use ($argv): string {
    $prefix = "--$name=";
    $values = array_values(array_filter($argv, static fn(string $arg): bool => str_starts_with($arg, $prefix)));
    return count($values) === 1 ? substr($values[0], strlen($prefix)) : '';
};
$root = realpath($option('root'));
$origin = $option('origin');
if ($root !== '/home/1516771.cloudwaysapps.com/aqdcpvafpj/public_html'
    || $origin !== 'https://phpstack-1516771-6717961.cloudwaysapps.com') {
    fwrite(STDERR, "This inventory is pinned to the disposable QA app.\n");
    exit(2);
}

$paths = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->isLink()) continue;
    $relative = substr($file->getPathname(), strlen($root) + 1);
    if (strtolower(pathinfo($relative, PATHINFO_EXTENSION)) === 'php') continue;
    $paths[] = str_replace('\\', '/', $relative);
}
sort($paths, SORT_STRING);
$current = array_fill_keys($paths, true);
$retired = [];
foreach ([
    '/home/master/.coreaccounting-cleanqa/static-prune-20261009',
    '/home/master/.coreaccounting-cleanqa/vendor-private-20261009',
] as $retiredRoot) {
    if (!is_dir($retiredRoot)) throw new RuntimeException('Pinned retired-file archive is unavailable.');
    $oldFiles = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($retiredRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($oldFiles as $file) {
        if (!$file->isFile() || $file->isLink()) continue;
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($retiredRoot) + 1));
        if (strtolower(pathinfo($relative, PATHINFO_EXTENSION)) === 'php') continue;
        if (isset($current[$relative])) {
            throw new RuntimeException("Retired path remains in public webroot: $relative");
        }
        $retired[$relative] = true;
    }
}
$pending = array_values(array_unique(array_merge($paths, array_keys($retired))));
sort($pending, SORT_STRING);

$multi = curl_multi_init();
$active = [];
$status = [];
$errors = [];
$served = [];
$servedHashes = [];
$stale = [];
$unexpectedStatus = [];
while ($pending || $active) {
    while ($pending && count($active) < 12) {
        $path = array_shift($pending);
        $url = $origin . '/' . implode('/', array_map('rawurlencode', explode('/', $path)));
        $curl = curl_init($url);
        $bodyHash = hash_init('sha256');
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_USERAGENT => 'CoreAccounting-QA-public-file-inventory/1',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($bodyHash): int {
                hash_update($bodyHash, $chunk);
                return strlen($chunk);
            },
        ]);
        curl_multi_add_handle($multi, $curl);
        $active[spl_object_id($curl)] = ['handle' => $curl, 'path' => $path, 'body_hash' => $bodyHash];
    }
    do {
        $result = curl_multi_exec($multi, $running);
    } while ($result === CURLM_CALL_MULTI_PERFORM);
    if ($result !== CURLM_OK) throw new RuntimeException('HTTP inventory transport failed.');
    while ($info = curl_multi_info_read($multi)) {
        $curl = $info['handle'];
        $id = spl_object_id($curl);
        $path = $active[$id]['path'];
        $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $responseHash = hash_final($active[$id]['body_hash']);
        $status[$code] = ($status[$code] ?? 0) + 1;
        if ($info['result'] !== CURLE_OK) {
            $errors[] = ['path' => $path, 'curl_error' => curl_error($curl)];
        } elseif (isset($retired[$path]) && $code !== 404) {
            $stale[] = ['path' => $path, 'status' => $code];
        } elseif (isset($current[$path]) && $code >= 200 && $code < 300) {
            $served[] = $path;
            $servedHashes[$path] = $responseHash;
        } elseif (isset($current[$path]) && !in_array($code, [403, 404], true)) {
            $unexpectedStatus[] = ['path' => $path, 'status' => $code];
        }
        curl_multi_remove_handle($multi, $curl);
        curl_close($curl);
        unset($active[$id]);
    }
    if ($running) {
        if (curl_multi_select($multi, 1.0) === -1) usleep(10000);
    }
}
curl_multi_close($multi);
ksort($status);
sort($served, SORT_STRING);
$allowedServed = [
    '404.html', '_deploy_ok.txt',
    'assets/brand/coreflux-logo.png', 'assets/brand/coreflux-mark.png',
    'assets/css/legal.css', 'assets/css/styles.css',
    'dashboard/dist/index.html', 'login.html', 'privacy.html',
    'quickbooks-connect.html', 'quickbooks-disconnect.html',
    'spa-assets/index-CJR12EpM.js', 'spa-assets/index-DUIIybQx.js',
    'spa-assets/index-SRkxIh9x.css', 'spa-assets/index-hTtQmukx.css',
    'spa-assets/sw.js', 'terms.html',
];
sort($allowedServed, SORT_STRING);
$unexpectedServed = array_values(array_diff($served, $allowedServed));
$missingServed = array_values(array_diff($allowedServed, $served));
$contentMismatch = [];
foreach ($allowedServed as $path) {
    if (!isset($servedHashes[$path])) continue;
    $diskHash = hash_file('sha256', $root . '/' . $path);
    if ($diskHash === false || !hash_equals($diskHash, $servedHashes[$path])) {
        $contentMismatch[] = $path;
    }
}
echo json_encode(['root' => $root, 'files' => count($paths), 'retired_files' => count($retired),
    'status' => $status, 'served' => $served, 'unexpected_served' => $unexpectedServed,
    'missing_served' => $missingServed, 'content_mismatch' => $contentMismatch,
    'stale_retired' => $stale,
    'unexpected_status' => $unexpectedStatus, 'errors' => $errors],
    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
exit(($errors || $stale || $unexpectedStatus || $unexpectedServed || $missingServed
    || $contentMismatch) ? 1 : 0);
