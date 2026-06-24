<?php
/**
 * forgot_password.php — public-facing password reset request flow.
 *
 * 2026-02 — Rewired to deliver via Resend through the central
 * mailerSend() pipeline (was using the legacy Yahoo SMTP helper that
 * silently dropped every reset email).
 *
 * Flow:
 *   1. User submits email on /forgot_password.php
 *   2. Look up user (existence not echoed back — non-enumerating UX)
 *   3. Generate a one-time token (sha256-hashed at rest)
 *   4. Resolve the user's home tenant (from users.tenant_id when present
 *      or oldest active tenant_memberships row) — needed for Resend
 *      routing + suppression scoping
 *   5. mailerSend() the reset link with purpose='password_reset'
 *   6. Always render a generic success message (no enumeration)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
if (function_exists('opcache_reset')) { @opcache_reset(); }
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/core/mailer.php';

$APP_NAME      = 'CoreFlux';
$TOKEN_TTL_MIN = 60;
$RESET_PATH    = '/reset_password.php';

// Idempotent — keep the table on-disk so callers don't need a migration.
$pdo->exec("
    CREATE TABLE IF NOT EXISTS password_resets (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id     INT UNSIGNED NOT NULL,
        email       VARCHAR(255) NOT NULL,
        token_hash  CHAR(64)     NOT NULL,
        expires_at  DATETIME     NOT NULL,
        used_at     DATETIME     NULL,
        created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (email), INDEX (user_id), INDEX (token_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$successMsg = '';
$errorMsg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMsg = 'Please enter a valid email address.';
    } else {
        try {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :e LIMIT 1');
            $stmt->execute([':e' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $userId = (int) $user['id'];

                // Resolve the user's home tenant — required for Resend
                // routing through mailerSend(). Order of preference:
                //   1. users.tenant_id (legacy NOT-NULL column on most prod envs)
                //   2. oldest active tenant_memberships row
                //   3. tenant id 1 (system fallback) — keeps Resend hot
                //      even if neither of the above resolves
                $tenantId = 0;
                try {
                    $tCol = $pdo->query("SHOW COLUMNS FROM users LIKE 'tenant_id'")->fetchColumn();
                    if ($tCol) {
                        $tStmt = $pdo->prepare('SELECT tenant_id FROM users WHERE id = :id');
                        $tStmt->execute([':id' => $userId]);
                        $tenantId = (int) ($tStmt->fetchColumn() ?: 0);
                    }
                } catch (\Throwable $_) { /* fall through */ }
                if ($tenantId <= 0) {
                    try {
                        $tmStmt = $pdo->prepare(
                            "SELECT tenant_id FROM tenant_memberships
                              WHERE user_id = :u AND status = 'active'
                              ORDER BY created_at ASC LIMIT 1"
                        );
                        $tmStmt->execute([':u' => $userId]);
                        $tenantId = (int) ($tmStmt->fetchColumn() ?: 0);
                    } catch (\Throwable $_) { /* fall through */ }
                }
                if ($tenantId <= 0) {
                    try {
                        $tenantId = (int) ($pdo->query(
                            'SELECT id FROM tenants WHERE COALESCE(is_active,1) = 1 ORDER BY id ASC LIMIT 1'
                        )->fetchColumn() ?: 1);
                    } catch (\Throwable $_) { $tenantId = 1; }
                }

                // Invalidate any active tokens, then issue a fresh one.
                $pdo->prepare('
                    UPDATE password_resets
                       SET used_at = NOW()
                     WHERE user_id = :uid AND used_at IS NULL AND expires_at > NOW()
                ')->execute([':uid' => $userId]);

                $rawToken  = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $rawToken);
                $expiresAt = (new DateTime("+{$TOKEN_TTL_MIN} minutes"))->format('Y-m-d H:i:s');

                $pdo->prepare('
                    INSERT INTO password_resets (user_id, email, token_hash, expires_at)
                    VALUES (:uid, :email, :hash, :exp)
                ')->execute([
                    ':uid'   => $userId,
                    ':email' => $email,
                    ':hash'  => $tokenHash,
                    ':exp'   => $expiresAt,
                ]);

                $host     = $_SERVER['HTTP_HOST'] ?? 'www.corefluxapp.com';
                $resetUrl = 'https://' . $host . $RESET_PATH . '?' . http_build_query([
                    'token' => $rawToken,
                    'email' => $email,
                ]);

                $bodyHtml = '<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;max-width:560px;margin:auto;padding:24px;color:#1f2937">'
                    . '<h2 style="margin:0 0 12px;color:#111827">Reset your ' . htmlspecialchars($APP_NAME) . ' password</h2>'
                    . '<p>We received a request to reset the password for <strong>' . htmlspecialchars($email) . '</strong>.</p>'
                    . '<p style="margin:24px 0">'
                    . '  <a href="' . htmlspecialchars($resetUrl) . '" style="display:inline-block;background:#0057ff;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none;font-weight:600">Choose a new password</a>'
                    . '</p>'
                    . '<p style="font-size:13px;color:#6b7280">Or paste this link into your browser:<br><span style="word-break:break-all">' . htmlspecialchars($resetUrl) . '</span></p>'
                    . '<p style="font-size:13px;color:#6b7280">This link expires in ' . $TOKEN_TTL_MIN . ' minutes. If you didn\'t request this, you can safely ignore this email.</p>'
                    . '</div>';
                $bodyText = "Reset your {$APP_NAME} password\n\n"
                    . "Paste this link into your browser:\n{$resetUrl}\n\n"
                    . "The link expires in {$TOKEN_TTL_MIN} minutes. If you didn't request this, ignore this email.";

                $res = mailerSend([
                    'tenant_id' => $tenantId,
                    'module'    => 'auth',
                    'purpose'   => 'password_reset',
                    'to'        => $email,
                    'subject'   => "Reset your {$APP_NAME} password",
                    'body_html' => $bodyHtml,
                    'body_text' => $bodyText,
                ]);
                if (empty($res['ok'])) {
                    error_log('[forgot_password] mailerSend failed for ' . $email
                        . ' tenant=' . $tenantId
                        . ' driver=' . ($res['driver'] ?? '?')
                        . ' err=' . ($res['error'] ?? '?'));
                }
            }

            // Non-enumerating response — render the same message whether
            // the email matched a user or not.
            $successMsg = 'If that email is registered, we’ve sent a reset link. Please check your inbox.';
        } catch (Throwable $e) {
            error_log('[forgot_password] error: ' . $e->getMessage());
            $errorMsg = 'Something went wrong. Please try again.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Forgot Password – <?= htmlspecialchars($APP_NAME) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    :root { --primary:#0057ff; --ok:#226a2b; --okbg:#e8f5e9; --err:#b3261e; --errbg:#fdecea; --border:#d8dbe2; }
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin:0; background:#f6f7fb; }
    .wrap { max-width: 420px; margin: 10vh auto; background:#fff; padding:32px; border-radius:12px; box-shadow: 0 10px 30px rgba(0,0,0,.06); }
    h1 { margin:0 0 12px; font-size: 22px; }
    p { color:#444; }
    form { margin-top: 16px; }
    label { display:block; font-weight:600; margin-bottom:8px; }
    input[type="email"] { width:100%; padding:12px; border:1px solid var(--border); border-radius:8px; font-size:16px; }
    button { margin-top:14px; width:100%; padding:12px; border:0; border-radius:8px; background:var(--primary); color:#fff; font-weight:700; cursor:pointer; }
    .msg { margin-top:12px; padding:12px; border-radius:8px; border:1px solid transparent; }
    .ok  { background:var(--okbg); color:var(--ok); border-color:#c6e6c9; }
    .err { background:var(--errbg); color:var(--err); border-color:#f5c6c3; }
    a { color:var(--primary); text-decoration:none; }
    a:hover { text-decoration:underline; }
  </style>
</head>
<body>
<div class="wrap">
  <h1>Forgot your password?</h1>
  <p>Enter the email you used for your account and we’ll send you a reset link.</p>

  <?php if ($successMsg): ?>
    <div class="msg ok" data-testid="forgot-password-success"><?= htmlspecialchars($successMsg) ?></div>
  <?php endif; ?>

  <?php if ($errorMsg): ?>
    <div class="msg err" data-testid="forgot-password-error"><?= htmlspecialchars($errorMsg) ?></div>
  <?php endif; ?>

  <form method="post" novalidate>
    <label for="email">Email address</label>
    <input id="email" name="email" type="email" required placeholder="you@example.com" autocomplete="email" data-testid="forgot-password-email">
    <button type="submit" data-testid="forgot-password-submit">Send reset link</button>
  </form>

  <p style="margin-top:10px;"><a href="/login.php">Back to sign in</a></p>
</div>
</body>
</html>
