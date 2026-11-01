<?php
/** Outside-in inventory of public static files in a standalone release. */
declare(strict_types=1);

require_once __DIR__ . '/coreaccounting_public_assets.php';

function coreAccountingInventoryFiles(string $directory, string $root): array
{
    $paths = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        if ($file->isLink()) {
            throw new RuntimeException("Linked file or directory is not accepted: $relative");
        }
        if (!$file->isFile() || strtolower(pathinfo($relative, PATHINFO_EXTENSION)) === 'php') {
            continue;
        }
        $paths[] = $relative;
    }
    sort($paths, SORT_STRING);
    return $paths;
}

function coreAccountingInventoryHttp(string $origin, array $paths): array
{
    $pending = $paths;
    $responses = [];
    $multi = curl_multi_init();
    $active = [];
    try {
        while ($pending || $active) {
            while ($pending && count($active) < 12) {
                $path = array_shift($pending);
                $url = $origin . '/' . implode('/', array_map('rawurlencode', explode('/', $path)));
                $curl = curl_init($url);
                if ($curl === false) throw new RuntimeException('Could not start public-file HTTP check.');
                $bodyHash = hash_init('sha256');
                curl_setopt_array($curl, [
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_TIMEOUT => 8,
                    CURLOPT_USERAGENT => 'CoreAccounting-public-file-inventory/1',
                    CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($bodyHash): int {
                        hash_update($bodyHash, $chunk);
                        return strlen($chunk);
                    },
                ]);
                curl_multi_add_handle($multi, $curl);
                $active[spl_object_id($curl)] = [
                    'handle' => $curl, 'path' => $path, 'body_hash' => $bodyHash,
                ];
            }
            do {
                $result = curl_multi_exec($multi, $running);
            } while ($result === CURLM_CALL_MULTI_PERFORM);
            if ($result !== CURLM_OK) throw new RuntimeException('Public-file HTTP inventory failed.');
            while ($info = curl_multi_info_read($multi)) {
                $curl = $info['handle'];
                $id = spl_object_id($curl);
                $path = $active[$id]['path'];
                $responses[$path] = [
                    'status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
                    'hash' => hash_final($active[$id]['body_hash']),
                    'error' => $info['result'] === CURLE_OK ? '' : curl_error($curl),
                ];
                curl_multi_remove_handle($multi, $curl);
                curl_close($curl);
                unset($active[$id]);
            }
            if ($running && curl_multi_select($multi, 1.0) === -1) usleep(10000);
        }
    } finally {
        foreach ($active as $item) {
            curl_multi_remove_handle($multi, $item['handle']);
            curl_close($item['handle']);
        }
        curl_multi_close($multi);
    }
    return $responses;
}

function coreAccountingAuditPublicFiles(
    string $root,
    string $origin,
    array $retiredRoots = [],
    ?callable $request = null
): array {
    $resolvedRoot = realpath($root);
    $parts = parse_url($origin);
    if ($resolvedRoot === false || !is_dir($resolvedRoot) || is_link($root)
        || !is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
        || empty($parts['host'])
        || array_intersect(['user', 'pass', 'port', 'path', 'query', 'fragment'], array_keys($parts))) {
        throw new RuntimeException('An exact release root and HTTPS origin are required.');
    }

    $paths = coreAccountingInventoryFiles($resolvedRoot, $resolvedRoot);
    $current = array_fill_keys($paths, true);
    $retired = [];
    foreach ($retiredRoots as $retiredRoot) {
        $resolvedRetired = realpath($retiredRoot);
        if ($resolvedRetired === false || !is_dir($resolvedRetired) || is_link($retiredRoot)
            || $resolvedRetired === $resolvedRoot
            || str_starts_with($resolvedRetired, $resolvedRoot . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Retired-file archive must exist outside the release root.');
        }
        foreach (coreAccountingInventoryFiles($resolvedRetired, $resolvedRetired) as $relative) {
            if (isset($current[$relative])) {
                throw new RuntimeException("Retired path remains in public webroot: $relative");
            }
            $retired[$relative] = true;
        }
    }

    $pending = array_values(array_unique(array_merge($paths, array_keys($retired))));
    sort($pending, SORT_STRING);
    if ($request === null) {
        $responses = coreAccountingInventoryHttp($origin, $pending);
    } else {
        $responses = [];
        foreach ($pending as $path) {
            $url = $origin . '/' . implode('/', array_map('rawurlencode', explode('/', $path)));
            $responses[$path] = $request($path, $url);
        }
    }

    $status = [];
    $errors = [];
    $served = [];
    $servedHashes = [];
    $stale = [];
    $unexpectedStatus = [];
    foreach ($pending as $path) {
        $response = $responses[$path] ?? [];
        $code = (int) ($response['status'] ?? 0);
        $status[$code] = ($status[$code] ?? 0) + 1;
        if (!empty($response['error'])) {
            $errors[] = ['path' => $path, 'curl_error' => (string) $response['error']];
        } elseif (isset($retired[$path]) && $code !== 404) {
            $stale[] = ['path' => $path, 'status' => $code];
        } elseif (isset($current[$path]) && $code >= 200 && $code < 300) {
            $served[] = $path;
            $servedHashes[$path] = (string) ($response['hash'] ?? '');
        } elseif (isset($current[$path]) && !in_array($code, [403, 404], true)) {
            $unexpectedStatus[] = ['path' => $path, 'status' => $code];
        }
    }
    ksort($status);
    sort($served, SORT_STRING);

    $allowedServed = coreAccountingExpectedPublicFiles($resolvedRoot);
    $unexpectedServed = array_values(array_diff($served, $allowedServed));
    $missingServed = array_values(array_diff($allowedServed, $served));
    $contentMismatch = [];
    foreach ($allowedServed as $path) {
        if (!isset($servedHashes[$path])) continue;
        $diskHash = hash_file('sha256', $resolvedRoot . '/' . $path);
        if ($diskHash === false || !hash_equals($diskHash, $servedHashes[$path])) {
            $contentMismatch[] = $path;
        }
    }
    return [
        'root' => $resolvedRoot,
        'files' => count($paths),
        'retired_files' => count($retired),
        'status' => $status,
        'served' => $served,
        'unexpected_served' => $unexpectedServed,
        'missing_served' => $missingServed,
        'content_mismatch' => $contentMismatch,
        'stale_retired' => $stale,
        'unexpected_status' => $unexpectedStatus,
        'errors' => $errors,
    ];
}

function coreAccountingPublicInventoryPassed(array $result): bool
{
    if (($result['served'] ?? []) === []) return false;
    foreach (['unexpected_served', 'missing_served', 'content_mismatch',
        'stale_retired', 'unexpected_status', 'errors'] as $problem) {
        if (!array_key_exists($problem, $result) || $result[$problem] !== []) return false;
    }
    return true;
}
