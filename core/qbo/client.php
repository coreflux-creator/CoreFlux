<?php
/**
 * QuickBooks Online (QBO) integration client.
 *
 * OAuth 2.0 against Intuit AppCenter. Per-tenant connection — each tenant
 * connects their own Intuit company; CoreFlux never holds a partner-level
 * token. Tokens are AES-256-GCM encrypted at rest.
 *
 * Endpoints (per https://developer.intuit.com/app/developer/qbo/docs):
 *   Authorize:    https://appcenter.intuit.com/connect/oauth2
 *   Token bearer: https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer
 *   Revoke:       https://developer.api.intuit.com/v2/oauth2/tokens/revoke
 *   Accounting (sandbox):    https://sandbox-quickbooks.api.intuit.com/v3/company/{realmId}/...
 *   Accounting (production): https://quickbooks.api.intuit.com/v3/company/{realmId}/...
 *
 * Required config (env or core/config.local.php):
 *   QBO_CLIENT_ID, QBO_CLIENT_SECRET, QBO_REDIRECT_URI,
 *   QBO_ENV ('sandbox' | 'production'), QBO_SCOPES (defaults to accounting).
 *
 * Public surface:
 *   qboConfigured(): bool
 *   qboConnection(int $tid): ?array
 *   qboBuildAuthorizeUrl(int $tid, ?int $userId): array  ['url'=>..., 'state'=>...]
 *   qboExchangeCode(int $tid, string $code, string $realmId, ?int $userId): array
 *   qboDisconnect(int $tid, ?int $userId): void
 *   qboPing(int $tid, ?int $userId): array
 *   qboAccessToken(int $tid): string                     // auto-refreshes
 *   qboCall(int $tid, string $method, string $path, ?array $body=null, ?array $query=null): array
 *   qboSyncConfigRead(int $tid): array
 *   qboSyncConfigWrite(int $tid, array $config, ?int $userId): array
 *   qboAudit(int $tid, string $action, array $opts=[]): void
 */
declare(strict_types=1);

require_once __DIR__ . '/../encryption.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';

const QBO_AUTHORIZE_URL = 'https://appcenter.intuit.com/connect/oauth2';
const QBO_TOKEN_URL     = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
const QBO_REVOKE_URL    = 'https://developer.api.intuit.com/v2/oauth2/tokens/revoke';
const QBO_API_BASE_SANDBOX    = 'https://sandbox-quickbooks.api.intuit.com';
const QBO_API_BASE_PRODUCTION = 'https://quickbooks.api.intuit.com';
const QBO_DEFAULT_SCOPES = 'com.intuit.quickbooks.accounting';
// Refresh the access token a minute before the server marks it expired,
// to avoid racing with our own clock skew.
const QBO_TOKEN_SLACK_SEC = 60;

// Per-entity sync direction config. `off` means CoreFlux ignores this
// entity for both directions. Tenants must explicitly opt direction in.
const QBO_SYNC_ENTITIES = [
    'journal_entries',
    'customers',
    'vendors',
    'invoices',
    'bills',
    'payments',
    'chart_of_accounts',
];
const QBO_SYNC_DIRECTIONS = ['push', 'pull', 'two_way', 'off'];

/**
 * QBO API exception — raised by `qboCall()` when the upstream returns
 * a 4xx / 5xx response. Carries:
 *   - $httpStatus  : the HTTP status code
 *   - $errorCode   : QBO's `Fault.Error[0].code` (their machine-readable code)
 *   - $raw         : ['body' => <first 600 chars of raw vendor response>]
 *
 * Charter primitive #6 — operators must always be able to see what the
 * vendor said when something fails. The sync drivers persist `$e->raw`
 * into the qbo_audit_log detail so the IntegrationsHealthPanel and
 * outbox UI can surface the un-parsed payload to engineers.
 */
class QboApiException extends \RuntimeException
{
    public ?int    $httpStatus = null;
    public ?string $errorCode  = null;
    public ?array  $raw        = null;
}
const QBO_SYNC_DEFAULTS = [
    'journal_entries'   => 'off',
    'customers'         => 'off',
    'vendors'           => 'off',
    'invoices'          => 'off',
    'bills'             => 'off',
    'payments'          => 'off',
    'chart_of_accounts' => 'off',
];

// ---------------------------------------------------------------------
// Config & helpers
// ---------------------------------------------------------------------

