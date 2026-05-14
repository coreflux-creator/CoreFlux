<?php
/**
 * Plaid service — Link / Auth / Transactions / Webhooks.
 *
 *   plaidConfigured()                      → bool, env-keys present
 *   plaidPost($endpoint, $body)            → array  (all calls go through here, includes auth)
 *   plaidExchangePublicToken($publicToken) → ['access_token' => ..., 'item_id' => ...]
 *   plaidGetAccounts($accessToken)         → /accounts/get response
 *   plaidGetAuth($accessToken)             → /auth/get response
 *   plaidSyncTransactions($accessToken, $cursor) → /transactions/sync response
 *   plaidVerifyWebhook($jwt, $rawBody)     → bool — ES256 + body-hash + freshness
 *
 * Refer to /app/core/payment_rails/plaid_transfer_driver.php for the
 * Transfer-specific endpoints (separate driver, same env keys).
 *
 * Encryption: all access_tokens persisted go through encryptField() in
 * /app/core/encryption.php (AES-256-GCM, COREFLUX_DATA_KEY).
 */

declare(strict_types=1);

// Eager-load host-specific secrets BEFORE any service function runs. Same
// pattern as ai_service.php / gusto_service.php. Some entry points (cron,
// webhooks) call plaidConfigured() before requiring encryption.php, so we
// can't rely on encryption.php's lazy load to prime PLAID_* constants.
$_plaidLocalConfig = __DIR__ . '/config.local.php';
if (file_exists($_plaidLocalConfig)) require_once $_plaidLocalConfig;
unset($_plaidLocalConfig);

require_once __DIR__ . '/encryption.php';
require_once __DIR__ . '/sim_mock_bridge.php';

// ---------------------------------------------------------------- config

function plaidConfigured(): bool
{
    $cid = plaidGet('PLAID_CLIENT_ID');
    $env = plaidEnv();
    $sec = $env === 'production' ? plaidGet('PLAID_SECRET_PRODUCTION') : plaidGet('PLAID_SECRET_SANDBOX');
    return $cid && $sec && in_array($env, ['sandbox','production'], true);
}

function plaidEnv(): string
{
    $e = strtolower((string) plaidGet('PLAID_ENV', 'sandbox'));
    return in_array($e, ['sandbox','production'], true) ? $e : 'sandbox';
}

function plaidHost(): string
{
    return plaidEnv() === 'production' ? 'https://production.plaid.com' : 'https://sandbox.plaid.com';
}

function plaidGet(string $name, ?string $default = null): ?string
{
    if (defined($name)) {
        $v = constant($name);
        if (is_string($v) && $v !== '') return $v;
    }
    $v = getenv($name);
    if (is_string($v) && $v !== '') return $v;
    return $default;
}

function plaidClientCreds(): array
{
    return [
        'client_id' => plaidGet('PLAID_CLIENT_ID', ''),
        'secret'    => plaidEnv() === 'production' ? plaidGet('PLAID_SECRET_PRODUCTION', '') : plaidGet('PLAID_SECRET_SANDBOX', ''),
    ];
}

/**
 * Probe each product against the active Plaid env to confirm enablement.
 * Used by /update.php so the user sees actionable status on every deploy.
 *
 * @return array{ env: string, products: array<string, array{enabled:bool, error?:string, request_url?:string}> }
 */
function plaidProductsHealthCheck(array $products = ['auth','transactions','identity']): array
{
    $out = ['env' => plaidEnv(), 'products' => []];
    foreach ($products as $p) {
        try {
            plaidPost('/link/token/create', [
                'client_name'   => 'CoreFlux Health Check',
                'user'          => ['client_user_id' => 'cf_health_' . bin2hex(random_bytes(2))],
                'language'      => 'en',
                'country_codes' => ['US'],
                'products'      => [$p],
            ], 10);
            $out['products'][$p] = ['enabled' => true];
        } catch (PlaidApiException $e) {
            $entry = ['enabled' => false, 'error' => $e->errorCode . ': ' . $e->getMessage()];
            if (stripos($e->errorCode, 'INVALID_PRODUCT') !== false || stripos($e->getMessage(), 'not enabled') !== false) {
                $entry['request_url'] = 'https://dashboard.plaid.com/overview/request-products';
            }
            $out['products'][$p] = $entry;
        }
    }
    return $out;
}

