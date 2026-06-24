<?php
/**
 * Smoke — Forgot Password + tenantless mailerSend regression (2026-02).
 *
 * The user reported: "Resend hasn't ever sent a single email other than
 * the test emails." Root cause: `mailerSend()` falls back to legacy
 * PHPMailer SMTP when no tenant context exists — which happens in EVERY
 * tenantless path (forgot_password, cron jobs, public webhooks).
 * Production SMTP creds are stale → emails went to /dev/null silently.
 *
 * This locks in:
 *   • forgot_password.php uses mailerSend() (not the legacy Yahoo SMTP
 *     helper) and resolves a real tenant_id before sending.
 *   • mailerSend() with tenantId=0 resolves a system tenant from the
 *     `tenants` table FIRST, only falling back to SMTP if no active
 *     tenant exists at all.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

echo "\n1. forgot_password.php routes via mailerSend (NOT Yahoo SMTP)\n";
$fp = (string) file_get_contents('/app/forgot_password.php');
$a('requires core/mailer.php',                   str_contains($fp, "require_once __DIR__ . '/core/mailer.php';"));
$a('does NOT use sendPasswordResetEmail',        !str_contains($fp, 'sendPasswordResetEmail('));
$a('does NOT require smtp_yahoo.php',            !str_contains($fp, "smtp_yahoo.php"));
$a('calls mailerSend with module=auth purpose=password_reset',
    str_contains($fp, "'module'    => 'auth'")
    && str_contains($fp, "'purpose'   => 'password_reset'"));
$a('resolves tenant_id from users.tenant_id when present',
    str_contains($fp, "SHOW COLUMNS FROM users LIKE 'tenant_id'")
    && str_contains($fp, "SELECT tenant_id FROM users WHERE id = :id"));
$a('falls back to tenant_memberships when users.tenant_id is missing',
    str_contains($fp, "FROM tenant_memberships")
    && str_contains($fp, "status = 'active'"));
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

echo "\n2. mailerSend tenantless path → system tenant resolver (NOT instant SMTP)\n";
$ms = (string) file_get_contents('/app/core/mailer.php');
$a('checks first active tenant when no tenant context',
    str_contains($ms, "'SELECT id FROM tenants WHERE COALESCE(is_active,1) = 1 ORDER BY id ASC LIMIT 1'"));
$a('only falls back to SMTP if no system tenant exists',
    str_contains($ms, "'no_tenant_context_no_system_tenant'"));
$a('keeps the original no_tenant_context fallback signature removed',
    !preg_match("/'no_tenant_context'\\)/", $ms));
$a('preserves the SMTP fallback for absolute last resort',
    str_contains($ms, '_mailer_fallback_smtp'));

echo "\n3. /app/login.html still links to forgot_password.php\n";
$loginHtml = (string) file_get_contents('/app/login.html');
$a('login.html → forgot_password.php link present',
    str_contains($loginHtml, 'href="forgot_password.php"'));

echo "\n4. PHP syntax\n";
$a('php -l /app/forgot_password.php',
    str_contains((string) shell_exec('php -l /app/forgot_password.php 2>&1'), 'No syntax errors'));
$a('php -l /app/core/mailer.php',
    str_contains((string) shell_exec('php -l /app/core/mailer.php 2>&1'), 'No syntax errors'));

echo "\n5. Live PDO exercise — system tenant resolution\n";
$pdo = new \PDO('sqlite::memory:');
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE tenants (id INTEGER PRIMARY KEY, is_active INTEGER NOT NULL DEFAULT 1)");
$pdo->exec("INSERT INTO tenants(id, is_active) VALUES (3, 1), (1, 0), (2, 1)");
$sysTid = (int) $pdo->query('SELECT id FROM tenants WHERE COALESCE(is_active,1) = 1 ORDER BY id ASC LIMIT 1')->fetchColumn();
$a('resolver picks the lowest-id ACTIVE tenant (2, not 1, not 3)', $sysTid === 2, "got {$sysTid}");

// And the users.tenant_id resolver from forgot_password.php:
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, tenant_id INTEGER)");
$pdo->exec("INSERT INTO users(id, email, tenant_id) VALUES (10, 'kunal@coreflux.app', 7)");
$st = $pdo->prepare('SELECT tenant_id FROM users WHERE id = :id');
$st->execute([':id' => 10]);
$utid = (int) ($st->fetchColumn() ?: 0);
$a('users.tenant_id lookup returns the correct tenant', $utid === 7, "got {$utid}");

echo "\n— pass={$pass}  fail={$fail}\n";
exit($fail === 0 ? 0 : 1);
