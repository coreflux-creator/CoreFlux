<?php
/**
 * Non-destructive HTTP reachability probe for the generated API inventory.
 *
 * The probe sends GET requests only. A 2xx, 3xx, 400, 401, 403, 405, 409,
 * 422, or 429 response proves the request reached an application handler.
 * Semantic JSON 404s also count as reached; router/server 404s do not.
 *
 * Usage:
 *   php scripts/probe_api.php --base-url=https://www.corefluxapp.com
 *   COREFLUX_API_TOKEN=... php scripts/probe_api.php --base-url=https://...
 *   php scripts/probe_api.php --base-url=http://127.0.0.1:8080 --json=report.json
 */

declare(strict_types=1);

function apiProbeUsage(): string
{
    return <<<TXT
Usage: php scripts/probe_api.php --base-url=URL [options]

Options:
  --base-url=URL       Required deployment origin, without /api suffix
  --inventory=FILE     Inventory JSON (default: api/endpoints.json)
  --token=JWT          Optional bearer token (prefer COREFLUX_API_TOKEN env)
  --timeout=SECONDS    Per-request timeout, 1-60 (default: 8)
  --scope=SCOPE        public, internal, or all (default: public)
  --path-mode=MODE     preferred or fallback (default: preferred)
  --json=FILE          Write the full report as JSON
  --limit=N            Probe only the first N endpoints (diagnostics)
  --strict             Treat HTTP 5xx as a failed verification
  --help               Show this help
TXT;
}

/** @return array<string, string|bool> */
function apiProbeOptions(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--strict' || $arg === '--help') {
            $options[substr($arg, 2)] = true;
            continue;
        }
        if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $match)) {
            $options[$match[1]] = $match[2];
            continue;
        }
        throw new InvalidArgumentException("Unknown option: {$arg}");
    }
    return $options;
}

/** @return array{status:int,headers:array<string,string>,body:string,error:?string,duration_ms:int} */
function apiProbeRequest(string $url, ?string $token, int $timeout): array
{
    if (str_starts_with(strtolower($url), 'https://') && !in_array('https', stream_get_wrappers(), true)) {
        return apiProbeCurlRequest($url, $token, $timeout);
    }

    $headers = [
        'Accept: application/json',
        'User-Agent: CoreFlux-API-Probe/1.0',
        'X-Request-ID: probe-' . bin2hex(random_bytes(6)),
    ];
    if ($token !== null && $token !== '') $headers[] = 'Authorization: Bearer ' . $token;

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => $timeout,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $started = microtime(true);
    $body = @file_get_contents($url, false, $context);
    $duration = (int) round((microtime(true) - $started) * 1000);
    $rawHeaders = $http_response_header ?? [];
    $status = 0;
    $parsedHeaders = [];
    foreach ($rawHeaders as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match)) {
            $status = (int) $match[1];
            $parsedHeaders = [];
            continue;
        }
        $pos = strpos($line, ':');
        if ($pos === false) continue;
        $name = strtolower(trim(substr($line, 0, $pos)));
        $parsedHeaders[$name] = trim(substr($line, $pos + 1));
    }

    $lastError = error_get_last();
    return [
        'status' => $status,
        'headers' => $parsedHeaders,
        'body' => $body === false ? '' : substr($body, 0, 2048),
        'error' => $status === 0 ? (string) ($lastError['message'] ?? 'network request failed') : null,
        'duration_ms' => $duration,
    ];
}

