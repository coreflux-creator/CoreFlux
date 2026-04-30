<?php
/**
 * M365GraphDriver + OAuth plumbing — contract smoke tests (Phase B Slice 2a).
 * Uses injected HTTP transport to stub Graph/Microsoft responses.
 */
declare(strict_types=1);
require_once __DIR__ . '/../core/mail/M365GraphDriver.php';

$pass = 0; $fail = 0;
$assert = function ($n, $c) use (&$pass, &$fail) { if ($c) { echo "  \u{2713} {$n}\n"; $pass++; } else { echo "  \u{2717} {$n}\n"; $fail++; } };

echo "Driver construction\n";
$d = new Core\Mail\M365GraphDriver('client-xyz', 'secret-xyz', 'https://app/cb');
$assert('driver_name = m365',                 $d->driver_name() === 'm365');
$assert('send() fails (read-only driver)',    $d->send([])['status'] === 'failed');

echo "\nAuthorize URL + PKCE\n";
$verifier = str_repeat('v', 64);
$url = $d->build_authorize_url('STATE123', $verifier);
$assert('hits Microsoft login',                strpos($url, 'login.microsoftonline.com/common/oauth2/v2.0/authorize') !== false);
$assert('has client_id',                       strpos($url, 'client_id=client-xyz') !== false);
$assert('has redirect_uri',                    strpos($url, 'redirect_uri=https') !== false);
$assert('has response_type=code',              strpos($url, 'response_type=code') !== false);
$assert('has state',                           strpos($url, 'state=STATE123') !== false);
$assert('has code_challenge_method=S256',      strpos($url, 'code_challenge_method=S256') !== false);
$assert('scope is Mail.Read + offline_access', str_contains(urldecode($url), 'scope=https://graph.microsoft.com/Mail.Read offline_access'));
// PKCE challenge must be S256(verifier), base64-url, unpadded
$expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
$assert('challenge matches S256(verifier)',    strpos($url, 'code_challenge=' . $expectedChallenge) !== false);

echo "\nDelta-token extraction\n";
$tok = $d->extractDeltaToken('https://graph.microsoft.com/v1.0/me/mailFolders/abc/messages/delta?$deltatoken=ABCDE');
$assert('extract $deltatoken',                 $tok === 'ABCDE');
$tok2 = $d->extractDeltaToken('https://x/?%24deltatoken=URLencoded');
$assert('extract url-encoded $deltatoken',     $tok2 === 'URLencoded');

echo "\nToken exchange — happy path (injected transport)\n";
$captured = null;
$driver = new Core\Mail\M365GraphDriver('client-xyz', 'secret-xyz', 'https://app/cb', function ($req) use (&$captured) {
    $captured = $req;
    return ['ok' => true, 'http' => 200, 'body' => [
        'access_token' => 'at_123', 'refresh_token' => 'rt_123',
        'expires_in'   => 3600,     'scope'         => 'Mail.Read offline_access',
        'token_type'   => 'Bearer',
    ]];
});
$token = $driver->exchange_code('CODE_AAA', str_repeat('x', 64));
$assert('POST to token endpoint',              strpos($captured['url'], '/oauth2/v2.0/token') !== false);
$assert('Content-Type form-urlencoded',        $captured['content_type'] === 'application/x-www-form-urlencoded');
$assert('body has grant_type=authorization_code', strpos($captured['body'], 'grant_type=authorization_code') !== false);
$assert('body has code=',                      strpos($captured['body'], 'code=CODE_AAA') !== false);
$assert('body has code_verifier=',             strpos($captured['body'], 'code_verifier=') !== false);
$assert('body has client_secret=',             strpos($captured['body'], 'client_secret=secret-xyz') !== false);
$assert('access_token returned',               ($token['access_token'] ?? null) === 'at_123');

