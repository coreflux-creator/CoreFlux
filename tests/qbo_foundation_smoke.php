<?php
/**
 * QuickBooks Online integration — Slice 1 (Foundation) smoke.
 *
 * Validates:
 *   - migration 052 is reachable and idempotent in shape
 *   - core/qbo/client.php exposes the documented public surface
 *   - api/qbo.php dispatches all expected actions
 *   - dashboard/src/pages/QboSettings.jsx renders the documented testids
 *   - AdminModule + IntegrationsHub wire QBO into the centralised
 *     /admin/integrations surface
 *
 * Run via: php -d zend.assertions=1 tests/qbo_foundation_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ----------------------------------------------------------------- migration shape
echo "Migration 052 — qbo_foundation\n";
$migPath = $ROOT . '/core/migrations/052_qbo_foundation.sql';
$mig = file_exists($migPath) ? (string) file_get_contents($migPath) : '';
$a('migration file present',                     $mig !== '');
$a('declares qbo_connections',                   $c($mig, 'CREATE TABLE IF NOT EXISTS qbo_connections'));
$a('declares qbo_oauth_state',                   $c($mig, 'CREATE TABLE IF NOT EXISTS qbo_oauth_state'));
$a('declares qbo_sync_audit',                    $c($mig, 'CREATE TABLE IF NOT EXISTS qbo_sync_audit'));
$a('unique tenant on connections',               $c($mig, 'UNIQUE KEY uq_qbo_tenant (tenant_id)'));
$a('AES-256-GCM token columns',                  $c($mig, 'access_token_ct') && $c($mig, 'refresh_token_ct'));
$a('environment column sandbox/production',      $c($mig, "ENUM('sandbox','production')"));
$a('state nonce unique key',                     $c($mig, 'UNIQUE KEY uq_qbo_state'));
$a('sync_config JSON column',                    $c($mig, 'sync_config         JSON NULL'));

// ----------------------------------------------------------------- client.php surface
echo "\ncore/qbo/client.php — public surface\n";
$cliPath = $ROOT . '/core/qbo/client.php';
$cli = (string) file_get_contents($cliPath);
$a('file exists',                                $cli !== '');
$a('declares strict types',                      $c($cli, 'declare(strict_types=1);'));
$a('QBO_SYNC_ENTITIES constant',                 $c($cli, "const QBO_SYNC_ENTITIES"));
$a('lists journal_entries',                      $c($cli, "'journal_entries'"));
$a('lists customers',                            $c($cli, "'customers'"));
$a('lists vendors',                              $c($cli, "'vendors'"));
$a('lists chart_of_accounts',                    $c($cli, "'chart_of_accounts'"));
$a('QBO_SYNC_DIRECTIONS includes push',          $c($cli, "'push'"));
$a('QBO_SYNC_DIRECTIONS includes pull',          $c($cli, "'pull'"));
$a('QBO_SYNC_DIRECTIONS includes two_way',       $c($cli, "'two_way'"));
$a('default scope accounting only',              $c($cli, "QBO_DEFAULT_SCOPES = 'com.intuit.quickbooks.accounting'"));
foreach ([
    'qboConfigured', 'qboConnection', 'qboBuildAuthorizeUrl',
    'qboExchangeCode', 'qboDisconnect', 'qboAccessToken',
    'qboRefreshAccessToken', 'qboCall', 'qboRawRequest',
    'qboPing', 'qboSyncConfigRead', 'qboSyncConfigWrite',
    'qboConsumeOAuthState', 'qboAudit',
] as $fn) {
    $a("declares $fn()",                         $c($cli, "function $fn"));
}
$a('uses encryptField for tokens',               substr_count($cli, 'encryptField(') >= 4);
$a('refresh url is intuit token bearer',         $c($cli, 'oauth.platform.intuit.com/oauth2/v1/tokens/bearer'));
$a('authorize url is appcenter',                 $c($cli, 'appcenter.intuit.com/connect/oauth2'));
$a('sandbox + production bases declared',        $c($cli, 'sandbox-quickbooks.api.intuit.com') && $c($cli, 'quickbooks.api.intuit.com'));
$a('test transport hook supported',              $c($cli, "__qbo_transport"));
$a('state nonce ttl 30 minutes',                 $c($cli, '$age > 1800'));

// ----------------------------------------------------------------- api dispatch
echo "\napi/qbo.php — action dispatch\n";
$apiPath = $ROOT . '/api/qbo.php';
$api = (string) file_get_contents($apiPath);
$a('file exists',                                $api !== '');
foreach (['status', 'oauth_start', 'oauth_callback', 'disconnect', 'ping', 'sync_config_get', 'sync_config_set'] as $act) {
    $a("handles action: $act",                   $c($api, "case '$act'") || $c($api, "\$action === '$act'"));
}
$a('oauth_callback consumes state nonce',        $c($api, 'qboConsumeOAuthState'));
$a('oauth_callback exchanges code',              $c($api, 'qboExchangeCode'));
$a('requires integrations.qbo.view for status',  $c($api, "RBAC::requirePermission(\$user, 'integrations.qbo.view')"));
$a('requires integrations.qbo.manage for write', $c($api, "RBAC::requirePermission(\$user, 'integrations.qbo.manage')"));
$a('returns configured + environment',           $c($api, "'configured'") && $c($api, "'environment'"));

// shim files exist
foreach (['status', 'oauth_start', 'oauth_callback', 'disconnect', 'ping', 'sync_config_get', 'sync_config_set'] as $shim) {
    $a("shim api/qbo/$shim.php present",         file_exists($ROOT . "/api/qbo/$shim.php"));
}

// ----------------------------------------------------------------- syntax sanity
echo "\nSyntax sanity (php -l)\n";
foreach ([
    'core/qbo/client.php',
    'api/qbo.php',
    'api/qbo/status.php',
    'api/qbo/oauth_start.php',
    'api/qbo/oauth_callback.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($ROOT . '/' . $f) . ' 2>&1', $out, $rc);
    $a("php -l $f",                              $rc === 0);
}

// ----------------------------------------------------------------- UI: QboSettings.jsx
echo "\nUI — QboSettings.jsx\n";
$uiPath = $ROOT . '/dashboard/src/pages/QboSettings.jsx';
$ui = (string) file_get_contents($uiPath);
$a('file exists',                                $ui !== '');
$a('root testid qbo-settings',                   $c($ui, 'data-testid="qbo-settings"'));
$a('connect button testid',                      $c($ui, 'data-testid="qbo-connect-btn"'));
$a('disconnect button testid',                   $c($ui, 'data-testid="qbo-disconnect-btn"'));
$a('ping (test connection) testid',              $c($ui, 'data-testid="qbo-ping-btn"'));
$a('connected branch testid',                    $c($ui, 'data-testid="qbo-connected"'));
$a('not-connected branch testid',                $c($ui, 'data-testid="qbo-not-connected"'));
$a('not-configured branch testid',               $c($ui, 'data-testid="qbo-not-configured"'));
$a('sync config table testid',                   $c($ui, 'data-testid="qbo-sync-config-table"'));
$a('per-entity direction picker testid',         $c($ui, 'data-testid={`qbo-sync-dir-${entity}`}'));
$a('save config button testid',                  $c($ui, 'data-testid="qbo-sync-config-save"'));
$a('uses /api/qbo/oauth_start',                  $c($ui, '/api/qbo/oauth_start.php'));
$a('uses /api/qbo/disconnect',                   $c($ui, '/api/qbo/disconnect.php'));
$a('uses /api/qbo/ping',                         $c($ui, '/api/qbo/ping.php'));
$a('uses /api/qbo/sync_config_set',              $c($ui, '/api/qbo/sync_config_set.php'));
$a('handles callback flash from URL',            $c($ui, 'parseFlashFromUrl'));
$a('confirms before disconnect',                 $c($ui, 'window.confirm'));

// ----------------------------------------------------------------- Admin + Hub wiring
echo "\nUI — AdminModule + IntegrationsHub wiring\n";
$ad = (string) file_get_contents($ROOT . '/dashboard/src/pages/AdminModule.jsx');
$a('imports QboSettings',                        $c($ad, "import QboSettings from './QboSettings'"));
$a('mounts /admin/integrations/qbo',
    $c($ad, '<Route path="/integrations/qbo"      element={<QboSettings session={session} />} />'));

$hub = (string) file_get_contents($ROOT . '/dashboard/src/pages/IntegrationsHub.jsx');
$a('hub adds qbo card testid',                   $c($hub, 'data-testid="integration-card-qbo"') || $c($hub, 'testid="integration-card-qbo"'));
$a('hub probes /api/qbo/status',                 $c($hub, '/api/qbo/status.php?action=status'));
$a('hub links to /admin/integrations/qbo',       $c($hub, 'href="/admin/integrations/qbo"'));
$a('hub surfaces Accounting section',            $c($hub, 'Accounting'));

// ----------------------------------------------------------------- Functional smoke (test-transport injection)
echo "\nFunctional — adapter via injected transport stub\n";
// Don't require the client unless we have a DB connection because qbo_connection() touches MySQL.
// Just smoke the qboRawRequest hook + URL construction for code reachability.
require_once $cliPath;
$captured = [];
$GLOBALS['__qbo_transport'] = function (string $method, string $url, array $headers, ?string $body) use (&$captured) {
    $captured[] = compact('method', 'url', 'headers', 'body');
    if ($method === 'POST' && strpos($url, 'tokens/bearer') !== false) {
        return ['status' => 200, 'body' => [
            'access_token' => 'fake.access.tok',
            'refresh_token' => 'fake.refresh.tok',
            'expires_in' => 3600,
            'x_refresh_token_expires_in' => 8726400,
            'scope' => 'com.intuit.quickbooks.accounting',
        ], 'headers' => []];
    }
    return ['status' => 200, 'body' => ['ok' => true], 'headers' => []];
};
$resp = qboRawRequest('POST', QBO_TOKEN_URL, 'grant_type=refresh_token&refresh_token=x', ['Content-Type: application/x-www-form-urlencoded']);
$a('transport stub captured a call',             count($captured) === 1);
$a('token URL hits intuit bearer endpoint',      $captured[0]['url'] === QBO_TOKEN_URL);
$a('response decoded with access_token',         is_array($resp['body']) && !empty($resp['body']['access_token']));
unset($GLOBALS['__qbo_transport']);

echo "\n=========================================\n";
echo "QBO Foundation smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
