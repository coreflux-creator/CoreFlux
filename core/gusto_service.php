<?php
/**
 * Gusto Embedded Payroll OAuth + API service.
 *
 * Mirrors the shape of /app/core/plaid_service.php:
 *   - Single chokepoint for HTTP calls (gustoRequest)
 *   - Encrypted-at-rest tokens via core/encryption.php
 *   - Audit log writes via gustoAudit
 *   - Env-aware host resolution (sandbox vs production)
 *
 * All API calls require a tenant_gusto_connections row (per tenant ↔ company).
 * Token refresh is automatic on 401 and proactively when expiring within 60s.
 *
 * Configuration (in /app/core/config.local.php on each host):
 *     define('GUSTO_CLIENT_ID',         'ae_...');
 *     define('GUSTO_CLIENT_SECRET',     '...');
 *     define('GUSTO_ENV',               'sandbox');   // or 'production'
 *     define('GUSTO_REDIRECT_URI',      'https://corefluxapp.com/api/gusto_oauth_callback.php');
 *     define('GUSTO_WEBHOOK_SECRET',    '...');       // shared secret from Gusto
 *     define('GUSTO_DEFAULT_SCOPES',    'companies:read employees:read payrolls:read payrolls:write pay_schedules:read compensations:read jobs:read');
 *
 * The OAuth code-exchange + refresh paths use HTTP Basic auth with
 * client_id:client_secret per Gusto's OAuth2 spec.
 */

declare(strict_types=1);

require_once __DIR__ . '/encryption.php';

// ---------------------------------------------------------------- exceptions
class GustoApiException extends \RuntimeException
{
    public string $errorKey   = '';
    public string $errorCategory = '';
    public int    $httpCode   = 0;
    public array  $rawResponse = [];
}

class GustoAuthException extends GustoApiException {}

// ---------------------------------------------------------------- config
function gustoGet(string $name, ?string $default = null): ?string
{
    if (defined($name)) {
        $v = constant($name);
        if (is_string($v) && $v !== '') return $v;
    }
    $v = getenv($name);
    if (is_string($v) && $v !== '') return $v;
    return $default;
}

function gustoConfigured(): bool
{
    return (bool) (gustoGet('GUSTO_CLIENT_ID') && gustoGet('GUSTO_CLIENT_SECRET') && gustoGet('GUSTO_REDIRECT_URI'));
}

function gustoEnv(): string
{
    $e = strtolower((string) gustoGet('GUSTO_ENV', 'sandbox'));
    return in_array($e, ['sandbox', 'production'], true) ? $e : 'sandbox';
}

function gustoApiHost(): string
{
    return gustoEnv() === 'production'
        ? 'https://api.gusto.com'
        : 'https://api.gusto-demo.com';
}

function gustoDefaultScopes(): string
{
    return (string) gustoGet(
        'GUSTO_DEFAULT_SCOPES',
        'companies:read employees:read payrolls:read payrolls:write pay_schedules:read compensations:read jobs:read'
    );
}

// ---------------------------------------------------------------- OAuth URL builder

/**
 * Build the Gusto authorization URL the user's browser should be sent to.
 * Stores the per-request `state` token in the session for CSRF validation
 * by the callback endpoint.
 */
