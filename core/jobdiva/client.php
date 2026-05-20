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

// ---------------------------------------------------------------------
// Slice A4 — per-entity sync config (source-of-truth + direction picker).
// ---------------------------------------------------------------------
const JOBDIVA_SYNC_ENTITIES   = ['company', 'contact', 'placement', 'time'];
const JOBDIVA_SYNC_SOURCES    = ['jobdiva', 'coreflux'];
const JOBDIVA_SYNC_DIRECTIONS = ['pull', 'push', 'two_way', 'off'];

// Sane defaults: the 3 ATS-adjacent entities are JobDiva-owned + pulled,
// time is OFF until the tenant explicitly opts a direction in. CoreFlux
// is not an ATS, so candidates/applicants/open positions are never listed.
const JOBDIVA_SYNC_DEFAULTS = [
    'company'   => ['source' => 'jobdiva',  'direction' => 'pull'],
    'contact'   => ['source' => 'jobdiva',  'direction' => 'pull'],
    'placement' => ['source' => 'jobdiva',  'direction' => 'pull'],
    'time'      => ['source' => 'coreflux', 'direction' => 'off'],
];

function jobdivaSyncConfigRead(int $tenantId): array
{
    $row = jobdivaConnection($tenantId);
    $stored = [];
    if ($row && !empty($row['sync_config'])) {
        $decoded = json_decode((string) $row['sync_config'], true);
        if (is_array($decoded)) $stored = $decoded;
    }
    $merged = [];
    foreach (JOBDIVA_SYNC_ENTITIES as $ent) {
        $merged[$ent] = $stored[$ent] ?? JOBDIVA_SYNC_DEFAULTS[$ent];
    }
    return $merged;
}

function jobdivaSyncConfigWrite(int $tenantId, array $config, ?int $userId): array
{
    $sanitised = [];
    foreach (JOBDIVA_SYNC_ENTITIES as $ent) {
        $entry = $config[$ent] ?? null;
        if (!is_array($entry)) {
            // Preserve whatever was there; fall back to default.
            $sanitised[$ent] = JOBDIVA_SYNC_DEFAULTS[$ent];
            continue;
        }
        $source    = (string) ($entry['source']    ?? 'jobdiva');
        $direction = (string) ($entry['direction'] ?? 'pull');
        if (!in_array($source, JOBDIVA_SYNC_SOURCES, true)) {
            throw new \InvalidArgumentException("invalid source for {$ent}: {$source}");
        }
        if (!in_array($direction, JOBDIVA_SYNC_DIRECTIONS, true)) {
            throw new \InvalidArgumentException("invalid direction for {$ent}: {$direction}");
        }
        // Belt-and-braces: when source=coreflux, direction can't be 'pull';
        // when source=jobdiva, direction can't be 'push' (would push CoreFlux
        // data into a system that's the source of truth — nonsensical).
        if ($source === 'coreflux' && $direction === 'pull') {
            throw new \InvalidArgumentException("incoherent {$ent}: source=coreflux cannot have direction=pull");
        }
        if ($source === 'jobdiva' && $direction === 'push') {
            throw new \InvalidArgumentException("incoherent {$ent}: source=jobdiva cannot have direction=push");
        }
        $sanitised[$ent] = ['source' => $source, 'direction' => $direction];
    }

    getDB()->prepare(
        'UPDATE jobdiva_connections SET sync_config = :c WHERE tenant_id = :t'
    )->execute(['c' => json_encode($sanitised), 't' => $tenantId]);

    jobdivaAudit($tenantId, 'sync_config_update', [
        'actor_user_id' => $userId,
        'detail'        => ['sync_config' => $sanitised],
    ]);
    return $sanitised;
}

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
 *
 * JobDiva's authenticate endpoint is unusual:
 *   - Credentials go in the QUERY STRING, not the JSON body
 *     (POST /api/jobdiva/authenticate?clientid=X&username=Y&password=Z).
 *   - The token can come back as a raw plain-text body, a JSON envelope
 *     with one of several key names, or a response header such as
 *     `x-li-token` / `li-token` / `Authorization`. We accept all of them.
 *   - The response also carries an `li-uuid` correlation header used by
 *     JobDiva support — we audit it on the connection row.
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

    // Mint — credentials as query params, NOT JSON body.
    $resp = jobdivaRawRequest(
        'POST',
        JOBDIVA_AUTH_PATH,
        /* body  */ null,
        /* query */ [
            'clientid' => (string) $row['client_id'],
            'username' => (string) $row['username'],
            'password' => decryptField((string) $row['password_enc']),
        ],
        /* withAuth */ false
    );

    $token = jobdivaExtractToken($resp);
    if ($token === '') {
        // Surface JobDiva's verbatim response so the operator can see
        // exactly what JobDiva said — not just our remediation hint.
        // (Previously we masked the response under a generic message when
        // it contained "Full authentication is required", which made it
        // impossible to tell whether anything had changed between attempts.)
        $bodyText = is_string($resp['body'])
            ? $resp['body']
            : (string) json_encode($resp['body']);
        $jdMsg = '';
        if (is_array($resp['body']) && isset($resp['body']['message'])) {
            $jdMsg = (string) $resp['body']['message'];
        }
        $liUuid = $resp['headers']['x-li-uuid'] ?? '';
        $isProvisioning = stripos($bodyText, 'Full authentication is required') !== false;

        jobdivaAudit($tenantId, 'authenticate_failed', [
            'ok'     => false,
            'detail' => [
                'http_status' => $resp['status'],
                'jd_message'  => $jdMsg,
                'body'        => substr($bodyText, 0, 500),
                'li_uuid'     => $liUuid,
            ],
        ]);

        // Build one message that always shows: HTTP status + JobDiva message +
        // raw body snippet + li-uuid + (optional) remediation footer.
        $msg = 'JobDiva authenticate failed → HTTP ' . $resp['status'];
        if ($jdMsg !== '')   $msg .= ' — "' . $jdMsg . '"';
        if ($liUuid !== '')  $msg .= ' [li-uuid: ' . $liUuid . ']';
        $msg .= "\nResponse body: " . substr($bodyText, 0, 400);
        if ($isProvisioning) {
            $msg .= "\n\nNext step: JobDiva says your tenant isn't provisioned for API access. "
                  . 'Email JobDiva Support, quote the li-uuid above, and ask them to: '
                  . '(1) issue an API Client ID, (2) create a dedicated API user (not a UI login), '
                  . '(3) enable the "Only allow to access JobDiva API Calls" permission. '
                  . 'Full template: /app/memory/JOBDIVA_API_ACCESS.md';
        }
        throw new \RuntimeException($msg);
    }

    // expires_in may be in the body (JSON) or — when the body is a raw
    // token — only derivable from the JWT itself. Either way, fall back
    // to a safe 60-minute window so we re-mint well before JobDiva's
    // typical token TTL expires.
    $expiresAt = null;
    if (is_array($resp['body']) && isset($resp['body']['expires_in'])) {
        $expiresAt = time() + (int) $resp['body']['expires_in'];
    }
    if ($expiresAt === null) {
        $expiresAt = jobdivaJwtExp($token) ?: (time() + 3600);
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
        // Surface a degraded state with the full upstream payload so the
        // UI shows JobDiva's verbatim response. li-uuid included so the
        // operator can quote it in a JobDiva support ticket.
        $bodyStr = is_string($resp['body']) ? $resp['body'] : (string) json_encode($resp['body']);
        $liUuid  = $resp['headers']['x-li-uuid'] ?? '';
        $err = 'JobDiva ' . $method . ' ' . $path . ' → HTTP ' . $resp['status']
             . ($liUuid !== '' ? ' [li-uuid: ' . $liUuid . ']' : '')
             . "\nResponse body: " . substr($bodyStr, 0, 800);
        getDB()->prepare(
            'UPDATE jobdiva_connections
                SET status = "degraded",
                    last_sync_error = :err
              WHERE tenant_id = :t'
        )->execute(['t' => $tenantId, 'err' => substr($err, 0, 4000)]);
        throw new \RuntimeException($err);
    }
    return $resp['body'];
}

