<?php
/**
 * Plaid integration smoke — Link / Auth / Transactions.
 *
 * Validates:
 *  - migration 006_plaid_items.sql has plaid_items + plaid_accounts +
 *    plaid_webhook_events with the expected columns
 *  - plaid_service.php exposes the public API (configured / post / verify /
 *    encrypt / audit) and round-trips encrypt/decrypt
 *  - all 5 endpoints exist + permission-gated
 *  - PlaidLinkButton.jsx is wired to /api/plaid_link_token + /api/plaid_exchange
 *  - JWK→PEM helper handles a P-256 spec test vector
 *  - ES256 raw→DER conversion handles edge cases (high-bit r/s)
 *
 * Functional (only if PLAID_CLIENT_ID + PLAID_SECRET_SANDBOX are set):
 *  - /link/token/create returns a usable link_token
 *  - /sandbox/public_token/create → exchange → /accounts/get → /auth/get
 *  - /transactions/sync returns valid response shape
 */

declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$c = fn (string $h, string $n): bool => strpos($h, $n) !== false;

echo "Migration 006_plaid_items.sql\n";
$mig = (string) file_get_contents(__DIR__ . '/../core/migrations/006_plaid_items.sql');
$a('plaid_items table',                       $c($mig, 'CREATE TABLE IF NOT EXISTS plaid_items'));
$a('plaid_accounts table',                    $c($mig, 'CREATE TABLE IF NOT EXISTS plaid_accounts'));
$a('plaid_webhook_events table',              $c($mig, 'CREATE TABLE IF NOT EXISTS plaid_webhook_events'));
$a('access_token_ct VARBINARY',               $c($mig, 'access_token_ct          VARBINARY(512)'));
$a('purpose enum has 4 values',
    $c($mig, "ENUM('bank_feed','vendor_banking','employee_banking','tenant_funding')"));
$a('status enum (linked/requires_update/revoked/error)',
    $c($mig, "ENUM('linked','requires_update','revoked','error')"));
$a('transactions_cursor MEDIUMTEXT',          $c($mig, 'transactions_cursor      MEDIUMTEXT'));
$a('webhook signature_verified flag',         $c($mig, 'signature_verified  TINYINT(1)'));
$a('utf8mb4_unicode_ci (no 0900_ai_ci)',
    $c($mig, 'utf8mb4_unicode_ci') && stripos($mig, 'utf8mb4_0900_ai_ci') === false);

echo "\ncore/plaid_service.php\n";
$svc = (string) file_get_contents(__DIR__ . '/../core/plaid_service.php');
$a('plaidConfigured()',           $c($svc, 'function plaidConfigured'));
$a('plaidEnv() / plaidHost()',    $c($svc, 'function plaidEnv') && $c($svc, 'function plaidHost'));
$a('plaidPost() — single chokepoint', $c($svc, 'function plaidPost'));
$a('plaidExchangePublicToken()',  $c($svc, 'function plaidExchangePublicToken'));
$a('plaidGetAuth()',              $c($svc, 'function plaidGetAuth'));
$a('plaidSyncTransactions()',     $c($svc, 'function plaidSyncTransactions'));
$a('plaidVerifyWebhook()',        $c($svc, 'function plaidVerifyWebhook'));
$a('plaidJwkToPem() — P-256 only',$c($svc, 'function plaidJwkToPem'));
$a('plaidEs256RawToDer()',        $c($svc, 'function plaidEs256RawToDer'));
$a('plaidEncryptAccessToken()',   $c($svc, 'function plaidEncryptAccessToken'));
$a('plaidAudit()',                $c($svc, 'function plaidAudit'));
$a('plaidAudit uses platform audit writer (no direct audit insert)',
    $c($svc, 'platformAuditLogWrite(') &&
    !$c($svc, 'INSERT INTO audit_log') &&
    !$c($svc, 'currentTenantContext'));
$a('plaidProductsHealthCheck() helper',        $c($svc, 'function plaidProductsHealthCheck'));
$a('health check exposes request_url for INVALID_PRODUCT',
    $c($svc, 'request_url') && $c($svc, 'overview/request-products'));
