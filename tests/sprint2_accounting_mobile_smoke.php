<?php
/**
 * Sprint 2 — Accounting depth (B2 Dimensions + B4 Close workflow) +
 *            Mobile foundation (M1 JWT + M2 devices + M3 PWA) — static smoke.
 *
 *   php -d zend.assertions=1 /app/tests/sprint2_accounting_mobile_smoke.php
 *
 * Verifies:
 *   1. Dimensions migration creates 3 tables (registry / values / account rules).
 *   2. Close workflow migration creates close_tasks + close_packets.
 *   3. Mobile-auth migration creates tenant_mobile_devices + auth_refresh_tokens.
 *   4. accounting/lib/dimensions.php exports validation API + accountingPostJe
 *      now wires it (scan source).
 *   5. accounting/lib/close.php exports checklist + packet builders.
 *   6. New API endpoints exist + parse + RBAC-guarded.
 *   7. core/jwt.php round-trips sign/verify and rejects expired/tampered tokens.
 *   8. api_bootstrap.php accepts JWT bearer alongside session.
 *   9. PWA artifacts (manifest + sw.js) exist with required keys.
 *  10. spa.php registers the SW + links the manifest.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/jwt.php';

$pass = 0; $fail = 0;
$assert = function (string $name, bool $cond, ?string $hint = null) use (&$pass, &$fail): void {
    if ($cond) { echo "  ✓ {$name}\n"; $pass++; }
    else       { echo "  ✗ {$name}" . ($hint ? "  ({$hint})" : '') . "\n"; $fail++; }
};

echo "B2 Dimensions migration\n";
$dimSql = (string) file_get_contents(__DIR__ . '/../modules/accounting/migrations/009_dimensions_and_close.sql');
$assert('migration file present',                   strlen($dimSql) > 0);
$assert('CREATE TABLE accounting_dimensions',       stripos($dimSql, 'CREATE TABLE IF NOT EXISTS accounting_dimensions') !== false);
$assert('CREATE TABLE accounting_dimension_values', stripos($dimSql, 'CREATE TABLE IF NOT EXISTS accounting_dimension_values') !== false);
$assert('CREATE TABLE accounting_account_dim_rules',stripos($dimSql, 'CREATE TABLE IF NOT EXISTS accounting_account_dim_rules') !== false);
foreach (['dim_key','label','data_type','required_default','sort_order','active'] as $c) {
    $assert("dimensions.{$c}", stripos($dimSql, $c) !== false);
}
$assert('account-rule requirement enum', stripos($dimSql, "ENUM('required','optional','blocked')") !== false);

echo "\nB4 Close workflow migration\n";
$assert('CREATE TABLE accounting_close_tasks',   stripos($dimSql, 'CREATE TABLE IF NOT EXISTS accounting_close_tasks')   !== false);
$assert('CREATE TABLE accounting_close_packets', stripos($dimSql, 'CREATE TABLE IF NOT EXISTS accounting_close_packets') !== false);
foreach (['task_key','assignee_user_id','due_date','completed_at','completed_by_user_id','notes','sort_order'] as $c) {
    $assert("close_tasks.{$c}", stripos($dimSql, $c) !== false);
}

echo "\nMobile-auth migration\n";
$mobSql = (string) file_get_contents(__DIR__ . '/../core/migrations/017_mobile_auth.sql');
$assert('017_mobile_auth.sql exists',          strlen($mobSql) > 0);
$assert('tenant_mobile_devices table',         stripos($mobSql, 'CREATE TABLE IF NOT EXISTS tenant_mobile_devices') !== false);
$assert('auth_refresh_tokens table',           stripos($mobSql, 'CREATE TABLE IF NOT EXISTS auth_refresh_tokens') !== false);
foreach (['device_id','platform','apns_token','fcm_token','last_seen_at','revoked_at'] as $c) {
    $assert("tenant_mobile_devices.{$c}", stripos($mobSql, $c) !== false);
}
foreach (['token_hash','expires_at','last_used_at'] as $c) {
    $assert("auth_refresh_tokens.{$c}", stripos($mobSql, $c) !== false);
}

echo "\nDimensions lib + JE wiring\n";
$dimLib = (string) file_get_contents(__DIR__ . '/../modules/accounting/lib/dimensions.php');
foreach (['accountingDimensionRegistry','accountingAccountDimRules','accountingValidateLineDims','accountingValidateJeDims'] as $fn) {
    $assert("dimensions.php exports {$fn}", stripos($dimLib, "function {$fn}(") !== false);
}
$assert('dimensions.php parses', _phpLint(__DIR__ . '/../modules/accounting/lib/dimensions.php'));

$accLib = (string) file_get_contents(__DIR__ . '/../modules/accounting/lib/accounting.php');
$assert('accountingPostJe wires dimension validation',
    stripos($accLib, 'accountingValidateJeDims(') !== false);

echo "\nClose lib + APIs\n";
$closeLib = (string) file_get_contents(__DIR__ . '/../modules/accounting/lib/close.php');
foreach (['accountingDefaultCloseChecklist','accountingSeedCloseChecklist','accountingCompleteCloseTask','accountingBuildClosePacketHtml'] as $fn) {
    $assert("close.php exports {$fn}", stripos($closeLib, "function {$fn}(") !== false);
}
$assert('close.php parses', _phpLint(__DIR__ . '/../modules/accounting/lib/close.php'));

$apiPaths = [
    'modules/accounting/api/dimensions.php',
    'modules/accounting/api/close_tasks.php',
    'modules/accounting/api/close_packet.php',
    'api/auth/mobile_login.php',
    'api/auth/mobile_refresh.php',
    'api/auth/mobile_devices.php',
];
foreach ($apiPaths as $rel) {
    $p = __DIR__ . '/../' . $rel;
    $assert("API exists: {$rel}", is_file($p));
    if (is_file($p)) $assert("API parses: {$rel}", _phpLint($p));
}

$dimApi = (string) file_get_contents(__DIR__ . '/../modules/accounting/api/dimensions.php');
$assert('dimensions API guards dimensions.view',  stripos($dimApi, "rbac_legacy_require(\$user, 'accounting.dimensions.view')") !== false);
$assert('dimensions API guards dimensions.manage',stripos($dimApi, "rbac_legacy_require(\$user, 'accounting.dimensions.manage')") !== false);
$assert('dimensions API supports set_account_rule', stripos($dimApi, 'set_account_rule') !== false);

$closeApi = (string) file_get_contents(__DIR__ . '/../modules/accounting/api/close_tasks.php');
$assert('close_tasks API guards close_workflow.manage',  stripos($closeApi, "rbac_legacy_require(\$user, 'accounting.close_workflow.manage')") !== false);
$assert('close_tasks API guards close_task.complete',    stripos($closeApi, "rbac_legacy_require(\$user, 'accounting.close_task.complete')") !== false);

$pktApi = (string) file_get_contents(__DIR__ . '/../modules/accounting/api/close_packet.php');
$assert('close_packet API exposes html download', stripos($pktApi, "Content-Disposition: attachment; filename=\"close-packet-period-") !== false);

echo "\nJWT round-trip\n";
$payload = ['user_id' => 7, 'tenant_id' => 1, 'name' => 'Tester', 'email' => 'a@b.c', 'role' => 'admin'];
$tok = jwtSign($payload, 60);
$assert('jwtSign produces 3-segment token', count(explode('.', $tok)) === 3);
$dec = jwtVerify($tok);
$assert('jwtVerify returns payload',        is_array($dec) && (int) $dec['user_id'] === 7);
$assert('payload includes iat/exp',         isset($dec['iat'], $dec['exp']));
$tampered = preg_replace('/.$/', 'A', $tok);
$assert('jwtVerify rejects tampered',       jwtVerify($tampered) === null);
// Build an already-expired token by hand (jwtSign clamps min TTL to 60s).
$h = jwtBase64UrlEncode(json_encode(['typ'=>'JWT','alg'=>'HS256']));
$p = jwtBase64UrlEncode(json_encode(['user_id'=>1,'tenant_id'=>1,'iat'=>time()-3600,'exp'=>time()-1800]));
$s = jwtBase64UrlEncode(hash_hmac('sha256', "{$h}.{$p}", 'coreflux-dev-jwt-secret-CHANGE-ME', true));
$expired = "{$h}.{$p}.{$s}";
$assert('jwtVerify rejects expired',        jwtVerify($expired) === null);
$assert('jwtVerify rejects gibberish',      jwtVerify('not.a.token') === null);

echo "\nApi bootstrap accepts JWT\n";
$boot = (string) file_get_contents(__DIR__ . '/../core/api_bootstrap.php');
$assert('api_require_auth requires jwt.php',         stripos($boot, "require_once __DIR__ . '/jwt.php'") !== false);
$assert('api_require_auth checks jwtFromRequest',    stripos($boot, "jwtFromRequest()") !== false);
$assert('api_require_auth hydrates session-shape',   stripos($boot, "\$_SESSION['user']") !== false);

echo "\nPWA artifacts\n";
$mf = (string) file_get_contents(__DIR__ . '/../spa-assets/manifest.webmanifest');
$mfArr = json_decode($mf, true);
$assert('manifest is valid JSON',           is_array($mfArr));
$assert('manifest.name = CoreFlux',         ($mfArr['name'] ?? '') === 'CoreFlux');
$assert('manifest.display = standalone',    ($mfArr['display'] ?? '') === 'standalone');
$assert('manifest.icons present',           !empty($mfArr['icons']));
$assert('manifest.shortcuts has Time',      stripos($mf, '"Time entry"') !== false);

$sw = (string) file_get_contents(__DIR__ . '/../spa-assets/sw.js');
$assert('sw.js install handler',            stripos($sw, "addEventListener('install'") !== false);
$assert('sw.js fetch handler',              stripos($sw, "addEventListener('fetch'")   !== false);
$assert('sw.js skips API caching',          stripos($sw, "url.pathname.startsWith('/api/')") !== false);

$spa = (string) file_get_contents(__DIR__ . '/../spa.php');
$assert('spa.php links manifest',           stripos($spa, '/spa-assets/manifest.webmanifest') !== false);
$assert('spa.php registers service worker', stripos($spa, "navigator.serviceWorker.register") !== false);
$assert('spa.php sets theme-color',         stripos($spa, 'name="theme-color"') !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);

function _phpLint(string $path): bool {
    $o = []; $rc = 0;
    @exec('php -l ' . escapeshellarg($path) . ' 2>&1', $o, $rc);
    return $rc === 0;
}