/**
 * Resolve the canonical webhook URL Plaid should POST to.
 *
 * Order of resolution:
 *   1) PLAID_WEBHOOK_URL constant or env  (explicit override)
 *   2) APP_PUBLIC_URL constant or env     (canonical app base URL — used by mailers)
 *   3) Auto-derived from $_SERVER         (works inside a request)
 *
 * Returns null only when running in CLI without any of the above set.
 */
function plaidWebhookUrl(): ?string
{
    $explicit = plaidGet('PLAID_WEBHOOK_URL');
    if ($explicit && $explicit !== '') return rtrim($explicit, '/');

    $base = plaidGet('APP_PUBLIC_URL');
    if ($base && $base !== '') return rtrim($base, '/') . '/api/plaid_webhook.php';

    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') return null;
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $proto = strtolower(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]);
    }
    return $proto . '://' . $host . '/api/plaid_webhook.php';
}

/**
 * Ensure a single Plaid Item points at our webhook URL.
 * Returns ['updated' => bool, 'old' => ?string, 'new' => string] or throws PlaidApiException.
 */
function plaidUpdateItemWebhook(string $accessToken, string $newWebhookUrl): array
{
    $resp = plaidPost('/item/webhook/update', [
        'access_token' => $accessToken,
        'webhook'      => $newWebhookUrl,
    ]);
    return [
        'updated' => true,
        'item'    => $resp['item'] ?? null,
        'new'     => $newWebhookUrl,
    ];
}

/**
 * Push the canonical webhook URL to every linked plaid_items row.
 * Used by /update.php after each deploy so domain changes propagate
 * automatically. Read-mostly: only calls /item/webhook/update if the
 * stored URL differs from the canonical one.
 *
 * @return array{checked:int, updated:int, skipped:int, failed:int, errors:array<int,string>}
 */
function plaidSyncAllItemWebhooks(?string $forceUrl = null): array
{
    $url = $forceUrl ?: plaidWebhookUrl();
    $out = ['checked' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [], 'webhook_url' => $url];
    if (!$url) {
        $out['errors'][] = 'cannot resolve webhook URL (set APP_PUBLIC_URL or PLAID_WEBHOOK_URL)';
        return $out;
    }
    if (!plaidConfigured()) {
        $out['errors'][] = 'Plaid not configured';
        return $out;
    }
    $pdo = function_exists('getDB') ? getDB() : null;
    if (!$pdo) { $out['errors'][] = 'no db'; return $out; }

    $rows = $pdo->query(
        "SELECT id, tenant_id, item_id, access_token_ct
         FROM plaid_items
         WHERE status IN ('linked','requires_update')
         ORDER BY tenant_id, id"
    )->fetchAll(\PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $out['checked']++;
        $accessToken = plaidDecryptAccessToken($row['access_token_ct']);
        if (!$accessToken) {
            $out['failed']++;
            $out['errors'][] = "item {$row['item_id']}: decrypt failed";
            continue;
        }
        try {
            // Read the current webhook to avoid a noop write.
            $cur = plaidPost('/item/get', ['access_token' => $accessToken]);
            $existing = (string) ($cur['item']['webhook'] ?? '');
            if ($existing === $url) { $out['skipped']++; continue; }
            plaidUpdateItemWebhook($accessToken, $url);
            $out['updated']++;
            plaidAudit('core.plaid.webhook_url_synced', [
                'item_id' => $row['item_id'], 'old' => $existing, 'new' => $url,
            ], (int) $row['id']);
        } catch (PlaidApiException $e) {
            $out['failed']++;
            $out['errors'][] = "item {$row['item_id']}: " . $e->getMessage();
        }
    }
    return $out;
}

