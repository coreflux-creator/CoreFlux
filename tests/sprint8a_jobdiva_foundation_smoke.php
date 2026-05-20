<?php
/**
 * Sprint 8a / Slice A1 smoke — JobDiva connection foundation.
 *
 * Asserts:
 *   - Migration 021_jobdiva_connections.sql shape (3 tables, idempotent
 *     CREATE TABLE IF NOT EXISTS, encrypted blob columns, dedup keys).
 *   - core/jobdiva/client.php public surface (connection CRUD, session
 *     token caching with slack, ping, audit, webhook signature verify,
 *     auto-refresh on 401).
 *   - api/jobdiva.php dispatch + per-action RBAC + path-style aliases.
 *   - Webhook auth path bypasses CoreFlux auth + verifies HMAC + queues.
 *   - JobDivaSettings.jsx renders connect form, status card, webhook
 *     URL panel, audit table with full testid coverage.
 *   - AdminModule wiring (route + sidebar link + action card).
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$lint = function (string $p): bool {
    $o = []; $rc = 0; @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
    return $rc === 0;
};
$ROOT = realpath(__DIR__ . '/..');

echo "Migration — 021_jobdiva_connections.sql\n";
$mig = (string) file_get_contents("{$ROOT}/core/migrations/021_jobdiva_connections.sql");
$assert('migration exists',                       strlen($mig) > 0);
$assert('jobdiva_connections idempotent',         strpos($mig, 'CREATE TABLE IF NOT EXISTS jobdiva_connections') !== false);
$assert('jobdiva_webhook_events idempotent',      strpos($mig, 'CREATE TABLE IF NOT EXISTS jobdiva_webhook_events') !== false);
$assert('jobdiva_sync_audit idempotent',          strpos($mig, 'CREATE TABLE IF NOT EXISTS jobdiva_sync_audit') !== false);
$assert('UNIQUE per tenant on connections',       strpos($mig, 'UNIQUE KEY uk_tenant (tenant_id)') !== false);
$assert('UNIQUE webhook event idempotency',
    strpos($mig, 'UNIQUE KEY uk_tenant_event (tenant_id, jd_event_id)') !== false);
$assert('encrypted password column VARBINARY',    strpos($mig, 'password_enc      VARBINARY(1024) NOT NULL') !== false);
$assert('encrypted session_token column',         strpos($mig, 'session_token_enc VARBINARY(1024)') !== false);
$assert('encrypted webhook_secret column',        strpos($mig, 'webhook_secret_enc VARBINARY(1024)') !== false);
$assert('status enum 4 states',
    strpos($mig, "ENUM('connected','degraded','disconnected','error')") !== false);
$assert('JSON sync_config + field_ownership',
    strpos($mig, 'field_ownership   JSON') !== false
    && strpos($mig, 'sync_config       JSON') !== false);

echo "\nClient — core/jobdiva/client.php\n";
$cli = (string) file_get_contents("{$ROOT}/core/jobdiva/client.php");
$assert('parses',                                 $lint("{$ROOT}/core/jobdiva/client.php"));
$assert('JOBDIVA_BASE_URL = api.jobdiva.com',     strpos($cli, "const JOBDIVA_BASE_URL  = 'https://api.jobdiva.com'") !== false);
$assert('auth path /api/jobdiva/authenticate',    strpos($cli, "JOBDIVA_AUTH_PATH = '/api/jobdiva/authenticate'") !== false);
$assert('token-slack constant defined',           strpos($cli, 'JOBDIVA_TOKEN_SLACK_SEC = 60') !== false);
$assert('jobdivaSaveConnection encrypts password',strpos($cli, "encryptField(\$password)") !== false);
$assert('jobdivaSaveConnection upsert path',
    strpos($cli, 'UPDATE jobdiva_connections') !== false
    && strpos($cli, 'INSERT INTO jobdiva_connections') !== false);
$assert('jobdivaSaveConnection clears stale session on update',
    strpos($cli, 'session_token_enc = NULL, session_token_exp = NULL') !== false);
$assert('jobdivaSessionToken caches + uses slack',
    strpos($cli, '$exp > (time() + JOBDIVA_TOKEN_SLACK_SEC)') !== false);
$assert('jobdivaSessionToken mints via authenticate (creds in query, not body)',
    strpos($cli, "jobdivaRawRequest(\n        'POST',\n        JOBDIVA_AUTH_PATH,\n        /* body  */ null,") !== false
    && strpos($cli, "'clientid' => (string) \$row['client_id'],") !== false);