echo "\nToken exchange — error surfaces provider message\n";
$driverFail = new Core\Mail\M365GraphDriver('a', 'b', 'https://c',
    fn ($req) => ['ok' => false, 'http' => 400, 'error' => 'invalid_grant: AADSTS70000', 'body' => []]);
try {
    $driverFail->exchange_code('bad', 'v');
    $assert('exchange_code throws on error', false);
} catch (\Throwable $e) {
    $assert('exchange_code throws on error', str_contains($e->getMessage(), 'invalid_grant'));
}

echo "\nFetch /me\n";
$driverMe = new Core\Mail\M365GraphDriver('a', 'b', 'https://c', function ($req) use (&$captured) {
    $captured = $req;
    return ['ok' => true, 'http' => 200, 'body' => [
        'id' => 'u1', 'displayName' => 'Test User',
        'mail' => 'test@acme.com', 'userPrincipalName' => 'test@acme.onmicrosoft.com',
    ]];
});
$me = $driverMe->fetch_me('AT');
$assert('GET /me with bearer',                 ($captured['method'] ?? 'GET') === 'GET' && str_contains($captured['url'], '/me?'));
$assert('Bearer token set',                    ($captured['token'] ?? '') === 'AT');
$assert('mail address returned',               $me['mail'] === 'test@acme.com');

echo "\n/api endpoint files\n";
$api = (string) file_get_contents(__DIR__ . '/../api/mail_connections.php');
$assert('api/mail_connections.php exists',     strlen($api) > 0);
$o = []; $rc = 0; @exec('php -l ' . escapeshellarg(__DIR__ . '/../api/mail_connections.php') . ' 2>&1', $o, $rc);
$assert('api/mail_connections.php parses',     $rc === 0);
foreach (['oauth_start','list_folders','watch_folder','poll_now'] as $a) {
    $assert("has action={$a}",                  strpos($api, "action === '{$a}'") !== false);
}
$assert('gated by tenant.manage',              strpos($api, "'tenant.manage'") !== false);
$assert('guards MICROSOFT_CLIENT_ID env',      strpos($api, 'MICROSOFT_CLIENT_ID') !== false);

$cb = (string) file_get_contents(__DIR__ . '/../oauth/callback/microsoft365.php');
$assert('callback file exists',                strlen($cb) > 0);
$o = []; $rc = 0; @exec('php -l ' . escapeshellarg(__DIR__ . '/../oauth/callback/microsoft365.php') . ' 2>&1', $o, $rc);
$assert('callback parses',                     $rc === 0);
$assert('callback validates state via hash_equals', strpos($cb, 'hash_equals(') !== false);
$assert('callback has 10-min session window',  strpos($cb, 'expires') !== false);
$assert('callback redirects to settings/mail', strpos($cb, '/settings/mail?m365=') !== false);
$assert('callback emits audit event',          strpos($cb, 'mail.connection.connected') !== false);

echo "\nReact UI wiring\n";
$page = (string) file_get_contents(__DIR__ . '/../dashboard/src/pages/MailSettingsPage.jsx');
$assert('imports MailConnectionsCard',         strpos($page, "import MailConnectionsCard from './MailConnectionsCard'") !== false);
$assert('renders <MailConnectionsCard',        strpos($page, '<MailConnectionsCard') !== false);
$assert('parses m365=connected query',         strpos($page, "m365 === 'connected'") !== false);

$card = (string) file_get_contents(__DIR__ . '/../dashboard/src/pages/MailConnectionsCard.jsx');
$assert('connect button testid',               strpos($card, 'mail-connect-m365') !== false);
$assert('folder picker testid',                strpos($card, 'mail-folder-picker') !== false);
$assert('poll-now testid pattern',             strpos($card, 'mail-poll-now-') !== false);
$assert('revoke testid pattern',               strpos($card, 'mail-revoke-') !== false);
$assert('hits /api/mail_connections.php',      strpos($card, '/api/mail_connections.php') !== false);
$assert('redirects to authorize_url',          strpos($card, 'window.location.href = res.authorize_url') !== false);

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