// ---------------------------------------------------------------- HTTP

class PlaidApiException extends \RuntimeException
{
    public string $errorCode = '';
    public int $httpCode = 0;
    public array $rawResponse = [];
}

function plaidPost(string $endpoint, array $body, int $timeoutSec = 25): array
{
    if (!plaidConfigured()) {
        $e = new PlaidApiException('Plaid is not configured (missing PLAID_CLIENT_ID / PLAID_SECRET_*)');
        $e->errorCode = 'NOT_CONFIGURED';
        throw $e;
    }
    $url = plaidHost() . $endpoint;
    $body = array_merge(plaidClientCreds(), $body);
    $payload = json_encode($body, JSON_UNESCAPED_SLASHES);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => $timeoutSec,
    ]);
    $resp = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        $e = new PlaidApiException('Plaid HTTP error: ' . $cerr);
        $e->errorCode = 'HTTP_ERROR';
        $e->httpCode  = 0;
        throw $e;
    }
    $decoded = json_decode((string) $resp, true) ?: [];
    if ($http >= 200 && $http < 300) return $decoded;

    $msg = ($decoded['error_message'] ?? '') ?: ('Plaid HTTP ' . $http);
    $e = new PlaidApiException($msg);
    $e->errorCode   = (string) ($decoded['error_code'] ?? 'UNKNOWN');
    $e->httpCode    = $http;
    $e->rawResponse = $decoded;
    throw $e;
}

// ---------------------------------------------------------------- helpers

function plaidExchangePublicToken(string $publicToken): array
{
    if (simShouldMockIfLoaded('plaid')) {
        require_once __DIR__ . '/../sim/mocks/plaid.php';
        $r = simMockPlaidExchange($publicToken);
        return ['access_token' => (string) $r['access_token'], 'item_id' => (string) $r['item_id']];
    }
    $r = plaidPost('/item/public_token/exchange', ['public_token' => $publicToken]);
    return ['access_token' => (string) $r['access_token'], 'item_id' => (string) $r['item_id']];
}

function plaidGetAccounts(string $accessToken): array
{
    if (simShouldMockIfLoaded('plaid')) {
        require_once __DIR__ . '/../sim/mocks/plaid.php';
        return simMockPlaidGetAccounts($accessToken);
    }
    return plaidPost('/accounts/get', ['access_token' => $accessToken]);
}

function plaidGetAuth(string $accessToken): array
{
    return plaidPost('/auth/get', ['access_token' => $accessToken]);
}

function plaidGetItem(string $accessToken): array
{
    if (simShouldMockIfLoaded('plaid')) {
        require_once __DIR__ . '/../sim/mocks/plaid.php';
        return simMockPlaidGetItem($accessToken);
    }
    return plaidPost('/item/get', ['access_token' => $accessToken]);
}

function plaidGetInstitution(string $institutionId): ?array
{
    try {
        return plaidPost('/institutions/get_by_id', [
            'institution_id' => $institutionId,
            'country_codes'  => ['US'],
        ]);
    } catch (PlaidApiException $e) {
        return null;
    }
}

function plaidSyncTransactions(string $accessToken, ?string $cursor, int $count = 250): array
{
    if (simShouldMockIfLoaded('plaid')) {
        require_once __DIR__ . '/../sim/mocks/plaid.php';
        return simMockPlaidSyncTransactions($accessToken, $cursor, $count);
    }
    $body = ['access_token' => $accessToken, 'count' => $count];
    if ($cursor !== null && $cursor !== '') $body['cursor'] = $cursor;
    return plaidPost('/transactions/sync', $body);
}

// ---------------------------------------------------------------- webhook verification

/**
 * Verify a Plaid webhook JWT (ES256). Returns true iff:
 *   - alg=ES256
 *   - signature checks against the Plaid-published verification key
 *   - JWT iat within last 5 minutes
 *   - sha256(rawBody) === claim 'request_body_sha256'
 *
 * @param string $jwt      raw value of the Plaid-Verification header
 * @param string $rawBody  raw request body (do NOT re-encode the JSON)
 */
