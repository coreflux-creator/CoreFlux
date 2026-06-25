<?php
/**
 * Smoke — QBO config_check sanity endpoint (2026-02).
 *
 * Locks the GET /api/qbo.php?action=config_check contract:
 *   - GET-only, RBAC-gated on integrations.qbo.view.
 *   - Returns booleans + redirect_uri + environment + api_base +
 *     client_id_tail (last 4 chars), but NEVER the secret value.
 *   - Defines exist in core/config.local.php on this pod (so the
 *     production deploy can run this test to confirm the keys land).
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };
$root = dirname(__DIR__);
$localConfig = $root . '/core/config.local.php';
$configPath = is_file($localConfig) ? $localConfig : $root . '/core/config.local.example.php';
$usingFixtureConfig = !is_file($localConfig);

// ──────────────────────────────────────────────────────────────────────
// 1) api/qbo.php — config_check action wired correctly.
// ──────────────────────────────────────────────────────────────────────
echo "\n── api/qbo.php config_check ──\n";
$api = (string) file_get_contents($root . '/api/qbo.php');

$a("case 'config_check' present",   $c($api, "case 'config_check':"));
$a('GET-only',                      preg_match("/case 'config_check'[\s\S]{0,300}\\\$method !== 'GET'/", $api) === 1);
$a('RBAC-gated on integrations.qbo.view',
    substr_count(substr($api, strpos($api, "case 'config_check'"), 700),
        "rbac_legacy_require(\$user, 'integrations.qbo.view')") >= 1);

// Envelope shape.
$payload = substr($api, strpos($api, "case 'config_check'"), 2000);
$a("returns 'configured' bool",     $c($payload, "'configured'"));
$a("returns 'environment'",         $c($payload, "'environment'"));
$a("returns 'redirect_uri'",        $c($payload, "'redirect_uri'"));
$a("returns 'scopes'",              $c($payload, "'scopes'"));
$a("returns 'has_client_id'",       $c($payload, "'has_client_id'"));
$a("returns 'has_client_secret'",   $c($payload, "'has_client_secret'"));
$a("returns 'has_redirect_uri'",    $c($payload, "'has_redirect_uri'"));
$a("returns 'client_id_tail' (last 4 chars only)",
    $c($payload, "'client_id_tail'") && $c($payload, 'substr($clientId, -4)'));
$a("returns 'api_base'",            $c($payload, "'api_base'"));

// Security guard — endpoint must NEVER echo the secret value.
$a('NEVER returns the raw QBO_CLIENT_SECRET value',
    !preg_match("/'client_secret'\s*=>\s*\\\$clientSec/", $payload)
    && !preg_match("/'secret'\s*=>\s*\\\$clientSec/", $payload));

// ──────────────────────────────────────────────────────────────────────
// 2) core/config.local.php — QBO defines present on this pod.
// ──────────────────────────────────────────────────────────────────────
echo "\n── core/config.local.php QBO defines ──\n";
$cfg = (string) file_get_contents($configPath);
$a('QBO_CLIENT_ID define present',     $c($cfg, "define('QBO_CLIENT_ID',"));
$a('QBO_CLIENT_SECRET define present', $c($cfg, "define('QBO_CLIENT_SECRET',"));
$a('QBO_REDIRECT_URI define present',  $c($cfg, "define('QBO_REDIRECT_URI',"));
$a('QBO_ENV define present',           $c($cfg, "define('QBO_ENV',"));
$a('QBO_SCOPES define present',        $c($cfg, "define('QBO_SCOPES',"));
$a('QBO_ENV is sandbox (not production yet)', $c($cfg, "define('QBO_ENV',           'sandbox')"));
$a('redirect uri points at /api/qbo/oauth_callback.php (path-style; Intuit rejects query strings)',
    $c($cfg, '/api/qbo/oauth_callback.php')
    && !$c($cfg, '?action=oauth_callback'));

// ──────────────────────────────────────────────────────────────────────
// 3) Functional smoke — load the file in this CLI and verify the
//    qboConfigured() helper actually returns true now that defines
//    are loaded.  Runs in process isolation so we don't pollute
//    other tests.
// ──────────────────────────────────────────────────────────────────────
echo "\n── Functional verification ──\n";
if ($usingFixtureConfig) {
    putenv('COREFLUX_DATA_KEY=' . base64_encode(str_repeat('q', 32)));
    putenv('QBO_CLIENT_ID=smoke-qbo-client-id');
    putenv('QBO_CLIENT_SECRET=smoke-qbo-client-secret');
    putenv('QBO_REDIRECT_URI=https://www.corefluxapp.com/api/qbo/oauth_callback.php');
    putenv('QBO_ENV=sandbox');
    putenv('QBO_SCOPES=com.intuit.quickbooks.accounting');
} else {
    require_once $localConfig;
}
require_once $root . '/core/qbo/client.php';

$a('qboConfigured() returns true with defines loaded',  qboConfigured() === true);
$a('qboEnvironment() returns sandbox',                  qboEnvironment() === 'sandbox');
$a('qboApiBase() routes to sandbox endpoint',           qboApiBase() === 'https://sandbox-quickbooks.api.intuit.com');
$a('QBO_CLIENT_ID is non-empty',                        qboCfg('QBO_CLIENT_ID') !== '');
$a('QBO_REDIRECT_URI matches production host',
    qboCfg('QBO_REDIRECT_URI') === 'https://www.corefluxapp.com/api/qbo/oauth_callback.php');

// Path-style shim must exist and delegate to the main router.
$shim = (string) file_get_contents($root . '/api/qbo/oauth_callback.php');
$a('oauth_callback.php shim exists and delegates to /api/qbo.php',
    $shim !== '' && strpos($shim, "require") !== false && strpos($shim, "qbo.php") !== false);

// ──────────────────────────────────────────────────────────────────────
echo "\n=========================================\n";
echo "QBO config_check smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