$a('plaidWebhookUrl() auto-derive helper',     $c($svc, 'function plaidWebhookUrl'));
$a('plaidUpdateItemWebhook() helper',          $c($svc, 'function plaidUpdateItemWebhook'));
$a('plaidSyncAllItemWebhooks() helper',        $c($svc, 'function plaidSyncAllItemWebhooks'));
$a('webhook URL falls back to APP_PUBLIC_URL', $c($svc, "plaidGet('APP_PUBLIC_URL')"));
$a('webhook URL respects X-Forwarded-Proto',   $c($svc, 'HTTP_X_FORWARDED_PROTO'));
$a('encryption.php required',     $c($svc, "require_once __DIR__ . '/encryption.php'"));
$a('5-min freshness window',      $c($svc, '300'));
$a('hash_equals() body-hash check', $c($svc, 'hash_equals('));

require_once __DIR__ . '/../core/encryption.php';
require_once __DIR__ . '/../core/plaid_service.php';

// Functional unit tests on the helpers (no DB, no network).
$ct = plaidEncryptAccessToken('access-sandbox-abc123');
$a('encrypt → ciphertext non-empty',         is_string($ct) && strlen($ct) >= 28);
$a('decrypt round-trip',                     plaidDecryptAccessToken($ct) === 'access-sandbox-abc123');

// Auto-derived webhook URL.
$_SERVER['HTTP_HOST'] = 'app.example.com';
$_SERVER['HTTPS']     = 'on';
unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
$a('plaidWebhookUrl auto-derives https from $_SERVER',
    plaidWebhookUrl() === 'https://app.example.com/api/plaid_webhook.php');

$_SERVER['HTTPS'] = 'off';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
$a('plaidWebhookUrl honours X-Forwarded-Proto',
    plaidWebhookUrl() === 'https://app.example.com/api/plaid_webhook.php');