function plaidVerifyWebhook(string $jwt, string $rawBody): bool
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return false;
    [$h64, $p64, $s64] = $parts;

    $header  = json_decode((string) plaidB64UrlDecode($h64), true);
    $payload = json_decode((string) plaidB64UrlDecode($p64), true);
    if (!is_array($header) || !is_array($payload)) return false;
    if (($header['alg'] ?? '') !== 'ES256')        return false;
    $kid = (string) ($header['kid'] ?? '');
    if ($kid === '') return false;

    // Fetch the verification key (Plaid will return inactive keys too — that's expected
    // since rotated keys must remain verifiable until expiration).
    try {
        $resp = plaidPost('/webhook_verification_key/get', ['key_id' => $kid]);
    } catch (PlaidApiException $e) {
        return false;
    }
    $jwk = $resp['key'] ?? null;
    if (!is_array($jwk) || ($jwk['alg'] ?? '') !== 'ES256') return false;
    $pem = plaidJwkToPem($jwk);
    if ($pem === null) return false;

    // ES256 raw signature is 64 bytes (r||s, 32 each); PHP openssl_verify expects DER.
    $sigRaw = (string) plaidB64UrlDecode($s64);
    if (strlen($sigRaw) !== 64) return false;
    $sigDer = plaidEs256RawToDer($sigRaw);

    $signingInput = $h64 . '.' . $p64;
    $ok = openssl_verify($signingInput, $sigDer, $pem, OPENSSL_ALGO_SHA256);
    if ($ok !== 1) return false;

    // Freshness: 5-minute window.
    $iat = (int) ($payload['iat'] ?? 0);
    if ($iat <= 0 || (time() - $iat) > 300) return false;

    // Body hash check: timing-safe.
    $claim = (string) ($payload['request_body_sha256'] ?? '');
    $calc  = hash('sha256', $rawBody);
    return hash_equals($claim, $calc);
}

function plaidB64UrlDecode(string $s): string|false
{
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    return base64_decode(strtr($s, '-_', '+/'), true);
}

/** Convert a P-256 EC JWK into a PEM-encoded SubjectPublicKeyInfo. */
function plaidJwkToPem(array $jwk): ?string
{
    if (($jwk['kty'] ?? '') !== 'EC' || ($jwk['crv'] ?? '') !== 'P-256') return null;
    $x = plaidB64UrlDecode((string) ($jwk['x'] ?? ''));
    $y = plaidB64UrlDecode((string) ($jwk['y'] ?? ''));
    if ($x === false || $y === false || strlen($x) !== 32 || strlen($y) !== 32) return null;

    // SubjectPublicKeyInfo for P-256:
    //   30 59                        SEQUENCE
    //     30 13                      SEQUENCE
    //       06 07 2A 86 48 CE 3D 02 01     OID id-ecPublicKey
    //       06 08 2A 86 48 CE 3D 03 01 07  OID prime256v1 (P-256)
    //     03 42 00 04 || x || y      BIT STRING (uncompressed point)
    $der  = "\x30\x59\x30\x13\x06\x07\x2A\x86\x48\xCE\x3D\x02\x01\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07";
    $der .= "\x03\x42\x00\x04" . $x . $y;
    $pem  = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    return $pem;
}

/** Convert a 64-byte raw ES256 signature (r||s) to DER (SEQUENCE { INTEGER r, INTEGER s }). */
function plaidEs256RawToDer(string $raw): string
{
    $r = ltrim(substr($raw, 0, 32), "\x00");
    $s = ltrim(substr($raw, 32, 32), "\x00");
    if ($r === '' || (ord($r[0]) & 0x80))  $r = "\x00" . $r;
    if ($s === '' || (ord($s[0]) & 0x80))  $s = "\x00" . $s;
    $rEnc = "\x02" . chr(strlen($r)) . $r;
    $sEnc = "\x02" . chr(strlen($s)) . $s;
    $body = $rEnc . $sEnc;
    return "\x30" . chr(strlen($body)) . $body;
}

