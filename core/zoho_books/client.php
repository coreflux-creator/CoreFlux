<?php
/**
 * Zoho Books integration client.
 *
 * OAuth 2.0 against Zoho Accounts. Per-tenant connection — each tenant
 * connects their own Zoho organization; CoreFlux never holds a partner-
 * level token. Tokens are AES-256-GCM encrypted at rest. DC is
 * auto-detected from the OAuth callback's `accounts-server` parameter.
 *
 * Endpoints (per https://www.zoho.com/books/api/v3/oauth/):
 *   Authorize:    https://accounts.zoho.{DC}/oauth/v2/auth
 *   Token bearer: https://accounts.zoho.{DC}/oauth/v2/token
 *   Revoke:       https://accounts.zoho.{DC}/oauth/v2/token/revoke
 *   API:          https://www.zohoapis.{DC}/books/v3/...
 *
 * Required config (env or core/config.local.php):
 *   ZOHO_BOOKS_CLIENT_ID, ZOHO_BOOKS_CLIENT_SECRET, ZOHO_BOOKS_REDIRECT_URI,
 *   ZOHO_BOOKS_SCOPES (defaults to ZohoBooks.fullaccess.all).
 *
 * Public surface:
 *   zohoBooksConfigured(): bool
 *   zohoBooksConnection(int $tid): ?array
 *   zohoBooksBuildAuthorizeUrl(int $tid, ?int $userId): array  ['url'=>..., 'state'=>...]
 *   zohoBooksExchangeCode(int $tid, string $code, string $accountsServer, ?int $userId): array
 *   zohoBooksDisconnect(int $tid, ?int $userId): void
 *   zohoBooksPing(int $tid, ?int $userId): array
 *   zohoBooksAccessToken(int $tid): string                       // auto-refreshes
 *   zohoBooksRefreshAccessToken(int $tid): string
 *   zohoBooksCall(int $tid, string $method, string $path, ?array $body=null, ?array $query=null): array
 *   zohoBooksSyncConfigRead(int $tid): array
 *   zohoBooksSyncConfigWrite(int $tid, array $config, ?int $userId): array
 *   zohoBooksConsumeOAuthState(int $tid, string $state): bool
 *   zohoBooksAudit(int $tid, string $action, array $opts=[]): void
 *   zohoBooksDcFromAccountsServer(string $accountsServer): string
 */
declare(strict_types=1);

require_once __DIR__ . '/../encryption.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';

/**
 * Zoho Books API exception — raised by `zohoBooksCall()` when the
 * upstream returns a 4xx / 5xx response. Carries:
 *   - $httpStatus  : the HTTP status code
 *   - $errorCode   : Zoho's `code` field (their machine-readable code)
 *   - $raw         : ['body' => <first 600 chars of raw vendor response>]
 *
 * Charter primitive #6 — operators must always be able to see what the
 * vendor said when something fails. The sync drivers persist `$e->raw`
 * into the audit log so the IntegrationsHealthPanel and outbox UI can
 * surface the un-parsed payload to engineers.
 */
class ZohoBooksApiException extends \RuntimeException
{
    public ?int    $httpStatus = null;
    public ?string $errorCode  = null;
    public ?array  $raw        = null;
}

// Default authorize URL is the .com DC; Zoho redirects the user's
// browser to their actual regional accounts host during login and
// returns it in the callback's `accounts-server` parameter.
const ZOHO_BOOKS_AUTHORIZE_URL_BASE = 'https://accounts.zoho.com/oauth/v2/auth';
const ZOHO_BOOKS_DEFAULT_SCOPES     = 'ZohoBooks.fullaccess.all';
// Refresh the access token a minute before the server marks it expired,
// to avoid racing with our own clock skew.
const ZOHO_BOOKS_TOKEN_SLACK_SEC = 60;
// Supported Zoho data centres. The DC suffix is what we append to
// `accounts.zoho.` and `www.zohoapis.` to build per-call hostnames.
const ZOHO_BOOKS_VALID_DCS = ['com', 'eu', 'in', 'com.au', 'jp', 'com.cn', 'sa'];

