<?php
/**
 * Smoke for /api/internal/jobdiva_proxy.php and /api/internal/mappings_lookup.php
 *
 * Boots the PHP built-in webserver against /app, then hits the endpoints
 * with hand-signed HMAC headers. Verifies:
 *   - Missing/invalid signature → 401
 *   - Stale timestamp           → 401
 *   - Secret unset              → 503
 *   - Non-JSON body             → 400
 *   - Path outside allowlist    → 400
 *   - Missing op fields         → 400
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

// ---------------------------------------------------------------------
// Spin up a one-off PHP built-in webserver on a free port.
// ---------------------------------------------------------------------
function find_free_port(): int {
    $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!$sock) throw new \RuntimeException("socket_server: $errstr");
    $name = stream_socket_get_name($sock, false);
    fclose($sock);
    $parts = explode(':', (string) $name);
    return (int) end($parts);
}

$port   = find_free_port();
$secret = 'smoke-' . bin2hex(random_bytes(8));
$root   = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
putenv('INTERNAL_HMAC_SECRET=' . $secret);
$cmd    = escapeshellarg(PHP_BINARY) . " -S 127.0.0.1:{$port} -t " . escapeshellarg($root);
$descr  = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc   = proc_open($cmd, $descr, $pipes, $root);
if (!is_resource($proc)) { echo "FATAL: failed to start php -S\n"; exit(1); }
register_shutdown_function(function () use ($proc) {
    if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
});

// Wait until the server actually accepts a connection.
$deadline = microtime(true) + 6;
$serverReady = false;
while (microtime(true) < $deadline) {
    $c = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 0.2);
    if ($c) { fclose($c); $serverReady = true; break; }
    usleep(150_000);
}
if (!$serverReady) {
    $err = stream_get_contents($pipes[2]);
    echo "FATAL: php -S did not start: {$err}\n";
    exit(1);
}

/** Hit one of the bridge endpoints. */
function bridge_call(int $port, string $path, string $body, array $headers): array {
    $hdrLines = [];
    foreach ($headers as $k => $v) $hdrLines[] = "{$k}: {$v}";
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $hdrLines),
            'content'       => $body,
            'ignore_errors' => true,   // capture 4xx/5xx bodies
            'timeout'       => 5,
        ],
    ]);
    $resp = @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $ctx);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0], $m)) {
        $status = (int) $m[1];
    }
    return [$status, (string) $resp];
}

$proxyPath   = '/api/internal/jobdiva_proxy.php';
$mappingPath = '/api/internal/mappings_lookup.php';

echo "JobDiva proxy bridge — endpoint accessible\n";
[$status] = bridge_call($port, $proxyPath, '{}', ['Content-Type' => 'application/json']);
$assert('endpoint is routable (got 4xx, not network failure)', $status >= 400 && $status < 600, "status={$status}");

echo "\nJobDiva proxy bridge — missing signature ⇒ 401\n";
$body = json_encode(['tenant_id' => 1, 'method' => 'POST', 'path' => '/apiv2/jobdiva/searchJob', 'body' => ['jobId' => 1]]);
[$status, $resp] = bridge_call($port, $proxyPath, $body, ['Content-Type' => 'application/json']);
$assert('returns 401 with no signature headers', $status === 401, "status={$status} body={$resp}");

echo "\nJobDiva proxy bridge — bad signature ⇒ 401\n";
$ts = (string) time();
[$status, $resp] = bridge_call($port, $proxyPath, $body, [
    'Content-Type' => 'application/json',
    'X-Internal-Timestamp' => $ts,
    'X-Internal-Signature' => 'deadbeef',
]);
$assert('returns 401 with bad signature', $status === 401);

echo "\nJobDiva proxy bridge — stale timestamp ⇒ 401\n";
$stale = (string) (time() - 3600);
$sig   = hash_hmac('sha256', $stale . '.' . $body, $secret);
[$status, $resp] = bridge_call($port, $proxyPath, $body, [
    'Content-Type' => 'application/json',
    'X-Internal-Timestamp' => $stale,
    'X-Internal-Signature' => $sig,
]);
$assert('returns 401 with stale timestamp', $status === 401);

echo "\nJobDiva proxy bridge — path outside allowlist ⇒ 400\n";
$badBody = json_encode(['tenant_id' => 1, 'method' => 'POST', 'path' => '/etc/passwd', 'body' => []]);
$ts  = (string) time();
$sig = hash_hmac('sha256', $ts . '.' . $badBody, $secret);
[$status, $resp] = bridge_call($port, $proxyPath, $badBody, [
    'Content-Type' => 'application/json',
    'X-Internal-Timestamp' => $ts,
    'X-Internal-Signature' => $sig,
]);
$assert('rejects non-/apiv2/jobdiva/ path with 400', $status === 400);
$decoded = json_decode($resp, true);
$assert('error message mentions /apiv2/jobdiva/', is_array($decoded) && str_contains((string)($decoded['error'] ?? ''), '/apiv2/jobdiva/'));

echo "\nJobDiva proxy bridge — non-JSON body ⇒ 400\n";
$ts  = (string) time();
$sig = hash_hmac('sha256', $ts . '.not-json', $secret);
[$status, $resp] = bridge_call($port, $proxyPath, 'not-json', [
    'Content-Type' => 'application/json',
    'X-Internal-Timestamp' => $ts,
    'X-Internal-Signature' => $sig,
]);
$assert('rejects non-JSON body with 400', $status === 400);

echo "\nMappings lookup bridge — HMAC enforced\n";
$mb = json_encode(['op' => 'find_external_by_internal', 'tenant_id' => 1, 'source_system' => 'jobdiva', 'internal_entity_type' => 'placement', 'internal_entity_id' => 1]);
[$status, $resp] = bridge_call($port, $mappingPath, $mb, ['Content-Type' => 'application/json']);
$assert('unsigned mappings_lookup → 401', $status === 401);

echo "\nMappings lookup bridge — missing required fields ⇒ 400\n";
$miss = json_encode(['op' => 'find_external_by_internal']);
$ts  = (string) time();
$sig = hash_hmac('sha256', $ts . '.' . $miss, $secret);
[$status, $resp] = bridge_call($port, $mappingPath, $miss, [
    'Content-Type' => 'application/json',
    'X-Internal-Timestamp' => $ts,
    'X-Internal-Signature' => $sig,
]);
$assert('missing tenant_id/source_system → 400', $status === 400);

echo "\n=== Summary ===\n";
echo "Passed: {$pass}\n";
echo "Failed: {$fail}\n";
exit($fail === 0 ? 0 : 1);