// ---------------------------------------------------------------- access-token helpers

/** Encrypt + persist a Plaid access_token onto a plaid_items row. */
function plaidEncryptAccessToken(string $accessToken): string
{
    $ct = encryptField($accessToken);
    if ($ct === null) throw new \RuntimeException('encrypt failed');
    return $ct;
}

function plaidDecryptAccessToken(?string $ct): ?string
{
    if ($ct === null || $ct === '') return null;
    return decryptField($ct);
}

// ---------------------------------------------------------------- audit

function plaidAudit(string $event, array $meta = [], ?int $targetId = null): void
{
    try {
        $ctx = function_exists('currentTenantContext') ? currentTenantContext() : null;
        $pdo = function_exists('getDB') ? getDB() : null;
        if (!$pdo) return;
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
        error_log('[plaid.audit] ' . $event . ' write-failed: ' . $e->getMessage());
    }
}
/**
 * Find or create an institution-level parent COA row for liability auto-grouping.
 *
 * Creates a parent row at code "{baseCode}-{slug}" (e.g. "2100-AMEX")
 * named exactly the institution (e.g. "American Express"), with
 * account_type='liability', is_postable=0 (header row, no journal entries
 * post to it directly), parent_account_id=NULL.
 *
 * Idempotent: if an existing row matches by name OR code, returns its id
 * without modification. User is free to rename / re-parent it later via the
 * Chart of Accounts UI.
 */
function plaidEnsureInstitutionParent(PDO $pdo, int $tenantId, string $instName, string $baseCode): ?int
{
    $instName = trim($instName);
    if ($instName === '') return null;

    $stmt = $pdo->prepare(
        "SELECT id FROM accounting_accounts
          WHERE tenant_id = :t AND name = :n AND account_type = 'liability' LIMIT 1"
    );
    $stmt->execute(['t' => $tenantId, 'n' => $instName]);
    $existing = (int) $stmt->fetchColumn();
    if ($existing > 0) return $existing;

    $slug = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $instName));
    $slug = $slug === '' ? 'INST' : substr($slug, 0, 8);
    $code = $baseCode . '-' . $slug;

    $codeCheck = $pdo->prepare(
        "SELECT id FROM accounting_accounts WHERE tenant_id = :t AND code = :c LIMIT 1"
    );
    $codeCheck->execute(['t' => $tenantId, 'c' => $code]);
    $byCode = (int) $codeCheck->fetchColumn();
    if ($byCode > 0) return $byCode;

    $pdo->prepare(
        "INSERT INTO accounting_accounts
            (tenant_id, code, name, account_type, normal_side, is_postable, parent_account_id, active, created_at)
         VALUES (:t, :c, :n, 'liability', 'credit', 0, NULL, 1, NOW())"
    )->execute(['t' => $tenantId, 'c' => $code, 'n' => $instName]);

    return (int) $pdo->lastInsertId();
}

/**
 * Pick a unique GL account code under (tenant_id, code).
 *   First try base (e.g. '1000'); if taken, append '-{last4}'; if still taken,
 *   append '-{last8 of accId}'; otherwise increment a numeric suffix until free.
 *
 * Shared between the Plaid Link exchange path (/api/plaid_bank_link.php)
 * and the orphan-backfill path (/api/plaid_diagnostics.php?action=backfill).
 */
