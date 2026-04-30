<?php
/**
 * OAuth callback for Microsoft 365 connection (M365GraphDriver).
 *
 * Registered URL (Azure → Authentication → Web):
 *   https://www.corefluxapp.com/oauth/callback/microsoft365.php
 *   https://<preview-domain>/oauth/callback/microsoft365.php
 *
 * Flow:
 *   1. /api/mail_connections.php?action=oauth_start  (puts verifier+state in $_SESSION)
 *   2. Browser redirects to login.microsoftonline.com → user consents
 *   3. Microsoft redirects HERE with ?code=... &state=...
 *   4. We verify state, exchange code for tokens, fetch /me, persist a new
 *      tenant_mail_connections row, then redirect to /settings/mail.
 */

require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/encryption.php';
require_once __DIR__ . '/../../core/mail/M365GraphDriver.php';

initSession();

use Core\Mail\M365GraphDriver;

$err = null;
$ok  = null;

try {
    if (isset($_GET['error'])) {
        throw new RuntimeException('Microsoft denied consent: '
            . ($_GET['error_description'] ?? $_GET['error']));
    }

    $code  = (string) ($_GET['code']  ?? '');
    $state = (string) ($_GET['state'] ?? '');
    if ($code === '' || $state === '') throw new RuntimeException('Missing code or state on callback');

    $sess = $_SESSION['m365_oauth'] ?? null;
    if (!$sess) throw new RuntimeException('No OAuth session — start over from Settings → Email delivery.');
    if (($sess['expires'] ?? 0) < time()) throw new RuntimeException('OAuth session expired (10 min limit). Start over.');
    if (!hash_equals((string) $sess['state'], $state)) throw new RuntimeException('State mismatch — possible CSRF');

    $tenantId = (int) $sess['tenant_id'];
    if ($tenantId <= 0) throw new RuntimeException('Tenant context missing from OAuth session');

    foreach (['MICROSOFT_CLIENT_ID', 'MICROSOFT_CLIENT_SECRET', 'MICROSOFT_REDIRECT_URI'] as $k) {
        if (!getenv($k)) throw new RuntimeException("{$k} not configured");
    }

    $driver = new M365GraphDriver();
    $token  = $driver->exchange_code($code, (string) $sess['verifier']);
    $access = (string) ($token['access_token'] ?? '');
    if ($access === '') throw new RuntimeException('No access_token in response');
    $me     = $driver->fetch_me($access);
    $email  = $me['mail'] ?? $me['userPrincipalName'] ?? null;
    if (!$email) throw new RuntimeException('Could not determine connected mailbox address');

    $pdo = getDB();
    // De-duplicate: if same tenant + same provider + same address already exists, reuse it.
    $existing = $pdo->prepare(
        'SELECT id FROM tenant_mail_connections
         WHERE tenant_id = :tid AND provider = :p AND account_address = :a LIMIT 1'
    );
    $existing->execute(['tid' => $tenantId, 'p' => 'm365', 'a' => $email]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $connectionId = (int) $row['id'];
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO tenant_mail_connections
              (tenant_id, provider, purpose, display_name, account_address, status, created_at)
             VALUES (:tid, :p, :pp, :dn, :addr, "active", NOW())'
        );
        $stmt->execute([
            'tid'  => $tenantId,
            'p'    => 'm365',
            'pp'   => 'inbound',
            'dn'   => $me['displayName'] ?? $email,
            'addr' => $email,
        ]);
        $connectionId = (int) $pdo->lastInsertId();
    }
    $driver->persistTokens($connectionId, $token);

    // Audit
    try {
        $pdo->prepare('INSERT INTO audit_log (tenant_id, actor_user_id, event, target_id, meta_json, ip_address, created_at)
                       VALUES (:tid, :uid, :ev, :t, :m, :ip, NOW())')
            ->execute([
                'tid' => $tenantId, 'uid' => $sess['user_id'] ?? null,
                'ev'  => 'mail.connection.connected',
                't'   => $connectionId,
                'm'   => json_encode(['provider' => 'm365', 'email' => $email]),
                'ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
    } catch (\Throwable $e) { error_log('[m365 callback] audit failed: ' . $e->getMessage()); }

    unset($_SESSION['m365_oauth']);
    $ok = ['email' => $email, 'connection_id' => $connectionId];
} catch (\Throwable $e) {
    error_log('[m365 callback] ' . $e->getMessage());
    $err = $e->getMessage();
    unset($_SESSION['m365_oauth']);
}

$redirect = '/settings/mail?m365=' . ($ok ? 'connected&email=' . rawurlencode($ok['email']) : 'error&msg=' . rawurlencode($err ?? 'unknown'));
?><!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Connecting Microsoft 365…</title>
<meta http-equiv="refresh" content="1;url=<?= htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8') ?>"></head>
<body style="font-family:system-ui;padding:40px;text-align:center;color:#374151">
<?php if ($ok): ?>
  <h2 data-testid="m365-cb-ok">Connected: <?= htmlspecialchars($ok['email'], ENT_QUOTES, 'UTF-8') ?></h2>
  <p>Redirecting back to settings…</p>
<?php else: ?>
  <h2 style="color:#b91c1c" data-testid="m365-cb-err">Couldn't connect Microsoft 365</h2>
  <p style="color:#6b7280"><?= htmlspecialchars((string) $err, ENT_QUOTES, 'UTF-8') ?></p>
  <p>Redirecting back to settings…</p>
<?php endif; ?>
<p style="font-size:12px;color:#9ca3af">If this page doesn't redirect, <a href="<?= htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8') ?>">click here</a>.</p>
</body></html>
