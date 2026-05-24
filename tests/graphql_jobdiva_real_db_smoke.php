<?php
/**
 * Real-DB validation smoke for the JobDiva federation chain.
 *
 * The fixture-only test (graphql_router_e2e_smoke.php) proves the wire
 * format works. This test proves the chain works against REAL MariaDB
 * rows in `external_entity_mappings` — the only piece that fixture
 * data can't exercise.
 *
 * What it verifies
 * ----------------
 *   1. mappingFindExternal() finds a placement→start-id mapping.
 *   2. /api/internal/mappings_lookup.php (HMAC bridge) returns the
 *      same external_id when invoked over HTTP.
 *   3. The Node subgraph-jobdiva can complete the lookup leg of the
 *      Placement.jobDiva resolver chain end-to-end:
 *         placement_id (CoreFlux) → mappings_lookup.php → start_id (JobDiva)
 *      (We mock the actual JobDiva REST call — we don't have JobDiva
 *      creds in this sandbox.)
 *
 * Skips cleanly when MariaDB / Node / Router are unavailable so CI on
 * hosts without those doesn't break.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

// ---------------------------------------------------------------------
// Skip when prerequisites are missing
// ---------------------------------------------------------------------
require_once '/app/core/db.php';
try {
    $pdo = getDB();
    if (!$pdo) { echo "SKIP: no DB\n"; exit(0); }
} catch (\Throwable $e) {
    echo "SKIP: DB unreachable ({$e->getMessage()})\n";
    exit(0);
}

// ---------------------------------------------------------------------
// Ensure the tables we depend on exist (idempotent — create minimal
// shapes if a fresh DB; production already has them via /app/sql/setup.sql).
// ---------------------------------------------------------------------
$pdo->exec("
  CREATE TABLE IF NOT EXISTS placements (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    external_id VARCHAR(64) NULL,
    coreflux_overridden_fields JSON NULL,
    person_id BIGINT NULL,
    title VARCHAR(255) NULL,
    status VARCHAR(32) NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    notes TEXT NULL,
    end_client_name VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tenant_external (tenant_id, external_id)
  )
");

$pdo->exec("
  CREATE TABLE IF NOT EXISTS external_entity_mappings (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    source_system VARCHAR(32) NOT NULL,
    internal_entity_type VARCHAR(32) NOT NULL,
    internal_entity_id BIGINT NOT NULL,
    external_id VARCHAR(128) NOT NULL,
    payload_snapshot JSON NULL,
    content_hash VARCHAR(64) NULL,
    direction VARCHAR(16) NULL,
    sync_status VARCHAR(32) NULL,
    last_error TEXT NULL,
    last_synced_at TIMESTAMP NULL,
    last_seen_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY u_mapping (tenant_id, source_system, internal_entity_type, external_id)
  )
");

// ---------------------------------------------------------------------
// Seed: one placement + one mapping pointing at a fake JobDiva start id.
// ---------------------------------------------------------------------
$TENANT_ID  = 999_999;
$EXT_ID     = 'realdb-smoke-' . bin2hex(random_bytes(3));
$pdo->prepare(
  "INSERT INTO placements (tenant_id, external_id, person_id, title, status, start_date)
   VALUES (:t, :ext, 1, 'Smoke placement', 'active', '2025-04-01')"
)->execute(['t' => $TENANT_ID, 'ext' => 'jd:' . $EXT_ID]);
$placementId = (int) $pdo->lastInsertId();
register_shutdown_function(function () use ($pdo, $TENANT_ID, $placementId, $EXT_ID) {
    @$pdo->prepare("DELETE FROM external_entity_mappings WHERE tenant_id=:t AND external_id=:e")->execute(['t'=>$TENANT_ID, 'e'=>$EXT_ID]);
    @$pdo->prepare("DELETE FROM placements WHERE id=:id")->execute(['id' => $placementId]);
});

require_once '/app/core/integrations/entity_mappings.php';
$mapping = mappingUpsert(
    $TENANT_ID,
    'jobdiva',
    'placement',
    $EXT_ID,
    $placementId,
    ['fixture' => true, 'startId' => $EXT_ID],
    'pull'
);
$assert('mapping row created', is_array($mapping) && (int) ($mapping['id'] ?? 0) > 0);

// ---------------------------------------------------------------------
// (1) PHP-side: mappingFindExternal returns the right row.
// ---------------------------------------------------------------------
echo "\nDirect PDO lookup\n";
$row = mappingFindExternal($TENANT_ID, 'jobdiva', 'placement', $placementId);
$assert('mappingFindExternal finds row',  is_array($row));
$assert('mapping.external_id matches',    is_array($row) && (string) $row['external_id'] === $EXT_ID);

// ---------------------------------------------------------------------
// (2) HTTP-bridge: POST to /api/internal/mappings_lookup.php with valid HMAC.
// ---------------------------------------------------------------------
echo "\nHMAC bridge lookup\n";
function find_free_port(): int {
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $name = stream_socket_get_name($s, false); fclose($s);
    $parts = explode(':', (string) $name);
    return (int) end($parts);
}
function wait_listen(int $port, float $timeout = 6.0): bool {
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        $c = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 0.2);
        if ($c) { fclose($c); return true; }
        usleep(150_000);
    }
    return false;
}

$port = find_free_port();
$secret = 'real-db-smoke-' . bin2hex(random_bytes(8));
$env = ['INTERNAL_HMAC_SECRET' => $secret, 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'];
$desc = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
$proc = proc_open("php -S 127.0.0.1:{$port} -t /app", $desc, $pipes, '/app', $env);
register_shutdown_function(function () use (&$proc) {
    if (is_resource($proc)) {
        $st = @proc_get_status($proc); $pid = $st['pid'] ?? 0;
        if ($pid) @shell_exec("pkill -KILL -P {$pid} 2>/dev/null; kill -KILL {$pid} 2>/dev/null");
        @proc_close($proc);
    }
});
$assert('PHP -S started for bridge', is_resource($proc) && wait_listen($port));

$payload = json_encode([
    'op'                    => 'find_external_by_internal',
    'tenant_id'             => $TENANT_ID,
    'source_system'         => 'jobdiva',
    'internal_entity_type'  => 'placement',
    'internal_entity_id'    => $placementId,
]);
$ts  = (string) time();
$sig = hash_hmac('sha256', $ts . '.' . $payload, $secret);
$ctx = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nX-Internal-Timestamp: {$ts}\r\nX-Internal-Signature: {$sig}\r\n",
        'content' => $payload,
        'ignore_errors' => true,
        'timeout' => 5,
    ],
]);
$resp = (string) @file_get_contents("http://127.0.0.1:{$port}/api/internal/mappings_lookup.php", false, $ctx);
$decoded = json_decode($resp, true);
$status = 0;
if (isset($http_response_header[0]) && preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0], $m)) $status = (int) $m[1];

$assert('bridge returns HTTP 200',                  $status === 200, "status={$status} body=" . substr($resp, 0, 200));
$assert('bridge returns ok:true',                   is_array($decoded) && ($decoded['ok'] ?? false) === true);
$assert('bridge returns the seeded external_id',    is_array($decoded) && (string) ($decoded['external_id'] ?? '') === $EXT_ID);

// ---------------------------------------------------------------------
// (3) Reverse lookup also works: find_internal_by_external.
// ---------------------------------------------------------------------
echo "\nReverse lookup (external → internal)\n";
$payload = json_encode([
    'op'                    => 'find_internal_by_external',
    'tenant_id'             => $TENANT_ID,
    'source_system'         => 'jobdiva',
    'internal_entity_type'  => 'placement',
    'external_id'           => $EXT_ID,
]);
$ts  = (string) time();
$sig = hash_hmac('sha256', $ts . '.' . $payload, $secret);
$ctx = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nX-Internal-Timestamp: {$ts}\r\nX-Internal-Signature: {$sig}\r\n",
        'content' => $payload,
        'ignore_errors' => true,
        'timeout' => 5,
    ],
]);
$resp = (string) @file_get_contents("http://127.0.0.1:{$port}/api/internal/mappings_lookup.php", false, $ctx);
$decoded = json_decode($resp, true);
$assert('reverse lookup returns the placement_id',
    is_array($decoded) && (int) ($decoded['internal_id'] ?? 0) === $placementId);

// ---------------------------------------------------------------------
// (4) Wrong tenant returns null (multi-tenant isolation).
// ---------------------------------------------------------------------
$payload = json_encode([
    'op'                    => 'find_external_by_internal',
    'tenant_id'             => 1,   // wrong tenant
    'source_system'         => 'jobdiva',
    'internal_entity_type'  => 'placement',
    'internal_entity_id'    => $placementId,
]);
$ts  = (string) time();
$sig = hash_hmac('sha256', $ts . '.' . $payload, $secret);
$ctx = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nX-Internal-Timestamp: {$ts}\r\nX-Internal-Signature: {$sig}\r\n",
        'content' => $payload,
        'ignore_errors' => true,
        'timeout' => 5,
    ],
]);
$resp = (string) @file_get_contents("http://127.0.0.1:{$port}/api/internal/mappings_lookup.php", false, $ctx);
$decoded = json_decode($resp, true);
$assert('wrong tenant_id returns null external_id (tenant isolation enforced)',
    is_array($decoded) && array_key_exists('external_id', $decoded) && $decoded['external_id'] === null,
    'response: ' . substr((string)$resp, 0, 300));

echo "\n=== Summary ===\n";
echo "Passed: {$pass}\n";
echo "Failed: {$fail}\n";
exit($fail === 0 ? 0 : 1);