$assert('jobdivaExtractToken handles raw body / JSON / header shapes',
    strpos($cli, 'function jobdivaExtractToken') !== false
    && strpos($cli, "'x-li-token'") !== false
    && strpos($cli, "'access_token'") !== false);
$assert('jobdivaRawRequest captures response headers',
    strpos($cli, 'CURLOPT_HEADERFUNCTION') !== false
    && strpos($cli, '$respHeaders[$k] = $v;') !== false);
$assert('jobdivaRawRequest only sets Content-Type when body present',
    strpos($cli, "if (\$body !== null) \$headers[] = 'Content-Type: application/json'") !== false);
$assert('jobdivaSessionToken handles JWT exp fallback',
    strpos($cli, 'jobdivaJwtExp($token)') !== false);
$assert('jobdivaCall auto-refreshes on 401',
    strpos($cli, "if (\$resp['status'] === 401)") !== false
    && strpos($cli, "session_token_enc = NULL, session_token_exp = NULL") !== false);
$assert('jobdivaCall surfaces degraded on >=400',
    strpos($cli, 'status = "degraded"') !== false);
$assert('Bearer header on authenticated calls',   strpos($cli, "Authorization: Bearer ' . \$token") !== false);
$assert('jobdivaPing emits ping audit row',
    strpos($cli, "jobdivaAudit(\$tenantId, 'ping'") !== false);
$assert('jobdivaWebhookVerify accepts JobDiva X-Hub-Signature (SHA1) + SHA256 + legacy',
    strpos($cli, "hash_hmac(\$algo, \$rawBody, \$secret)") !== false
    && strpos($cli, 'HTTP_X_HUB_SIGNATURE') !== false
    && strpos($cli, 'HTTP_X_HUB_SIGNATURE_256') !== false
    && strpos($cli, 'HTTP_X_JOBDIVA_SIGNATURE') !== false
    && strpos($cli, 'hash_equals') !== false);
$assert('jobdivaAudit shape (action+direction+ok)',
    strpos($cli, 'INSERT INTO jobdiva_sync_audit') !== false
    && strpos($cli, "'ok'  => isset(\$opts['ok']) ? ((int) (bool) \$opts['ok']) : 1") !== false);
$assert('jobdivaDisconnect clears cached token + flips status',
    strpos($cli, 'status = "disconnected", session_token_enc = NULL') !== false);

echo "\nDispatcher — api/jobdiva.php\n";
$disp = (string) file_get_contents("{$ROOT}/api/jobdiva.php");
$assert('parses',                                 $lint("{$ROOT}/api/jobdiva.php"));
$assert('webhook bypasses CoreFlux auth',
    strpos($disp, "if (\$action === 'webhook') {") !== false
    && strpos($disp, '$ctx  = api_require_auth();') !== false
    && strpos($disp, "if (\$action === 'webhook') {") < strpos($disp, '$ctx  = api_require_auth();'));
$assert('webhook reads X-JobDiva-Signature',      strpos($disp, "HTTP_X_JOBDIVA_SIGNATURE") !== false);
$assert('webhook persists with ON DUPLICATE KEY id=id (idempotent)',
    strpos($disp, 'ON DUPLICATE KEY UPDATE id = id') !== false);
$assert('webhook returns 401 on bad signature',   strpos($disp, "api_error('Invalid signature', 401)") !== false);
$assert('connect RBAC integrations.jobdiva.manage',
    strpos($disp, "rbac_legacy_require(\$user, 'integrations.jobdiva.manage')") !== false);
$assert('status RBAC integrations.jobdiva.view',
    strpos($disp, "rbac_legacy_require(\$user, 'integrations.jobdiva.view')") !== false);
$assert('status returns recent_audit + recent_events',
    strpos($disp, "'recent_audit'") !== false
    && strpos($disp, "'recent_events'") !== false);