/**
 * Low-level HTTP. Always returns ['status'=>int,'body'=>mixed,'headers'=>array]
 *
 * `headers` is keyed by lower-cased header name so callers can do
 * $resp['headers']['x-li-token'] without worrying about case.
 */
function jobdivaRawRequest(string $method, string $path, ?array $body = null, ?array $query = null, bool $withAuth = true, ?string $token = null): array
{
    $url = JOBDIVA_BASE_URL . $path;
    if ($query) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);

    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    // Only set Content-Type when there's a JSON body to send. Authenticate
    // posts with credentials in the query string and an empty body — JobDiva
    // rejects requests that announce JSON without one.
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    if ($withAuth && $token) $headers[] = 'Authorization: Bearer ' . $token;

    // Capture response headers for token extraction (JobDiva surfaces
    // `x-li-token` and `li-uuid` on the authenticate response).
    $respHeaders = [];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
            $len = strlen($line);
            $p = strpos($line, ':');
            if ($p !== false) {
                $k = strtolower(trim(substr($line, 0, $p)));
                $v = trim(substr($line, $p + 1));
                $respHeaders[$k] = $v;
            }
            return $len;
        },
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
    // Try JSON-decode; if that fails (or yields a scalar), keep the raw
    // string body so the token extractor can read it directly.
    $decoded = ($raw === '' || $raw === false) ? null : json_decode((string) $raw, true);
    $body    = (is_array($decoded)) ? $decoded : ($raw === false ? null : (string) $raw);
    return [
        'status'  => $status,
        'body'    => $body,
        'headers' => $respHeaders,
    ];
}

/**
 * Pull a JobDiva session token out of an authenticate response.
 *
 * Order of checks:
 *   1. Plain-text body that looks like a JWT or opaque token string.
 *   2. JSON body with `token` / `access_token` / `jwt` / `id_token`.
 *   3. Response headers — `x-li-token`, `li-token`, or `authorization` (Bearer X).
 *
 * Returns the token string, or '' if none found.
 */