unset($_SERVER['HTTP_HOST'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTPS']);
$a('plaidWebhookUrl returns null without HTTP_HOST + APP_PUBLIC_URL', plaidWebhookUrl() === null);

// JWK→PEM: synthesize a P-256 keypair, render JWK, convert to PEM, sign+verify.
$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
$details = openssl_pkey_get_details($key);
$jwk = [
    'kty' => 'EC', 'crv' => 'P-256', 'alg' => 'ES256',
    'x'   => rtrim(strtr(base64_encode($details['ec']['x']), '+/', '-_'), '='),
    'y'   => rtrim(strtr(base64_encode($details['ec']['y']), '+/', '-_'), '='),
];
$pem = plaidJwkToPem($jwk);
$a('JwkToPem returns PEM',                   is_string($pem) && strpos($pem, '-----BEGIN PUBLIC KEY-----') === 0);
$msg = 'cf-test-message-2026';
openssl_sign($msg, $sigDer, $key, OPENSSL_ALGO_SHA256);
$ok = openssl_verify($msg, $sigDer, $pem, OPENSSL_ALGO_SHA256);
$a('Synthetic ES256 verify against PEM',     $ok === 1);

// Raw→DER edge case: random r/s with high bit set.
$raw = str_repeat("\x80", 64);   // both r and s start with 0x80 → must prepend 0x00
$der = plaidEs256RawToDer($raw);
$a('EsRawToDer prepends 0x00 for high-bit r/s', $der[0] === "\x30" && strlen($der) === strlen($raw) + 8);

echo "\nupdate.php integration\n";
$up = (string) file_get_contents(__DIR__ . '/../update.php');
$a('update.php loads plaid_service if present',   $c($up, "require_once \$root . '/core/plaid_service.php'"));
$a('update.php gates on plaidConfigured()',       $c($up, 'function_exists(\'plaidConfigured\') && plaidConfigured()'));
$a('update.php calls plaidSyncAllItemWebhooks()', $c($up, 'plaidSyncAllItemWebhooks()'));
$a('update.php calls plaidProductsHealthCheck()', $c($up, 'plaidProductsHealthCheck('));
$a('update.php surfaces enabled/disabled per product',
    $c($up, '=ENABLED') && $c($up, '=DISABLED'));
$a('update.php soft-fails Plaid step (deploy continues)',
    $c($up, "'ok'     => true,    // soft-fail (deploy continues)") || $c($up, '// soft-fail'));
$a('update.php surfaces webhook URL + counts in detail',
    $c($up, "'webhook=%s'") || $c($up, 'webhook=%s'));

echo "\nlink_token.php auto-uses plaidWebhookUrl()\n";
$lt2 = (string) file_get_contents(__DIR__ . '/../api/plaid_link_token.php');
$a('link_token resolves webhook via plaidWebhookUrl()', $c($lt2, 'plaidWebhookUrl()'));
$a('link_token still allows body override',             $c($lt2, "\$body['webhook_url']"));
$lt = (string) file_get_contents(__DIR__ . '/../api/plaid_link_token.php');
$a('POST guard',                              $c($lt, "if (api_method() !== 'POST')"));
$a('purpose enum guard',                      $c($lt, "['bank_feed','vendor_banking','employee_banking','tenant_funding']"));
$a('per-purpose RBAC gate',                   $c($lt, 'rbac_legacy_require'));
$a('purpose-aware product defaults (auth-only for vendor/employee)',
    $c($lt, "'bank_feed'        => ['transactions']") &&
    $c($lt, "required_if_supported_products") &&
    $c($lt, "'employee_banking'") && $c($lt, "=> ['auth']"));
$a('update mode: existing item access_token', $c($lt, "\$body['update_item_id']") && $c($lt, "\$req['access_token']"));
$a('calls /link/token/create',                $c($lt, "/link/token/create"));

echo "\napi/plaid_exchange.php\n";
$ex = (string) file_get_contents(__DIR__ . '/../api/plaid_exchange.php');
$a('exchanges public_token',                  $c($ex, 'plaidExchangePublicToken'));
$a('encrypts access_token',                   $c($ex, 'plaidEncryptAccessToken'));
$a('persists plaid_items',                    $c($ex, "scopedInsert('plaid_items'"));
$a('hydrates plaid_accounts',                 $c($ex, "scopedInsert('plaid_accounts'"));
$a('binds vendor_id when purpose=vendor_banking',   $c($ex, "\$purpose === 'vendor_banking'"));
$a('binds employee_id when purpose=employee_banking',$c($ex, "\$purpose === 'employee_banking'"));
$a('audits core.plaid.item_linked',           $c($ex, "'core.plaid.item_linked'"));
$a('returns 201',                             $c($ex, '], 201)'));

echo "\napi/plaid_auth_pull.php\n";
$au = (string) file_get_contents(__DIR__ . '/../api/plaid_auth_pull.php');
$a('decrypts access_token from db',           $c($au, 'plaidDecryptAccessToken'));
$a('calls /auth/get',                         $c($au, 'plaidGetAuth'));
$a('refuses if no ACH numbers',               $c($au, 'No ACH-eligible accounts'));
$a('validates 9-digit routing',               $c($au, 'Routing not 9 digits'));
$a('writes ap_vendors_index for vendor_banking', $c($au, 'UPDATE ap_vendors_index'));
$a('writes people_bank_accounts for employee_banking',
    $c($au, 'INSERT INTO people_bank_accounts'));
$a('audits auth_persisted_vendor / employee', $c($au, 'core.plaid.auth_persisted_vendor') && $c($au, 'core.plaid.auth_persisted_employee'));

echo "\napi/plaid_sync_transactions.php\n";
$tx = (string) file_get_contents(__DIR__ . '/../api/plaid_sync_transactions.php');
$a('calls /transactions/sync via helper',     $c($tx, 'plaidSyncTransactions'));
$a('persists cursor on each completion',      $c($tx, 'transactions_cursor'));
$a('upserts to accounting_bank_statement_lines',
    $c($tx, 'INSERT INTO accounting_bank_statement_lines') && $c($tx, 'ON DUPLICATE KEY UPDATE'));
$a('flips Plaid +outflow → signed -debit',    $c($tx, '* -1'));
$a('mutation-during-pagination retry once',   $c($tx, 'MUTATION_DURING_PAGINATION'));
$a('200-page safety cap',                     $c($tx, "exceeded 200 pages") || $c($tx, '> 200'));
$a('marks removed → match_status=ignored',    $c($tx, "match_status = 'ignored'"));
$a('audits transactions_synced',              $c($tx, "'core.plaid.transactions_synced'"));

echo "\napi/plaid_webhook.php\n";
$wh = (string) file_get_contents(__DIR__ . '/../api/plaid_webhook.php');
$a('does NOT call api_require_auth',          stripos($wh, 'api_require_auth()') === false);
$a('reads Plaid-Verification header',         $c($wh, 'plaid-verification'));
$a('verifies via plaidVerifyWebhook',         $c($wh, 'plaidVerifyWebhook'));
$a('persists every event (verified or not)', $c($wh, 'INSERT INTO plaid_webhook_events'));
$a('rejects unverified after logging',        $c($wh, "'signature_invalid'"));
$a('ITEM_LOGIN_REQUIRED → requires_update',
    $c($wh, 'ITEM_LOGIN_REQUIRED') && $c($wh, "status = \"requires_update\""));
$a('USER_PERMISSION_REVOKED → revoked',       $c($wh, "'USER_PERMISSION_REVOKED'") && $c($wh, "status = \"revoked\""));
$a('SYNC_UPDATES_AVAILABLE only touches stamp', $c($wh, 'SYNC_UPDATES_AVAILABLE'));
$a('always returns 200',                      substr_count($wh, 'http_response_code(200)') >= 2);

echo "\ndashboard/src/components/PlaidLinkButton.jsx\n";
$jsx = (string) file_get_contents(__DIR__ . '/../dashboard/src/components/PlaidLinkButton.jsx');
$a('loads Plaid Link from cdn.plaid.com',     $c($jsx, 'cdn.plaid.com/link/v2/stable/link-initialize.js'));
$a('POSTs /api/plaid_link_token',             $c($jsx, "/api/plaid_link_token"));
$a('POSTs /api/plaid_exchange on success',    $c($jsx, "/api/plaid_exchange"));
$a('passes purpose + binding ids',            $c($jsx, 'vendor_id:') && $c($jsx, 'employee_id:') && $c($jsx, 'accounting_bank_account_id:'));
$a('emits onLinked callback',                 $c($jsx, 'onLinked && onLinked(result)'));
$a('test-id data-testid="plaid-link-btn"',    $c($jsx, 'plaid-link-btn'));

echo "\ncron/plaid_sync_nightly.php\n";
$cr = (string) file_get_contents(__DIR__ . '/../cron/plaid_sync_nightly.php');
$a('iterates linked items only',              $c($cr, "status = 'linked'"));
$a('per-item retry on mutation error',        $c($cr, 'MUTATION_DURING_PAGINATION'));
$a('updates cursor + last_transaction_sync_at',$c($cr, 'last_transaction_sync_at = NOW()'));
$a('exit code reflects failures',             $c($cr, 'exit($fail > 0 ? 1 : 0)'));

// =====================================================================
// Functional tests against the active Plaid env (only if creds present)
// =====================================================================
if (plaidConfigured() && plaidEnv() === 'sandbox') {
    echo "\nFunctional — Plaid Sandbox round-trip\n";
    try {
        $r = plaidPost('/link/token/create', [
            'client_name' => 'CoreFlux Smoke',
            'user'        => ['client_user_id' => 'cf_smoke_' . bin2hex(random_bytes(2))],
            'language'    => 'en',
            'country_codes' => ['US'],
            'products'    => ['auth','transactions'],
        ]);
        $a('/link/token/create returns link_token',  !empty($r['link_token']));
        $a('link_token has sandbox prefix',           strpos((string) $r['link_token'], 'link-sandbox-') === 0);

        $pt = plaidPost('/sandbox/public_token/create', [
            'institution_id'   => 'ins_109508',
            'initial_products' => ['auth','transactions'],
        ]);
        $a('/sandbox/public_token/create',            !empty($pt['public_token']));

        $exch = plaidExchangePublicToken($pt['public_token']);
        $a('exchange returns access_token + item_id', !empty($exch['access_token']) && !empty($exch['item_id']));

        $au = plaidGetAuth($exch['access_token']);
        $a('/auth/get returns ACH numbers',           count($au['numbers']['ach'] ?? []) >= 1);
        $first = $au['numbers']['ach'][0] ?? [];
        $a('first ACH has 9-digit routing',           preg_match('/^\d{9}$/', (string) ($first['routing'] ?? '')) === 1);

        $sync = plaidSyncTransactions($exch['access_token'], null, 5);
        $a('/transactions/sync valid shape',
            isset($sync['added']) && isset($sync['modified']) && isset($sync['removed']) && isset($sync['has_more']));
    } catch (PlaidApiException $e) {
        $a('SANDBOX FUNCTIONAL: unexpected error: ' . $e->getMessage(), false);
    }
} elseif (plaidConfigured() && plaidEnv() === 'production') {
    echo "\nFunctional — Plaid Production health check\n";
    $h = plaidProductsHealthCheck(['auth']);
    $a('Plaid PROD: Auth product enabled',
        !empty($h['products']['auth']['enabled']));
} else {
    echo "\nFunctional Plaid tests SKIPPED (PLAID_* not configured)\n";
}

echo PHP_EOL . "Total: $pass passed, $fail failed" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