function gustoAuthorizationUrl(int $tenantId, ?int $userId = null): string
{
    if (!gustoConfigured()) {
        throw new GustoApiException('Gusto is not configured (missing GUSTO_CLIENT_ID / GUSTO_CLIENT_SECRET / GUSTO_REDIRECT_URI)');
    }
    if (session_status() === PHP_SESSION_NONE) session_start();

    $state = bin2hex(random_bytes(24));
    $_SESSION['gusto_oauth'] = [
        'state'        => $state,
        'tenant_id'    => $tenantId,
        'user_id'      => $userId,
        'created_at'   => time(),
        'expires_at'   => time() + 600,   // 10 min window
    ];

    $params = [
        'client_id'     => (string) gustoGet('GUSTO_CLIENT_ID'),
        'redirect_uri'  => (string) gustoGet('GUSTO_REDIRECT_URI'),
        'response_type' => 'code',
        'state'         => $state,
        'scope'         => gustoDefaultScopes(),
    ];
    return gustoApiHost() . '/oauth/authorize?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

/**
 * Validate state from session (single-use), return the saved metadata.
 * Throws GustoAuthException on mismatch / expiry / replay.
 */
function gustoConsumeOAuthState(string $stateFromCallback): array
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    $saved = $_SESSION['gusto_oauth'] ?? null;
    unset($_SESSION['gusto_oauth']);   // single-use
    if (!is_array($saved) || empty($saved['state'])) {
        throw new GustoAuthException('No pending Gusto OAuth state in session');
    }
    if (!hash_equals((string) $saved['state'], (string) $stateFromCallback)) {
        throw new GustoAuthException('Gusto OAuth state mismatch (possible CSRF)');
    }
    if (time() > (int) ($saved['expires_at'] ?? 0)) {
        throw new GustoAuthException('Gusto OAuth state expired — restart the connect flow');
    }
    return $saved;
}

// ---------------------------------------------------------------- OAuth token endpoints

/**
 * Exchange an authorization code for access + refresh tokens.
 * Returns the raw token payload (already validated to contain access_token / refresh_token).
 */
function gustoExchangeCodeForToken(string $code): array
{
    return _gustoTokenRequest([
        'client_id'     => (string) gustoGet('GUSTO_CLIENT_ID'),
        'client_secret' => (string) gustoGet('GUSTO_CLIENT_SECRET'),
        'redirect_uri'  => (string) gustoGet('GUSTO_REDIRECT_URI'),
        'code'          => $code,
        'grant_type'    => 'authorization_code',
    ]);
}

function gustoRefreshAccessToken(string $refreshToken): array
{
    return _gustoTokenRequest([
        'client_id'     => (string) gustoGet('GUSTO_CLIENT_ID'),
        'client_secret' => (string) gustoGet('GUSTO_CLIENT_SECRET'),
        'grant_type'    => 'refresh_token',
        'refresh_token' => $refreshToken,
    ]);
}

function _gustoTokenRequest(array $body): array
{
    if (!gustoConfigured()) throw new GustoApiException('Gusto not configured');
    $url = gustoApiHost() . '/oauth/token';
    $payload = json_encode($body, JSON_UNESCAPED_SLASHES);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 25,
    ]);
    $resp = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        $e = new GustoAuthException('Gusto token endpoint HTTP error: ' . $cerr);
        $e->httpCode = 0;
        throw $e;
    }
    $decoded = json_decode((string) $resp, true) ?: [];
    if ($http < 200 || $http >= 300) {
        $msg = ($decoded['error_description'] ?? $decoded['error'] ?? '') ?: 'Gusto token HTTP ' . $http;
        $e = new GustoAuthException((string) $msg);
        $e->httpCode    = $http;
        $e->rawResponse = $decoded;
        throw $e;
    }
    if (empty($decoded['access_token']) || empty($decoded['refresh_token'])) {
        throw new GustoAuthException('Gusto token response missing access_token / refresh_token');
    }
    return $decoded;
}

// ---------------------------------------------------------------- Connection persistence

