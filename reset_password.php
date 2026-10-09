<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Referrer-Policy: no-referrer');
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/mailer.php';
require_once __DIR__ . '/core/memberships.php';
$pdo = getDB();
if (!$pdo) {
    http_response_code(503);
    exit('Password reset is temporarily unavailable.');
}

// Accept both styles (?token=&email=) and legacy (?t=&e=)
$emailValue = $_GET['email'] ?? $_GET['e'] ?? $_POST['email'] ?? '';
$tokenValue = $_GET['token'] ?? $_GET['t'] ?? $_POST['token'] ?? '';
$email = is_string($emailValue) ? trim($emailValue) : '';
$token = is_string($tokenValue) ? trim($tokenValue) : '';

$can_show_form = false;
$error   = '';
$success = '';

/**
 * Fetch and validate the latest, unused, unexpired reset token for the email.
 * Returns [row|null, error|null].
 */
function get_valid_reset(PDO $pdo, string $email, string $token, bool $lock = false) {
    $stmt = $pdo->prepare("
        SELECT id, user_id, email, token_hash, expires_at, used_at
        FROM password_resets
        WHERE email = :email
          AND used_at IS NULL
          AND user_id IS NOT NULL AND token_hash IS NOT NULL AND expires_at IS NOT NULL
        ORDER BY created_at DESC, id DESC
        LIMIT 1
    " . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([':email' => $email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return [null, 'Invalid or expired reset link.'];
    }
    if (strtotime($row['expires_at']) <= time()) {
        return [null, 'This reset link has expired. Please request a new one.'];
    }
    // Verify SHA-256 token using constant-time comparison
    if (!hash_equals((string) $row['token_hash'], hash('sha256', $token))) {
        return [null, 'Invalid reset token.'];
    }
    return [$row, null];
}

function reset_password_tenant_id(PDO $pdo, int $userId): int {
    $tenantId = 0;
    try {
        $cols = authTableColumns($pdo, 'users');
        if (in_array('tenant_id', $cols, true)) {
            $tStmt = $pdo->prepare('SELECT tenant_id FROM users WHERE id = :id');
            $tStmt->execute([':id' => $userId]);
            $tenantId = (int) ($tStmt->fetchColumn() ?: 0);
        }
    } catch (Throwable $_) { /* fall through */ }
    if ($tenantId <= 0) {
        try {
            $tmStmt = $pdo->prepare(
                "SELECT tenant_id
                   FROM " . membershipReadSourceSql() . " src
                  WHERE src.user_id = :u
                  ORDER BY src.is_primary DESC, src.tenant_id ASC
                  LIMIT 1"
            );
            $tmStmt->execute([':u' => $userId]);
            $tenantId = (int) ($tmStmt->fetchColumn() ?: 0);
        } catch (Throwable $_) { /* fall through */ }
    }
    if ($tenantId <= 0) {
        try {
            $tenantId = (int) ($pdo->query(
                'SELECT id FROM tenants WHERE COALESCE(is_active,1) = 1 ORDER BY id ASC LIMIT 1'
            )->fetchColumn() ?: 1);
        } catch (Throwable $_) { $tenantId = 1; }
    }
    return $tenantId;
}

// GET: decide whether to show the form
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($email !== '' && $token !== '') {
        [, $err] = get_valid_reset($pdo, $email, $token);
        if ($err) {
            $error = $err;
        } else {
            $can_show_form = true;
        }
    } else {
        $error = 'Missing reset link parameters.';
    }
}

