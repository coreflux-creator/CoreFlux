<?php
/**
 * Airtable integration client.
 *
 * Per-tenant connection via Personal Access Token (PAT). PATs are
 * AES-256-GCM encrypted at rest. Per the project's custom-legacy
 * integration pattern (mirrors core/qbo/client.php).
 *
 * Endpoints:
 *   Meta whoami:       GET  https://api.airtable.com/v0/meta/whoami
 *   Meta bases:        GET  https://api.airtable.com/v0/meta/bases
 *   Meta tables:       GET  https://api.airtable.com/v0/meta/bases/{baseId}/tables
 *   Records list:      GET  https://api.airtable.com/v0/{baseId}/{tableId}
 *
 * Required config (env or core/config.local.php) — none, since PAT is
 * per-tenant user-supplied. AIRTABLE_API_BASE may be overridden for
 * proxy/test setups.
 *
 * Public surface:
 *   airtableConfigured(): bool                                       // always true (PAT is user-supplied)
 *   airtableConnection(int $tid): ?array
 *   airtableSavePAT(int $tid, string $pat, ?string $label, ?int $userId): array
 *   airtableDisconnect(int $tid, ?int $userId): void
 *   airtablePing(int $tid, ?int $userId): array
 *   airtableCall(int $tid, string $method, string $path, ?array $query=null): array
 *   airtableListBases(int $tid): array
 *   airtableListTables(int $tid, string $baseId): array
 *   airtableSelectRecords(int $tid, string $baseId, string $tableId, ?string $offset=null, int $pageSize=100): array
 *   airtableAudit(int $tid, string $action, array $opts=[]): void
 */
declare(strict_types=1);

require_once __DIR__ . '/../encryption.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';

const AIRTABLE_API_BASE_DEFAULT = 'https://api.airtable.com';

// ---------------------------------------------------------------------
// Config helpers
// ---------------------------------------------------------------------

function airtableCfg(string $key): string
{
    $v = defined($key) ? constant($key) : (getenv($key) ?: '');
    return is_string($v) ? $v : '';
}

function airtableApiBase(): string
{
    $base = airtableCfg('AIRTABLE_API_BASE');
    return $base !== '' ? rtrim($base, '/') : AIRTABLE_API_BASE_DEFAULT;
}

/**
 * Airtable PATs are user-supplied per-tenant, so the integration is
 * always "configured" at the pod level. This function exists only to
 * mirror the QBO/Plaid/Mercury pattern and to give the UI a single
 * predicate to branch on.
 */
function airtableConfigured(): bool
{
    return true;
}

// ---------------------------------------------------------------------
// Connection row
// ---------------------------------------------------------------------