function gustoSaveConnection(int $tenantId, int $userId, array $tokenPayload, ?string $companyUuid = null, ?string $companyName = null): int
{
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/tenant_scope.php';

    $companyUuid = $companyUuid ?: (string) ($tokenPayload['company_uuid'] ?? '');
    if ($companyUuid === '') {
        // Some Gusto OAuth responses don't include company_uuid — derive it from /v1/me.
        try {
            $me = gustoRequest('GET', '/v1/me', null, ['access_token' => (string) $tokenPayload['access_token']]);
            $companyUuid = (string) ($me['roles'][0]['payroll_admin']['companies'][0]['uuid'] ?? '');
            $companyName = $companyName ?: (string) ($me['roles'][0]['payroll_admin']['companies'][0]['name'] ?? '');
        } catch (\Throwable $e) {
            error_log('[gusto] /v1/me failed during save: ' . $e->getMessage());
        }
    }
    if ($companyUuid === '') {
        throw new GustoApiException('Could not resolve Gusto company_uuid from token response or /v1/me');
    }

    $expiresAt = date('Y-m-d H:i:s', time() + (int) ($tokenPayload['expires_in'] ?? 7200));
    $accessCt  = encryptField((string) $tokenPayload['access_token']);
    $refreshCt = encryptField((string) $tokenPayload['refresh_token']);
    if (!$accessCt || !$refreshCt) throw new GustoApiException('Encryption failed for Gusto tokens');

    $pdo = getDB();
    $existing = $pdo->prepare(
        'SELECT id FROM tenant_gusto_connections WHERE tenant_id = :t AND company_uuid = :c'
    );
    $existing->execute(['t' => $tenantId, 'c' => $companyUuid]);
    $row = $existing->fetch();

    if ($row) {
        $stmt = $pdo->prepare(
            'UPDATE tenant_gusto_connections SET
                access_token_ct = :a, refresh_token_ct = :r, token_type = :tt,
                access_token_expires_at = :exp, scopes = :sc, env = :env,
                status = :status, last_refreshed_at = NOW(), last_error = NULL,
                company_name = COALESCE(:cn, company_name)
             WHERE id = :id'
        );
        $stmt->execute([
            'a' => $accessCt, 'r' => $refreshCt, 'tt' => (string) ($tokenPayload['token_type'] ?? 'bearer'),
            'exp' => $expiresAt, 'sc' => (string) ($tokenPayload['scope'] ?? gustoDefaultScopes()),
            'env' => gustoEnv(), 'status' => 'active', 'cn' => $companyName, 'id' => (int) $row['id'],
        ]);
        return (int) $row['id'];
    }
    return scopedInsert('tenant_gusto_connections', [
        'tenant_id'               => $tenantId,
        'company_uuid'            => $companyUuid,
        'company_name'            => $companyName,
        'access_token_ct'         => $accessCt,
        'refresh_token_ct'        => $refreshCt,
        'token_type'              => (string) ($tokenPayload['token_type'] ?? 'bearer'),
        'access_token_expires_at' => $expiresAt,
        'scopes'                  => (string) ($tokenPayload['scope'] ?? gustoDefaultScopes()),
        'env'                     => gustoEnv(),
        'status'                  => 'active',
        'connected_by_user_id'    => $userId,
        'connected_at'            => date('Y-m-d H:i:s'),
    ]);
}

/**
 * Resolve the active Gusto connection for the current tenant. Returns
 * null if the tenant hasn't connected yet (CSV-fallback case).
 */
function gustoActiveConnection(int $tenantId): ?array
{
    require_once __DIR__ . '/db.php';
    $pdo = getDB();
    if (!$pdo) return null;
    $stmt = $pdo->prepare(
        'SELECT * FROM tenant_gusto_connections
         WHERE tenant_id = :t AND status = "active"
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId]);
    return $stmt->fetch() ?: null;
}

/**
 * Get a fresh access_token for a connection row, refreshing on the wire if
 * within 60s of expiry. Mutates the DB row + returns the (decrypted) bearer.
 */
function gustoTokenForConnection(array $conn): string
{
    $expiresAt = strtotime((string) $conn['access_token_expires_at']);
    $needRefresh = $expiresAt <= (time() + 60);
    if (!$needRefresh) {
        $access = decryptField((string) $conn['access_token_ct']);
        if (!$access) throw new GustoAuthException('Decrypt of access_token failed');
        return $access;
    }
    $refresh = decryptField((string) $conn['refresh_token_ct']);
    if (!$refresh) throw new GustoAuthException('Decrypt of refresh_token failed');

    $payload = gustoRefreshAccessToken($refresh);

    require_once __DIR__ . '/db.php';
    $accessCt  = encryptField((string) $payload['access_token']);
    $refreshCt = encryptField((string) $payload['refresh_token']);
    $newExp    = date('Y-m-d H:i:s', time() + (int) ($payload['expires_in'] ?? 7200));
    $stmt = getDB()->prepare(
        'UPDATE tenant_gusto_connections
            SET access_token_ct = :a, refresh_token_ct = :r,
                access_token_expires_at = :exp, last_refreshed_at = NOW(),
                last_error = NULL
          WHERE id = :id'
    );
    $stmt->execute(['a' => $accessCt, 'r' => $refreshCt, 'exp' => $newExp, 'id' => (int) $conn['id']]);
    gustoAudit('payroll.gusto.token_refreshed', ['connection_id' => (int) $conn['id']], (int) $conn['id']);
    return (string) $payload['access_token'];
}

