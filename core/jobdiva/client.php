<?php
/**
 * JobDiva REST client (Sprint 8a / Slice A1).
 *
 * Auth flow (per api.jobdiva.com/jobdiva-api.html):
 *   1. POST https://api.jobdiva.com/api/jobdiva/authenticate
 *      body: { clientid, username, password }
 *      → returns a session JWT we cache server-side (encrypted)
 *      The token is bearer-style: `Authorization: Bearer <token>`.
 *   2. Subsequent calls auto-refresh on 401 (retry once after re-auth).
 *
 * Tenant logs in exactly once via /api/jobdiva/connect — afterwards this
 * class transparently re-mints the session token whenever the cached one
 * expires.
 *
 * Surface:
 *   jobdivaConnection(int $tid): ?array                 // row or null
 *   jobdivaSaveConnection(int $tid, array $creds, int $userId): array
 *   jobdivaDisconnect(int $tid, int $userId): void
 *   jobdivaPing(int $tid, int $userId): array           // { ok, latency_ms, account?, error? }
 *   jobdivaCall(int $tid, string $method, string $path, ?array $body=null, ?array $query=null): array
 *   jobdivaAudit(int $tid, string $action, array $opts=[]): void
 *   jobdivaWebhookVerify(int $tid, string $rawBody, string $sigHeader): bool
 */
declare(strict_types=1);

require_once __DIR__ . '/../encryption.php';

const JOBDIVA_BASE_URL  = 'https://api.jobdiva.com';
const JOBDIVA_AUTH_PATH = '/api/jobdiva/authenticate';
// Refresh the session token a minute before the server marks it expired,
// to avoid racing with our own clock skew.
const JOBDIVA_TOKEN_SLACK_SEC = 60;

/**
 * @return array|null normalized connection row (encrypted blobs decoded
 *                    back to plaintext only for the password/token, never
 *                    returned to the API caller).
 */
function jobdivaConnection(int $tenantId): ?array
{
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT id, tenant_id, client_id, username, password_enc,
                session_token_enc, session_token_exp, webhook_secret_enc,
                status, last_sync_at, last_sync_error, last_ping_at,
                field_ownership, sync_config,
                connected_by_user_id, created_at, updated_at
           FROM jobdiva_connections
          WHERE tenant_id = :t LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row ?: null;
}

function jobdivaSaveConnection(int $tenantId, array $creds, ?int $userId): array
{
    $clientId = trim((string) ($creds['client_id'] ?? ''));
    $username = trim((string) ($creds['username']  ?? ''));
    $password = (string) ($creds['password'] ?? '');
    $whSecret = isset($creds['webhook_secret']) ? (string) $creds['webhook_secret'] : null;

    if ($clientId === '') throw new \InvalidArgumentException('client_id required');
    if ($username === '') throw new \InvalidArgumentException('username required');
    if ($password === '') throw new \InvalidArgumentException('password required');

    $pdo = getDB();
    $existing = jobdivaConnection($tenantId);
    if ($existing) {
        $pdo->prepare(
            'UPDATE jobdiva_connections
                SET client_id = :c, username = :u, password_enc = :p,
                    webhook_secret_enc = COALESCE(:w, webhook_secret_enc),
                    session_token_enc = NULL, session_token_exp = NULL,
                    status = "connected", last_sync_error = NULL,
                    connected_by_user_id = :uid
              WHERE id = :id'
        )->execute([
            'c'   => $clientId,
            'u'   => $username,
            'p'   => encryptField($password),
            'w'   => $whSecret !== null ? encryptField($whSecret) : null,
            'uid' => $userId,
            'id'  => (int) $existing['id'],
        ]);
        $id = (int) $existing['id'];
    } else {
        $pdo->prepare(
            'INSERT INTO jobdiva_connections
                (tenant_id, client_id, username, password_enc, webhook_secret_enc, status, connected_by_user_id)
             VALUES (:t, :c, :u, :p, :w, "connected", :uid)'
        )->execute([
            't'   => $tenantId,
            'c'   => $clientId,
            'u'   => $username,
            'p'   => encryptField($password),
            'w'   => $whSecret !== null ? encryptField($whSecret) : null,
            'uid' => $userId,
        ]);
        $id = (int) $pdo->lastInsertId();
    }

    jobdivaAudit($tenantId, 'connect', [
        'actor_user_id' => $userId,
        'detail'        => ['client_id' => $clientId, 'username' => $username, 'webhook_secret_set' => $whSecret !== null],
    ]);
    return ['id' => $id];
}

