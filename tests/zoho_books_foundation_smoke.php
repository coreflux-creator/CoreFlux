<?php
/**
 * Zoho Books integration — Slice 1 (Foundation) smoke.
 *
 * Validates:
 *   - migration 064 is reachable and idempotent in shape
 *   - core/zoho_books/client.php exposes the documented public surface
 *   - api/zoho_books.php dispatches all expected actions
 *   - dashboard/src/pages/ZohoBooksSettings.jsx renders the documented testids
 *   - AdminModule + IntegrationsHub wire Zoho Books into the centralised
 *     /admin/integrations surface
 *   - RBAC legacy_map registers integrations.zoho_books.{view,manage}
 *
 * Run via: php -d zend.assertions=1 tests/zoho_books_foundation_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ok  $msg\n"; $pass++; }
    else       { echo "FAIL  $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ----------------------------------------------------------------- migration shape
echo "Migration 064 — zoho_books_foundation\n";
$migPath = $ROOT . '/core/migrations/064_zoho_books_foundation.sql';
$mig = file_exists($migPath) ? (string) file_get_contents($migPath) : '';
$a('migration file present',                     $mig !== '');
$a('declares zoho_books_connections',            $c($mig, 'CREATE TABLE IF NOT EXISTS zoho_books_connections'));
$a('declares zoho_books_oauth_state',            $c($mig, 'CREATE TABLE IF NOT EXISTS zoho_books_oauth_state'));
$a('declares zoho_books_sync_audit',             $c($mig, 'CREATE TABLE IF NOT EXISTS zoho_books_sync_audit'));
$a('unique tenant on connections',               $c($mig, 'UNIQUE KEY uq_zoho_tenant (tenant_id)'));
$a('AES-256-GCM token columns',                  $c($mig, 'access_token_ct') && $c($mig, 'refresh_token_ct'));
$a('dc column with default',                     $c($mig, "dc                  VARCHAR(16)  NOT NULL DEFAULT 'com'"));
$a('state nonce unique key',                     $c($mig, 'UNIQUE KEY uq_zoho_state'));
$a('sync_config JSON column',                    $c($mig, 'sync_config         JSON NULL'));

// ----------------------------------------------------------------- client.php surface
echo "\ncore/zoho_books/client.php — public surface\n";
$cliPath = $ROOT . '/core/zoho_books/client.php';
$cli = (string) file_get_contents($cliPath);
$a('file exists',                                $cli !== '');
$a('declares strict types',                      $c($cli, 'declare(strict_types=1);'));
$a('ZOHO_BOOKS_SYNC_ENTITIES constant',          $c($cli, 'const ZOHO_BOOKS_SYNC_ENTITIES'));
$a('lists journal_entries',                      $c($cli, "'journal_entries'"));
$a('lists contacts',                             $c($cli, "'contacts'"));
$a('lists invoices',                             $c($cli, "'invoices'"));
$a('lists bills',                                $c($cli, "'bills'"));
$a('lists payments',                             $c($cli, "'payments'"));
$a('lists chart_of_accounts',                    $c($cli, "'chart_of_accounts'"));
$a('SYNC_DIRECTIONS includes push',              $c($cli, "'push'"));
$a('SYNC_DIRECTIONS includes pull',              $c($cli, "'pull'"));
$a('SYNC_DIRECTIONS includes two_way',           $c($cli, "'two_way'"));
$a('default scope ZohoBooks.fullaccess',         $c($cli, "ZOHO_BOOKS_DEFAULT_SCOPES     = 'ZohoBooks.fullaccess.all'"));
$a('valid DCs include com.au',                   $c($cli, "'com.au'"));
$a('valid DCs include eu',                       $c($cli, "ZOHO_BOOKS_VALID_DCS") && $c($cli, "'eu'"));
foreach ([
    'zohoBooksConfigured', 'zohoBooksConnection', 'zohoBooksBuildAuthorizeUrl',
    'zohoBooksExchangeCode', 'zohoBooksDisconnect', 'zohoBooksAccessToken',
    'zohoBooksRefreshAccessToken', 'zohoBooksCall', 'zohoBooksRawRequest',
    'zohoBooksPing', 'zohoBooksSyncConfigRead', 'zohoBooksSyncConfigWrite',
    'zohoBooksConsumeOAuthState', 'zohoBooksAudit',
    'zohoBooksDcFromAccountsServer', 'zohoBooksAccountsHost', 'zohoBooksApiBase',
] as $fn) {
    $a("declares $fn()",                         $c($cli, "function $fn"));
}
$a('uses encryptField for tokens',               substr_count($cli, 'encryptField(') >= 4);
$a('uses Zoho-oauthtoken header',                $c($cli, 'Authorization: Zoho-oauthtoken '));
$a('authorize URL base is accounts.zoho.com',    $c($cli, "ZOHO_BOOKS_AUTHORIZE_URL_BASE = 'https://accounts.zoho.com/oauth/v2/auth'"));
$a('refresh uses /oauth/v2/token',               $c($cli, "/oauth/v2/token'"));
$a('test transport hook supported',              $c($cli, '__zoho_books_transport'));
$a('state nonce ttl 30 minutes',                 $c($cli, '$age > 1800'));
$a('access_type offline (long-lived refresh)',   $c($cli, "'access_type'   => 'offline'"));

// ----------------------------------------------------------------- api dispatch
echo "\napi/zoho_books.php — action dispatch\n";
$apiPath = $ROOT . '/api/zoho_books.php';
$api = (string) file_get_contents($apiPath);
$a('file exists',                                $api !== '');
foreach (['status', 'oauth_start', 'oauth_callback', 'disconnect', 'ping', 'sync_config_get', 'sync_config_set'] as $act) {
    $a("handles action: $act",                   $c($api, "case '$act'") || $c($api, "\$action === '$act'"));
}
$a('oauth_callback consumes state nonce',        $c($api, 'zohoBooksConsumeOAuthState'));
$a('oauth_callback exchanges code',              $c($api, 'zohoBooksExchangeCode'));
$a('oauth_callback reads accounts-server',       $c($api, "api_query('accounts-server')"));
$a('requires integrations.zoho_books.view',      $c($api, "rbac_legacy_require(\$user, 'integrations.zoho_books.view')"));
$a('requires integrations.zoho_books.manage',    $c($api, "rbac_legacy_require(\$user, 'integrations.zoho_books.manage')"));
$a('returns configured + dc',                    $c($api, "'configured'") && $c($api, "'dc'"));

// shim files exist
foreach (['status', 'oauth_start', 'oauth_callback', 'disconnect', 'ping', 'sync_config_get', 'sync_config_set'] as $shim) {
    $a("shim api/zoho_books/$shim.php present",  file_exists($ROOT . "/api/zoho_books/$shim.php"));
}

// ----------------------------------------------------------------- syntax sanity
echo "\nSyntax sanity (php -l)\n";
foreach ([
    'core/zoho_books/client.php',
    'api/zoho_books.php',
    'api/zoho_books/status.php',
    'api/zoho_books/oauth_start.php',
    'api/zoho_books/oauth_callback.php',
    'api/zoho_books/ping.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($ROOT . '/' . $f) . ' 2>&1', $out, $rc);
    $a("php -l $f",                              $rc === 0);
}

// ----------------------------------------------------------------- UI: ZohoBooksSettings.jsx
echo "\nUI — ZohoBooksSettings.jsx\n";
$uiPath = $ROOT . '/dashboard/src/pages/ZohoBooksSettings.jsx';
$ui = (string) file_get_contents($uiPath);
$a('file exists',                                $ui !== '');
$a('root testid zoho-books-settings',            $c($ui, 'data-testid="zoho-books-settings"'));
$a('connect button testid',                      $c($ui, 'data-testid="zoho-books-connect-btn"'));
$a('disconnect button testid',                   $c($ui, 'data-testid="zoho-books-disconnect-btn"'));
$a('ping (test connection) testid',              $c($ui, 'data-testid="zoho-books-ping-btn"'));
$a('connected branch testid',                    $c($ui, 'data-testid="zoho-books-connected"'));
$a('not-connected branch testid',                $c($ui, 'data-testid="zoho-books-not-connected"'));
$a('not-configured branch testid',               $c($ui, 'data-testid="zoho-books-not-configured"'));
$a('sync config table testid',                   $c($ui, 'data-testid="zoho-books-sync-config-table"'));
$a('per-entity direction picker testid',         $c($ui, 'data-testid={`zoho-books-sync-dir-${entity}`}'));
$a('save config button testid',                  $c($ui, 'data-testid="zoho-books-sync-config-save"'));
$a('uses /api/zoho_books/oauth_start',           $c($ui, '/api/zoho_books/oauth_start.php'));
$a('uses /api/zoho_books/disconnect',            $c($ui, '/api/zoho_books/disconnect.php'));
$a('uses /api/zoho_books/ping',                  $c($ui, '/api/zoho_books/ping.php'));
$a('uses /api/zoho_books/sync_config_set',       $c($ui, '/api/zoho_books/sync_config_set.php'));
$a('handles callback flash from URL',            $c($ui, 'parseFlashFromUrl'));
$a('confirms before disconnect',                 $c($ui, 'window.confirm'));

// ----------------------------------------------------------------- Admin + Hub wiring
echo "\nUI — AdminModule + IntegrationsHub wiring\n";
$ad = (string) file_get_contents($ROOT . '/dashboard/src/pages/AdminModule.jsx');
$a('imports ZohoBooksSettings',                  $c($ad, "import ZohoBooksSettings from './ZohoBooksSettings'"));
$a('mounts /admin/integrations/zoho-books',
    $c($ad, '<Route path="/integrations/zoho-books" element={<ZohoBooksSettings session={session} />} />'));

$hub = (string) file_get_contents($ROOT . '/dashboard/src/pages/IntegrationsHub.jsx');
$a('hub adds zoho-books card testid',            $c($hub, 'data-testid="integration-card-zoho-books"') || $c($hub, 'testid="integration-card-zoho-books"'));
$a('hub probes /api/zoho_books/status',          $c($hub, '/api/zoho_books/status.php?action=status'));
$a('hub links to /admin/integrations/zoho-books',$c($hub, 'href="/admin/integrations/zoho-books"'));

// ----------------------------------------------------------------- RBAC legacy_map
echo "\nRBAC — legacy_map entries\n";
$map = (string) file_get_contents($ROOT . '/core/rbac/legacy_map.php');
$a('legacy_map registers integrations.zoho_books.view',   $c($map, "'integrations.zoho_books.view'"));
$a('legacy_map registers integrations.zoho_books.manage', $c($map, "'integrations.zoho_books.manage'"));

// ----------------------------------------------------------------- Functional smoke (test-transport injection)
echo "\nFunctional — adapter via injected transport stub\n";
require_once $cliPath;

// Validate DC parser against every supported region.
$a('DC parse accounts.zoho.eu → eu',             zohoBooksDcFromAccountsServer('https://accounts.zoho.eu') === 'eu');
$a('DC parse accounts.zoho.in → in',             zohoBooksDcFromAccountsServer('https://accounts.zoho.in') === 'in');
$a('DC parse accounts.zoho.com.au → com.au',     zohoBooksDcFromAccountsServer('https://accounts.zoho.com.au') === 'com.au');
$a('DC parse accounts.zoho.com.cn → com.cn',     zohoBooksDcFromAccountsServer('https://accounts.zoho.com.cn') === 'com.cn');
$a('DC parse accounts.zoho.jp → jp',             zohoBooksDcFromAccountsServer('https://accounts.zoho.jp') === 'jp');
$a('DC parse accounts.zoho.sa → sa',             zohoBooksDcFromAccountsServer('https://accounts.zoho.sa') === 'sa');
$a('DC parse accounts.zoho.com → com',           zohoBooksDcFromAccountsServer('https://accounts.zoho.com') === 'com');
$a('DC parse empty string → com',                zohoBooksDcFromAccountsServer('') === 'com');
$a('DC parse garbage → com',                     zohoBooksDcFromAccountsServer('https://evil.example/') === 'com');

// API base + accounts host wiring per DC.
$a('accounts host com',                          zohoBooksAccountsHost('com') === 'https://accounts.zoho.com');
$a('accounts host eu',                           zohoBooksAccountsHost('eu')  === 'https://accounts.zoho.eu');
$a('api base com',                               zohoBooksApiBase('com') === 'https://www.zohoapis.com');
$a('api base com.au',                            zohoBooksApiBase('com.au') === 'https://www.zohoapis.com.au');
$a('api base invalid → com fallback',            zohoBooksApiBase('ru')  === 'https://www.zohoapis.com');

// Transport stub captures the call shape.
$captured = [];
$GLOBALS['__zoho_books_transport'] = function (string $method, string $url, array $headers, ?string $body) use (&$captured) {
    $captured[] = compact('method', 'url', 'headers', 'body');
    if (strpos($url, '/oauth/v2/token') !== false) {
        return ['status' => 200, 'body' => [
            'access_token'  => 'fake.access.tok',
            'refresh_token' => 'fake.refresh.tok',
            'expires_in'    => 3600,
            'scope'         => 'ZohoBooks.fullaccess.all',
        ], 'headers' => []];
    }
    return ['status' => 200, 'body' => ['ok' => true], 'headers' => []];
};
$resp = zohoBooksRawRequest('POST',
    'https://accounts.zoho.eu/oauth/v2/token',
    'grant_type=refresh_token&refresh_token=x',
    ['Content-Type: application/x-www-form-urlencoded']
);
$a('transport stub captured a call',             count($captured) === 1);
$a('token URL hits accounts.zoho.{eu}',          $captured[0]['url'] === 'https://accounts.zoho.eu/oauth/v2/token');
$a('response decoded with access_token',         is_array($resp['body']) && !empty($resp['body']['access_token']));
unset($GLOBALS['__zoho_books_transport']);

echo "\n=========================================\n";
echo "Zoho Books Foundation smoke: {$pass} ok / {$fail} fail\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