// Per-entity sync direction config. `off` means CoreFlux ignores this
// entity for both directions. Tenants must explicitly opt direction in.
const ZOHO_BOOKS_SYNC_ENTITIES = [
    'journal_entries',
    'contacts',          // customers + vendors are one entity in Zoho Books
    'invoices',
    'bills',
    'payments',
    'chart_of_accounts',
];
const ZOHO_BOOKS_SYNC_DIRECTIONS = ['push', 'pull', 'two_way', 'off'];
const ZOHO_BOOKS_SYNC_DEFAULTS = [
    'journal_entries'   => 'off',
    'contacts'          => 'off',
    'invoices'          => 'off',
    'bills'             => 'off',
    'payments'          => 'off',
    'chart_of_accounts' => 'off',
];

// ---------------------------------------------------------------------
// Config & helpers
// ---------------------------------------------------------------------

function zohoBooksCfg(string $key): string
{
    $v = defined($key) ? constant($key) : (getenv($key) ?: '');
    return is_string($v) ? $v : '';
}

function zohoBooksConfigured(): bool
{
    return zohoBooksCfg('ZOHO_BOOKS_CLIENT_ID') !== ''
        && zohoBooksCfg('ZOHO_BOOKS_CLIENT_SECRET') !== ''
        && zohoBooksCfg('ZOHO_BOOKS_REDIRECT_URI') !== '';
}

/**
 * Extract the Zoho data-centre suffix from the `accounts-server`
 * parameter Zoho returns on the OAuth callback. Returns 'com' for
 * anything unrecognised (Zoho's most conservative DC).
 *
 *   "https://accounts.zoho.eu"      → "eu"
 *   "https://accounts.zoho.com.au"  → "com.au"
 *   "https://accounts.zoho.com"     → "com"
 */
function zohoBooksDcFromAccountsServer(string $accountsServer): string
{
    $accountsServer = trim($accountsServer);
    if ($accountsServer === '') return 'com';
    if (preg_match('#accounts\.zoho\.(com\.au|com\.cn|com|eu|in|jp|sa)#i', $accountsServer, $m)) {
        $dc = strtolower($m[1]);
        if (in_array($dc, ZOHO_BOOKS_VALID_DCS, true)) return $dc;
    }
    return 'com';
}

function zohoBooksAccountsHost(string $dc): string
{
    if (!in_array($dc, ZOHO_BOOKS_VALID_DCS, true)) $dc = 'com';
    return 'https://accounts.zoho.' . $dc;
}

function zohoBooksApiBase(string $dc): string
{
    if (!in_array($dc, ZOHO_BOOKS_VALID_DCS, true)) $dc = 'com';
    return 'https://www.zohoapis.' . $dc;
}

// ---------------------------------------------------------------------
// Connection row
// ---------------------------------------------------------------------

/**
 * Read a Zoho Books connection row.
 *
 * Per migration 099 each (tenant, sub_tenant) pair gets its own row. The
 * second argument is the legal entity id. When NULL, the helper falls
 * back to the parent self-entity row (sub_tenant_id = tenant_id), which
 * preserves pre-099 behaviour for single-entity tenants. Pre-099 rows
 * that were back-filled to (tenant_id, tenant_id) are picked up by the
 * fallback transparently.
 */