function jobdivaDisconnect(int $tenantId, ?int $userId): void
{
    $pdo = getDB();
    $pdo->prepare(
        'UPDATE jobdiva_connections
            SET status = "disconnected", session_token_enc = NULL, session_token_exp = NULL
          WHERE tenant_id = :t'
    )->execute(['t' => $tenantId]);
    jobdivaAudit($tenantId, 'disconnect', ['actor_user_id' => $userId]);
}

function jobdivaAudit(int $tenantId, string $action, array $opts = []): void
{
    $pdo = getDB();
    $pdo->prepare(
        'INSERT INTO jobdiva_sync_audit
            (tenant_id, action, entity_type, direction, ok,
             items_processed, items_skipped, items_failed, detail, actor_user_id)
         VALUES (:t, :a, :et, :dir, :ok, :ip, :is, :if, :det, :u)'
    )->execute([
        't'   => $tenantId,
        'a'   => $action,
        'et'  => $opts['entity_type'] ?? null,
        'dir' => $opts['direction']    ?? 'none',
        'ok'  => isset($opts['ok']) ? ((int) (bool) $opts['ok']) : 1,
        'ip'  => (int) ($opts['items_processed'] ?? 0),
        'is'  => (int) ($opts['items_skipped']   ?? 0),
        'if'  => (int) ($opts['items_failed']    ?? 0),
        'det' => isset($opts['detail']) ? json_encode($opts['detail']) : null,
        'u'   => $opts['actor_user_id'] ?? null,
    ]);
}

/**
 * Mint or return the cached session token. Always returns a non-empty
 * string token, or throws.
 */
function jobdivaSessionToken(int $tenantId): string
{
    $pdo = getDB();
    $row = jobdivaConnection($tenantId);
    if (!$row || $row['status'] === 'disconnected') {
        throw new \RuntimeException('JobDiva is not connected for this tenant');
    }

    // Cached + still fresh?
    if (!empty($row['session_token_enc']) && !empty($row['session_token_exp'])) {
        $exp = strtotime((string) $row['session_token_exp']);
        if ($exp !== false && $exp > (time() + JOBDIVA_TOKEN_SLACK_SEC)) {
            $tok = decryptField((string) $row['session_token_enc']);
            if ($tok) return $tok;
        }
    }

    // Mint.
    $resp = jobdivaRawRequest('POST', JOBDIVA_AUTH_PATH, [
        'clientid' => (string) $row['client_id'],
        'username' => (string) $row['username'],
        'password' => decryptField((string) $row['password_enc']),
    ], null, /* withAuth */ false);

    $token = (string) ($resp['body']['token'] ?? $resp['body']['access_token'] ?? $resp['body']['jwt'] ?? '');
    if ($token === '') {
        throw new \RuntimeException('JobDiva authenticate response missing token: ' . substr(json_encode($resp['body']), 0, 200));
    }
    // Server may return an explicit expires_in OR a JWT we can decode.
    $expiresAt = isset($resp['body']['expires_in']) ? (time() + (int) $resp['body']['expires_in']) : null;
    if ($expiresAt === null) {
        $expiresAt = jobdivaJwtExp($token) ?: (time() + 3600); // safe default 60 min
    }

    $pdo->prepare(
        'UPDATE jobdiva_connections
            SET session_token_enc = :t, session_token_exp = FROM_UNIXTIME(:e),
                status = IF(status = "disconnected", "connected", status),
                last_sync_error = NULL
          WHERE tenant_id = :tid'
    )->execute([
        't'   => encryptField($token),
        'e'   => $expiresAt,
        'tid' => $tenantId,
    ]);
    jobdivaAudit($tenantId, 'refresh_token', [
        'detail' => ['expires_at' => date('c', $expiresAt)],
    ]);
    return $token;
}

/**
 * High-level call: authenticated, auto-refresh on 401 once.
 */