$assert('status hides password but exposes username + client_id',
    strpos($disp, "'client_id'         => \$row['client_id']") !== false
    && strpos($disp, "'username'          => \$row['username']") !== false
    && strpos($disp, "password_enc") === strpos($disp, "password_enc"));
$assert('connect runs jobdivaPing immediately',
    strpos($disp, "\$ping = jobdivaPing(\$tid, \$user['id'] ?? null);") !== false);
$assert('sync action upgraded to A3 entity sync (placeholder removed)',
    strpos($disp, 'Slice A1 placeholder — entity sync arrives in A2') === false
    && strpos($disp, 'jobdivaSyncAll($tid, $user') !== false);
$assert('disconnect supports POST + DELETE',
    strpos($disp, "in_array(\$method, ['POST', 'DELETE'], true)") !== false);
$assert('webhook URL helper present',             strpos($disp, 'function jobdivaWebhookUrl') !== false);
$assert('webhook URL helper derives absolute URL from request',
    strpos($disp, "HTTP_X_FORWARDED_HOST") !== false
    && strpos($disp, "HTTP_X_FORWARDED_PROTO") !== false
    && strpos($disp, "HTTP_HOST") !== false);

echo "\nPath-style aliases\n";
foreach (['connect','disconnect','status','ping','sync','webhook'] as $v) {
    $f = "{$ROOT}/api/jobdiva/{$v}.php";
    $assert("alias /api/jobdiva/{$v}.php exists", is_file($f));
    $assert("alias /api/jobdiva/{$v}.php parses", $lint($f));
    $assert("alias /api/jobdiva/{$v}.php delegates",
        strpos((string) file_get_contents($f), "require __DIR__ . '/../jobdiva.php'") !== false);
}

echo "\nFrontend — JobDivaSettings.jsx\n";
$jsx = (string) file_get_contents("{$ROOT}/dashboard/src/pages/JobDivaSettings.jsx");
$assert('reads /api/jobdiva/status.php',          strpos($jsx, "/api/jobdiva/status.php?action=status") !== false);
$assert('connect POST',                           strpos($jsx, "/api/jobdiva/connect.php?action=connect") !== false);
$assert('ping POST',                              strpos($jsx, "/api/jobdiva/ping.php?action=ping") !== false);
$assert('sync POST',                              strpos($jsx, "/api/jobdiva/sync.php?action=sync") !== false);
$assert('disconnect POST',                        strpos($jsx, "/api/jobdiva/disconnect.php?action=disconnect") !== false);
foreach ([
    'page','refresh','loading','error','msg','err',
    'status-badge','status-card','client-id','username','last-ping','last-sync','token-exp',
    'webhook-card','webhook-url','webhook-copy','webhook-secret-set',
    'connect-form','client-id-input','username-input','password-input','show-pwd','webhook-secret-input',
    'connect','ping','sync','disconnect',
    'audit-card','audit-table','audit-empty',
] as $id) {
    $assert("testid: jobdiva-settings-{$id}",
        strpos($jsx, "data-testid=\"jobdiva-settings-{$id}\"") !== false);
}
$assert('audit row testid template',
    strpos($jsx, 'data-testid={`jobdiva-settings-audit-row-${r.id}`}') !== false);
$assert('webhook row testid template',
    strpos($jsx, 'data-testid={`jobdiva-settings-webhook-row-${e.id}`}') !== false);
$assert('password input has autoComplete=new-password',
    strpos($jsx, 'autoComplete="new-password"') !== false);

echo "\nWiring — AdminModule\n";
$ad = (string) file_get_contents("{$ROOT}/dashboard/src/pages/AdminModule.jsx");
$assert('imports JobDivaSettings',                strpos($ad, "import JobDivaSettings from './JobDivaSettings'") !== false);
$assert('mounts /integrations/jobdiva route',
    strpos($ad, 'path="/integrations/jobdiva" element={<JobDivaSettings session={session} />}') !== false);
$assert('integrations hub sidebar link',
    strpos($ad, "to: '/admin/integrations'") !== false
    && strpos($ad, "label: 'Integrations'") !== false);
$assert('overview action card routes to integrations hub',
    strpos($ad, '<ActionCard icon={PlugZap} title="Integrations"') !== false
    && strpos($ad, 'href="/admin/integrations"') !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