function zohoBooksConnection(int $tenantId, ?int $subTenantId = null): ?array
{
    $sub = $subTenantId !== null && $subTenantId > 0 ? $subTenantId : $tenantId;
    $stmt = getDB()->prepare(
        'SELECT id, tenant_id, sub_tenant_id, organization_id, organization_name, dc,
                access_token_ct, refresh_token_ct,
                access_token_exp,
                scope, status, sync_config,
                last_probe_at, last_probe_error,
                connected_by_user_id, created_at, updated_at
           FROM zoho_books_connections
          WHERE tenant_id = :t AND sub_tenant_id = :st LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'st' => $sub]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * List every active Zoho Books connection inside a master tenant — used
 * by sync workers invoked at the master-tenant level to iterate per
 * entity. Returns rows ordered by sub_tenant_id ASC (so the parent
 * self-entity comes first when present).
 */
function zohoBooksConnectionsForTenant(int $tenantId, ?string $status = null): array
{
    $sql = 'SELECT id, tenant_id, sub_tenant_id, organization_id, organization_name, dc,
                   status
              FROM zoho_books_connections
             WHERE tenant_id = :t';
    $params = ['t' => $tenantId];
    if ($status !== null && $status !== '') {
        $sql .= ' AND status = :s';
        $params['s'] = $status;
    }
    $sql .= ' ORDER BY sub_tenant_id ASC';
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

// ---------------------------------------------------------------------
// OAuth: authorize URL + code exchange + token refresh
// ---------------------------------------------------------------------

/**
 * Build the Zoho authorize URL and persist a state nonce for CSRF
 * defence. Returns { url, state }.
 */
function zohoBooksBuildAuthorizeUrl(int $tenantId, ?int $userId, ?int $subTenantId = null): array
{
    if (!zohoBooksConfigured()) {
        throw new \RuntimeException('Zoho Books is not configured on this pod (missing ZOHO_BOOKS_CLIENT_ID / ZOHO_BOOKS_CLIENT_SECRET / ZOHO_BOOKS_REDIRECT_URI).');
    }
    $sub   = $subTenantId !== null && $subTenantId > 0 ? $subTenantId : $tenantId;
    $state = bin2hex(random_bytes(24));
    // sub_tenant_id is persisted on the state row so the callback can
    // bind the new connection to the right legal entity.
    getDB()->prepare(
        'INSERT INTO zoho_books_oauth_state (tenant_id, sub_tenant_id, state_token, initiator_user_id)
         VALUES (:t, :st, :s, :u)'
    )->execute(['t' => $tenantId, 'st' => $sub, 's' => $state, 'u' => $userId]);

    $scopes = zohoBooksCfg('ZOHO_BOOKS_SCOPES') ?: ZOHO_BOOKS_DEFAULT_SCOPES;
    $qs = http_build_query([
        'client_id'     => zohoBooksCfg('ZOHO_BOOKS_CLIENT_ID'),
        'response_type' => 'code',
        'scope'         => $scopes,
        'redirect_uri'  => zohoBooksCfg('ZOHO_BOOKS_REDIRECT_URI'),
        'state'         => $state,
        'access_type'   => 'offline',   // long-lived refresh_token
        'prompt'        => 'consent',   // ensures refresh_token is reissued on reconnect
    ]);
    return ['url' => ZOHO_BOOKS_AUTHORIZE_URL_BASE . '?' . $qs, 'state' => $state];
}

/**
 * Exchange the authorization code for an access + refresh token, probe
 * /organizations to capture the org name + id, and upsert the
 * zoho_books_connections row. Caller MUST verify the state nonce before
 * calling this.
 */
function zohoBooksExchangeCode(int $tenantId, string $code, string $accountsServer, ?int $userId, ?int $subTenantId = null): array
{
    if (!zohoBooksConfigured()) {
        throw new \RuntimeException('Zoho Books is not configured on this pod.');
    }
    $code = trim($code);
    if ($code === '') throw new \InvalidArgumentException('code is required');

    $sub       = $subTenantId !== null && $subTenantId > 0 ? $subTenantId : $tenantId;
    $dc        = zohoBooksDcFromAccountsServer($accountsServer);
    $tokenUrl  = zohoBooksAccountsHost($dc) . '/oauth/v2/token';

    $resp = zohoBooksRawRequest('POST', $tokenUrl, http_build_query([
        'grant_type'    => 'authorization_code',
        'client_id'     => zohoBooksCfg('ZOHO_BOOKS_CLIENT_ID'),
        'client_secret' => zohoBooksCfg('ZOHO_BOOKS_CLIENT_SECRET'),
        'redirect_uri'  => zohoBooksCfg('ZOHO_BOOKS_REDIRECT_URI'),
        'code'          => $code,
    ]), [
        'Accept: application/json',
        'Content-Type: application/x-www-form-urlencoded',
    ]);

    if ($resp['status'] !== 200 || !is_array($resp['body']) || empty($resp['body']['access_token'])) {
        throw new \RuntimeException(
            'Zoho Books token exchange failed: HTTP ' . $resp['status']
            . ' ' . substr(json_encode($resp['body']), 0, 200)
        );
    }
    $accessToken  = (string) $resp['body']['access_token'];
    $refreshToken = (string) ($resp['body']['refresh_token'] ?? '');
    $expiresIn    = (int)    ($resp['body']['expires_in']    ?? 3600);
    $scope        = (string) ($resp['body']['scope']         ?? (zohoBooksCfg('ZOHO_BOOKS_SCOPES') ?: ZOHO_BOOKS_DEFAULT_SCOPES));
    if ($refreshToken === '') {
        throw new \RuntimeException('Zoho Books did not issue a refresh_token. Reconnect with prompt=consent.');
    }

    $accessExp = date('Y-m-d H:i:s', time() + $expiresIn);

    $existing = zohoBooksConnection($tenantId, $sub);
    $pdo = getDB();
    // We don't know the organization_id yet — probe /organizations next.
    if ($existing) {
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare(
            'UPDATE zoho_books_connections
                SET dc = :dc,
                    access_token_ct = :at, refresh_token_ct = :rt,
                    access_token_exp = :ae,
                    scope = :sc, status = "active",
                    last_probe_error = NULL,
                    connected_by_user_id = :uid
              WHERE id = :id'
        )->execute([
            'dc'  => $dc,
            'at'  => encryptField($accessToken),
            'rt'  => encryptField($refreshToken),
            'ae'  => $accessExp,
            'sc'  => $scope,
            'uid' => $userId,
            'id'  => (int) $existing['id'],
        ]);
        $id = (int) $existing['id'];
    } else {
        // Placeholder organization_id; updated below from /organizations.
        $pdo->prepare(
            'INSERT INTO zoho_books_connections
                (tenant_id, sub_tenant_id, organization_id, dc,
                 access_token_ct, refresh_token_ct,
                 access_token_exp,
                 scope, status, connected_by_user_id)
             VALUES (:t, :st, :oid, :dc, :at, :rt, :ae, :sc, "active", :uid)'
        )->execute([
            't'   => $tenantId,
            'st'  => $sub,
            'oid' => 'pending',
            'dc'  => $dc,
            'at'  => encryptField($accessToken),
            'rt'  => encryptField($refreshToken),
            'ae'  => $accessExp,
            'sc'  => $scope,
            'uid' => $userId,
        ]);
        $id = (int) $pdo->lastInsertId();
    }

    // Probe /organizations to capture the primary org id + name. If the
    // user has multiple orgs we pick the first one (Slice 1 MVP — a
    // future slice will surface a picker).
    try {
        $orgResp = zohoBooksCall($tenantId, 'GET', '/books/v3/organizations');
        $orgs    = is_array($orgResp['organizations'] ?? null) ? $orgResp['organizations'] : [];
        $first   = $orgs[0] ?? null;
        if (is_array($first) && !empty($first['organization_id'])) {
            $oid  = (string) $first['organization_id'];
            $name = (string) ($first['name'] ?? '');
            // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
            $pdo->prepare(
                'UPDATE zoho_books_connections
                    SET organization_id = :oid, organization_name = :n, last_probe_at = NOW()
                  WHERE id = :id'
            )->execute(['oid' => $oid, 'n' => $name !== '' ? $name : null, 'id' => $id]);
        }
    } catch (\Throwable $e) {
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare('UPDATE zoho_books_connections SET last_probe_at = NOW(), last_probe_error = :e WHERE id = :id')
            ->execute(['e' => substr($e->getMessage(), 0, 500), 'id' => $id]);
    }

    zohoBooksAudit($tenantId, 'connect', [
        'actor_user_id' => $userId,
        'detail'        => ['dc' => $dc, 'scope' => $scope],
    ]);
    return ['id' => $id, 'dc' => $dc];
}

function zohoBooksDisconnect(int $tenantId, ?int $userId, ?int $subTenantId = null): void
{
    $row = zohoBooksConnection($tenantId, $subTenantId);
    if (!$row) return;
    // Best-effort upstream revoke. Zoho's revoke endpoint accepts the
    // refresh_token in the query string. Failure is non-fatal.
    try {
        $refresh = decryptField((string) $row['refresh_token_ct']);
        if (is_string($refresh) && $refresh !== '') {
            $dc = (string) $row['dc'];
            zohoBooksRawRequest('POST',
                zohoBooksAccountsHost($dc) . '/oauth/v2/token/revoke?token=' . urlencode($refresh),
                null,
                ['Accept: application/json']
            );
        }
    } catch (\Throwable $_) { /* upstream revoke is best-effort */ }

    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    getDB()->prepare(
        'UPDATE zoho_books_connections
            SET status = "revoked", access_token_ct = "", refresh_token_ct = ""
          WHERE id = :id'
    )->execute(['id' => (int) $row['id']]);
    zohoBooksAudit($tenantId, 'disconnect', [
        'actor_user_id' => $userId,
        'sub_tenant_id' => (int) ($row['sub_tenant_id'] ?? 0),
    ]);
}

/**
 * Returns a valid access_token, refreshing if expired (or about to expire).
 */
function zohoBooksAccessToken(int $tenantId, ?int $subTenantId = null): string
{
    $row = zohoBooksConnection($tenantId, $subTenantId);
    if (!$row || $row['status'] !== 'active') {
        throw new \RuntimeException('Zoho Books is not connected for this tenant/entity');
    }
    $exp = $row['access_token_exp'] ? strtotime((string) $row['access_token_exp']) : 0;
    if ($exp > time() + ZOHO_BOOKS_TOKEN_SLACK_SEC) {
        $tok = decryptField((string) $row['access_token_ct']);
        if (is_string($tok) && $tok !== '') return $tok;
    }
    return zohoBooksRefreshAccessToken($tenantId, $subTenantId);
}

function zohoBooksRefreshAccessToken(int $tenantId, ?int $subTenantId = null): string
{
    $row = zohoBooksConnection($tenantId, $subTenantId);
    if (!$row) throw new \RuntimeException('Zoho Books is not connected');
    $refresh = decryptField((string) $row['refresh_token_ct']);
    if (!is_string($refresh) || $refresh === '') {
        throw new \RuntimeException('Zoho Books refresh_token missing — reconnect required');
    }
    $dc = (string) $row['dc'];
    $resp = zohoBooksRawRequest('POST',
        zohoBooksAccountsHost($dc) . '/oauth/v2/token',
        http_build_query([
            'grant_type'    => 'refresh_token',
            'client_id'     => zohoBooksCfg('ZOHO_BOOKS_CLIENT_ID'),
            'client_secret' => zohoBooksCfg('ZOHO_BOOKS_CLIENT_SECRET'),
            'refresh_token' => $refresh,
        ]),
        ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded']
    );
    if ($resp['status'] !== 200 || !is_array($resp['body']) || empty($resp['body']['access_token'])) {
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        getDB()->prepare(
            'UPDATE zoho_books_connections SET status = "error", last_probe_error = :e WHERE id = :id'
        )->execute([
            'e'  => substr('Refresh failed: HTTP ' . $resp['status'], 0, 500),
            'id' => (int) $row['id'],
        ]);
        throw new \RuntimeException('Zoho Books refresh failed: HTTP ' . $resp['status']);
    }
    $accessToken = (string) $resp['body']['access_token'];
    $expiresIn   = (int)    ($resp['body']['expires_in'] ?? 3600);
    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    getDB()->prepare(
        'UPDATE zoho_books_connections
            SET access_token_ct = :at, access_token_exp = :ae, status = "active"
          WHERE id = :id'
    )->execute([
        'at' => encryptField($accessToken),
        'ae' => date('Y-m-d H:i:s', time() + $expiresIn),
        'id' => (int) $row['id'],
    ]);
    zohoBooksAudit($tenantId, 'refresh_token', ['detail' => ['expires_in' => $expiresIn]]);
    return $accessToken;
}

// ---------------------------------------------------------------------
// API call helpers
// ---------------------------------------------------------------------

/**
 * Authenticated Zoho Books API call. Auto-refreshes on 401 and retries
 * once. Adds the `organization_id` query parameter if the connection
 * has one and the caller didn't supply it. Returns the decoded JSON
 * body. Throws on non-2xx.
 */
function zohoBooksCall(int $tenantId, string $method, string $path, ?array $body = null, ?array $query = null, ?int $subTenantId = null): array
{
    // Allow worker functions to set the per-entity scope once at the top
    // and have every nested zohoBooksCall pick it up automatically — avoids
    // threading sub_tenant_id through 12 internal call sites.
    if ($subTenantId === null && isset($GLOBALS['__zb_sub_tenant_id'])) {
        $g = (int) $GLOBALS['__zb_sub_tenant_id'];
        if ($g > 0) $subTenantId = $g;
    }
    $row = zohoBooksConnection($tenantId, $subTenantId);
    if (!$row) throw new \RuntimeException('Zoho Books is not connected for this tenant/entity');
    $dc    = (string) $row['dc'];
    $orgId = (string) $row['organization_id'];

    $token = zohoBooksAccessToken($tenantId, $subTenantId);
    $url   = zohoBooksApiBase($dc) . $path;

    $query = $query ?: [];
    if ($orgId !== '' && $orgId !== 'pending' && !isset($query['organization_id']) && $path !== '/books/v3/organizations') {
        $query['organization_id'] = $orgId;
    }
    if ($query) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Zoho-oauthtoken ' . $token,
    ];
    $resp = zohoBooksRawRequest($method, $url, $body !== null ? json_encode($body) : null, $headers);

    if ($resp['status'] === 401) {
        $token = zohoBooksRefreshAccessToken($tenantId, $subTenantId);
        $headers[2] = 'Authorization: Zoho-oauthtoken ' . $token;
        $resp = zohoBooksRawRequest($method, $url, $body !== null ? json_encode($body) : null, $headers);
    }
    if ($resp['status'] >= 400) {
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        getDB()->prepare(
            'UPDATE zoho_books_connections SET status = "error", last_probe_error = :e WHERE id = :id'
        )->execute([
            'id' => (int) $row['id'],
            'e'  => substr('HTTP ' . $resp['status'] . ' on ' . $method . ' ' . $path, 0, 500),
        ]);
        // Charter primitive #6 — capture the raw vendor response so the
        // operator can see exactly what Zoho said (e.g. validation error
        // detail). Truncate to 600 chars to stay within audit-log limits.
        $rawBody = is_string($resp['body']) ? $resp['body'] : json_encode($resp['body']);
        $msg = 'Zoho Books ' . $method . ' ' . $path . ' returned HTTP ' . $resp['status']
             . ': ' . substr($rawBody, 0, 300);
        $errCode = '';
        if (is_array($resp['body']) && isset($resp['body']['code'])) {
            $errCode = (string) $resp['body']['code'];
        }
        $ex = new ZohoBooksApiException($msg);
        $ex->httpStatus = (int) $resp['status'];
        $ex->errorCode  = $errCode;
        $ex->raw        = ['body' => substr($rawBody, 0, 600)];
        throw $ex;
    }
    if (!is_array($resp['body'])) return ['_raw' => $resp['body']];
    return $resp['body'];
}

/**
 * Low-level HTTP. Test override: set $GLOBALS['__zoho_books_transport']
 * to a callable for unit tests — same shape as qboRawRequest.
 *
 * @return array{status:int,body:mixed,headers:array}
 */
function zohoBooksRawRequest(string $method, string $url, ?string $rawBody, array $headers): array
{
    if (isset($GLOBALS['__zoho_books_transport']) && is_callable($GLOBALS['__zoho_books_transport'])) {
        return ($GLOBALS['__zoho_books_transport'])($method, $url, $headers, $rawBody);
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
    if ($errno) throw new \RuntimeException('Zoho Books network error: ' . $err . ' (errno ' . $errno . ')');
    $decoded = ($raw === '' || $raw === false) ? null : json_decode((string) $raw, true);
    return ['status' => $status, 'body' => $decoded ?? $raw, 'headers' => []];
}

/**
 * Cheap auth round-trip — refresh + probe /organizations. Updates
 * last_probe_* + organization name/id on success.
 */
function zohoBooksPing(int $tenantId, ?int $userId, ?int $subTenantId = null): array
{
    $start = microtime(true);
    $sub = $subTenantId !== null && $subTenantId > 0 ? $subTenantId : $tenantId;
    try {
        $row = zohoBooksConnection($tenantId, $sub);
        if (!$row) throw new \RuntimeException('Zoho Books is not connected');
        // Calling /organizations doesn't require an organization_id, which
        // is critical for the very first ping right after connect (the row
        // is in 'pending' state until this call lands).
        $info = zohoBooksCall($tenantId, 'GET', '/books/v3/organizations', null, [], $sub);
        $latency = (int) round((microtime(true) - $start) * 1000);
        $orgs = is_array($info['organizations'] ?? null) ? $info['organizations'] : [];
        $first = $orgs[0] ?? null;
        $name  = is_array($first) ? (string) ($first['name']           ?? '') : '';
        $oid   = is_array($first) ? (string) ($first['organization_id'] ?? '') : '';
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        getDB()->prepare(
            'UPDATE zoho_books_connections
                SET last_probe_at = NOW(), last_probe_error = NULL,
                    organization_name = COALESCE(:n, organization_name),
                    organization_id   = CASE WHEN :oid_chk <> "" THEN :oid_set ELSE organization_id END,
                    status = "active"
              WHERE id = :id'
        )->execute([
            'n'       => $name !== '' ? $name : null,
            'oid_chk' => $oid,
            'oid_set' => $oid,
            'id'      => (int) $row['id'],
        ]);
        zohoBooksAudit($tenantId, 'ping', [
            'ok' => true, 'actor_user_id' => $userId, 'sub_tenant_id' => $sub,
            'detail' => ['latency_ms' => $latency, 'organization_name' => $name, 'organization_id' => $oid],
        ]);
        return ['ok' => true, 'latency_ms' => $latency, 'organization_name' => $name, 'organization_id' => $oid];
    } catch (\Throwable $e) {
        getDB()->prepare(
            'UPDATE zoho_books_connections SET last_probe_at = NOW(), last_probe_error = :e, status = "error"
              WHERE tenant_id = :t AND sub_tenant_id = :st'
        )->execute(['t' => $tenantId, 'st' => $sub, 'e' => substr($e->getMessage(), 0, 500)]);
        zohoBooksAudit($tenantId, 'ping', [
            'ok' => false, 'actor_user_id' => $userId, 'sub_tenant_id' => $sub,
            'detail' => ['error' => substr($e->getMessage(), 0, 500)],
        ]);
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

// ---------------------------------------------------------------------
// Sync config — per-entity direction picker stored on connection row
// ---------------------------------------------------------------------

function zohoBooksSyncConfigRead(int $tenantId, ?int $subTenantId = null): array
{
    $row = zohoBooksConnection($tenantId, $subTenantId);
    $stored = [];
    if ($row && !empty($row['sync_config'])) {
        $decoded = json_decode((string) $row['sync_config'], true);
        if (is_array($decoded)) $stored = $decoded;
    }
    $merged = [];
    foreach (ZOHO_BOOKS_SYNC_ENTITIES as $entity) {
        $dir = $stored[$entity] ?? ZOHO_BOOKS_SYNC_DEFAULTS[$entity];
        if (!in_array($dir, ZOHO_BOOKS_SYNC_DIRECTIONS, true)) $dir = 'off';
        $merged[$entity] = $dir;
    }
    return $merged;
}

function zohoBooksSyncConfigWrite(int $tenantId, array $config, ?int $userId, ?int $subTenantId = null): array
{
    $sanitised = [];
    foreach (ZOHO_BOOKS_SYNC_ENTITIES as $entity) {
        $dir = $config[$entity] ?? ZOHO_BOOKS_SYNC_DEFAULTS[$entity];
        if (!in_array($dir, ZOHO_BOOKS_SYNC_DIRECTIONS, true)) {
            throw new \InvalidArgumentException('Invalid direction for ' . $entity . ': ' . (string) $dir);
        }
        $sanitised[$entity] = $dir;
    }
    $sub = $subTenantId !== null && $subTenantId > 0 ? $subTenantId : $tenantId;
    $row = zohoBooksConnection($tenantId, $sub);
    if (!$row) throw new \RuntimeException('Connect Zoho Books for this entity before changing sync settings.');
    getDB()->prepare(
        'UPDATE zoho_books_connections SET sync_config = :c
          WHERE tenant_id = :t AND sub_tenant_id = :st'
    )->execute(['c' => json_encode($sanitised), 't' => $tenantId, 'st' => $sub]);

    zohoBooksAudit($tenantId, 'sync_config_update', [
        'actor_user_id' => $userId,
        'detail'        => ['sync_config' => $sanitised],
    ]);
    return $sanitised;
}

// ---------------------------------------------------------------------
// OAuth state consumption — single-use, time-bound (30 min)
// ---------------------------------------------------------------------

/**
 * Consume an OAuth state nonce. Returns the sub_tenant_id stored on the
 * state row so the callback can bind the new connection to the right
 * legal entity (or 0 when not found / expired / reused).
 */
function zohoBooksConsumeOAuthState(int $tenantId, string $state): int
{
    if ($state === '') return 0;
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT id, tenant_id, sub_tenant_id, consumed_at, created_at
           FROM zoho_books_oauth_state
          WHERE state_token = :s LIMIT 1'
    );
    $stmt->execute(['s' => $state]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) return 0;
    if ((int) $row['tenant_id'] !== $tenantId) return 0;
    if ($row['consumed_at'] !== null) return 0;
    $age = time() - strtotime((string) $row['created_at']);
    if ($age > 1800) return 0; // 30-minute window
    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    $pdo->prepare('UPDATE zoho_books_oauth_state SET consumed_at = NOW() WHERE id = :id')
        ->execute(['id' => (int) $row['id']]);
    $sub = (int) ($row['sub_tenant_id'] ?? 0);
    return $sub > 0 ? $sub : $tenantId; // legacy rows pre-099 → parent self-entity
}

/**
 * Copy sync_config + account mappings from one entity to another.
 * Mirrors `accountingSyncConfigCopy` but works against zoho_books's
 * own connection table (since Zoho still uses its own table — the
 * provider-neutral table is reserved for adapters built on top of
 * `accounting_provider_connections`). Account-mapping copies route
 * through the provider-neutral table with provider='zoho_books'.
 */
function zohoBooksSyncConfigCopy(
    int $tenantId,
    int $fromSubTenantId,
    int $toSubTenantId,
    bool $overwriteExisting = true
): array {
    if ($fromSubTenantId === $toSubTenantId) {
        throw new \InvalidArgumentException('from and to sub_tenant_id must differ');
    }
    $src = zohoBooksConnection($tenantId, $fromSubTenantId);
    $dst = zohoBooksConnection($tenantId, $toSubTenantId);
    if (!$src) throw new \InvalidArgumentException('Source entity has no Zoho Books connection');
    if (!$dst) throw new \InvalidArgumentException('Target entity has no Zoho Books connection — connect it first');

    $cfg = zohoBooksSyncConfigRead($tenantId, $fromSubTenantId);
    if (!$overwriteExisting) {
        $existing = zohoBooksSyncConfigRead($tenantId, $toSubTenantId);
        $nonOff   = array_filter($existing, fn ($v) => $v !== 'off');
        if (!empty($nonOff)) {
            throw new \InvalidArgumentException('Target entity already has a non-empty sync_config; pass overwrite=true to replace');
        }
    }
    $saved = zohoBooksSyncConfigWrite($tenantId, $cfg, null, $toSubTenantId);

    // Account mappings — Zoho uses the provider-neutral table too.
    require_once __DIR__ . '/../accounting/account_mapping_service.php';
    $sourceMappings = accountingAccountMappingsList($tenantId, $fromSubTenantId, 'zoho_books');
    $copied = 0; $skipped = 0;
    foreach ($sourceMappings as $m) {
        try {
            accountingAccountMappingsSave($tenantId, $toSubTenantId, 'zoho_books', [
                'coreflux_account_id'   => (int) $m['coreflux_account_id'],
                'provider_account_id'   => $m['provider_account_id'],
                'provider_account_code' => $m['provider_account_code'] ?? null,
                'provider_account_name' => $m['provider_account_name'] ?? null,
                'provider_account_type' => $m['provider_account_type'] ?? null,
                'source'                => 'imported',
                'confidence'            => (int) ($m['confidence'] ?? 100),
                'notes'                 => 'Copied from entity #' . $fromSubTenantId,
            ]);
            $copied++;
        } catch (\Throwable $_) { $skipped++; }
    }
    zohoBooksAudit($tenantId, 'sync_config_copy', [
        'ok' => true, 'sub_tenant_id' => $toSubTenantId,
        'detail' => ['from' => $fromSubTenantId, 'to' => $toSubTenantId, 'mappings_copied' => $copied],
    ]);
    return [
        'sync_config'       => $saved,
        'mappings_copied'   => $copied,
        'mappings_skipped'  => $skipped,
        'from'              => $fromSubTenantId,
        'to'                => $toSubTenantId,
    ];
}

// ---------------------------------------------------------------------
// Audit
// ---------------------------------------------------------------------

function zohoBooksAudit(int $tenantId, string $action, array $opts = []): void
{
    try {
        getDB()->prepare(
            'INSERT INTO zoho_books_sync_audit
                (tenant_id, sub_tenant_id, action, entity_type, direction, ok,
                 items_processed, items_skipped, items_failed,
                 detail, actor_user_id)
             VALUES (:t, :st, :a, :et, :dir, :ok, :ip, :is, :if, :det, :u)'
        )->execute([
            't'   => $tenantId,
            'st'  => isset($opts['sub_tenant_id']) && (int) $opts['sub_tenant_id'] > 0 ? (int) $opts['sub_tenant_id'] : null,
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
