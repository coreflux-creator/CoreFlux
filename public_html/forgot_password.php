<?php
// Enable while finishing setup; remove later.
error_reporting(E_ALL);
ini_set('display_errors', 1);
if (function_exists('opcache_reset')) { @opcache_reset(); }
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// Correct includes (note the slash after __DIR__)
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/smtp_yahoo.php';

$APP_NAME      = 'CoreFlux';
$TOKEN_TTL_MIN = 60;                 // token valid for 60 minutes
$RESET_PATH    = '/reset_password.php';

// Ensure table exists (safe to run repeatedly)
$pdo->exec("
    CREATE TABLE IF NOT EXISTS password_resets (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        email VARCHAR(255) NOT NULL,
        token_hash CHAR(64) NOT NULL,  -- sha256 hex
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
            // Adjust column names if your schema differs
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :e LIMIT 1");
            $stmt->execute([':e' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $userId = (int)$user['id'];

                // Invalidate any existing active tokens (optional hardening)
                $pdo->prepare("
                    UPDATE password_resets
                    SET used_at = NOW()
                    WHERE user_id = :uid AND used_at IS NULL AND expires_at > NOW()
                ")->execute([':uid' => $userId]);

                // Create token
                $rawToken  = bin2hex(random_bytes(32)); // 64 hex chars
                $tokenHash = hash('sha256', $rawToken);
                $expiresAt = (new DateTime("+{$TOKEN_TTL_MIN} minutes"))->format('Y-m-d H:i:s');

                // Store token
                $pdo->prepare("
                    INSERT INTO password_resets (user_id, email, token_hash, expires_at)
                    VALUES (:uid, :email, :hash, :exp)
                ")->execute([
                    ':uid'   => $userId,
                    ':email' => $email,
                    ':hash'  => $tokenHash,
                    ':exp'   => $expiresAt,
                ]);

                // Build reset URL (?token=&email=)
                $host     = $_SERVER['HTTP_HOST'] ?? 'www.corefluxapp.com';
                $resetUrl = 'https://' . $host . $RESET_PATH . '?' . http_build_query([
                    'token' => $rawToken,
                    'email' => $email,
                ]);

                // Send email using your Yahoo SMTP helper
                if (function_exists('sendPasswordResetEmail')) {
                    $sent = sendPasswordResetEmail($email, $resetUrl);
                    if (!$sent) error_log("Password reset email failed for: $email");
                } else {
                    error_log('sendPasswordResetEmail() not found in config/smtp_yahoo.php');
                }
            }

            // Non-enumerating response
            $successMsg = 'If that email is registered, we’ve sent a reset link. Please check your inbox.';
        } catch (Throwable $e) {
            error_log('Forgot password error: ' . $e->getMessage());
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
    <div class="msg ok"><?= htmlspecialchars($successMsg) ?></div>
  <?php endif; ?>

  <?php if ($errorMsg): ?>
    <div class="msg err"><?= htmlspecialchars($errorMsg) ?></div>
  <?php endif; ?>

  <form method="post" novalidate>
    <label for="email">Email address</label>
    <input id="email" name="email" type="email" required placeholder="you@example.com" autocomplete="email">
    <button type="submit">Send reset link</button>
  </form>

  <p style="margin-top:10px;"><a href="/login.php">Back to sign in</a></p>
</div>
</body>
</html>