// ---------------------------------------------------------------- API HTTP wrapper

/**
 * Single chokepoint for Gusto API calls. Handles auth header, rate-limit
 * retries (429 with Retry-After), and one auto-refresh on 401.
 *
 * @param array $auth { connection: array }  OR  { access_token: string }
 */
function gustoRequest(string $method, string $endpoint, $body = null, array $auth = []): array
{
    if (!gustoConfigured()) throw new GustoApiException('Gusto not configured');

    if (isset($auth['connection'])) {
        $accessToken = gustoTokenForConnection($auth['connection']);
    } elseif (isset($auth['access_token'])) {
        $accessToken = (string) $auth['access_token'];
    } else {
        throw new GustoApiException('gustoRequest requires auth.connection or auth.access_token');
    }

    return _gustoHttp($method, $endpoint, $body, $accessToken, $auth['connection'] ?? null, /*allowRefresh*/ isset($auth['connection']));
}

function _gustoHttp(string $method, string $endpoint, $body, string $accessToken, ?array $connection, bool $allowRefresh, int $retries = 0): array
{
    $url = gustoApiHost() . $endpoint;
    $ch = curl_init();
    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json',
        'Content-Type: application/json',
        'X-Gusto-API-Version: 2024-04-01',
    ];
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HEADER         => true,
    ];
    if ($body !== null && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opts);
    $raw  = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hdrLen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        $e = new GustoApiException('Gusto HTTP error: ' . $cerr);
        $e->httpCode = 0;
        throw $e;
    }
    $hdrTxt = substr((string) $raw, 0, $hdrLen);
    $bodyTxt = (string) substr((string) $raw, $hdrLen);
    $decoded = $bodyTxt === '' ? [] : (json_decode($bodyTxt, true) ?: []);

    // Auth failure → one transparent refresh + retry (only for connection-backed calls).
    if ($http === 401 && $allowRefresh && $connection && $retries === 0) {
        $refresh = decryptField((string) $connection['refresh_token_ct']);
        if ($refresh) {
            try {
                $payload = gustoRefreshAccessToken($refresh);
                require_once __DIR__ . '/db.php';
                getDB()->prepare(
                    'UPDATE tenant_gusto_connections SET
                        access_token_ct = :a, refresh_token_ct = :r,
                        access_token_expires_at = :exp, last_refreshed_at = NOW(), last_error = NULL
                     WHERE id = :id'
                )->execute([
                    'a' => encryptField((string) $payload['access_token']),
                    'r' => encryptField((string) $payload['refresh_token']),
                    'exp' => date('Y-m-d H:i:s', time() + (int) ($payload['expires_in'] ?? 7200)),
                    'id' => (int) $connection['id'],
                ]);
                gustoAudit('payroll.gusto.token_refreshed_on_401', ['connection_id' => (int) $connection['id']], (int) $connection['id']);
                return _gustoHttp($method, $endpoint, $body, (string) $payload['access_token'], $connection, false, $retries + 1);
            } catch (\Throwable $e) {
                error_log('[gusto] refresh-on-401 failed: ' . $e->getMessage());
            }
        }
    }

    // Rate-limit handling (max 1 retry honoring Retry-After).
    if ($http === 429 && $retries === 0) {
        $retryAfter = 1;
        if (preg_match('/Retry-After:\s*(\d+)/i', $hdrTxt, $m)) $retryAfter = max(1, min(30, (int) $m[1]));
        sleep($retryAfter);
        return _gustoHttp($method, $endpoint, $body, $accessToken, $connection, $allowRefresh, $retries + 1);
    }

    if ($http >= 200 && $http < 300) return $decoded;

    $first = $decoded['errors'][0] ?? ['message' => $bodyTxt ?: ('HTTP ' . $http)];
    $e = new GustoApiException((string) ($first['message'] ?? 'Gusto API error'));
    $e->errorKey       = (string) ($first['error_key']  ?? '');
    $e->errorCategory  = (string) ($first['category']   ?? '');
    $e->httpCode       = $http;
    $e->rawResponse    = $decoded;
    throw $e;
}