function airtableConnection(int $tenantId): ?array
{
    $stmt = getDB()->prepare(
        'SELECT id, tenant_id, pat_ct, pat_last4, workspace_label, scopes,
                status, last_probe_at, last_probe_error,
                connected_by_user_id, created_at, updated_at
           FROM airtable_connections
          WHERE tenant_id = :t LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Decrypts and returns the PAT for outbound calls. Throws if no active
 * connection exists.
 */
function airtablePAT(int $tenantId): string
{
    $row = airtableConnection($tenantId);
    if (!$row || $row['status'] !== 'active') {
        throw new \RuntimeException('Airtable is not connected for this tenant');
    }
    $pat = decryptField((string) $row['pat_ct']);
    if (!is_string($pat) || $pat === '') {
        throw new \RuntimeException('Airtable PAT could not be decrypted');
    }
    return $pat;
}

/**
 * Persist a PAT (encrypted) and probe /meta/whoami to verify the token
 * is live. Returns { id, last4, scopes }. Throws on bad PAT.
 */
function airtableSavePAT(int $tenantId, string $pat, ?string $label, ?int $userId): array
{
    $pat = trim($pat);
    if ($pat === '') throw new \InvalidArgumentException('PAT is required');
    // PATs start with `pat` per Airtable docs.
    if (!preg_match('/^pat[A-Za-z0-9._-]{10,}$/', $pat)) {
        throw new \InvalidArgumentException('PAT format looks invalid (must start with "pat")');
    }

    $last4 = substr($pat, -4);
    $existing = airtableConnection($tenantId);
    $pdo = getDB();
    if ($existing) {
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare(
            'UPDATE airtable_connections
                SET pat_ct = :p, pat_last4 = :l4, workspace_label = :wl,
                    status = "active", last_probe_error = NULL,
                    connected_by_user_id = :uid
              WHERE id = :id'
        )->execute([
            'p'   => encryptField($pat),
            'l4'  => $last4,
            'wl'  => $label !== null && $label !== '' ? $label : ($existing['workspace_label'] ?? null),
            'uid' => $userId,
            'id'  => (int) $existing['id'],
        ]);
        $id = (int) $existing['id'];
    } else {
        $pdo->prepare(
            'INSERT INTO airtable_connections
                (tenant_id, pat_ct, pat_last4, workspace_label, status, connected_by_user_id)
             VALUES (:t, :p, :l4, :wl, "active", :uid)'
        )->execute([
            't'   => $tenantId,
            'p'   => encryptField($pat),
            'l4'  => $last4,
            'wl'  => $label !== null && $label !== '' ? $label : null,
            'uid' => $userId,
        ]);
        $id = (int) $pdo->lastInsertId();
    }

    // Probe /meta/whoami so we capture scopes + flag bad tokens immediately.
    try {
        $who = airtableCall($tenantId, 'GET', '/v0/meta/whoami');
        $scopes = is_array($who['scopes'] ?? null) ? implode(',', $who['scopes']) : null;
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare(
            'UPDATE airtable_connections
                SET last_probe_at = NOW(), last_probe_error = NULL,
                    scopes = :s, status = "active"
              WHERE id = :id'
        )->execute(['s' => $scopes, 'id' => $id]);
        airtableAudit($tenantId, 'connect', [
            'actor_user_id' => $userId,
            'detail'        => ['last4' => $last4, 'scopes' => $scopes],
        ]);
        return ['id' => $id, 'last4' => $last4, 'scopes' => $scopes];
    } catch (\Throwable $e) {
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare(
            'UPDATE airtable_connections
                SET last_probe_at = NOW(), last_probe_error = :e, status = "error"
              WHERE id = :id'
        )->execute(['e' => substr($e->getMessage(), 0, 500), 'id' => $id]);
        airtableAudit($tenantId, 'connect', [
            'ok' => false, 'actor_user_id' => $userId,
            'detail' => ['error' => $e->getMessage()],
        ]);
        throw new \RuntimeException('Airtable PAT rejected: ' . $e->getMessage());
    }
}

function airtableDisconnect(int $tenantId, ?int $userId): void
{
    // Airtable PATs are revoked by the user inside the Airtable account
    // settings — there is no programmatic revoke endpoint. We mark the
    // row revoked and zero out the ciphertext so the PAT is truly gone.
    getDB()->prepare(
        'UPDATE airtable_connections
            SET status = "revoked", pat_ct = ""
          WHERE tenant_id = :t'
    )->execute(['t' => $tenantId]);
    airtableAudit($tenantId, 'disconnect', ['actor_user_id' => $userId]);
}

// ---------------------------------------------------------------------
// API call helpers
// ---------------------------------------------------------------------

/**
 * Authenticated Airtable REST call. Returns the decoded JSON body.
 * Throws \RuntimeException on non-2xx with the error message.
 */
function airtableCall(int $tenantId, string $method, string $path, ?array $query = null): array
{
    $token = airtablePAT($tenantId);
    $url = airtableApiBase() . $path;
    if ($query) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ];
    $resp = airtableRawRequest($method, $url, null, $headers);

    if ($resp['status'] === 429) {
        // Airtable rate-limits at 5 req/s/base. Short backoff + retry once.
        usleep(1100 * 1000);
        $resp = airtableRawRequest($method, $url, null, $headers);
    }

    if ($resp['status'] >= 400) {
        $msg = is_array($resp['body'])
            ? json_encode($resp['body']['error'] ?? $resp['body'])
            : (string) $resp['body'];
        getDB()->prepare(
            'UPDATE airtable_connections SET status = "error", last_probe_error = :e WHERE tenant_id = :t'
        )->execute([
            't' => $tenantId,
            'e' => substr('HTTP ' . $resp['status'] . ' on ' . $method . ' ' . $path, 0, 500),
        ]);
        throw new \RuntimeException('Airtable ' . $method . ' ' . $path . ' returned HTTP ' . $resp['status'] . ': ' . substr((string) $msg, 0, 300));
    }
    if (!is_array($resp['body'])) return ['_raw' => $resp['body']];
    return $resp['body'];
}

/**
 * Low-level HTTP. Test override: set $GLOBALS['__airtable_transport']
 * to a callable for unit tests — same shape as qboRawRequest.
 *
 * @return array{status:int,body:mixed,headers:array}
 */
function airtableRawRequest(string $method, string $url, ?string $rawBody, array $headers): array
{
    if (isset($GLOBALS['__airtable_transport']) && is_callable($GLOBALS['__airtable_transport'])) {
        return ($GLOBALS['__airtable_transport'])($method, $url, $headers, $rawBody);
    }
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 8,
    ];
    if ($rawBody !== null) $opts[CURLOPT_POSTFIELDS] = $rawBody;
    curl_setopt_array($ch, $opts);
    $raw    = curl_exec($ch);
    $errno  = curl_errno($ch);
    $err    = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errno) throw new \RuntimeException('Airtable network error: ' . $err . ' (errno ' . $errno . ')');
    $decoded = ($raw === '' || $raw === false) ? null : json_decode((string) $raw, true);
    return ['status' => $status, 'body' => $decoded ?? $raw, 'headers' => []];
}

