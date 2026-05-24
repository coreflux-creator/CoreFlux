<?php
/**
 * Smoke test for /app/graphql/router/router.yaml (PRODUCTION config).
 *
 * Boots the Apollo Router with the production config + the composed
 * supergraph schema, on free ports, and asserts:
 *   - the binary actually accepts the YAML (no schema errors at boot)
 *   - the router listens on its public port
 *   - the health endpoint at 127.0.0.1:8088/health returns 200
 *   - introspection still works (the MCP server depends on this)
 *
 * This catches config drift between commits without having to roll out
 * to staging.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$routerBin = trim((string) shell_exec('command -v router 2>/dev/null'));
if ($routerBin === '' || !is_executable($routerBin)) {
    echo "SKIP: Apollo Router binary not installed.\n";
    exit(0);
}

$cfg   = '/app/graphql/router/router.yaml';
$super = '/app/graphql/router/supergraph.graphql';
$assert('production router.yaml exists',  is_file($cfg));
$assert('supergraph.graphql exists',      is_file($super));

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

$portRouter = find_free_port();
$portHealth = find_free_port();

$descr = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
$env   = [
    'ROUTER_LISTEN'    => "127.0.0.1:{$portRouter}",
    'PATH'             => getenv('PATH') ?: '/usr/bin:/bin:/usr/local/bin',
    // Production config hard-codes the health listen address; override
    // via a tiny inline patch file to avoid the 8088 collision when
    // another router process is running on the same box.
];

// Patch the production config in-memory to remap the health listener
// to a free port, otherwise back-to-back smoke runs collide on :8088.
$cfgYaml = (string) file_get_contents($cfg);
$cfgYaml = preg_replace('/listen:\s*127\.0\.0\.1:8088/', "listen: 127.0.0.1:{$portHealth}", $cfgYaml) ?? $cfgYaml;
$tmpCfg = tempnam(sys_get_temp_dir(), 'router-cfg-') . '.yaml';
file_put_contents($tmpCfg, $cfgYaml);
register_shutdown_function(function () use ($tmpCfg) { @unlink($tmpCfg); });

$proc = proc_open(
    "router --config {$tmpCfg} --supergraph {$super} --log warn --anonymous-telemetry-disabled",
    $descr, $pipes, '/app', $env
);
register_shutdown_function(function () use (&$proc) {
    if (is_resource($proc)) {
        $st = @proc_get_status($proc);
        $pid = $st['pid'] ?? 0;
        if ($pid > 0) @shell_exec("pkill -KILL -P {$pid} 2>/dev/null; kill -KILL {$pid} 2>/dev/null");
        @proc_close($proc);
    }
});

$assert('router process started', is_resource($proc));
$bootOK = wait_listen($portRouter, 10.0);

if (!$bootOK) {
    // Capture stderr for the failure message.
    stream_set_blocking($pipes[2], false);
    $err = (string) stream_get_contents($pipes[2]);
    echo "  router stderr: " . substr($err, 0, 800) . "\n";
}
$assert('router listening on its public port',  $bootOK);
$assert('router health endpoint responding',     wait_listen($portHealth, 3.0));

if ($bootOK) {
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode(['query' => '{ __schema { queryType { name } } }']),
            'ignore_errors' => true,
            'timeout' => 5,
        ],
    ]);
    $resp = (string) @file_get_contents("http://127.0.0.1:{$portRouter}/", false, $ctx);
    $decoded = json_decode($resp, true);
    $assert('introspection works through prod config',
        is_array($decoded) && ($decoded['data']['__schema']['queryType']['name'] ?? '') === 'Query',
        'resp=' . substr($resp, 0, 300));

    // Health endpoint should return 200 with JSON body.
    $h = (string) @file_get_contents("http://127.0.0.1:{$portHealth}/health", false,
        stream_context_create(['http' => ['method' => 'GET', 'ignore_errors' => true, 'timeout' => 3]])
    );
    $hd = json_decode($h, true);
    $assert('health endpoint returns JSON status', is_array($hd) && isset($hd['status']),
        'body=' . substr($h, 0, 200));
}

echo "\n=== Summary ===\n";
echo "Passed: {$pass}\n";
echo "Failed: {$fail}\n";
exit($fail === 0 ? 0 : 1);