function jobdivaCall(int $tenantId, string $method, string $path, ?array $body = null, ?array $query = null): array
{
    $token = jobdivaSessionToken($tenantId);
    $resp  = jobdivaRawRequest($method, $path, $body, $query, /* withAuth */ true, $token);

    if ($resp['status'] === 401) {
        // Force-refresh and retry once.
        $pdo = getDB();
        $pdo->prepare(
            'UPDATE jobdiva_connections
                SET session_token_enc = NULL, session_token_exp = NULL
              WHERE tenant_id = :t'
        )->execute(['t' => $tenantId]);
        $token = jobdivaSessionToken($tenantId);
        $resp  = jobdivaRawRequest($method, $path, $body, $query, true, $token);
    }
    if ($resp['status'] >= 400) {
        // Surface a degraded state.
        getDB()->prepare(
            'UPDATE jobdiva_connections
                SET status = "degraded",
                    last_sync_error = :err
              WHERE tenant_id = :t'
        )->execute([
            't'   => $tenantId,
            'err' => substr('HTTP ' . $resp['status'] . ' on ' . $method . ' ' . $path, 0, 500),
        ]);
        throw new \RuntimeException('JobDiva ' . $method . ' ' . $path . ' returned HTTP ' . $resp['status'] . ': ' . substr(json_encode($resp['body']), 0, 200));
    }
    return $resp['body'];
}

/**
 * Low-level HTTP. Always returns ['status'=>int,'body'=>mixed,'headers'=>array]
 */
function jobdivaRawRequest(string $method, string $path, ?array $body = null, ?array $query = null, bool $withAuth = true, ?string $token = null): array
{
    $url = JOBDIVA_BASE_URL . $path;
    if ($query) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);

    $ch = curl_init($url);
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($withAuth && $token) $headers[] = 'Authorization: Bearer ' . $token;

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 8,
    ];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    curl_setopt_array($ch, $opts);

    $raw    = curl_exec($ch);
    $errno  = curl_errno($ch);
    $err    = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        throw new \RuntimeException('JobDiva network error: ' . $err . ' (errno ' . $errno . ')');
    }
    $decoded = ($raw === '' || $raw === false) ? null : json_decode((string) $raw, true);
    return [
        'status'  => $status,
        'body'    => $decoded ?? $raw,
        'headers' => [],
    ];
}

/**
 * Best-effort JWT exp extraction. Returns unix timestamp or null.
 */
function jobdivaJwtExp(string $token): ?int
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
    if ($payload === false) return null;
    $j = json_decode($payload, true);
    return isset($j['exp']) ? (int) $j['exp'] : null;
}

function jobdivaPing(int $tenantId, ?int $userId): array
{
    $start = microtime(true);
    try {
        // Mint or refresh the session token; that's the cheapest auth round-trip.
        $token = jobdivaSessionToken($tenantId);
        $latency = (int) round((microtime(true) - $start) * 1000);
        getDB()->prepare(
            'UPDATE jobdiva_connections
                SET last_ping_at = NOW(), status = "connected", last_sync_error = NULL
              WHERE tenant_id = :t'
        )->execute(['t' => $tenantId]);
        jobdivaAudit($tenantId, 'ping', [
            'ok' => true, 'actor_user_id' => $userId,
            'detail' => ['latency_ms' => $latency, 'token_exp' => jobdivaJwtExp($token)],
        ]);
        return ['ok' => true, 'latency_ms' => $latency];
    } catch (\Throwable $e) {
        getDB()->prepare(
            'UPDATE jobdiva_connections
                SET status = "error", last_sync_error = :err
              WHERE tenant_id = :t'
        )->execute(['t' => $tenantId, 'err' => substr($e->getMessage(), 0, 500)]);
        jobdivaAudit($tenantId, 'ping', [
            'ok' => false, 'actor_user_id' => $userId,
            'detail' => ['error' => substr($e->getMessage(), 0, 500)],
        ]);
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * HMAC-SHA256 webhook signature verification.
 * Header format expected (configurable in JobDiva dashboard):
 *   X-JobDiva-Signature: sha256=<hex digest>
 */
function jobdivaWebhookVerify(int $tenantId, string $rawBody, string $sigHeader): bool
{
    $row = jobdivaConnection($tenantId);
    if (!$row || empty($row['webhook_secret_enc'])) return false;
    $secret = decryptField((string) $row['webhook_secret_enc']);
    if (!$secret) return false;
    $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
    return hash_equals($expected, $sigHeader);
}