function plaidAllocateBankGlCode(PDO $pdo, int $tenantId, string $base, ?string $mask, string $accId): string
{
    $candidates = [$base];
    if ($mask) $candidates[] = $base . '-' . $mask;
    $candidates[] = $base . '-' . substr(preg_replace('/[^A-Za-z0-9]/', '', $accId), -8);
    $check  = $pdo->prepare('SELECT 1 FROM accounting_bank_accounts WHERE tenant_id = :t AND gl_account_code = :c LIMIT 1');
    $check2 = $pdo->prepare('SELECT 1 FROM accounting_accounts        WHERE tenant_id = :t AND code             = :c LIMIT 1');
    foreach ($candidates as $c) {
        $check->execute(['t' => $tenantId, 'c' => $c]);
        if ($check->fetchColumn()) continue;
        $check2->execute(['t' => $tenantId, 'c' => $c]);
        if ($check2->fetchColumn()) continue;
        return $c;
    }
    for ($i = 2; $i < 100; $i++) {
        $c = $base . '-' . $i;
        $check->execute(['t' => $tenantId, 'c' => $c]);
        if ($check->fetchColumn()) continue;
        $check2->execute(['t' => $tenantId, 'c' => $c]);
        if ($check2->fetchColumn()) continue;
        return $c;
    }
    throw new RuntimeException('Could not allocate a free GL code for ' . $base);
}

/**
 * Persist /accounts/get balance data into plaid_accounts so the Treasury
 * list pages can render a live "Bank balance" column without firing Plaid
 * on every GET. Safe to call multiple times — uses UPDATE + runtime
 * ALTER guards so tenants that haven't run migration 010 yet self-heal.
 *
 * @param array  $plaidAccounts  Raw /accounts/get `accounts` array (each item
 *                               has `account_id`, `balances.current`,
 *                               `balances.available`, `balances.limit`,
 *                               `balances.iso_currency_code`).
 * @return int                   Number of plaid_accounts rows updated.
 */
function plaidPersistAccountBalances(PDO $pdo, int $tenantId, array $plaidAccounts): int
{
    if (!$plaidAccounts) return 0;

    // Self-heal: add the balance columns if migration 010 isn't applied yet.
    static $columnsEnsured = false;
    if (!$columnsEnsured) {
        foreach ([
            ['current_balance_cents',   'BIGINT NULL'],
            ['available_balance_cents', 'BIGINT NULL'],
            ['limit_balance_cents',     'BIGINT NULL'],
            ['iso_currency_code',       'CHAR(3) NULL'],
            ['balance_as_of',           'TIMESTAMP NULL'],
        ] as [$col, $def]) {
            try {
                $chk = $pdo->prepare(
                    "SELECT COUNT(*) FROM information_schema.columns
                      WHERE table_schema = DATABASE()
                        AND table_name   = 'plaid_accounts'
                        AND column_name  = :c"
                );
                $chk->execute(['c' => $col]);
                if ((int) $chk->fetchColumn() === 0) {
                    $pdo->exec("ALTER TABLE plaid_accounts ADD COLUMN {$col} {$def}");
                }
            } catch (\Throwable $_) { /* non-fatal */ }
        }
        $columnsEnsured = true;
    }

    $upd = $pdo->prepare(
        'UPDATE plaid_accounts
            SET current_balance_cents   = :cur,
                available_balance_cents = :avl,
                limit_balance_cents     = :lim,
                iso_currency_code       = :iso,
                balance_as_of           = NOW(),
                updated_at              = NOW()
          WHERE tenant_id = :t AND account_id = :a'
    );

    $updated = 0;
    foreach ($plaidAccounts as $acc) {
        $accId = (string) ($acc['account_id'] ?? '');
        if ($accId === '') continue;
        $b = is_array($acc['balances'] ?? null) ? $acc['balances'] : [];
        $cur = isset($b['current'])   && is_numeric($b['current'])   ? (int) round(((float) $b['current'])   * 100) : null;
        $avl = isset($b['available']) && is_numeric($b['available']) ? (int) round(((float) $b['available']) * 100) : null;
        $lim = isset($b['limit'])     && is_numeric($b['limit'])     ? (int) round(((float) $b['limit'])     * 100) : null;
        $iso = isset($b['iso_currency_code']) ? substr((string) $b['iso_currency_code'], 0, 3) : 'USD';

        try {
            $upd->execute([
                't'   => $tenantId, 'a' => $accId,
                'cur' => $cur, 'avl' => $avl, 'lim' => $lim, 'iso' => $iso,
            ]);
            $updated += $upd->rowCount();
        } catch (\Throwable $_) { /* non-fatal per-row */ }
    }
    return $updated;
}


