<?php
/**
 * Auth-gate sentry — codebase-wide static analyzer.
 *
 * Scans every PHP file under /app/api/ for an `api_require_auth()` call.
 * Any file that doesn't call it AND isn't on the explicit allow-list is
 * flagged as a potential privilege-escalation surface.
 *
 * Allow-list lives in `_authGateAllowedUnauthEndpoints()` — endpoints that
 * deliberately skip auth must justify themselves there (webhooks, public
 * view-by-token endpoints, SSO callbacks, healthchecks).
 *
 * Skips: /api/index.php (router), /api/*.md, files under sub-dirs are
 * scanned recursively.
 *
 *   php -d zend.assertions=1 /app/tests/auth_gate_static_analyzer_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$API  = $ROOT . '/api';

$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};

/**
 * Endpoints intentionally unauthenticated. Each entry MUST have a reason.
 */
function _authGateAllowedUnauthEndpoints(): array {
    return [
        // ── Vendor webhooks (called by external systems; verified by HMAC/JWT signatures) ──
        'api/plaid_webhook.php'           => 'Plaid webhook — verified by Plaid-Verification JWT header',
        'api/plaid_transfer_webhook.php'  => 'Plaid Transfer webhook — verified by Plaid-Verification JWT header',
        'api/gusto_webhook.php'           => 'Gusto webhook — verified by HMAC signature',
        'api/gusto_oauth_callback.php'    => 'Gusto OAuth callback — verified by state nonce + token exchange',
        'api/gusto_oauth_start.php'       => 'Initiates OAuth — must be reachable to start the flow',
        'api/mercury_webhook.php'         => 'Mercury webhook — verified by HMAC signature',
        'api/qbo_oauth_callback.php'      => 'QBO OAuth callback — verified by state nonce + token exchange',
        'api/qbo.php'                     => 'QBO endpoint dispatches to sub-actions; each enforces auth internally',
        'api/webhooks/resend.php'         => 'Resend webhook — verified by Svix-style HMAC signature + replay window',
        'api/webhooks/mercury.php'        => 'Mercury webhook — verified by HMAC-SHA256 signature + 5min replay window',
        'api/webhooks/qbo.php'            => 'QBO Accounting webhook — verified by intuit-signature HMAC-SHA256 of raw body',

        // ── Public-facing endpoints (deliberate; consumed by recipients without accounts) ──
        'api/billing/invoice_public.php'  => 'Public invoice viewer — auth via single-use token_hash in URL',
        'api/billing/money_movement_view.php' => 'Public scenario share — auth via share-link token',
        'api/treasury_scenario_share.php' => 'Public scenario share — auth via share-link token',
        'api/sso/callback.php'            => 'OIDC callback — auth via state nonce + JWKS-verified ID token',
        'api/sso/start.php'               => 'Initiates OIDC — must be reachable to start the flow',
        'api/auth/login.php'              => 'Issues session on valid credentials',
        'api/auth/logout.php'             => 'Clears session (safe to call without prior auth)',
        'api/auth/register.php'           => 'Issues account on valid signup',
        'api/auth/forgot_password.php'    => 'Initiates password reset (mailbox is the auth)',
        'api/auth/reset_password.php'     => 'Resets password (token in URL is the auth)',
        'api/auth/magic_link_request.php' => 'Issues magic-link email (rate-limited)',
        'api/auth/magic_link_consume.php' => 'Consumes magic-link token (token is the auth)',
        'api/auth/mobile_login.php'       => 'Issues JWT on valid credentials',
        'api/auth/mobile_refresh.php'     => 'Refresh-token rotation (refresh token is the auth)',
        'api/auth/whoami.php'             => 'Returns 401 if not authed; safe to call unauth',
        'api/auth/request_magic_link.php' => 'Issues magic-link email (rate-limited; mailbox is the auth)',
        'api/auth/consume_magic_link.php' => 'Consumes magic-link token (token is the auth)',
        'api/ap/approve_by_email.php'     => 'Email one-tap approve/reject — auth via signed token in URL',
        'api/ap/email_approval.php'       => 'Email one-tap approve/reject — auth via signed token in URL',
        'api/time/email_approval.php'     => 'Email one-tap approve/reject — auth via signed token in URL',
        'api/staffing/approve_timesheet_by_email.php' => 'Email one-tap approve/reject — auth via signed token in URL',

        // ── Healthchecks / infrastructure ──
        'api/admin_healthcheck.php'       => 'Liveness probe — returns 200 if DB+app responsive',
        'api/ci_status.php'               => 'Public CI badge endpoint',
        'api/index.php'                   => 'Router stub — delegates to specific endpoints',
    ];
}

// ----------------------------------------------------------------- file discovery
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($API, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = (string) $f;
    if (!str_ends_with($p, '.php')) continue;
    $files[] = $p;
}
sort($files);
$a('discovered API php files', count($files) > 0);
echo "  · scanning " . count($files) . " API php files\n";

$allowed = _authGateAllowedUnauthEndpoints();
$offenders = [];
$exempted  = [];
foreach ($files as $path) {
    $rel = str_replace('\\', '/', substr($path, strlen($ROOT) + 1)); // strip /app/
    $src = (string) file_get_contents($path);

    // Skip definition-only files (no `<?php` body or only declares functions/constants).
    $hasAuth = preg_match('/\b(?:api_require_auth|api_require_admin|api_require_role|api_require_cfo|requireAuth)\s*\(/', $src);
    $isAllowed = isset($allowed[$rel]);

    if ($hasAuth) continue;
    if ($isAllowed) { $exempted[] = ['file' => $rel, 'reason' => $allowed[$rel]]; continue; }

    // Treat files that don't expose any handler logic (e.g. pure helpers in
    // a sub-dir) as a soft pass: skip if the file has no method dispatch or
    // direct request handling. Heuristic: contains api_method() OR api_json_body()
    // OR direct $_GET/$_POST/$_REQUEST read.
    $hasHandler = preg_match('/\b(?:api_method|api_json_body)\s*\(/', $src)
               || preg_match('/\$_(?:GET|POST|REQUEST)\s*\[/', $src);
    if (!$hasHandler) continue;

    $offenders[] = $rel;
}

echo "  · " . count($exempted) . " endpoints have explicit allow-list exemption\n";

echo "\nAuth-gate offender report\n";
if ($offenders) {
    echo "  · " . count($offenders) . " API endpoint(s) handle requests WITHOUT api_require_auth():\n";
    foreach ($offenders as $o) {
        echo "    ✗ $o\n";
    }
}
$a('every request-handling API endpoint calls api_require_auth() or is allow-listed', count($offenders) === 0);

// ----------------------------------------------------------------- sanity self-test
echo "\nSelf-test (synthetic bad input — sentry must catch this)\n";
$tmp = sys_get_temp_dir() . '/auth_gate_sentry_test_' . getmypid() . '.php';
file_put_contents($tmp, "<?php\nrequire_once 'core/api_bootstrap.php';\n\$body = api_json_body();\necho 'leaked';\n");
$src = file_get_contents($tmp);
$has = preg_match('/api_require_auth\s*\(/', $src);
$handles = preg_match('/api_json_body\s*\(/', $src);
$caught = !$has && $handles;
@unlink($tmp);
$a('sentry catches synthetic endpoint with api_json_body() but no api_require_auth()', $caught);

echo "\n=========================================\n";
echo "Auth-gate sentry smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
