<?php
/**
 * MCP ⇆ Apollo Router e2e smoke.
 *
 * Boots the same stack as graphql_router_e2e_smoke.php, then ALSO
 * boots the MCP server in HTTP transport mode and proves that a
 * standard MCP `tools/call` flow returns the same federated data
 * an AI client would see.
 *
 *   mock-php → subgraph-coreflux ─┐
 *                                 ├── Apollo Router :ROUTE
 *              subgraph-jobdiva ──┘        ▲
 *                                          │
 *                                  mcp-server HTTP :MCP
 *                                          ▲
 *                                          │
 *                                       this test
 *
 * What this proves
 *   1. MCP server can be reached over HTTP/SSE on a free port.
 *   2. tools/list returns the four registered tools.
 *   3. tools/call coreflux_placement(id="17") routes through the
 *      router and returns the merged federated payload.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$ROOT = '/app/graphql';

$routerBin = trim((string) shell_exec('command -v router 2>/dev/null'));
if ($routerBin === '' || !is_executable($routerBin)) {
    echo "SKIP: Apollo Router binary not installed; see graphql_router_e2e_smoke.php for install hint.\n";
    exit(0);
}

function find_free_port(): int {
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $name = stream_socket_get_name($s, false); fclose($s);
    $parts = explode(':', (string) $name);
    return (int) end($parts);
}
function wait_listen(int $port, float $timeout = 8.0): bool {
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        $c = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 0.2);
        if ($c) { fclose($c); return true; }
        usleep(150_000);
    }
    return false;
}

$portPHP    = find_free_port();
$portCF     = find_free_port();
$portJD     = find_free_port();
$portRouter = find_free_port();
$portMCP    = find_free_port();

echo "Ports: php={$portPHP} cf={$portCF} jd={$portJD} router={$portRouter} mcp={$portMCP}\n";

$procs = [];
register_shutdown_function(function () use (&$procs) {
    // Two-phase teardown: SIGTERM, then SIGKILL on PID + any children.
    // proc_terminate alone is unreliable for grandchildren (router spawns
    // worker threads; php -S spawns request handlers).
    foreach ($procs as $p) {
        if (!is_resource($p)) continue;
        $st = @proc_get_status($p);
        $pid = $st['pid'] ?? 0;
        if ($pid > 0) {
            @shell_exec("pkill -TERM -P {$pid} 2>/dev/null; kill -TERM {$pid} 2>/dev/null");
        }
    }
    usleep(300_000);
    foreach ($procs as $p) {
        if (!is_resource($p)) continue;
        $st = @proc_get_status($p);
        $pid = $st['pid'] ?? 0;
        if ($pid > 0) {
            @shell_exec("pkill -KILL -P {$pid} 2>/dev/null; kill -KILL {$pid} 2>/dev/null");
        }
        @proc_close($p);
    }
});
$descr = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];

// 1. mock-php
$phpRoot = $ROOT . '/mock-php';
$procs['php'] = proc_open(
    "php -S 127.0.0.1:{$portPHP} -t {$phpRoot} {$phpRoot}/index.php",
    $descr, $phpPipes, $phpRoot, []
);
$assert('mock-php listening', wait_listen($portPHP));

// 2. Recompose supergraph at smoke ports.
$env = [
    'JWT_SECRET'              => 'smoke-jwt-secret',
    'INTERNAL_HMAC_SECRET'    => 'smoke-hmac',
    'COREFLUX_API_BASE'       => "http://127.0.0.1:{$portPHP}",
    'SUBGRAPH_COREFLUX_URL'   => "http://127.0.0.1:{$portCF}/",
    'SUBGRAPH_JOBDIVA_URL'    => "http://127.0.0.1:{$portJD}/",
    'DEFAULT_TENANT_ID'       => '1',
    'PATH'                    => getenv('PATH') ?: '/usr/bin:/bin:/usr/local/bin',
];
$cp = proc_open('node compose.mjs', $descr, $cpPipes, "{$ROOT}/router", $env);
if (is_resource($cp)) { stream_get_contents($cpPipes[1]); stream_get_contents($cpPipes[2]); proc_close($cp); }
$assert('supergraph recomposed at smoke ports',
    str_contains((string) file_get_contents("{$ROOT}/router/supergraph.graphql"), "127.0.0.1:{$portCF}"));

// 3. Subgraphs
$envCF = $env + ['PORT' => (string) $portCF];
$envJD = $env + ['PORT' => (string) $portJD];
$procs['coreflux'] = proc_open('node dist/index.js', $descr, $pCF, "{$ROOT}/subgraph-coreflux", $envCF);
$procs['jobdiva']  = proc_open('node dist/index.js', $descr, $pJD, "{$ROOT}/subgraph-jobdiva",  $envJD);
$assert('coreflux subgraph listening', wait_listen($portCF));
$assert('jobdiva  subgraph listening', wait_listen($portJD));

// 4. Apollo Router (smoke config — no auth)
$routerEnv = $env + ['ROUTER_LISTEN' => "127.0.0.1:{$portRouter}"];
$procs['router'] = proc_open(
    "router --config {$ROOT}/router/router.smoke.yaml --supergraph {$ROOT}/router/supergraph.graphql --log warn",
    $descr, $pRouter, $ROOT, $routerEnv
);
$assert('router listening', wait_listen($portRouter, 12.0));

// 5. MCP server in HTTP transport mode, pointed at the live router.
$mcpEnv = $env + [
    'MCP_TRANSPORT' => 'http',
    'MCP_HTTP_PORT' => (string) $portMCP,
    'ROUTER_URL'    => "http://127.0.0.1:{$portRouter}/",
];
$procs['mcp'] = proc_open('node dist/index.js', $descr, $pMcp, "{$ROOT}/mcp-server", $mcpEnv);
$assert('mcp-server listening', wait_listen($portMCP));

// ---------------------------------------------------------------------
// MCP wire protocol — initialize → tools/list → tools/call
// ---------------------------------------------------------------------
$sessionId = null;
$mcpRpc = function (array $payload, ?string &$sessionId = null) use ($portMCP): array {
    $hdrs = "Content-Type: application/json\r\nAccept: application/json, text/event-stream\r\n";
    if ($sessionId !== null) $hdrs .= "Mcp-Session-Id: {$sessionId}\r\n";
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => $hdrs,
            'content'       => json_encode($payload),
            'ignore_errors' => true,
            'timeout'       => 15,
        ],
    ]);
    $body = @file_get_contents("http://127.0.0.1:{$portMCP}/mcp", false, $ctx);
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('/^mcp-session-id:\s*(.+)$/i', $h, $m)) $sessionId = trim($m[1]);
    }
    // MCP HTTP responses can be JSON or text/event-stream framed.
    // For the smoke we only need the first JSON object, regardless of frame.
    if (preg_match('/(\{.*"jsonrpc".*\})/s', (string) $body, $m)) {
        $obj = json_decode($m[1], true);
        if (is_array($obj)) return $obj;
    }
    return ['raw' => (string) $body];
};

$initResp = $mcpRpc([
    'jsonrpc' => '2.0',
    'id'      => 1,
    'method'  => 'initialize',
    'params'  => [
        'protocolVersion' => '2025-06-18',
        'capabilities'    => new \stdClass(),
        'clientInfo'      => ['name' => 'router-mcp-smoke', 'version' => '0.0.1'],
    ],
], $sessionId);
$assert('mcp initialize succeeded',
    isset($initResp['result']) && isset($initResp['result']['serverInfo']['name']),
    'resp=' . json_encode($initResp));
$assert('mcp server identifies as coreflux-graphql',
    ($initResp['result']['serverInfo']['name'] ?? '') === 'coreflux-graphql');
$assert('mcp session id returned via header', is_string($sessionId) && $sessionId !== '');

// initialized notification (no response expected — but we still POST it).
$mcpRpc(['jsonrpc' => '2.0', 'method' => 'notifications/initialized', 'params' => new \stdClass()], $sessionId);

$listResp = $mcpRpc(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => new \stdClass()], $sessionId);
$tools = (array) ($listResp['result']['tools'] ?? []);
$toolNames = array_map(static fn($t) => (string)($t['name'] ?? ''), $tools);
$assert('tools/list returned >=4 tools', count($tools) >= 4);
$assert('coreflux_query tool registered',      in_array('coreflux_query', $toolNames, true));
$assert('coreflux_introspect tool registered', in_array('coreflux_introspect', $toolNames, true));
$assert('coreflux_placement tool registered',  in_array('coreflux_placement', $toolNames, true));
$assert('coreflux_placements tool registered', in_array('coreflux_placements', $toolNames, true));

// tools/call coreflux_placement(id="17") — proves MCP → router → both subgraphs.
$callResp = $mcpRpc([
    'jsonrpc' => '2.0',
    'id'      => 3,
    'method'  => 'tools/call',
    'params'  => [
        'name'      => 'coreflux_placement',
        'arguments' => ['id' => '17'],
    ],
], $sessionId);

$content = $callResp['result']['content'][0]['text'] ?? '';
$assert('coreflux_placement returned text content', is_string($content) && $content !== '',
    'resp=' . substr(json_encode($callResp), 0, 400));

$decoded = json_decode($content, true);
$p = is_array($decoded) ? ($decoded['data']['placement'] ?? null) : null;

echo "\nMCP tools/call response preview:\n  " . substr($content, 0, 400) . "\n\n";

$assert('placement is in MCP response',                is_array($p));
$assert('placement.id round-trips through MCP',        is_array($p) && (string) ($p['id'] ?? '') === '17');
$assert('placement.title resolved (coreflux subgraph)', is_array($p) && ($p['title'] ?? null) === 'Senior Service Desk Analyst');
$assert('placement.person.firstName resolved',         is_array($p) && ($p['person']['firstName'] ?? null) === 'Alex');
$assert('placement.endClient.name resolved',           is_array($p) && ($p['endClient']['name'] ?? null) === 'Acme Health Systems');
$assert('jobDiva resolved via federation',             is_array($p) && is_array($p['jobDiva'] ?? null));
$assert('jobDiva.job.title resolved (jobdiva subgraph)',
    is_array($p) && (($p['jobDiva']['job']['title'] ?? null) === 'Service Desk Analyst'));
$assert('jobDiva.candidate.firstName resolved',
    is_array($p) && (($p['jobDiva']['candidate']['firstName'] ?? null) === 'Alex'));
$assert('jobDiva.bill.rate resolved',
    is_array($p) && (float)($p['jobDiva']['bill']['rate'] ?? 0) === 110.0);

echo "\n=== Summary ===\n";
echo "Passed: {$pass}\n";
echo "Failed: {$fail}\n";
exit($fail === 0 ? 0 : 1);
