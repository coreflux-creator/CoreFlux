<?php
/**
 * Sprint 1 — Login UX + tenant module filter smoke
 *
 * Static-source assertions only (no DB required) — verifies:
 *   1. App.jsx no longer silently falls back to demo mode (now 401 → /login.html).
 *   2. session.php filters modules by tenant_modules.is_enabled for non-master_admin.
 *   3. login.html surfaces backend error codes + supports ?next= deep-link return.
 *   4. login.php whitelists only local paths for the post-login SPA bounce.
 */

declare(strict_types=1);

$pass = 0; $fail = 0;
function _a(string $label, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok  $label\n"; }
    else       { $fail++; echo "  FAIL  $label\n"; }
}

echo "Sprint 1 — Login + tenant module filter\n";

$app = (string) file_get_contents(__DIR__ . '/../dashboard/src/App.jsx');
$ses = (string) file_get_contents(__DIR__ . '/../session.php');
$lh  = (string) file_get_contents(__DIR__ . '/../login.html');
$lp  = (string) file_get_contents(__DIR__ . '/../login.php');

echo "\nApp.jsx — no silent demo fallback\n";
_a('401 from session.php redirects to /login.html', str_contains($app, "res.status === 401") && str_contains($app, "/login.html?next="));
_a('demo mode gated by window.__CF_FORCE_DEMO__',  str_contains($app, '__CF_FORCE_DEMO__'));
_a('hard-failure shows session-error screen',      str_contains($app, 'data-testid="session-error-screen"'));
_a('error screen offers "Sign in again" link',     str_contains($app, 'data-testid="session-error-login-link"'));

echo "\nsession.php — tenant_modules filter\n";
_a('session.php pulls global_role',                str_contains($ses, "user['global_role']"));
_a('master_admin bypass intact',                   str_contains($ses, "globalRole !== 'master_admin'"));
_a('queries tenant_modules.is_enabled',            str_contains($ses, "FROM tenant_modules"));
_a('greenfield tenants default to all-on',         str_contains($ses, '!array_key_exists($key, $sub)'));
_a('safe-fail (logs, never 500s)',                 str_contains($ses, 'session.php tenant_modules filter failed'));

echo "\nlogin.html — error surface + ?next=\n";
_a('renders backend error codes',                  str_contains($lh, 'ERROR_MESSAGES') && str_contains($lh, 'invalid:'));
_a('reads ?next= for SPA deep-link return',        str_contains($lh, "urlParams.get('next')") && str_contains($lh, 'nextField'));
_a('default redirect is the SPA (not legacy dash)',str_contains($lh, 'value="spa"'));
_a('username/password inputs have testids',        str_contains($lh, 'data-testid="login-username"') && str_contains($lh, 'data-testid="login-password"'));
_a('submit button has testid',                     str_contains($lh, 'data-testid="login-submit"'));

echo "\nlogin.php — open-redirect guard\n";
_a('reads next from POST or GET',                  str_contains($lp, "\$_POST['next']") && str_contains($lp, "\$_GET['next']"));
_a('rejects scheme/host (open-redirect guard)',    str_contains($lp, "strncmp(\$next, '/', 1) === 0") && str_contains($lp, "strncmp(\$next, '//', 2) !== 0"));
_a('preserves SPA hash when bouncing back',        str_contains($lp, "str_contains(\$next, '#')"));
_a('legacy ?redirect=dashboard path still works',  str_contains($lp, "\$redirect === 'dashboard'"));

echo "\n--- $pass assertions, $fail failed ---\n";
exit($fail === 0 ? 0 : 1);
