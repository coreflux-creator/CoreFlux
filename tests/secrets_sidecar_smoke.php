<?php
/**
 * Smoke — secrets sidecar split (2026-02).
 *
 * Locks the contract introduced when secrets moved from
 *   core/config.local.php (gitcommitted)
 * to
 *   core/config.secrets.php (gitignored)
 *
 * with core/config.local.php @include'ing the sidecar so downstream
 * callers see one merged set of constants.
 *
 * Static-source-only — pure-function probes verify the sidecar loads
 * the constants into the runtime, but no DB / network calls.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ──────────────────────────────────────────────────────────────────────
// 1) The 3 files exist with the expected roles.
// ──────────────────────────────────────────────────────────────────────
echo "\n── file layout ──\n";
$a('core/config.local.php exists (committed)',
    file_exists('/app/core/config.local.php'));
$a('core/config.secrets.php exists (this pod)',
    file_exists('/app/core/config.secrets.php'));
$a('core/config.secrets.example.php exists (committed template)',
    file_exists('/app/core/config.secrets.example.php'));

// .gitignore must list the sidecar.
$gi = (string) file_get_contents('/app/.gitignore');
$a('.gitignore lists core/config.secrets.php',
    $c($gi, 'core/config.secrets.php'));

// ──────────────────────────────────────────────────────────────────────
// 2) config.local.php — committed, no secrets, @includes the sidecar.
// ──────────────────────────────────────────────────────────────────────
echo "\n── core/config.local.php (committed) ──\n";
$cfg = (string) file_get_contents('/app/core/config.local.php');

$a('@includes the sidecar',
    $c($cfg, "@include __DIR__ . '/config.secrets.php'"));
$a('does NOT commit RESEND_API_KEY',
    !preg_match("#^[^/]*define\(\s*'RESEND_API_KEY'#m", $cfg));
$a('does NOT commit OPENAI_API_KEY',
    !preg_match("#^[^/]*define\(\s*'OPENAI_API_KEY'#m", $cfg));
$a('does NOT commit PLAID_SECRET_SANDBOX',
    !preg_match("#^[^/]*define\(\s*'PLAID_SECRET_SANDBOX'#m", $cfg));
$a('does NOT commit PLAID_SECRET_PRODUCTION',
    !preg_match("#^[^/]*define\(\s*'PLAID_SECRET_PRODUCTION'#m", $cfg));
$a('does NOT commit PLAID_CLIENT_ID',
    !preg_match("#^[^/]*define\(\s*'PLAID_CLIENT_ID'#m", $cfg));
$a('does NOT commit QBO_CLIENT_ID',
    !preg_match("#^[^/]*define\(\s*'QBO_CLIENT_ID'#m", $cfg));
$a('does NOT commit QBO_CLIENT_SECRET',
    !preg_match("#^[^/]*define\(\s*'QBO_CLIENT_SECRET'#m", $cfg));
$a('does NOT commit COREFLUX_DATA_KEY',
    !preg_match("#^[^/]*define\(\s*'COREFLUX_DATA_KEY'#m", $cfg));

// Still keeps non-secret defines.
$a('still defines RESEND_FROM_EMAIL (non-secret)',
    $c($cfg, "define('RESEND_FROM_EMAIL'"));
$a('still defines RESEND_FROM_NAME (non-secret)',
    $c($cfg, "define('RESEND_FROM_NAME'"));
$a('still defines PLAID_ENV (non-secret)',
    $c($cfg, "define('PLAID_ENV'"));
$a('still defines QBO_REDIRECT_URI (non-secret)',
    $c($cfg, "define('QBO_REDIRECT_URI'"));
$a('still defines QBO_ENV (non-secret)',
    $c($cfg, "define('QBO_ENV'"));
$a('still defines QBO_SCOPES (non-secret)',
    $c($cfg, "define('QBO_SCOPES'"));

// All non-secret defines guarded so a duplicate define from a host's
// legacy config.local.php during rollout doesn't emit warnings.
$a('non-secret defines are guarded with if (!defined(...))',
    substr_count($cfg, 'if (!defined(') >= 5);

// php -l clean.
exec('php -l /app/core/config.local.php 2>&1', $out, $rc);
$a('config.local.php passes php -l',                 $rc === 0);

// ──────────────────────────────────────────────────────────────────────
// 3) config.secrets.example.php — committed template, no real values.
// ──────────────────────────────────────────────────────────────────────
echo "\n── core/config.secrets.example.php (committed template) ──\n";
$ex = (string) file_get_contents('/app/core/config.secrets.example.php');
$a('template defines RESEND_API_KEY placeholder',
    $c($ex, "define('RESEND_API_KEY', 're_REPLACE_ME')"));
$a('template defines OPENAI_API_KEY placeholder',
    $c($ex, "define('OPENAI_API_KEY', 'sk-REPLACE_ME')"));
$a('template includes COREFLUX_DATA_KEY placeholder',
    $c($ex, "COREFLUX_DATA_KEY"));
$a('template includes PLAID + QBO placeholders',
    $c($ex, 'PLAID_CLIENT_ID') && $c($ex, 'QBO_CLIENT_ID'));
$a('template values are clearly fake (REPLACE_ME tokens)',
    substr_count($ex, 'REPLACE_ME') >= 5);
$a('template guards all defines with if (!defined(...))',
    substr_count($ex, 'if (!defined(') >= 6);

// php -l clean.
exec('php -l /app/core/config.secrets.example.php 2>&1', $out2, $rc2);
$a('config.secrets.example.php passes php -l',       $rc2 === 0);

// ──────────────────────────────────────────────────────────────────────
// 4) config.secrets.php (this pod) — sidecar contract.
// ──────────────────────────────────────────────────────────────────────
echo "\n── core/config.secrets.php (this pod, gitignored) ──\n";
$sec = (string) file_get_contents('/app/core/config.secrets.php');
$a('sidecar guards every define with if (!defined(...))',
    substr_count($sec, 'if (!defined(') >= 6);
$a('sidecar defines RESEND_API_KEY',  $c($sec, "define('RESEND_API_KEY'"));
$a('sidecar defines OPENAI_API_KEY',  $c($sec, "define('OPENAI_API_KEY'"));
$a('sidecar defines COREFLUX_DATA_KEY', $c($sec, "define('COREFLUX_DATA_KEY'"));
$a('sidecar defines QBO_CLIENT_ID + QBO_CLIENT_SECRET',
    $c($sec, "define('QBO_CLIENT_ID'") && $c($sec, "define('QBO_CLIENT_SECRET'"));
$a('sidecar defines PLAID secrets',
    $c($sec, "define('PLAID_CLIENT_ID'")
    && $c($sec, "define('PLAID_SECRET_SANDBOX'")
    && $c($sec, "define('PLAID_SECRET_PRODUCTION'"));

// php -l clean.
exec('php -l /app/core/config.secrets.php 2>&1', $out3, $rc3);
$a('config.secrets.php passes php -l',               $rc3 === 0);

// ──────────────────────────────────────────────────────────────────────
// 5) Runtime probe — loading config.local.php pulls in every secret
//    AND non-secret constant, no warnings, no errors.
// ──────────────────────────────────────────────────────────────────────
echo "\n── runtime: require_once('config.local.php') ──\n";

// Capture any warnings emitted on load (duplicate-define etc.).
$warned = false;
set_error_handler(function ($errno, $errstr) use (&$warned) {
    $warned = true;
    fwrite(STDERR, "  warn: $errstr\n");
}, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE);
require_once '/app/core/config.local.php';
restore_error_handler();

$a('no warning/notice emitted on load',              $warned === false);
$a('runtime: RESEND_API_KEY defined',                defined('RESEND_API_KEY'));
$a('runtime: RESEND_API_KEY starts with re_',
    defined('RESEND_API_KEY') && str_starts_with(RESEND_API_KEY, 're_'));
$a('runtime: OPENAI_API_KEY defined',                defined('OPENAI_API_KEY'));
$a('runtime: COREFLUX_DATA_KEY defined',             defined('COREFLUX_DATA_KEY'));
$a('runtime: QBO_CLIENT_ID defined',                 defined('QBO_CLIENT_ID'));
$a('runtime: QBO_CLIENT_SECRET defined',             defined('QBO_CLIENT_SECRET'));
$a('runtime: PLAID_CLIENT_ID defined',               defined('PLAID_CLIENT_ID'));
$a('runtime: RESEND_FROM_EMAIL defined (non-secret)', defined('RESEND_FROM_EMAIL'));
$a('runtime: QBO_REDIRECT_URI defined (non-secret)', defined('QBO_REDIRECT_URI'));

// ──────────────────────────────────────────────────────────────────────
// 6) Mail bootstrap still picks ResendDriver as default after the split.
// ──────────────────────────────────────────────────────────────────────
echo "\n── runtime: cf_mail_bootstrap() uses ResendDriver ──\n";
require_once '/app/core/mail_bootstrap.php';
$svc = cf_mail_bootstrap();
$ref = new \ReflectionClass($svc);
$prop = $ref->getProperty('default');
$prop->setAccessible(true);
$default = $prop->getValue($svc);
$a('default driver is Core\\Mail\\ResendDriver',
    is_object($default) && get_class($default) === 'Core\\Mail\\ResendDriver');

// ──────────────────────────────────────────────────────────────────────
echo "\n=========================================\n";
echo "Secrets sidecar smoke: $pass ✓ / $fail ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