/**
 * Probe /meta/whoami — cheap auth round-trip. Updates last_probe_*.
 */
function airtablePing(int $tenantId, ?int $userId): array
{
    $start = microtime(true);
    try {
        $row = airtableConnection($tenantId);
        if (!$row) throw new \RuntimeException('Airtable is not connected');
        $who = airtableCall($tenantId, 'GET', '/v0/meta/whoami');
        $latency = (int) round((microtime(true) - $start) * 1000);
        $scopes = is_array($who['scopes'] ?? null) ? implode(',', $who['scopes']) : null;
        getDB()->prepare(
            'UPDATE airtable_connections
                SET last_probe_at = NOW(), last_probe_error = NULL,
                    scopes = COALESCE(:s, scopes), status = "active"
              WHERE tenant_id = :t'
        )->execute(['s' => $scopes, 't' => $tenantId]);
        airtableAudit($tenantId, 'ping', [
            'ok' => true, 'actor_user_id' => $userId,
            'detail' => ['latency_ms' => $latency, 'user_id' => $who['id'] ?? null],
        ]);
        return ['ok' => true, 'latency_ms' => $latency, 'user_id' => $who['id'] ?? null, 'scopes' => $scopes];
    } catch (\Throwable $e) {
        getDB()->prepare(
            'UPDATE airtable_connections SET last_probe_at = NOW(), last_probe_error = :e, status = "error" WHERE tenant_id = :t'
        )->execute(['t' => $tenantId, 'e' => substr($e->getMessage(), 0, 500)]);
        airtableAudit($tenantId, 'ping', [
            'ok' => false, 'actor_user_id' => $userId,
            'detail' => ['error' => substr($e->getMessage(), 0, 500)],
        ]);
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * @return array<int, array{id:string,name:string,permissionLevel?:string}>
 */
function airtableListBases(int $tenantId): array
{
    $resp = airtableCall($tenantId, 'GET', '/v0/meta/bases');
    $out = [];
    foreach (($resp['bases'] ?? []) as $b) {
        if (!isset($b['id'], $b['name'])) continue;
        $out[] = [
            'id'   => (string) $b['id'],
            'name' => (string) $b['name'],
            'permissionLevel' => (string) ($b['permissionLevel'] ?? ''),
        ];
    }
    return $out;
}

/**
 * @return array<int, array{id:string,name:string,primaryFieldId?:string,fields:array<int,array{id:string,name:string,type:string}>}>
 */
function airtableListTables(int $tenantId, string $baseId): array
{
    if (!preg_match('/^app[A-Za-z0-9]{10,}$/', $baseId)) {
        throw new \InvalidArgumentException('Invalid Airtable base_id');
    }
    $resp = airtableCall($tenantId, 'GET', '/v0/meta/bases/' . $baseId . '/tables');
    $out = [];
    foreach (($resp['tables'] ?? []) as $t) {
        if (!isset($t['id'], $t['name'])) continue;
        $fields = [];
        foreach (($t['fields'] ?? []) as $f) {
            if (!isset($f['id'], $f['name'])) continue;
            $fields[] = [
                'id'   => (string) $f['id'],
                'name' => (string) $f['name'],
                'type' => (string) ($f['type'] ?? ''),
            ];
        }
        $out[] = [
            'id'             => (string) $t['id'],
            'name'           => (string) $t['name'],
            'primaryFieldId' => (string) ($t['primaryFieldId'] ?? ''),
            'fields'         => $fields,
        ];
    }
    return $out;
}

/**
 * One page of records. Returns { records, offset } — caller paginates.
 */
function airtableSelectRecords(int $tenantId, string $baseId, string $tableId, ?string $offset = null, int $pageSize = 100): array
{
    if (!preg_match('/^app[A-Za-z0-9]{10,}$/', $baseId)) {
        throw new \InvalidArgumentException('Invalid Airtable base_id');
    }
    if (!preg_match('/^tbl[A-Za-z0-9]{10,}$/', $tableId)) {
        throw new \InvalidArgumentException('Invalid Airtable table_id');
    }
    $q = ['pageSize' => max(1, min(100, $pageSize))];
    if ($offset !== null && $offset !== '') $q['offset'] = $offset;
    $resp = airtableCall($tenantId, 'GET', '/v0/' . $baseId . '/' . $tableId, $q);
    return [
        'records' => is_array($resp['records'] ?? null) ? $resp['records'] : [],
        'offset'  => isset($resp['offset']) ? (string) $resp['offset'] : null,
    ];
}

// ---------------------------------------------------------------------
// Audit
// ---------------------------------------------------------------------

function airtableAudit(int $tenantId, string $action, array $opts = []): void
{
    try {
        getDB()->prepare(
            'INSERT INTO airtable_sync_audit
                (tenant_id, action, base_id, table_id, direction, ok,
                 items_processed, items_skipped, items_failed,
                 detail, actor_user_id)
             VALUES (:t, :a, :b, :tb, :dir, :ok, :ip, :is, :if, :det, :u)'
        )->execute([
            't'   => $tenantId,
            'a'   => $action,
            'b'   => $opts['base_id']  ?? null,
            'tb'  => $opts['table_id'] ?? null,
            'dir' => $opts['direction'] ?? 'none',
            'ok'  => isset($opts['ok']) ? ((int) (bool) $opts['ok']) : 1,
            'ip'  => (int) ($opts['items_processed'] ?? 0),
            'is'  => (int) ($opts['items_skipped']   ?? 0),
            'if'  => (int) ($opts['items_failed']    ?? 0),
            'det' => isset($opts['detail']) ? json_encode($opts['detail']) : null,
            'u'   => $opts['actor_user_id'] ?? null,
        ]);
    } catch (\Throwable $e) {
        // audit is best-effort; never bubble a logging failure into the caller
    }
}
