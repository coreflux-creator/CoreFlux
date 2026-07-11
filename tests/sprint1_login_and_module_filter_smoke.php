<?php
/**
 * Sprint 1 - Login UX + tenant module filter smoke.
 *
 * Static-source assertions only (no DB required).
 */
declare(strict_types=1);

$pass = 0;
$fail = 0;
function _a(string $label, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok  {$label}\n"; }
    else       { $fail++; echo "  FAIL  {$label}\n"; }
}

echo "Sprint 1 - Login + tenant module filter\n";

$app = (string) file_get_contents(__DIR__ . '/../dashboard/src/App.jsx');
$ses = (string) file_get_contents(__DIR__ . '/../session.php');
$lh  = (string) file_get_contents(__DIR__ . '/../login.html');
$lp  = (string) file_get_contents(__DIR__ . '/../login.php');
$legacyLogin = (string) file_get_contents(__DIR__ . '/../auth/login.php');
$mobileLogin = (string) file_get_contents(__DIR__ . '/../api/auth/mobile_login.php');

echo "\nApp.jsx - no silent demo fallback\n";
_a('401 from session.php redirects to /login (SPA route)', str_contains($app, "res.status === 401") && str_contains($app, "/login?next="));
_a('demo mode gated by window.__CF_FORCE_DEMO__',          str_contains($app, '__CF_FORCE_DEMO__'));
_a('hard-failure shows session-error screen',              str_contains($app, 'data-testid="session-error-screen"'));
_a('error screen offers "Sign in again" link',             str_contains($app, 'data-testid="session-error-login-link"'));

echo "\nsession.php - tenant_modules filter\n";
_a('session.php pulls global_role',                str_contains($ses, "user['global_role']"));
_a('master_admin bypass intact',                   str_contains($ses, "globalRole !== 'master_admin'"));
_a('queries tenant_modules.is_enabled',            str_contains($ses, "FROM tenant_modules"));
_a('greenfield tenants default to all-on',         str_contains($ses, '!array_key_exists($key, $sub)'));
_a('safe-fail (logs, never 500s)',                 str_contains($ses, 'session.php tenant_modules filter failed'));

echo "\nlogin.html - error surface + ?next=\n";
_a('renders backend error codes',                   str_contains($lh, 'ERROR_MESSAGES') && str_contains($lh, 'invalid:'));
_a('reads ?next= for SPA deep-link return',         str_contains($lh, "urlParams.get('next')") && str_contains($lh, 'nextField'));
_a('default redirect is the SPA (not legacy dash)', str_contains($lh, 'value="spa"'));
_a('username/password inputs have testids',         str_contains($lh, 'data-testid="login-username"') && str_contains($lh, 'data-testid="login-password"'));
_a('submit button has testid',                      str_contains($lh, 'data-testid="login-submit"'));

echo "\nlogin.php - password + redirect handling\n";
_a('uses shared password verifier',             str_contains($lp, 'authVerifyPassword($dbUser'));
_a('case-insensitive email lookup',             str_contains($lp, 'LOWER(email) = LOWER(?)'));
_a('accepts username or email field',           str_contains($lp, "\$_POST['username']") && str_contains($lp, "\$_POST['email']"));
_a('normalizes display name via auth helper',   str_contains($lp, 'authUserNameParts($dbUser'));
_a('reads next from POST or GET',               str_contains($lp, "\$_POST['next']") && str_contains($lp, "\$_GET['next']"));
_a('rejects scheme/host (open-redirect guard)', str_contains($lp, "strncmp(\$next, '/', 1) === 0") && str_contains($lp, "strncmp(\$next, '//', 2) !== 0"));
_a('preserves SPA hash when bouncing back',     str_contains($lp, "str_contains(\$next, '#')"));
_a('legacy ?redirect=dashboard path still works', str_contains($lp, "\$redirect === 'dashboard'"));

echo "\nauth/login.php - legacy wrapper\n";
_a('does not require removed legacy auth includes',
    !str_contains($legacyLogin, 'db_connection.php') && !str_contains($legacyLogin, 'functions_auth.php'));
_a('forwards POSTs to root login handler',
    str_contains($legacyLogin, "\$_POST['username']") && str_contains($legacyLogin, "require __DIR__ . '/../login.php'"));
_a('GET redirects to maintained login screen',
    str_contains($legacyLogin, "header('Location: /login.html')"));

echo "\napi/auth/mobile_login.php - auth parity\n";
_a('mobile login uses shared password verifier', str_contains($mobileLogin, 'authVerifyPassword($user'));
_a('mobile login heals legacy memberships',      str_contains($mobileLogin, 'healMembershipsForUser('));
_a('mobile tenant lookup uses membership union', str_contains($mobileLogin, 'membershipReadSourceSql()'));
_a('mobile display name uses auth helper',       str_contains($mobileLogin, 'authUserDisplayName($user'));

echo "\n--- {$pass} assertions, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
