<?php
/**
 * End-to-end smoke for the GraphQL Federation, INCLUDING the Apollo Router.
 *
 * This complements graphql_federation_smoke.php (which proves the
 * subgraphs work in isolation). Here we boot the full stack:
 *
 *   mock-php  :MP  ← fixture data
 *      ▲
 *      │
 *   subgraph-coreflux :SC ─┐
 *                          ├── Apollo Router :ROUTE ── e2e federated query
 *   subgraph-jobdiva  :SJ ─┘
 *
 * and run a single federated query that touches BOTH subgraphs:
 *
 *   query {
 *     placement(id:"17") {
 *       title          # coreflux
 *       person { firstName }   # coreflux
 *       endClient { name }     # coreflux
 *       rates { billRate }     # coreflux
 *       jobDiva {              # jobdiva (via @key extend)
 *         externalId
 *         job { title }
 *         candidate { firstName }
 *         customer { name }
 *         bill { rate currency }
 *       }
 *     }
 *   }
 *
 * If this returns a single merged JSON with both subgraphs' data,
 * the Federation Phase 1 spike is proven end-to-end.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$ROOT = '/app/graphql';

// Verify the router binary is available — if not, skip cleanly so CI on
// hosts without the binary doesn't break.
$routerBin = trim((string) shell_exec('command -v router 2>/dev/null'));
if ($routerBin === '' || !is_executable($routerBin)) {
    echo "SKIP: Apollo Router binary not installed at \$PATH/router. ";
    echo "Install via: curl -sSL https://router.apollo.dev/download/nix/v1.55.0 | sh && sudo mv router /usr/local/bin/\n";
    exit(0);
}
echo "Router binary: {$routerBin}\n";

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------
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

echo "Ports: php={$portPHP} coreflux={$portCF} jobdiva={$portJD} router={$portRouter}\n";

// ---------------------------------------------------------------------
// Track processes so the shutdown hook can clean them all up.
// ---------------------------------------------------------------------
$procs = [];
register_shutdown_function(function () use (&$procs) {
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

// ---------------------------------------------------------------------
// 1. Boot the mock PHP backend
// ---------------------------------------------------------------------
$phpRoot = $ROOT . '/mock-php';
$procs['php'] = proc_open(
    "php -S 127.0.0.1:{$portPHP} -t {$phpRoot} {$phpRoot}/index.php",
    $descr, $phpPipes, $phpRoot, []
);
$assert('mock-php process started', is_resource($procs['php']));
$assert('mock-php listening', wait_listen($portPHP));

// Quick fixture sanity check.
$ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 3, 'ignore_errors' => true]]);
$resp = @file_get_contents("http://127.0.0.1:{$portPHP}/api/placements/placements?id=17", false, $ctx);
$j = json_decode((string) $resp, true);
$assert('mock-php returns fixture placement id=17',
    is_array($j) && (int) ($j['placement']['id'] ?? 0) === 17);

// ---------------------------------------------------------------------
// 2. Re-compose the supergraph with subgraph URLs at our smoke ports.
//    compose.mjs reads SUBGRAPH_*_URL from env.
// ---------------------------------------------------------------------
$env = [
    'JWT_SECRET'              => 'smoke-jwt-secret',
    'INTERNAL_HMAC_SECRET'    => 'smoke-hmac',
    'COREFLUX_API_BASE'       => "http://127.0.0.1:{$portPHP}",
    'SUBGRAPH_COREFLUX_URL'   => "http://127.0.0.1:{$portCF}/",
    'SUBGRAPH_JOBDIVA_URL'    => "http://127.0.0.1:{$portJD}/",
    'PATH'                    => getenv('PATH') ?: '/usr/bin:/bin:/usr/local/bin',
    'DEFAULT_TENANT_ID'       => '1',
];
$composeProc = proc_open("node compose.mjs", $descr, $cp, "{$ROOT}/router", $env);
$composeOut = '';
$composeErr = '';
if (is_resource($composeProc)) {
    $composeOut = (string) stream_get_contents($cp[1]);
    $composeErr = (string) stream_get_contents($cp[2]);
    proc_close($composeProc);
}
$assert('supergraph re-composed with smoke ports',
    str_contains($composeOut, 'wrote') && is_file("{$ROOT}/router/supergraph.graphql"),
    "out={$composeOut} err={$composeErr}");
$sg = (string) file_get_contents("{$ROOT}/router/supergraph.graphql");
$assert('composed supergraph points coreflux subgraph at smoke port',
    str_contains($sg, "127.0.0.1:{$portCF}"));
$assert('composed supergraph points jobdiva subgraph at smoke port',
    str_contains($sg, "127.0.0.1:{$portJD}"));

// ---------------------------------------------------------------------
// 3. Boot both subgraphs pointed at mock-php
// ---------------------------------------------------------------------
$envCF = $env + ['PORT' => (string) $portCF];
$envJD = $env + ['PORT' => (string) $portJD];

$procs['coreflux'] = proc_open('node dist/index.js', $descr, $pCF, "{$ROOT}/subgraph-coreflux", $envCF);
$procs['jobdiva']  = proc_open('node dist/index.js', $descr, $pJD, "{$ROOT}/subgraph-jobdiva",  $envJD);
$assert('coreflux subgraph started', is_resource($procs['coreflux']));
$assert('jobdiva  subgraph started', is_resource($procs['jobdiva']));
$assert('coreflux subgraph listening', wait_listen($portCF));
$assert('jobdiva  subgraph listening', wait_listen($portJD));

// ---------------------------------------------------------------------
// 4. Boot Apollo Router pointed at the composed supergraph
// ---------------------------------------------------------------------
$routerEnv = $env + ['ROUTER_LISTEN' => "127.0.0.1:{$portRouter}"];
$procs['router'] = proc_open(
    "router --config {$ROOT}/router/router.smoke.yaml --supergraph {$ROOT}/router/supergraph.graphql --log warn",
    $descr,
    $pRouter,
    $ROOT,
    $routerEnv
);
$assert('router process started', is_resource($procs['router']));
$routerUp = wait_listen($portRouter, 12.0);
if (!$routerUp) {
    $stderr = '';
    if (isset($pRouter[2])) {
        stream_set_blocking($pRouter[2], false);
        $stderr = (string) stream_get_contents($pRouter[2]);
    }
    echo "  router stderr: " . substr($stderr, 0, 800) . "\n";
}
$assert('router listening on its port', $routerUp);

// ---------------------------------------------------------------------
// 5. Run the federated query end-to-end
// ---------------------------------------------------------------------
$query = '
query Smoke($id: ID!) {
  placement(id: $id) {
    id
    title
    status
    startDate
    person   { id firstName lastName emailPrimary }
    endClient { id name billingAddress { city state } }
    rates    { billRate billRateUnit payRate currency }
    externalMappings { sourceSystem externalId }
    jobDiva {
      externalId
      refNumber
      startStatus
      job       { externalId title department }
      candidate { externalId firstName lastName email }
      customer  { externalId name }
      contact   { externalId displayName email }
      bill      { rate payRate currency }
    }
  }
}';
$payload = json_encode(['query' => $query, 'variables' => ['id' => '17']]);
$ctx = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
        'content' => $payload,
        'ignore_errors' => true,
        'timeout' => 10,
    ],
]);
$resp = @file_get_contents("http://127.0.0.1:{$portRouter}/", false, $ctx);
$status = 0;
if (isset($http_response_header[0]) && preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0], $m)) {
    $status = (int) $m[1];
}
$result = json_decode((string) $resp, true);

echo "\nFederated query response (truncated):\n  " . substr((string) $resp, 0, 800) . "\n\n";

$assert('router returned HTTP 200',           $status === 200, "status={$status}");
$assert('response decoded as JSON',           is_array($result));
$assert('no GraphQL errors in response',      !isset($result['errors']) || empty($result['errors']),
    isset($result['errors']) ? json_encode($result['errors']) : '');

$p = is_array($result) ? ($result['data']['placement'] ?? null) : null;
$assert('data.placement present',                          is_array($p));
$assert('placement.id matches request',                    is_array($p) && (string) $p['id'] === '17');
$assert('placement.title from coreflux subgraph',          is_array($p) && $p['title'] === 'Senior Service Desk Analyst');
$assert('placement.person.firstName from coreflux subgraph', is_array($p) && ($p['person']['firstName'] ?? null) === 'Alex');
$assert('placement.endClient.name from coreflux subgraph', is_array($p) && ($p['endClient']['name'] ?? null) === 'Acme Health Systems');
$assert('placement.rates.billRate from coreflux subgraph', is_array($p) && (string) ($p['rates']['billRate'] ?? '') === '110.00');
$assert('placement.externalMappings present',              is_array($p) && !empty($p['externalMappings']));
$assert('mapping points at jobdiva start 5581186',         is_array($p) && ($p['externalMappings'][0]['externalId'] ?? '') === '5581186');

$jd = is_array($p) ? ($p['jobDiva'] ?? null) : null;
$assert('placement.jobDiva resolved via jobdiva subgraph', is_array($jd));
$assert('jobDiva.externalId is 5581186',                   is_array($jd) && (string) ($jd['externalId'] ?? '') === '5581186');
$assert('jobDiva.startStatus from JobDiva fixture',        is_array($jd) && ($jd['startStatus'] ?? null) === 'Offer Accepted');
$assert('jobDiva.job.title resolved',                      is_array($jd) && ($jd['job']['title'] ?? null) === 'Service Desk Analyst');
$assert('jobDiva.job.department resolved',                 is_array($jd) && ($jd['job']['department'] ?? null) === 'IT Operations');
$assert('jobDiva.candidate.firstName resolved',            is_array($jd) && ($jd['candidate']['firstName'] ?? null) === 'Alex');
$assert('jobDiva.customer.name resolved',                  is_array($jd) && ($jd['customer']['name'] ?? null) === 'Acme Health Systems');
$assert('jobDiva.contact.displayName resolved',            is_array($jd) && ($jd['contact']['displayName'] ?? null) === 'Jane Approver');
$assert('jobDiva.bill.rate from BI feed',                  is_array($jd) && (float) ($jd['bill']['rate'] ?? 0) === 110.0);
$assert('jobDiva.bill.currency = USD',                     is_array($jd) && ($jd['bill']['currency'] ?? null) === 'USD');

echo "\n=== Summary ===\n";
echo "Passed: {$pass}\n";
echo "Failed: {$fail}\n";
exit($fail === 0 ? 0 : 1);
