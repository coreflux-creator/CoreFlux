<?php
/**
 * Gusto OAuth + API integration smoke test.
 *
 * Static asserts plus pure-function runtime checks. Does NOT call out to
 * Gusto's real API — mocks tokens and verifies the logic end-to-end.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

echo "Migration 004 — gusto_oauth schema\n";
$mig = (string) file_get_contents(__DIR__ . '/../modules/payroll/migrations/004_gusto_oauth.sql');
$a('tenant_gusto_connections table',         strpos($mig, 'CREATE TABLE IF NOT EXISTS tenant_gusto_connections') !== false);
$a('utf8mb4_unicode_ci collation',           strpos($mig, 'utf8mb4_unicode_ci') !== false);
$a('encrypted access/refresh columns',
    strpos($mig, 'access_token_ct') !== false &&
    strpos($mig, 'refresh_token_ct') !== false);
$a('access_token_ct stored as VARBINARY (raw AES-GCM bytes)',
    strpos($mig, 'access_token_ct             VARBINARY') !== false);
$a('refresh_token_ct stored as VARBINARY',
    strpos($mig, 'refresh_token_ct            VARBINARY') !== false);
$a('access_token expiry column',             strpos($mig, 'access_token_expires_at') !== false);
$a('env column (sandbox/production)',        strpos($mig, "env                         VARCHAR(20)") !== false);
$a('per-tenant uniqueness on company_uuid',  strpos($mig, 'uq_gconn_tenant_company') !== false);
$a('payroll_runs.gusto_payroll_uuid (idempotent)',
    strpos($mig, "TABLE_NAME = 'payroll_runs'") !== false &&
    strpos($mig, "COLUMN_NAME = 'gusto_payroll_uuid'") !== false);
$a('adds gusto_submission_status column',    strpos($mig, 'gusto_submission_status') !== false);
$a('adds gusto_submitted_at column',         strpos($mig, 'gusto_submitted_at') !== false);
$a('adds gusto_submission_error column',     strpos($mig, 'gusto_submission_error') !== false);

echo "\ncore/gusto_service.php — service shape\n";
$svc = (string) file_get_contents(__DIR__ . '/../core/gusto_service.php');
$a('gustoConfigured function',               strpos($svc, 'function gustoConfigured') !== false);
$a('gustoEnv (sandbox/production)',          strpos($svc, "function gustoEnv") !== false);
$a('gustoApiHost host switching',
    strpos($svc, 'https://api.gusto.com') !== false &&
    strpos($svc, 'https://api.gusto-demo.com') !== false);
$a('gustoAuthorizationUrl — code+state',
    strpos($svc, "function gustoAuthorizationUrl") !== false &&
    strpos($svc, "'response_type' => 'code'") !== false &&
    strpos($svc, "'state'         => \$state") !== false);
$a('gustoConsumeOAuthState — single use',
    strpos($svc, "unset(\$_SESSION['gusto_oauth'])") !== false);
$a('gustoExchangeCodeForToken — auth code grant',
    strpos($svc, "'grant_type'    => 'authorization_code'") !== false);
$a('gustoRefreshAccessToken — refresh grant',
    strpos($svc, "'grant_type'    => 'refresh_token'") !== false);
$a('Encrypted token persistence',
    strpos($svc, 'encryptField') !== false &&
    strpos($svc, 'decryptField') !== false);
$a('Refresh-on-401 retry logic',             strpos($svc, '$http === 401 && $allowRefresh') !== false);
$a('Rate-limit (429) Retry-After honored',   strpos($svc, '$http === 429') !== false && strpos($svc, 'Retry-After') !== false);
$a('Bearer auth header',                     strpos($svc, "'Authorization: Bearer '") !== false);
$a('X-Gusto-API-Version pinned',             strpos($svc, 'X-Gusto-API-Version: 2024-04-01') !== false);
$a('Webhook HMAC verifier',                  strpos($svc, 'function gustoVerifyWebhook') !== false);
$a('Audit helper writes audit_log',          strpos($svc, "INSERT INTO audit_log") !== false);
$a('GustoApiException + GustoAuthException', strpos($svc, 'class GustoApiException') !== false && strpos($svc, 'class GustoAuthException') !== false);

echo "\ncore/gusto_service.php — pure runtime tests\n";
require_once __DIR__ . '/../core/gusto_service.php';

// HMAC webhook verification
$secret = 'test_webhook_secret_xyz';
define('GUSTO_WEBHOOK_SECRET', $secret);
$body = '{"event_type":"Payroll.processed","resource_uuid":"abc-123"}';
$sig  = hash_hmac('sha256', $body, $secret);
$a('webhook HMAC verifies a valid signature',     gustoVerifyWebhook($sig, $body) === true);
$a('webhook HMAC rejects a tampered signature',   gustoVerifyWebhook(str_repeat('0', 64), $body) === false);
$a('webhook HMAC rejects a tampered body',        gustoVerifyWebhook($sig, $body . 'x') === false);
$a('webhook HMAC rejects an empty signature',     gustoVerifyWebhook('', $body) === false);

// Env switching
putenv('GUSTO_ENV=sandbox');
$a('gustoApiHost respects sandbox env',           gustoApiHost() === 'https://api.gusto-demo.com');
putenv('GUSTO_ENV=production');
$a('gustoApiHost respects production env',        gustoApiHost() === 'https://api.gusto.com');
putenv('GUSTO_ENV=sandbox');

// Default scopes
$scopes = gustoDefaultScopes();
$a('default scopes include payrolls:write',       strpos($scopes, 'payrolls:write') !== false);
$a('default scopes include companies:read',       strpos($scopes, 'companies:read') !== false);
$a('default scopes include compensations:read',   strpos($scopes, 'compensations:read') !== false);

// Session state validation
session_start();
define('GUSTO_CLIENT_ID', 'test_client_id');
define('GUSTO_CLIENT_SECRET', 'test_secret');
define('GUSTO_REDIRECT_URI', 'https://example.com/cb');
$url = gustoAuthorizationUrl(42, 7);
$a('authz URL contains client_id',                strpos($url, 'client_id=test_client_id') !== false);
$a('authz URL contains response_type=code',       strpos($url, 'response_type=code') !== false);
$a('authz URL contains redirect_uri (encoded)',   strpos($url, 'redirect_uri=https%3A%2F%2Fexample.com%2Fcb') !== false);
$a('authz URL points at sandbox host',            strpos($url, 'https://api.gusto-demo.com/oauth/authorize') === 0);
$a('authz URL contains scope',                    strpos($url, 'scope=') !== false);

// Validate state round-trip
parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $params);
$state = $params['state'] ?? '';
$a('authz URL state is 48 hex chars',             strlen($state) === 48 && ctype_xdigit($state));
$saved = gustoConsumeOAuthState($state);
$a('state validates on first use',                ($saved['tenant_id'] ?? 0) === 42);
$replay = false;
try { gustoConsumeOAuthState($state); $replay = true; } catch (GustoAuthException $e) {}
$a('state cannot be replayed (single-use)',       $replay === false);
$mismatch = false;
try { $url2 = gustoAuthorizationUrl(99); gustoConsumeOAuthState('bogus_state_value'); $mismatch = true; }
catch (GustoAuthException $e) {}
$a('state mismatch raises GustoAuthException',    $mismatch === false);

echo "\napi/gusto_oauth_start.php — start endpoint\n";
$start = (string) file_get_contents(__DIR__ . '/../api/gusto_oauth_start.php');
$a('requires payroll.run.disburse',              strpos($start, "RBAC::requirePermission(\$ctx['user'], 'payroll.run.disburse')") !== false);
$a('returns 503 if not configured',              strpos($start, ', 503') !== false);
$a('302-redirects to authorization URL',         strpos($start, "header('Location: ' . \$url, true, 302)") !== false);
$a('JSON variant for SPA callers',               strpos($start, "['authorize_url' => \$url]") !== false);
$a('audit emits connect_initiated',              strpos($start, "'payroll.gusto.connect_initiated'") !== false);

echo "\napi/gusto_oauth_callback.php — callback endpoint\n";
$cb = (string) file_get_contents(__DIR__ . '/../api/gusto_oauth_callback.php');
$a('handles error param from Gusto',             strpos($cb, "if (\$err !== '')") !== false);
$a('rejects missing code or state',              strpos($cb, "missing_params") !== false);
$a('consumes OAuth state via service helper',    strpos($cb, 'gustoConsumeOAuthState') !== false);
$a('exchanges code for token',                   strpos($cb, 'gustoExchangeCodeForToken') !== false);
$a('persists encrypted tokens',                  strpos($cb, 'gustoSaveConnection') !== false);
$a('bounces back to Payroll Settings page',      strpos($cb, '/spa.php#/modules/payroll/settings') !== false);
$a('audit emits connected on success',           strpos($cb, "'payroll.gusto.connected'") !== false);
$a('redirects unauthenticated user to login',    strpos($cb, '/login.php?redirect=') !== false);

echo "\napi/gusto_webhook.php — webhook receiver\n";
$wh = (string) file_get_contents(__DIR__ . '/../api/gusto_webhook.php');
$a('rejects non-POST',                           strpos($wh, "api_method() !== 'POST'") !== false);
$a('echoes verification_token handshake',        strpos($wh, 'verification_token') !== false);
$a('verifies signature via gustoVerifyWebhook',  strpos($wh, 'gustoVerifyWebhook') !== false);
$a('updates payroll_runs by gusto_payroll_uuid', strpos($wh, "WHERE gusto_payroll_uuid = :u") !== false);
$a('Payroll.paid → marks run paid',              strpos($wh, "Payroll.paid") !== false);
$a('returns 204 after handling',                 strpos($wh, 'http_response_code(204)') !== false);
$a('audits payroll.gusto.webhook_received',      strpos($wh, "'payroll.gusto.webhook_received'") !== false);
$a('audits webhook_signature_invalid',           strpos($wh, "'payroll.gusto.webhook_signature_invalid'") !== false);

echo "\nmodules/payroll/api/gusto_connect.php\n";
$con = (string) file_get_contents(__DIR__ . '/../modules/payroll/api/gusto_connect.php');
$a('GET returns configured + connection',        strpos($con, "'configured'    => \$configured") !== false);
$a('GET never returns raw tokens',
    strpos($con, "'access_token_ct'") === false &&
    strpos($con, "'refresh_token_ct'") === false);
$a('DELETE soft-revokes connection',             strpos($con, "['status' => 'revoked']") !== false);
$a('DELETE requires payroll.run.disburse',       strpos($con, "RBAC::requirePermission(\$ctx['user'], 'payroll.run.disburse')") !== false);
$a('audits payroll.gusto.disconnected',          strpos($con, "'payroll.gusto.disconnected'") !== false);

echo "\nmodules/payroll/api/gusto_submit.php\n";
$sub = (string) file_get_contents(__DIR__ . '/../modules/payroll/api/gusto_submit.php');
$a('requires payroll.run.disburse',              strpos($sub, "RBAC::requirePermission(\$ctx['user'], 'payroll.run.disburse')") !== false);
$a('rejects unapproved runs',                    strpos($sub, "'Run must be approved before submitting to Gusto") !== false);
$a('returns 412 if not connected',               strpos($sub, ', 412') !== false);
$a('list_unprocessed action',                    strpos($sub, "if (\$action === 'list_unprocessed')") !== false);
$a('matches employees by employee_number',       strpos($sub, '$linesByEmpNum') !== false);
$a('routes bonus → Bonus fixed comp',            strpos($sub, "=> 'Bonus'") !== false);
$a('routes commission → Commission',             strpos($sub, "=> 'Commission'") !== false);
$a('routes reimbursement → Reimbursement',       strpos($sub, "=> 'Reimbursement'") !== false);
$a('three-step submit: PUT → calculate → submit',
    strpos($sub, 'gustoUpdatePayrollCompensations') !== false &&
    strpos($sub, 'gustoCalculatePayroll') !== false &&
    strpos($sub, 'gustoSubmitPayroll') !== false);
$a('persists gusto_payroll_uuid + status',
    strpos($sub, "'gusto_payroll_uuid'") !== false &&
    strpos($sub, "'gusto_submission_status'") !== false);
$a('audits run_submitted on success',            strpos($sub, "'payroll.gusto.run_submitted'") !== false);
$a('audits run_submission_failed on error',      strpos($sub, "'payroll.gusto.run_submission_failed'") !== false);
$a('passes version field for optimistic locking', strpos($sub, "(int) (\$payroll['version']") !== false);
$a('aborts with 422 when no employees match',    strpos($sub, "No employees matched between CoreFlux and Gusto") !== false);

echo "\nmanifest — Gusto audit events\n";
$man = (string) file_get_contents(__DIR__ . '/../modules/payroll/manifest.php');
foreach ([
    'payroll.gusto.connect_initiated',
    'payroll.gusto.connected',
    'payroll.gusto.disconnected',
    'payroll.gusto.token_refreshed',
    'payroll.gusto.run_submitted',
    'payroll.gusto.run_submission_failed',
    'payroll.gusto.webhook_received',
    'payroll.gusto.webhook_signature_invalid',
] as $event) {
    $a("declares '$event'", strpos($man, "'$event'") !== false);
}

echo "\nUI — GustoConnectCard.jsx\n";
$gcc = (string) file_get_contents(__DIR__ . '/../modules/payroll/ui/GustoConnectCard.jsx');
$a('card testid',                                strpos($gcc, "data-testid=\"gusto-connect-card\"") !== false);
$a('connect button testid',                      strpos($gcc, 'gusto-connect-btn') !== false);
$a('disconnect button testid',                   strpos($gcc, 'gusto-connect-disconnect-btn') !== false);
$a('not-configured branch testid',               strpos($gcc, 'gusto-connect-not-configured') !== false);
$a('connected company name testid',              strpos($gcc, 'gusto-connect-company-name') !== false);
$a('uses /api/gusto_oauth_start.php for connect', strpos($gcc, '/api/gusto_oauth_start.php') !== false);
$a('reads bounce params from hash',              strpos($gcc, 'gusto-connect-bounce-ok') !== false);

echo "\nUI — PayrollSettings embeds Gusto card\n";
$ps = (string) file_get_contents(__DIR__ . '/../modules/payroll/ui/PayrollSettings.jsx');
$a('imports GustoConnectCard',                   strpos($ps, "import GustoConnectCard from './GustoConnectCard'") !== false);
$a('renders <GustoConnectCard />',               strpos($ps, '<GustoConnectCard />') !== false);

echo "\nUI — PayrollRunDetail OAuth submit panel\n";
$rd = (string) file_get_contents(__DIR__ . '/../modules/payroll/ui/PayrollRunDetail.jsx');
$a('OAuth panel testid',                         strpos($rd, 'payroll-run-gusto-api-panel') !== false);
$a('list-unprocessed button testid',             strpos($rd, 'payroll-run-gusto-list-unprocessed-btn') !== false);
$a('period-select testid',                       strpos($rd, 'payroll-run-gusto-period-select') !== false);
$a('submit button testid',                       strpos($rd, 'payroll-run-gusto-submit-btn') !== false);
$a('submit-result testid',                       strpos($rd, 'payroll-run-gusto-submit-result') !== false);
$a('CSV-fallback hint when not connected',       strpos($rd, 'payroll-run-gusto-csv-fallback-hint') !== false);
$a('loads connection status on mount',           strpos($rd, "/modules/payroll/api/gusto_connect.php") !== false);
$a('CSV-paste flow preserved as fallback',
    strpos($rd, 'payroll-run-gusto-id-input') !== false &&
    strpos($rd, 'payroll-run-gusto-link-btn')  !== false);

echo PHP_EOL . "Total: $pass passed, $fail failed" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