/** @return array{status:int,headers:array<string,string>,body:string,error:?string,duration_ms:int} */
function apiProbeCurlRequest(string $url, ?string $token, int $timeout): array
{
    $escape = static fn(string $value): string => str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $value);
    $config = [
        'url = "' . $escape($url) . '"',
        'header = "Accept: application/json"',
        'header = "User-Agent: CoreFlux-API-Probe/1.0"',
        'header = "X-Request-ID: probe-' . bin2hex(random_bytes(6)) . '"',
    ];
    if ($token !== null && $token !== '') {
        // Passed over stdin rather than the process command line so the token
        // is not exposed in process listings.
        $config[] = 'header = "Authorization: Bearer ' . $escape($token) . '"';
    }

    $command = [
        PHP_OS_FAMILY === 'Windows' ? 'curl.exe' : 'curl',
        '--silent',
        '--show-error',
        '--include',
        '--max-time',
        (string) $timeout,
        '--request',
        'GET',
        '--config',
        '-',
    ];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $started = microtime(true);
    try {
        $process = proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    } catch (Throwable $error) {
        return [
            'status' => 0,
            'headers' => [],
            'body' => '',
            'error' => 'unable to start curl transport: ' . $error->getMessage(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }
    if (!is_resource($process)) {
        return [
            'status' => 0,
            'headers' => [],
            'body' => '',
            'error' => 'unable to start curl transport',
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    fwrite($pipes[0], implode(PHP_EOL, $config) . PHP_EOL);
    fclose($pipes[0]);
    $raw = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = trim((string) stream_get_contents($pipes[2]));
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $duration = (int) round((microtime(true) - $started) * 1000);

    $offset = 0;
    $status = 0;
    $parsedHeaders = [];
    $length = strlen($raw);
    while ($offset < $length && preg_match('/\GHTTP\/\S+\s+(\d{3})[^\r\n]*\r?\n/', $raw, $statusMatch, 0, $offset)) {
        $headerEnd = strpos($raw, "\r\n\r\n", $offset);
        $separatorLength = 4;
        if ($headerEnd === false) {
            $headerEnd = strpos($raw, "\n\n", $offset);
            $separatorLength = 2;
        }
        if ($headerEnd === false) break;
        $block = substr($raw, $offset, $headerEnd - $offset);
        $lines = preg_split('/\r?\n/', $block) ?: [];
        $status = (int) $statusMatch[1];
        $parsedHeaders = [];
        foreach (array_slice($lines, 1) as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) continue;
            $parsedHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
        }
        $offset = $headerEnd + $separatorLength;
    }

    return [
        'status' => $status,
        'headers' => $parsedHeaders,
        'body' => substr($raw, $offset, 2048),
        'error' => $exitCode === 0 ? null : ($stderr !== '' ? $stderr : "curl exited {$exitCode}"),
        'duration_ms' => $duration,
    ];
}

function apiProbeClassification(array $response, array $endpoint): string
{
    $status = (int) $response['status'];
    if ($status === 0) return 'network_error';
    if ($status >= 500) return 'reachable_unhealthy';
    if ($status !== 404) return 'reachable';

    $body = strtolower((string) $response['body']);
    if (
        str_contains($body, 'endpoint not found:')
        || str_contains($body, 'module not found:')
        || str_contains($body, 'expected /api/<module>/<endpoint>')
        || str_contains($body, '<title>404')
        || str_contains($body, '404 not found')
    ) {
        return 'route_missing';
    }

    $contentType = strtolower((string) ($response['headers']['content-type'] ?? ''));
    if (str_contains($contentType, 'application/json')) return 'reachable_semantic_404';
    if (
        in_array(($endpoint['audience'] ?? ''), ['signed_or_public', 'tenant'], true)
        && ($endpoint['auth'] ?? '') === 'custom'
        && str_contains($contentType, 'text/html')
        && str_contains($body, 'data-testid=')
    ) {
        return 'reachable_semantic_404';
    }
    return 'unverified_404';
}

function apiProbeMain(array $argv): int
{
    try {
        $options = apiProbeOptions($argv);
    } catch (InvalidArgumentException $error) {
        fwrite(STDERR, $error->getMessage() . PHP_EOL . apiProbeUsage() . PHP_EOL);
        return 2;
    }
    if (!empty($options['help'])) {
        echo apiProbeUsage() . PHP_EOL;
        return 0;
    }

    $root = dirname(__DIR__);
    $baseUrl = rtrim((string) ($options['base-url'] ?? ''), '/');
    if ($baseUrl === '' || !preg_match('#^https?://#i', $baseUrl)) {
        fwrite(STDERR, "--base-url must be an http(s) URL." . PHP_EOL . apiProbeUsage() . PHP_EOL);
        return 2;
    }
    $inventoryFile = (string) ($options['inventory'] ?? ($root . '/api/endpoints.json'));
    if (!is_file($inventoryFile)) {
        fwrite(STDERR, "Inventory not found: {$inventoryFile}. Run php scripts/build_api_package.php." . PHP_EOL);
        return 2;
    }

    $timeout = max(1, min(60, (int) ($options['timeout'] ?? 8)));
    $token = (string) ($options['token'] ?? getenv('COREFLUX_API_TOKEN') ?: '');
    $limit = isset($options['limit']) ? max(1, (int) $options['limit']) : null;
    $strict = !empty($options['strict']);
    $scope = strtolower((string) ($options['scope'] ?? 'public'));
    if (!in_array($scope, ['public', 'internal', 'all'], true)) {
        fwrite(STDERR, "--scope must be public, internal, or all." . PHP_EOL);
        return 2;
    }
    $pathMode = strtolower((string) ($options['path-mode'] ?? 'preferred'));
    if (!in_array($pathMode, ['preferred', 'fallback'], true)) {
        fwrite(STDERR, "--path-mode must be preferred or fallback." . PHP_EOL);
        return 2;
    }
    $inventory = json_decode((string) file_get_contents($inventoryFile), true, 512, JSON_THROW_ON_ERROR);
    $endpoints = $inventory['endpoints'] ?? [];
    $endpoints = array_values(array_filter($endpoints, static function (array $endpoint) use ($scope): bool {
        $internal = ($endpoint['audience'] ?? '') === 'internal';
        if ($scope === 'internal') return $internal;
        if ($scope === 'public') return !$internal;
        return true;
    }));
    if ($limit !== null) $endpoints = array_slice($endpoints, 0, $limit);

    // Fail fast on DNS/TLS/network problems rather than waiting once for every
    // endpoint. This intentionally uses a nonexistent route as the baseline.
    $baselinePath = '/api/__coreflux_reachability_sentinel__/' . bin2hex(random_bytes(4));
    $baseline = apiProbeRequest($baseUrl . $baselinePath, $token ?: null, $timeout);
    if ($baseline['status'] === 0) {
        fwrite(STDERR, "Deployment is unreachable: {$baseline['error']}" . PHP_EOL);
        return 2;
    }

    $results = [];
    $counts = [];
    $total = count($endpoints);
    foreach ($endpoints as $index => $endpoint) {
        $preferredPath = (string) $endpoint['preferred_path'];
        $candidatePaths = [$preferredPath];
        if ($pathMode === 'fallback') {
            $candidatePaths = array_values(array_unique(array_merge($candidatePaths, $endpoint['aliases'] ?? [])));
        }
        $attempts = [];
        $path = $preferredPath;
        $response = ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'not attempted', 'duration_ms' => 0];
        $classification = 'network_error';
        foreach ($candidatePaths as $candidatePath) {
            $path = (string) $candidatePath;
            $response = apiProbeRequest($baseUrl . $path, $token ?: null, $timeout);
            $classification = apiProbeClassification($response, $endpoint);
            $attempts[] = [
                'path' => $path,
                'status' => $response['status'],
                'classification' => $classification,
            ];
            if (!in_array($classification, ['route_missing', 'unverified_404'], true)) break;
        }
        $counts[$classification] = ($counts[$classification] ?? 0) + 1;
        $results[] = [
            'path' => $path,
            'preferred_path' => $preferredPath,
            'used_fallback' => $path !== $preferredPath,
            'source_file' => $endpoint['source_file'],
            'status' => $response['status'],
            'classification' => $classification,
            'duration_ms' => $response['duration_ms'],
            'content_type' => $response['headers']['content-type'] ?? null,
            'error' => $response['error'],
            'attempts' => $attempts,
        ];
        printf("[%d/%d] %-24s %3d %s\n", $index + 1, $total, $classification, $response['status'], $path);
    }
    ksort($counts);

    $report = [
        'base_url' => $baseUrl,
        'authenticated' => $token !== '',
        'scope' => $scope,
        'path_mode' => $pathMode,
        'strict' => $strict,
        'baseline_status' => $baseline['status'],
        'counts' => $counts,
        'results' => $results,
    ];
    if (!empty($options['json'])) {
        $jsonPath = (string) $options['json'];
        $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (file_put_contents($jsonPath, $encoded) === false) {
            fwrite(STDERR, "Unable to write report: {$jsonPath}" . PHP_EOL);
            return 2;
        }
    }

    echo PHP_EOL . 'Summary: ';
    $parts = [];
    foreach ($counts as $key => $count) $parts[] = "{$key}={$count}";
    echo implode(', ', $parts) . PHP_EOL;

    $failed = ($counts['network_error'] ?? 0)
        + ($counts['route_missing'] ?? 0)
        + ($counts['unverified_404'] ?? 0);
    if ($strict) $failed += ($counts['reachable_unhealthy'] ?? 0);
    return $failed === 0 ? 0 : 1;
}

if (PHP_SAPI === 'cli') exit(apiProbeMain($argv));