// ---------------------------------------------------------------- Payroll API helpers

function gustoListUnprocessedPayrolls(array $conn, ?string $startDate = null, ?string $endDate = null): array
{
    $params = ['processing_statuses' => 'unprocessed'];
    if ($startDate) $params['start_date'] = $startDate;
    if ($endDate)   $params['end_date']   = $endDate;
    $endpoint = '/v1/companies/' . urlencode((string) $conn['company_uuid']) . '/payrolls?' . http_build_query($params);
    return gustoRequest('GET', $endpoint, null, ['connection' => $conn]);
}

function gustoGetPayroll(array $conn, string $payrollUuid): array
{
    return gustoRequest(
        'GET',
        '/v1/companies/' . urlencode((string) $conn['company_uuid']) . '/payrolls/' . urlencode($payrollUuid),
        null,
        ['connection' => $conn]
    );
}

function gustoUpdatePayrollCompensations(array $conn, string $payrollUuid, int $version, array $employeeCompensations): array
{
    return gustoRequest(
        'PUT',
        '/v1/companies/' . urlencode((string) $conn['company_uuid']) . '/payrolls/' . urlencode($payrollUuid),
        ['version' => $version, 'employee_compensations' => $employeeCompensations],
        ['connection' => $conn]
    );
}

function gustoCalculatePayroll(array $conn, string $payrollUuid, int $version): array
{
    return gustoRequest(
        'PUT',
        '/v1/companies/' . urlencode((string) $conn['company_uuid']) . '/payrolls/' . urlencode($payrollUuid) . '/calculate',
        ['version' => $version],
        ['connection' => $conn]
    );
}

function gustoSubmitPayroll(array $conn, string $payrollUuid, int $version): array
{
    return gustoRequest(
        'PUT',
        '/v1/companies/' . urlencode((string) $conn['company_uuid']) . '/payrolls/' . urlencode($payrollUuid) . '/submit',
        ['version' => $version],
        ['connection' => $conn]
    );
}

// ---------------------------------------------------------------- Webhook verification

/**
 * Verify Gusto's webhook HMAC signature.
 *   X-Gusto-Signature: <hex sha256 hmac of raw body using shared secret>
 */
function gustoVerifyWebhook(string $signatureHeader, string $rawBody): bool
{
    $secret = (string) gustoGet('GUSTO_WEBHOOK_SECRET', '');
    if ($secret === '' || $signatureHeader === '') return false;
    $calc = hash_hmac('sha256', $rawBody, $secret);
    return hash_equals($calc, $signatureHeader);
}

// ---------------------------------------------------------------- Audit
function gustoAudit(string $event, array $meta = [], ?int $targetId = null): void
{
    try {
        require_once __DIR__ . '/db.php';
        $pdo = function_exists('getDB') ? getDB() : null;
        if (!$pdo) return;
        $ctx = function_exists('currentTenantContext') ? currentTenantContext() : null;
        $pdo->prepare(
            'INSERT INTO audit_log (tenant_id, actor_user_id, event, target_id, meta_json, ip_address, created_at)
             VALUES (:tenant_id, :actor, :event, :target_id, :meta_json, :ip, NOW())'
        )->execute([
            'tenant_id' => $ctx['tenant_id'] ?? null,
            'actor'     => $ctx['user']['id'] ?? null,
            'event'     => $event,
            'target_id' => $targetId,
            'meta_json' => json_encode($meta),
            'ip'        => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (\Throwable $e) {
        error_log('[gusto.audit] ' . $event . ' write-failed: ' . $e->getMessage());
    }
}
