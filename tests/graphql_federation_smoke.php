<?php
/**
 * Smoke test for the GraphQL Federation Phase 1 spike.
 *
 * Validates that the supergraph composition + both subgraphs are healthy:
 *   1. Subgraph TypeScript builds successfully (dist/ exists for both).
 *   2. The composition script runs without errors and produces a
 *      non-trivial supergraph.graphql.
 *   3. Both subgraphs can be started as Node processes and respond to
 *      a `{ __schema { types { name } } }` introspection query.
 *   4. Each subgraph implements the federation `_service { sdl }` query.
 *   5. The composed supergraph references both subgraphs and BOTH the
 *      CoreFlux `Placement` type AND the JobDiva `JobDivaAssignment`
 *      type (proving extend-type federation works).
 *   6. The MCP server boots without crashing in stdio mode.
 *
 * What this DOES NOT test
 * -----------------------
 * Full end-to-end queries that hit the live PHP API or JobDiva — those
 * require a live backend with credentials, which the smoke suite does
 * not have. Those are validated manually in production via the Apollo
 * Sandbox once the router is deployed.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$ROOT = '/app/graphql';

echo "GraphQL Federation — repo layout\n";
$assert('subgraph-coreflux/ exists',  is_dir("{$ROOT}/subgraph-coreflux"));
$assert('subgraph-jobdiva/ exists',   is_dir("{$ROOT}/subgraph-jobdiva"));
$assert('router/ exists',             is_dir("{$ROOT}/router"));
$assert('mcp-server/ exists',         is_dir("{$ROOT}/mcp-server"));

echo "\nGraphQL Federation — TypeScript builds\n";
$assert('subgraph-coreflux dist/index.js compiled', is_file("{$ROOT}/subgraph-coreflux/dist/index.js"));
$assert('subgraph-jobdiva  dist/index.js compiled', is_file("{$ROOT}/subgraph-jobdiva/dist/index.js"));
$assert('mcp-server        dist/index.js compiled', is_file("{$ROOT}/mcp-server/dist/index.js"));

echo "\nGraphQL Federation — supergraph composition\n";
// Re-run composition (idempotent) to validate the script still works.
$composeOut = shell_exec("cd {$ROOT}/router && node compose.mjs 2>&1");
$assert('compose.mjs runs without error', is_string($composeOut) && str_contains($composeOut, 'wrote'), 'output: ' . (string) $composeOut);
$superPath = "{$ROOT}/router/supergraph.graphql";
$assert('supergraph.graphql is on disk', is_file($superPath));
$super = is_file($superPath) ? (string) file_get_contents($superPath) : '';
$assert('supergraph SDL is non-empty', strlen($super) > 1000, 'length=' . strlen($super));
$assert('supergraph references coreflux subgraph', str_contains($super, 'CO_REFLUX') || str_contains($super, 'coreflux') || str_contains($super, 'subgraph-coreflux'));
$assert('supergraph references jobdiva subgraph',  str_contains($super, 'JOBDIVA') || str_contains($super, 'jobdiva') || str_contains($super, 'subgraph-jobdiva'));
$assert('supergraph contains Placement type',          str_contains($super, 'type Placement'));
$assert('supergraph contains JobDivaAssignment type',  str_contains($super, 'type JobDivaAssignment'));
$assert('supergraph contains Placement.jobDiva field', preg_match('/jobDiva\s*:\s*JobDivaAssignment/', $super) === 1);

echo "\nGraphQL Federation — subgraphs serve queries\n";
// Find two free ports.
$findPort = function (): int {
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $name = stream_socket_get_name($s, false); fclose($s);
    $parts = explode(':', (string) $name);
    return (int) end($parts);
};
$portCF = $findPort();
$portJD = $findPort();

$baseEnv = getenv();
if (!is_array($baseEnv)) $baseEnv = [];
$env = array_merge($baseEnv, [
    'JWT_SECRET'           => 'smoke-jwt-secret',
    'INTERNAL_HMAC_SECRET' => 'smoke-hmac-secret',
    'COREFLUX_API_BASE'    => 'http://localhost:9',  // never reached in this smoke
    'PATH'                 => getenv('PATH') ?: '/usr/bin:/bin',
]);
$descr = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];

$envCF = array_merge($env, ['PORT' => (string) $portCF]);
$envJD = array_merge($env, ['PORT' => (string) $portJD]);

$terminateProc = function (&$proc, ?array &$pipes = null): void {
    if (is_array($pipes)) {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) @fclose($pipe);
        }
    }
    if (is_resource($proc)) {
        $status = @proc_get_status($proc);
        if (DIRECTORY_SEPARATOR === '\\' && is_array($status) && !empty($status['pid'])) {
            @exec('taskkill /F /T /PID ' . (int) $status['pid'] . ' 2>NUL');
        } else {
            @proc_terminate($proc);
        }
        @proc_close($proc);
    }
    $proc = null;
};

$procCF = proc_open('node dist/index.js', $descr, $p1, "{$ROOT}/subgraph-coreflux", $envCF);
$procJD = proc_open('node dist/index.js', $descr, $p2, "{$ROOT}/subgraph-jobdiva",  $envJD);
register_shutdown_function(function () use (&$procCF, &$procJD, &$p1, &$p2, $terminateProc) {
    $terminateProc($procCF, $p1);
    $terminateProc($procJD, $p2);
});

// Wait up to 6s for both to listen.
$waitListen = function (int $port): bool {
    $deadline = microtime(true) + 6;
    while (microtime(true) < $deadline) {
        $c = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 0.2);
        if ($c) { fclose($c); return true; }
        usleep(150_000);
    }
    return false;
};
$assert('subgraph-coreflux listening on its port', $waitListen($portCF));
$assert('subgraph-jobdiva  listening on its port', $waitListen($portJD));

$post = function (int $port, string $payload): array {
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json",
            'content' => $payload,
            'ignore_errors' => true,
            'timeout' => 5,
        ],
    ]);
    $resp = @file_get_contents("http://127.0.0.1:{$port}/", false, $ctx);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0], $m)) {
        $status = (int) $m[1];
    }
    return [$status, (string) $resp];
};

[$statusCF, $bodyCF] = $post($portCF, json_encode(['query' => '{ __schema { types { name } } }']));
$assert('subgraph-coreflux returns HTTP 200 on introspection', $statusCF === 200, "got {$statusCF}");
$j = json_decode($bodyCF, true);
$cfTypes = is_array($j) ? array_column((array)($j['data']['__schema']['types'] ?? []), 'name') : [];
$assert('coreflux exposes Placement',            in_array('Placement', $cfTypes, true));
$assert('coreflux exposes Person',               in_array('Person', $cfTypes, true));
$assert('coreflux exposes Company',              in_array('Company', $cfTypes, true));

[$statusJD, $bodyJD] = $post($portJD, json_encode(['query' => '{ __schema { types { name } } }']));
$assert('subgraph-jobdiva  returns HTTP 200 on introspection', $statusJD === 200, "got {$statusJD}");
$j = json_decode($bodyJD, true);
$jdTypes = is_array($j) ? array_column((array)($j['data']['__schema']['types'] ?? []), 'name') : [];
$assert('jobdiva  exposes JobDivaAssignment',    in_array('JobDivaAssignment', $jdTypes, true));
$assert('jobdiva  exposes JobDivaJob',           in_array('JobDivaJob', $jdTypes, true));
$assert('jobdiva  exposes JobDivaCandidate',     in_array('JobDivaCandidate', $jdTypes, true));
$assert('jobdiva  exposes JobDivaCustomer',      in_array('JobDivaCustomer', $jdTypes, true));

echo "\nGraphQL Federation — _service { sdl } shape (Apollo Federation spec)\n";
[$status, $body] = $post($portCF, json_encode(['query' => '{ _service { sdl } }']));
$assert('coreflux serves _service { sdl }', $status === 200 && str_contains($body, 'type Placement'));
[$status, $body] = $post($portJD, json_encode(['query' => '{ _service { sdl } }']));
$assert('jobdiva  serves _service { sdl }', $status === 200 && str_contains($body, 'type JobDivaAssignment'));

$terminateProc($procCF, $p1);
$terminateProc($procJD, $p2);

echo "\nGraphQL MCP server — smoke boot (stdio transport)\n";
// Spawn the MCP server in stdio mode, send it the initialize RPC, expect
// a JSON-RPC response back on stdout, then kill it. This is the minimum
// to prove the server protocol layer works without depending on a router.
$mcpDescr = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
$mcpEnv   = array_merge($env, ['ROUTER_URL' => "http://127.0.0.1:{$portCF}/"]);
$mcpProc  = proc_open('node dist/index.js', $mcpDescr, $mcpPipes, "{$ROOT}/mcp-server", $mcpEnv);
$assert('mcp-server process started', is_resource($mcpProc));
if (is_resource($mcpProc)) {
    $init = json_encode([
        'jsonrpc' => '2.0',
        'id'      => 1,
        'method'  => 'initialize',
        'params'  => [
            'protocolVersion' => '2025-06-18',
            'capabilities'    => new \stdClass(),
            'clientInfo'      => ['name' => 'smoke', 'version' => '0.0.1'],
        ],
    ]) . "\n";
    fwrite($mcpPipes[0], $init);
    fflush($mcpPipes[0]);
    stream_set_blocking($mcpPipes[1], false);
    $deadline = microtime(true) + 4;
    $stdout = '';
    while (microtime(true) < $deadline) {
        $chunk = fread($mcpPipes[1], 4096);
        if ($chunk !== false && $chunk !== '') {
            $stdout .= $chunk;
            if (str_contains($stdout, '"jsonrpc"') && str_contains($stdout, "\n")) break;
        }
        usleep(100_000);
    }
    $terminateProc($mcpProc, $mcpPipes);
    $assert('mcp-server responded to initialize over stdio', str_contains($stdout, '"jsonrpc"') && str_contains($stdout, '"result"'), 'stdout=' . substr($stdout, 0, 300));
    $assert('mcp-server announced server name "coreflux-graphql"', str_contains($stdout, 'coreflux-graphql'));
}

echo "\n=== Summary ===\n";
echo "Passed: {$pass}\n";
echo "Failed: {$fail}\n";
exit($fail === 0 ? 0 : 1);