function qboCfg(string $key): string
{
    $v = defined($key) ? constant($key) : (getenv($key) ?: '');
    return is_string($v) ? $v : '';
}

function qboConfigured(): bool
{
    return qboCfg('QBO_CLIENT_ID') !== ''
        && qboCfg('QBO_CLIENT_SECRET') !== ''
        && qboCfg('QBO_REDIRECT_URI') !== '';
}

function qboEnvironment(): string
{
    $e = strtolower(qboCfg('QBO_ENV'));
    return $e === 'production' ? 'production' : 'sandbox';
}

function qboApiBase(): string
{
    return qboEnvironment() === 'production' ? QBO_API_BASE_PRODUCTION : QBO_API_BASE_SANDBOX;
}

// ---------------------------------------------------------------------
// Connection row
// ---------------------------------------------------------------------

function qboConnection(int $tenantId): ?array
{
    // auto_reconcile_paid_out_of_band landed in migration 115. Older
    // pods (or smoke envs running a partial schema) may not have it
    // yet — fall back to the legacy column list on failure so the
    // function stays usable.
    try {
        $stmt = getDB()->prepare(
            'SELECT id, tenant_id, realm_id, company_name, environment,
                    access_token_ct, refresh_token_ct,
                    access_token_exp, refresh_token_exp,
                    scope, status, sync_config,
                    auto_reconcile_paid_out_of_band,
                    last_probe_at, last_probe_error,
                    connected_by_user_id, created_at, updated_at
               FROM qbo_connections
              WHERE tenant_id = :t LIMIT 1'
        );
        $stmt->execute(['t' => $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (\Throwable $_) {
        $stmt = getDB()->prepare(
            'SELECT id, tenant_id, realm_id, company_name, environment,
                    access_token_ct, refresh_token_ct,
                    access_token_exp, refresh_token_exp,
                    scope, status, sync_config,
                    last_probe_at, last_probe_error,
                    connected_by_user_id, created_at, updated_at
               FROM qbo_connections
              WHERE tenant_id = :t LIMIT 1'
        );
        $stmt->execute(['t' => $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['auto_reconcile_paid_out_of_band'] = 0;
        return $row;
    }
}

// ---------------------------------------------------------------------
// Auto-reconcile flag — opt-in per tenant. When true, paid_out_of_band
// drift rows are automatically closed by writing matching CoreFlux
// payments. See core/qbo/auto_reconcile.php.
// ---------------------------------------------------------------------

function qboAutoReconcileEnabled(int $tenantId): bool
{
    $row = qboConnection($tenantId);
    return $row && (int) ($row['auto_reconcile_paid_out_of_band'] ?? 0) === 1;
}

function qboAutoReconcileSet(int $tenantId, bool $enabled, ?int $userId = null): bool
{
    $row = qboConnection($tenantId);
    if (!$row) {
        throw new \RuntimeException('Connect QuickBooks before changing auto-reconcile.');
    }
    getDB()->prepare(
        'UPDATE qbo_connections
            SET auto_reconcile_paid_out_of_band = :v
          WHERE tenant_id = :t'
    )->execute(['v' => $enabled ? 1 : 0, 't' => $tenantId]);

    qboAudit($tenantId, 'auto_reconcile_toggle', [
        'actor_user_id' => $userId,
        'detail'        => ['enabled' => $enabled],
    ]);
    return $enabled;
}

// ---------------------------------------------------------------------
// OAuth: authorize URL + code exchange + token refresh
// ---------------------------------------------------------------------

/**
 * Build the Intuit authorize URL and persist a state nonce for CSRF
 * defence. Returns { url, state }.
 */
function qboBuildAuthorizeUrl(int $tenantId, ?int $userId): array
{
    if (!qboConfigured()) {
        throw new \RuntimeException('QBO is not configured on this pod (missing QBO_CLIENT_ID / QBO_CLIENT_SECRET / QBO_REDIRECT_URI).');
    }
    $state = bin2hex(random_bytes(24));
    getDB()->prepare(
        'INSERT INTO qbo_oauth_state (tenant_id, state_token, initiator_user_id)
         VALUES (:t, :s, :u)'
    )->execute(['t' => $tenantId, 's' => $state, 'u' => $userId]);

    $scopes = qboCfg('QBO_SCOPES') ?: QBO_DEFAULT_SCOPES;
    $qs = http_build_query([
        'client_id'     => qboCfg('QBO_CLIENT_ID'),
        'response_type' => 'code',
        'scope'         => $scopes,
        'redirect_uri'  => qboCfg('QBO_REDIRECT_URI'),
        'state'         => $state,
    ]);
    return ['url' => QBO_AUTHORIZE_URL . '?' . $qs, 'state' => $state];
}

/**
 * Exchange the authorization code for an access + refresh token, probe
 * /companyinfo to capture the company name, and upsert the qbo_connections
 * row. Caller MUST verify the state nonce before calling this.
 */
function qboExchangeCode(int $tenantId, string $code, string $realmId, ?int $userId): array
{
    if (!qboConfigured()) {
        throw new \RuntimeException('QBO is not configured on this pod.');
    }
    $code    = trim($code);
    $realmId = trim($realmId);
    if ($code === '' || $realmId === '') {
        throw new \InvalidArgumentException('code and realmId are required');
    }

    $resp = qboRawRequest('POST', QBO_TOKEN_URL, http_build_query([
        'grant_type'   => 'authorization_code',
        'code'         => $code,
        'redirect_uri' => qboCfg('QBO_REDIRECT_URI'),
    ]), [
        'Accept: application/json',
        'Content-Type: application/x-www-form-urlencoded',
        'Authorization: Basic ' . base64_encode(qboCfg('QBO_CLIENT_ID') . ':' . qboCfg('QBO_CLIENT_SECRET')),
    ]);

    if ($resp['status'] !== 200 || !is_array($resp['body']) || empty($resp['body']['access_token'])) {
        throw new \RuntimeException('QBO token exchange failed: HTTP ' . $resp['status'] . ' ' . substr(json_encode($resp['body']), 0, 200));
    }
    $accessToken  = (string) $resp['body']['access_token'];
    $refreshToken = (string) ($resp['body']['refresh_token']        ?? '');
    $expiresIn    = (int)    ($resp['body']['expires_in']           ?? 3600);
    $refreshExpIn = (int)    ($resp['body']['x_refresh_token_expires_in'] ?? 8726400); // ~101d default
    $scope        = (string) ($resp['body']['scope']                ?? (qboCfg('QBO_SCOPES') ?: QBO_DEFAULT_SCOPES));

    $accessExp  = date('Y-m-d H:i:s', time() + $expiresIn);
    $refreshExp = date('Y-m-d H:i:s', time() + $refreshExpIn);

    $env = qboEnvironment();
    $existing = qboConnection($tenantId);

    $pdo = getDB();
    if ($existing) {
        // tenant-leak-allow: defense-in-depth — caller scoped row by tenant_id before this id-only write
        $pdo->prepare(
            'UPDATE qbo_connections
                SET realm_id = :rid, environment = :env,
                    access_token_ct = :at, refresh_token_ct = :rt,
                    access_token_exp = :ae, refresh_token_exp = :re,
                    scope = :sc, status = "active",
                    last_probe_error = NULL,
                    connected_by_user_id = :uid
              WHERE id = :id'
        )->execute([
            'rid' => $realmId,
            'env' => $env,
            'at'  => encryptField($accessToken),
            'rt'  => encryptField($refreshToken),
            'ae'  => $accessExp,
            're'  => $refreshExp,
            'sc'  => $scope,
            'uid' => $userId,
            'id'  => (int) $existing['id'],
        ]);
        $id = (int) $existing['id'];
    } else {
        $pdo->prepare(
            'INSERT INTO qbo_connections
                (tenant_id, realm_id, environment,
                 access_token_ct, refresh_token_ct,
                 access_token_exp, refresh_token_exp,
                 scope, status, connected_by_user_id)
             VALUES (:t, :rid, :env, :at, :rt, :ae, :re, :sc, "active", :uid)'
        )->execute([
            't'   => $tenantId,
            'rid' => $realmId,
            'env' => $env,
            'at'  => encryptField($accessToken),
            'rt'  => encryptField($refreshToken),
            'ae'  => $accessExp,
            're'  => $refreshExp,
            'sc'  => $scope,
            'uid' => $userId,
        ]);
        $id = (int) $pdo->lastInsertId();
    }

    // Probe /companyinfo to capture the QBO company display name. Failure
    // is non-fatal — the tokens are saved, we just don't have a friendly
    // label yet.
    try {
        $info = qboCall($tenantId, 'GET', '/v3/company/' . $realmId . '/companyinfo/' . $realmId, null, ['minorversion' => 65]);
        $name = (string) ($info['CompanyInfo']['CompanyName'] ?? '');
        if ($name !== '') {
            // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
            $pdo->prepare('UPDATE qbo_connections SET company_name = :n, last_probe_at = NOW() WHERE id = :id')
                ->execute(['n' => $name, 'id' => $id]);
        }
    } catch (\Throwable $e) {
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare('UPDATE qbo_connections SET last_probe_at = NOW(), last_probe_error = :e WHERE id = :id')
            ->execute(['e' => substr($e->getMessage(), 0, 500), 'id' => $id]);
    }

    qboAudit($tenantId, 'connect', [
        'actor_user_id' => $userId,
        'detail'        => ['realm_id' => $realmId, 'environment' => $env, 'scope' => $scope],
    ]);
    return ['id' => $id, 'realm_id' => $realmId, 'environment' => $env];
}

/**
 * Soft-disconnect: best-effort revoke the refresh token upstream, then
 * mark the local row revoked. Audit row written either way.
 */
function qboDisconnect(int $tenantId, ?int $userId): void
{
    $row = qboConnection($tenantId);
    if ($row && !empty($row['refresh_token_ct'])) {
        try {
            $refresh = decryptField((string) $row['refresh_token_ct']);
            if ($refresh) {
                qboRawRequest('POST', QBO_REVOKE_URL, json_encode(['token' => $refresh]), [
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'Authorization: Basic ' . base64_encode(qboCfg('QBO_CLIENT_ID') . ':' . qboCfg('QBO_CLIENT_SECRET')),
                ]);
            }
        } catch (\Throwable $e) {
            // Non-fatal — we still mark local row revoked below.
        }
    }
    getDB()->prepare(
        'UPDATE qbo_connections SET status = "revoked" WHERE tenant_id = :t'
    )->execute(['t' => $tenantId]);
    qboAudit($tenantId, 'disconnect', ['actor_user_id' => $userId]);
}

/**
 * Returns a non-empty access token, refreshing it if expiry is within
 * QBO_TOKEN_SLACK_SEC. Throws if the tenant isn't connected or the
 * refresh round-trip fails.
 */
function qboAccessToken(int $tenantId): string
{
    $row = qboConnection($tenantId);
    if (!$row || $row['status'] !== 'active') {
        throw new \RuntimeException('QuickBooks is not connected for this tenant');
    }

    $exp = $row['access_token_exp'] ? strtotime((string) $row['access_token_exp']) : 0;
    if ($exp && $exp > (time() + QBO_TOKEN_SLACK_SEC)) {
        $tok = decryptField((string) $row['access_token_ct']);
        if ($tok) return $tok;
    }
    return qboRefreshAccessToken($tenantId);
}

function qboRefreshAccessToken(int $tenantId): string
{
    $row = qboConnection($tenantId);
    if (!$row) throw new \RuntimeException('QBO connection missing');
    $refresh = decryptField((string) $row['refresh_token_ct']);
    if (!$refresh) throw new \RuntimeException('QBO refresh token unreadable');

    $resp = qboRawRequest('POST', QBO_TOKEN_URL, http_build_query([
        'grant_type'    => 'refresh_token',
        'refresh_token' => $refresh,
    ]), [
        'Accept: application/json',
        'Content-Type: application/x-www-form-urlencoded',
        'Authorization: Basic ' . base64_encode(qboCfg('QBO_CLIENT_ID') . ':' . qboCfg('QBO_CLIENT_SECRET')),
    ]);
    if ($resp['status'] !== 200 || empty($resp['body']['access_token'])) {
        // Mark connection error so the UI surfaces it.
        getDB()->prepare('UPDATE qbo_connections SET status = "error", last_probe_error = :e WHERE tenant_id = :t')
            ->execute(['t' => $tenantId, 'e' => substr('Refresh failed: HTTP ' . $resp['status'], 0, 500)]);
        throw new \RuntimeException('QBO refresh failed: HTTP ' . $resp['status'] . ' ' . substr(json_encode($resp['body']), 0, 200));
    }
    $accessToken   = (string) $resp['body']['access_token'];
    $newRefresh    = (string) ($resp['body']['refresh_token'] ?? $refresh);
    $expiresIn     = (int)    ($resp['body']['expires_in']    ?? 3600);
    $refreshExpIn  = (int)    ($resp['body']['x_refresh_token_expires_in'] ?? 8726400);

    getDB()->prepare(
        'UPDATE qbo_connections
            SET access_token_ct = :at, refresh_token_ct = :rt,
                access_token_exp = :ae, refresh_token_exp = :re,
                status = "active", last_probe_error = NULL
          WHERE tenant_id = :t'
    )->execute([
        'at' => encryptField($accessToken),
        'rt' => encryptField($newRefresh),
        'ae' => date('Y-m-d H:i:s', time() + $expiresIn),
        're' => date('Y-m-d H:i:s', time() + $refreshExpIn),
        't'  => $tenantId,
    ]);
    qboAudit($tenantId, 'refresh_token', ['detail' => ['expires_in' => $expiresIn]]);
    return $accessToken;
}

// ---------------------------------------------------------------------
// API call helpers
// ---------------------------------------------------------------------

/**
 * Authenticated QBO Accounting API call. Auto-refreshes on 401 and
 * retries once. Returns the decoded JSON body. Throws on non-2xx.
 */
function qboCall(int $tenantId, string $method, string $path, ?array $body = null, ?array $query = null): array
{
    $token = qboAccessToken($tenantId);
    $url   = qboApiBase() . $path;
    if ($query) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ];
    $resp = qboRawRequest($method, $url, $body !== null ? json_encode($body) : null, $headers);

    if ($resp['status'] === 401) {
        // Force-refresh and retry once.
        $token = qboRefreshAccessToken($tenantId);
        $headers[2] = 'Authorization: Bearer ' . $token;
        $resp = qboRawRequest($method, $url, $body !== null ? json_encode($body) : null, $headers);
    }
    if ($resp['status'] >= 400) {
        getDB()->prepare(
            'UPDATE qbo_connections SET status = "error", last_probe_error = :e WHERE tenant_id = :t'
        )->execute([
            't' => $tenantId,
            'e' => substr('HTTP ' . $resp['status'] . ' on ' . $method . ' ' . $path, 0, 500),
        ]);
        // Charter primitive #6 — capture the raw vendor response so the
        // operator can see exactly what QBO said (e.g. validation error
        // detail with Fault.Error[]). Truncate to 600 chars to stay
        // within audit-log limits.
        $rawBody = is_string($resp['body']) ? $resp['body'] : json_encode($resp['body']);
        $msg = 'QBO ' . $method . ' ' . $path . ' returned HTTP ' . $resp['status']
             . ': ' . substr($rawBody, 0, 300);
        $errCode = '';
        if (is_array($resp['body']) && isset($resp['body']['Fault']['Error'][0]['code'])) {
            $errCode = (string) $resp['body']['Fault']['Error'][0]['code'];
        }
        $ex = new QboApiException($msg);
        $ex->httpStatus = (int) $resp['status'];
        $ex->errorCode  = $errCode;
        $ex->raw        = ['body' => substr($rawBody, 0, 600)];
        throw $ex;
    }
    if (!is_array($resp['body'])) return ['_raw' => $resp['body']];
    return $resp['body'];
}

/**
 * Low-level HTTP. Test override: set $GLOBALS['__qbo_transport'] to a
 * callable for unit tests — same shape as mercury_adapter.
 *
 * @return array{status:int,body:mixed,headers:array}
 */
function qboRawRequest(string $method, string $url, ?string $rawBody, array $headers): array
{
    if (isset($GLOBALS['__qbo_transport']) && is_callable($GLOBALS['__qbo_transport'])) {
        return ($GLOBALS['__qbo_transport'])($method, $url, $headers, $rawBody);
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
    if ($errno) throw new \RuntimeException('QBO network error: ' . $err . ' (errno ' . $errno . ')');
    $decoded = ($raw === '' || $raw === false) ? null : json_decode((string) $raw, true);
    return ['status' => $status, 'body' => $decoded ?? $raw, 'headers' => []];
}

/**
 * Cheap auth round-trip — refresh the access token and probe /companyinfo.
 */
function qboPing(int $tenantId, ?int $userId): array
{
    $start = microtime(true);
    try {
        $row = qboConnection($tenantId);
        if (!$row) throw new \RuntimeException('QuickBooks is not connected');
        $token = qboAccessToken($tenantId);
        $info  = qboCall($tenantId, 'GET', '/v3/company/' . $row['realm_id'] . '/companyinfo/' . $row['realm_id'], null, ['minorversion' => 65]);
        $latency = (int) round((microtime(true) - $start) * 1000);
        $name = (string) ($info['CompanyInfo']['CompanyName'] ?? ($row['company_name'] ?? ''));
        getDB()->prepare(
            'UPDATE qbo_connections SET last_probe_at = NOW(), last_probe_error = NULL, company_name = COALESCE(:n, company_name), status = "active" WHERE tenant_id = :t'
        )->execute(['n' => $name !== '' ? $name : null, 't' => $tenantId]);
        qboAudit($tenantId, 'ping', [
            'ok' => true, 'actor_user_id' => $userId,
            'detail' => ['latency_ms' => $latency, 'company_name' => $name],
        ]);
        unset($token);
        return ['ok' => true, 'latency_ms' => $latency, 'company_name' => $name];
    } catch (\Throwable $e) {
        getDB()->prepare(
            'UPDATE qbo_connections SET last_probe_at = NOW(), last_probe_error = :e, status = "error" WHERE tenant_id = :t'
        )->execute(['t' => $tenantId, 'e' => substr($e->getMessage(), 0, 500)]);
        qboAudit($tenantId, 'ping', [
            'ok' => false, 'actor_user_id' => $userId,
            'detail' => ['error' => substr($e->getMessage(), 0, 500)],
        ]);
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

// ---------------------------------------------------------------------
// Sync config — per-entity direction picker stored on connection row
// ---------------------------------------------------------------------

function qboSyncConfigRead(int $tenantId): array
{
    $row = qboConnection($tenantId);
    $stored = [];
    if ($row && !empty($row['sync_config'])) {
        $decoded = json_decode((string) $row['sync_config'], true);
        if (is_array($decoded)) $stored = $decoded;
    }
    $merged = [];
    foreach (QBO_SYNC_ENTITIES as $entity) {
        $dir = $stored[$entity] ?? QBO_SYNC_DEFAULTS[$entity];
        if (!in_array($dir, QBO_SYNC_DIRECTIONS, true)) $dir = 'off';
        $merged[$entity] = $dir;
    }
    return $merged;
}

function qboSyncConfigWrite(int $tenantId, array $config, ?int $userId): array
{
    $sanitised = [];
    foreach (QBO_SYNC_ENTITIES as $entity) {
        $dir = $config[$entity] ?? QBO_SYNC_DEFAULTS[$entity];
        if (!in_array($dir, QBO_SYNC_DIRECTIONS, true)) {
            throw new \InvalidArgumentException('Invalid direction for ' . $entity . ': ' . (string) $dir);
        }
        $sanitised[$entity] = $dir;
    }
    $row = qboConnection($tenantId);
    if (!$row) throw new \RuntimeException('Connect QuickBooks before changing sync settings.');
    getDB()->prepare(
        'UPDATE qbo_connections SET sync_config = :c WHERE tenant_id = :t'
    )->execute(['c' => json_encode($sanitised), 't' => $tenantId]);

    qboAudit($tenantId, 'sync_config_update', [
        'actor_user_id' => $userId,
        'detail'        => ['sync_config' => $sanitised],
    ]);
    return $sanitised;
}

// ---------------------------------------------------------------------
// OAuth state consumption — single-use, time-bound (30 min)
// ---------------------------------------------------------------------

function qboConsumeOAuthState(int $tenantId, string $state): bool
{
    if ($state === '') return false;
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT id, tenant_id, consumed_at, created_at
           FROM qbo_oauth_state
          WHERE state_token = :s LIMIT 1'
    );
    $stmt->execute(['s' => $state]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) return false;
    if ((int) $row['tenant_id'] !== $tenantId) return false;
    if ($row['consumed_at'] !== null) return false;
    $age = time() - strtotime((string) $row['created_at']);
    if ($age > 1800) return false; // 30-minute window
    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    $pdo->prepare('UPDATE qbo_oauth_state SET consumed_at = NOW() WHERE id = :id')
        ->execute(['id' => (int) $row['id']]);
    return true;
}

// ---------------------------------------------------------------------
// Audit
// ---------------------------------------------------------------------

function qboAudit(int $tenantId, string $action, array $opts = []): void
{
    try {
        getDB()->prepare(
            'INSERT INTO qbo_sync_audit
                (tenant_id, action, entity_type, direction, ok,
                 items_processed, items_skipped, items_failed,
                 detail, actor_user_id)
             VALUES (:t, :a, :et, :dir, :ok, :ip, :is, :if, :det, :u)'
        )->execute([
            't'   => $tenantId,
            'a'   => $action,
            'et'  => $opts['entity_type'] ?? null,
            'dir' => $opts['direction']   ?? 'none',
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
