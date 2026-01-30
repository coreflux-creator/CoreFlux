<?php
// Enable while setting up; disable in production.
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/smtp_yahoo.php';

// Accept both styles (?token=&email=) and legacy (?t=&e=)
$email = trim($_GET['email'] ?? $_GET['e'] ?? $_POST['email'] ?? '');
$token = trim($_GET['token'] ?? $_GET['t'] ?? $_POST['token'] ?? '');

$can_show_form = false;
$error   = '';
$success = '';

/**
 * Fetch and validate the latest, unused, unexpired reset token for the email.
 * Returns [row|null, error|null].
 */
function get_valid_reset(PDO $pdo, string $email, string $token) {
    $stmt = $pdo->prepare("
        SELECT id, token_hash, expires_at, used_at
        FROM password_resets
        WHERE email = :email
          AND used_at IS NULL
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([':email' => $email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return [null, 'Invalid or expired reset link.'];
    }
    if (strtotime($row['expires_at']) <= time()) {
        return [null, 'This reset link has expired. Please request a new one.'];
    }
    // Verify SHA-256 token using constant-time comparison
    if (!hash_equals($row['token_hash'], hash('sha256', $token))) {
        return [null, 'Invalid reset token.'];
    }
    return [$row, null];
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
    $newPassword = trim($_POST['password'] ?? '');
    $confirm     = trim($_POST['confirm'] ?? '');

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
            [$row, $err] = get_valid_reset($pdo, $email, $token);
            if ($err) {
                $error = $err;
            } else {
                // Update user password
                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET password = :p WHERE email = :e")
                    ->execute([':p' => $passwordHash, ':e' => $email]);

                // Mark token as used and optionally clean up old used tokens
                $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = :id")
                    ->execute([':id' => $row['id']]);
                $pdo->prepare("DELETE FROM password_resets WHERE email = :e AND used_at IS NOT NULL")
                    ->execute([':e' => $email]);

                // Optional confirmation email
                if (function_exists('sendPasswordChangedNotice')) {
                    @sendPasswordChangedNotice($email);
                }

                $success = 'Your password has been reset successfully. You can now <a href="/login.php">log in</a>.';
            }
        } catch (Throwable $e) {
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
