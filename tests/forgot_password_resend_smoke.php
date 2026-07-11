<?php
/**
 * Smoke - Forgot/reset password + tenantless mailerSend regression.
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0;
$fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ok  {$msg}\n"; $pass++; }
    else     { echo "  FAIL  {$msg}" . ($detail !== '' ? " - {$detail}" : '') . "\n"; $fail++; }
};

echo "\n1. forgot_password.php routes via mailerSend\n";
$fp = (string) file_get_contents($ROOT . '/forgot_password.php');
$a('requires core/mailer.php',                   str_contains($fp, "require_once __DIR__ . '/core/mailer.php';"));
$a('requires memberships for unified tenant lookup', str_contains($fp, "require_once __DIR__ . '/core/memberships.php';"));
$a('does NOT use sendPasswordResetEmail',        !str_contains($fp, 'sendPasswordResetEmail('));
$a('does NOT require smtp_yahoo.php',            !str_contains($fp, "smtp_yahoo.php"));
$a('calls mailerSend with module=auth purpose=password_reset',
    str_contains($fp, "'module'    => 'auth'")
    && str_contains($fp, "'purpose'   => 'password_reset'"));
$a('resolves tenant_id from users.tenant_id when present',
    str_contains($fp, "SHOW COLUMNS FROM users LIKE 'tenant_id'")
    && str_contains($fp, "SELECT tenant_id FROM users WHERE id = :id"));
$a('falls back to unified membership source when users.tenant_id is missing',
    str_contains($fp, 'membershipReadSourceSql()')
    && str_contains($fp, 'ORDER BY src.is_primary DESC'));
$a('final fallback to first active tenant',
    str_contains($fp, 'SELECT id FROM tenants WHERE COALESCE(is_active,1) = 1 ORDER BY id ASC LIMIT 1'));
$a('logs failures with tenant + driver + err',
    str_contains($fp, '[forgot_password] mailerSend failed'));
$a('renders non-enumerating success message',
    str_contains($fp, "If that email is registered"));
$a('exposes data-testids for the form',
    str_contains($fp, 'data-testid="forgot-password-email"')
    && str_contains($fp, 'data-testid="forgot-password-submit"')
    && str_contains($fp, 'data-testid="forgot-password-success"'));

echo "\n2. reset_password.php updates usable login credentials\n";
$rp = (string) file_get_contents($ROOT . '/reset_password.php');
$a('does NOT require legacy smtp_yahoo.php',      !str_contains($rp, "smtp_yahoo.php"));
$a('uses auth schema helpers',                    str_contains($rp, "require_once __DIR__ . '/core/auth.php';"));
$a('updates password when column exists',         str_contains($rp, "in_array('password', \$userCols, true)") && str_contains($rp, 'password = :password'));
$a('updates password_hash when column exists',    str_contains($rp, "in_array('password_hash', \$userCols, true)") && str_contains($rp, 'password_hash = :password_hash'));
$a('password update is case-insensitive by email', str_contains($rp, 'WHERE LOWER(email) = LOWER(:e)'));
$a('confirmation uses central mailer',            str_contains($rp, 'mailerSend([') && str_contains($rp, "'purpose'   => 'password_reset'"));
$a('confirmation tenant lookup uses membership union', str_contains($rp, 'membershipReadSourceSql()'));

echo "\n3. mailerSend tenantless path uses system tenant resolver\n";
$ms = (string) file_get_contents($ROOT . '/core/mailer.php');
$a('checks first active tenant when no tenant context',
    str_contains($ms, "'SELECT id FROM tenants WHERE COALESCE(is_active,1) = 1 ORDER BY id ASC LIMIT 1'"));
$a('only falls back to SMTP if no system tenant exists',
    str_contains($ms, "'no_tenant_context_no_system_tenant'"));
$a('keeps the original no_tenant_context fallback signature removed',
    !preg_match("/'no_tenant_context'\\)/", $ms));
$a('preserves the SMTP fallback for absolute last resort',
    str_contains($ms, '_mailer_fallback_smtp'));

echo "\n4. login.html still links to forgot_password.php\n";
$loginHtml = (string) file_get_contents($ROOT . '/login.html');
$a('login.html -> forgot_password.php link present',
    str_contains($loginHtml, 'href="forgot_password.php"'));

echo "\n5. PHP syntax\n";
foreach (['forgot_password.php', 'reset_password.php', 'core/mailer.php'] as $rel) {
    $rc = 0;
    $out = [];
    exec('php -l ' . escapeshellarg($ROOT . '/' . $rel) . ' 2>&1', $out, $rc);
    $a("php -l {$rel}", $rc === 0, implode("\n", $out));
}

echo "\n6. Live PDO exercise - system tenant resolution\n";
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "  skip  sqlite PDO driver unavailable in this PHP build\n";
    echo "\npass={$pass} fail={$fail}\n";
    exit($fail === 0 ? 0 : 1);
}
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE tenants (id INTEGER PRIMARY KEY, is_active INTEGER NOT NULL DEFAULT 1)");
$pdo->exec("INSERT INTO tenants(id, is_active) VALUES (3, 1), (1, 0), (2, 1)");
$sysTid = (int) $pdo->query('SELECT id FROM tenants WHERE COALESCE(is_active,1) = 1 ORDER BY id ASC LIMIT 1')->fetchColumn();
$a('resolver picks the lowest-id ACTIVE tenant (2, not 1, not 3)', $sysTid === 2, "got {$sysTid}");

$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, tenant_id INTEGER)");
$pdo->exec("INSERT INTO users(id, email, tenant_id) VALUES (10, 'kunal@coreflux.app', 7)");
$st = $pdo->prepare('SELECT tenant_id FROM users WHERE id = :id');
$st->execute([':id' => 10]);
$utid = (int) ($st->fetchColumn() ?: 0);
$a('users.tenant_id lookup returns the correct tenant', $utid === 7, "got {$utid}");

echo "\npass={$pass} fail={$fail}\n";
exit($fail === 0 ? 0 : 1);