// POST: attempt password change
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirm     = is_string($_POST['confirm'] ?? null) ? $_POST['confirm'] : '';

    if ($email === '' || $token === '') {
        $error = 'Missing reset link parameters.';
    } elseif ($newPassword === '' || $confirm === '') {
        $error = 'Please enter and confirm your new password.';
    } elseif ($newPassword !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (strlen($newPassword) < 8) {
        $error = 'Password must be at least 8 characters.';
    }

    if ($error === '') {
        try {
            $pdo->beginTransaction();
            [$row, $err] = get_valid_reset($pdo, $email, $token, true);
            if ($err) {
                $pdo->rollBack();
                $error = $err;
            } else {
                // Update user password across canonical and legacy columns.
                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $userCols = authTableColumns($pdo, 'users');
                $sets = [];
                $bind = [':e' => $email, ':uid' => (int) $row['user_id']];
                if (in_array('password', $userCols, true)) {
                    $sets[] = 'password = :password';
                    $bind[':password'] = $passwordHash;
                }
                if (in_array('password_hash', $userCols, true)) {
                    $sets[] = 'password_hash = :password_hash';
                    $bind[':password_hash'] = $passwordHash;
                }
                if (in_array('updated_at', $userCols, true)) {
                    $sets[] = 'updated_at = NOW()';
                }
                if (!$sets) {
                    throw new RuntimeException('No password column exists on users table.');
                }
                $userUpdate = $pdo->prepare('UPDATE users SET ' . implode(', ', $sets)
                    . ' WHERE id = :uid AND LOWER(email) = LOWER(:e)');
                $userUpdate->execute($bind);
                if ($userUpdate->rowCount() !== 1) {
                    throw new RuntimeException('Reset user is no longer available.');
                }

                $tokenUpdate = $pdo->prepare(
                    'UPDATE password_resets SET used_at = NOW() WHERE id = :id AND used_at IS NULL'
                );
                $tokenUpdate->execute([':id' => $row['id']]);
                if ($tokenUpdate->rowCount() !== 1) {
                    throw new RuntimeException('Reset token was already used.');
                }
                $pdo->prepare("DELETE FROM password_resets WHERE email = :e AND used_at IS NOT NULL")
                    ->execute([':e' => $email]);
                $pdo->commit();

                // Optional confirmation email through the central mailer.
                try {
                    $tenantId = reset_password_tenant_id($pdo, (int) $row['user_id']);
                    $notice = mailerSend([
                        'tenant_id' => $tenantId,
                        'module'    => 'auth',
                        'purpose'   => 'password_reset',
                        'to'        => (string) ($row['email'] ?? $email),
                        'subject'   => 'Your CoreFlux password was changed',
                        'body_text' => "Your CoreFlux password was changed. If you did not make this change, contact your administrator immediately.\n",
                    ]);
                    if (empty($notice['ok'])) {
                        error_log('[reset_password] password-changed notice failed for ' . $email
                            . ' tenant=' . $tenantId
                            . ' driver=' . ($notice['driver'] ?? '?')
                            . ' err=' . ($notice['error'] ?? '?'));
                    }
                } catch (Throwable $noticeErr) {
                    error_log('[reset_password] password-changed notice error: ' . $noticeErr->getMessage());
                }

                $success = 'Your password has been reset successfully. You can now <a href="/login.php">log in</a>.';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Reset password error: ' . $e->getMessage());
            $error = 'Something went wrong. Please try again.';
        }
    }

    // If there was an error, re-show the form with the hidden fields intact
    if ($error !== '') $can_show_form = true;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Reset Password | CoreFlux</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    :root { --primary:#0057ff; --ok:#226a2b; --okbg:#e8f5e9; --err:#b3261e; --errbg:#fdecea; --border:#d8dbe2; }
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin:0; background:#f6f7fb; }
    .wrap { max-width: 420px; margin: 10vh auto; background:#fff; padding:32px; border-radius:12px; box-shadow: 0 10px 30px rgba(0,0,0,.06); }
    h1 { margin:0 0 12px; font-size: 22px; }
    label { display:block; font-weight:600; margin:10px 0 6px; }
    input[type="password"] { width:100%; padding:12px; border:1px solid var(--border); border-radius:8px; font-size:16px; }
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
  <h1>Choose a New Password</h1>

  <?php if ($error): ?>
    <div class="msg err"><?= $error ?></div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div class="msg ok"><?= $success ?></div>
  <?php endif; ?>

  <?php if ($can_show_form && !$success): ?>
  <form method="post" action="/reset_password.php">
    <input type="hidden" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">

    <label for="password">New Password</label>
    <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password">

    <label for="confirm">Confirm New Password</label>
    <input type="password" id="confirm" name="confirm" required minlength="8" autocomplete="new-password">

    <button type="submit">Reset Password</button>
    <p style="margin-top:10px;"><a href="/login.php">Back to Login</a></p>
  </form>
  <?php endif; ?>
</div>
</body>
</html>
