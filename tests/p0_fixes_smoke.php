<?php
/**
 * P0 bug-fix smoke (this fork) —
 *   1. Migration 054 idempotently adds users.created_at + users.updated_at
 *      when the table predates migration 013.
 *   2. api_require_auth() resolves the effective role from user_tenants for
 *      the active tenant (was: stuck on login-time users.role).
 *   3. /api/sub_tenants.php?action=switch re-derives + persists the new
 *      role into the session.
 *   4. admin_healthcheck.php degrades gracefully when exec() is disabled
 *      AND treats missing PDF binary as `warn` (host issue) not `fail`.
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ----------------------------------------------------------------- 1) Migration 054
echo "Migration 054 — users timestamp back-fill\n";
$mig = (string) file_get_contents($ROOT . '/core/migrations/054_users_timestamps_safe.sql');
$a('file present',                            $mig !== '');
$a('queries information_schema',              $c($mig, 'information_schema.columns'));
$a('uses prepared ALTER for created_at',      $c($mig, "ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP"));
$a('uses prepared ALTER for updated_at',      $c($mig, "ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP"));
$a('idempotent (DO 0 fallback)',              substr_count($mig, "'DO 0'") >= 2);

// ----------------------------------------------------------------- 2) api_require_auth() effective role
echo "\napi_require_auth — effective role from user_tenants\n";
$ab = (string) file_get_contents($ROOT . '/core/api_bootstrap.php');
$a('queries user_tenants for active tenant',  $c($ab, "FROM user_tenants\n                  WHERE user_id = :u AND tenant_id = :t AND status = \"active\""));
$a('mirrors role back to session',            $c($ab, "\$_SESSION['user']['role'] = \$effectiveRole"));
$a('falls through silently on DB hiccup',     $c($ab, "/* keep session role */"));
$a('returns effective role in context',       (bool) preg_match("/'role'\s+=>\s+\\\$effectiveRole/", $ab));

// ----------------------------------------------------------------- 3) sub_tenants switch
echo "\nsub_tenants switch — role refresh on tenant change\n";
$st = (string) file_get_contents($ROOT . '/api/sub_tenants.php');
$a('queries user_tenants on switch',          $c($st, 'SELECT role FROM user_tenants WHERE user_id = :u AND tenant_id = :t'));
$a('updates session role',                    $c($st, "\$_SESSION['user']['role'] = (string) \$newRole"));
$a('refreshes available modules',             $c($st, "\$_SESSION['modules'] = getUserModules"));
$a('returns role in switch response',         $c($st, "'role'      => \$_SESSION['user']['role']"));
$a('requires modules.php for getUserModules', $c($st, "require_once __DIR__ . '/../core/modules.php'"));

// ----------------------------------------------------------------- 4) Healthcheck graceful degradation
echo "\nadmin_healthcheck — graceful degradation\n";
$hc = (string) file_get_contents($ROOT . '/api/admin_healthcheck.php');
$a('cron script check detects exec() disabled',
    $c($hc, "in_array('exec', \$disabled, true) || !function_exists('exec')"));
$a('cron script check uses tokenizer fallback', $c($hc, 'token_get_all($src, TOKEN_PARSE)'));
$a('cron script check catches ParseError',     $c($hc, '} catch (\\ParseError $e) {'));
$a('PDF binary missing → warn (not fail)',     $c($hc, "'status' => 'warn', 'detail' => 'no chromium/wkhtmltopdf"));

// ----------------------------------------------------------------- syntax
echo "\nSyntax sanity\n";
foreach (['core/api_bootstrap.php', 'api/sub_tenants.php', 'api/admin_healthcheck.php'] as $f) {
    $rc = 0; $o = [];
    exec('php -l ' . escapeshellarg($ROOT . '/' . $f) . ' 2>&1', $o, $rc);
    $a("php -l $f",                            $rc === 0);
}

echo "\n=========================================\n";
echo "P0 fixes smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