function jobdivaExtractToken(array $resp): string
{
    $body = $resp['body'] ?? null;

    // (1) Raw string body — what JobDiva actually returns most of the time.
    if (is_string($body)) {
        $b = trim($body);
        // Strip enclosing quotes if the server wrapped the token (e.g. "eyJhbGc...").
        if ($b !== '' && $b[0] === '"' && substr($b, -1) === '"') {
            $b = trim($b, '"');
        }
        // Reasonable token shape: long, no whitespace, not obviously HTML/JSON.
        if ($b !== '' && strlen($b) >= 20 && !preg_match('/\s/', $b)
            && $b[0] !== '<' && $b[0] !== '{') {
            return $b;
        }
    }

    // (2) JSON envelope.
    if (is_array($body)) {
        foreach (['token', 'access_token', 'jwt', 'id_token'] as $k) {
            if (!empty($body[$k]) && is_string($body[$k])) return (string) $body[$k];
        }
    }

    // (3) Response headers.
    $headers = $resp['headers'] ?? [];
    foreach (['x-li-token', 'li-token', 'x-auth-token'] as $h) {
        if (!empty($headers[$h])) return (string) $headers[$h];
    }
    if (!empty($headers['authorization']) && stripos($headers['authorization'], 'Bearer ') === 0) {
        return trim(substr((string) $headers['authorization'], 7));
    }

    return '';
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
        // Persist the full upstream message (migration 057 widened the
        // column to TEXT) so the UI can render JobDiva's verbatim response,
        // status code, message, and li-uuid without forcing the admin to
        // query the audit table.
        getDB()->prepare(
            'UPDATE jobdiva_connections
                SET status = "error", last_sync_error = :err
              WHERE tenant_id = :t'
        )->execute(['t' => $tenantId, 'err' => substr($e->getMessage(), 0, 4000)]);
        jobdivaAudit($tenantId, 'ping', [
            'ok' => false, 'actor_user_id' => $userId,
            'detail' => ['error' => substr($e->getMessage(), 0, 4000)],
        ]);
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Webhook signature verification.
 *
 * JobDiva's "Manage API Webhook" dialog sends an `X-Hub-Signature` header
 * computed as HmacSHA1 over the raw request body using the shared secret
 * the operator entered as "Webhook Signature". The value is either a raw
 * hex digest, or prefixed with the algorithm (`sha1=<hex>` for X-Hub style,
 * `sha256=<hex>` for newer integrations).
 *
 * We accept all three shapes plus our own historic `X-JobDiva-Signature`
 * (kept for backwards compatibility with already-registered webhooks).
 * Comparison is constant-time via hash_equals().
 *
 * Headers checked in order:
 *   1. X-Hub-Signature        (JobDiva's UI default — primary)
 *   2. X-Hub-Signature-256    (newer JobDiva tenants)
 *   3. X-JobDiva-Signature    (CoreFlux legacy)
 *
 * Algorithm picked from the header prefix; falls back to SHA1 when no
 * prefix is present (matches JobDiva's bare-hex variant).
 */
function jobdivaWebhookVerify(int $tenantId, string $rawBody, string $sigHeader = '', array $allHeaders = []): bool
{
    $row = jobdivaConnection($tenantId);
    if (!$row || empty($row['webhook_secret_enc'])) return false;
    $secret = decryptField((string) $row['webhook_secret_enc']);
    if (!$secret) return false;

    // Aggregate every signature-bearing header so callers can either pass
    // them explicitly (preferred — controller passes $_SERVER once) or rely
    // on $_SERVER fall-through here.  Lower-cased keys for predictable lookups.
    $sources = [];
    foreach ([
        'HTTP_X_HUB_SIGNATURE'         => 'x-hub-signature',
        'HTTP_X_HUB_SIGNATURE_256'     => 'x-hub-signature-256',
        'HTTP_X_JOBDIVA_SIGNATURE'     => 'x-jobdiva-signature',
    ] as $serverKey => $headerKey) {
        if (isset($allHeaders[$headerKey])) $sources[$headerKey] = (string) $allHeaders[$headerKey];
        elseif (isset($_SERVER[$serverKey])) $sources[$serverKey] = (string) $_SERVER[$serverKey];
    }
    // The classic single-string $sigHeader arg (back-compat) is checked first.
    if ($sigHeader !== '') array_unshift($sources, $sigHeader);

    foreach ($sources as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate === '') continue;
        // Algorithm + digest.
        $algo   = 'sha1';
        $digest = $candidate;
        if (stripos($candidate, 'sha256=') === 0) { $algo = 'sha256'; $digest = substr($candidate, 7); }
        elseif (stripos($candidate, 'sha1=') === 0) { $algo = 'sha1';   $digest = substr($candidate, 5); }
        $expected = hash_hmac($algo, $rawBody, $secret);
        // Match the bare digest *or* the algo-prefixed variant for symmetry.
        if (hash_equals($expected, $digest)) return true;
        if (hash_equals($algo . '=' . $expected, $candidate)) return true;
    }
    return false;
}
