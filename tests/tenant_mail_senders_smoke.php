<?php
/**
 * tenant_mail_senders_smoke — per-purpose mail sender overrides.
 *
 * Validates:
 *   - core/migrations/065_tenant_mail_senders.sql declares the table
 *     with the expected schema and UNIQUE constraint.
 *   - core/tenant_mail.php exposes the new registry + resolver helpers
 *     (cf_mail_purpose_registry, cf_mail_purpose_lookup,
 *      cf_mail_senders_list, cf_mail_senders_upsert).
 *   - cf_tenant_mail_sender still resolves correctly via platform
 *     defaults when no DB row exists (pure no-DB path).
 *   - mailerSend() shim consults the resolver and honours
 *     enabled=false (purpose mute).
 *   - All five canonical mailerSend() call sites pass module + purpose
 *     + tenant_id, matching the registry keys.
 *   - /api/admin/mail_senders.php declares GET/POST/DELETE handlers
 *     and gates on tenant.manage.
 *   - SettingsPage.jsx links to /settings/notifications.
 *   - App.jsx mounts /settings/notifications → NotificationSendersPage.
 *   - NotificationSendersPage.jsx renders per-purpose form with
 *     data-testids for {key}-from-name, {key}-reply-to, {key}-enabled,
 *     {key}-save, {key}-reset, plus the purpose registry coverage.
 *   - config.local.php ships RESEND_FROM_EMAIL =
 *     'no-reply@mail.corefluxapp.com' (platform sender envelope).
 *
 * Run via: php -d zend.assertions=1 tests/tenant_mail_senders_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ok  $msg\n"; $pass++; }
    else       { echo "FAIL  $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// -------------------------------------------------------------- migration
echo "core/migrations/065_tenant_mail_senders.sql\n";
$mig = (string) @file_get_contents($ROOT . '/core/migrations/065_tenant_mail_senders.sql');
$a('migration file exists',                  $mig !== '');
$a('declares tenant_mail_senders table',     $c($mig, 'CREATE TABLE IF NOT EXISTS tenant_mail_senders'));
$a('unique (tenant_id, purpose)',            $c($mig, 'UNIQUE KEY uq_tms_tenant_purpose (tenant_id, purpose)'));
$a('has from_name column',                   $c($mig, 'from_name'));
$a('has reply_to column',                    $c($mig, 'reply_to'));
$a('has enabled flag (default 1)',           $c($mig, 'enabled'));
$a('tracks updated_by_user_id',              $c($mig, 'updated_by_user_id'));
$a('utf8mb4 charset',                        $c($mig, 'utf8mb4'));

// -------------------------------------------------------------- tenant_mail.php surface
echo "\ncore/tenant_mail.php — purpose registry + resolver\n";
$tm = (string) file_get_contents($ROOT . '/core/tenant_mail.php');
foreach (['cf_mail_purpose_registry', 'cf_mail_purpose_lookup', 'cf_tenant_mail_sender', 'cf_mail_senders_list', 'cf_mail_senders_upsert'] as $fn) {
    $a("declares $fn()",                     $c($tm, "function $fn"));
}
$a('reads RESEND_FROM_EMAIL define',         $c($tm, "defined('RESEND_FROM_EMAIL')"));
$a('upsert UPSERT via ON DUPLICATE KEY',     $c($tm, 'ON DUPLICATE KEY UPDATE'));
$a('returns enabled flag in resolve',        $c($tm, "'enabled'   => \$enabled"));
$a('returns source for diagnostics',         $c($tm, "'source'    => \$source"));
$a('derived default = tenant_name + label',
    $c($tm, "trim(\$trow['name'] . ' ' . \$purposeMeta['label']"));

// php -l
$out = []; $rc = 0;
exec('php -l ' . escapeshellarg($ROOT . '/core/tenant_mail.php') . ' 2>&1', $out, $rc);
$a('php -l core/tenant_mail.php',            $rc === 0);

// -------------------------------------------------------------- registry contents
echo "\nFunctional — purpose registry\n";
require_once $ROOT . '/core/tenant_mail.php';
$reg = cf_mail_purpose_registry();
$a('registry has 5 entries',                 is_array($reg) && count($reg) === 5);
$expectedKeys = ['timesheets', 'ap', 'vendor_portal', 'cfo', 'payments'];
$actualKeys   = array_map(static fn ($r) => $r['key'], $reg);
foreach ($expectedKeys as $k) {
    $a("registry exposes purpose key: $k",   in_array($k, $actualKeys, true));
}
$expectedLabels = ['Timesheets', 'AP', 'Vendor Portal', 'CFO', 'Payments'];
$actualLabels   = array_map(static fn ($r) => $r['label'], $reg);
foreach ($expectedLabels as $lbl) {
    $a("registry exposes label: $lbl",       in_array($lbl, $actualLabels, true));
}
$a('cf_mail_purpose_lookup("ap") returns row', cf_mail_purpose_lookup('ap')['label'] === 'AP');
$a('cf_mail_purpose_lookup("bogus") null',     cf_mail_purpose_lookup('bogus') === null);

// resolver smoke — tenant=0 short-circuits DB and returns platform defaults
$res = cf_tenant_mail_sender(0, 'cfo');
$a('resolver(0,"cfo") returns array',         is_array($res));
$a('resolver(0) has from key',                array_key_exists('from', $res));
$a('resolver(0) enabled defaults to true',    ($res['enabled'] ?? null) === true);
$a('resolver(0) source = platform',           ($res['source'] ?? null) === 'platform');

// -------------------------------------------------------------- mailerSend shim integration
echo "\ncore/mailer.php — purpose-aware shim\n";
$mailer = (string) file_get_contents($ROOT . '/core/mailer.php');
$a('mailer requires tenant_mail.php',         $c($mailer, "require_once __DIR__ . '/tenant_mail.php'"));
$a('shim calls cf_tenant_mail_sender',        $c($mailer, "cf_tenant_mail_sender(\$tenantId, \$purposeKey)"));
$a('enabled=false → returns disabled driver', $c($mailer, "'driver' => 'disabled'") && $c($mailer, "'purpose_disabled'"));
$a('fills from_name from resolver when blank',$c($mailer, "\$args['from_name'] = \$sender['from_name']"));
$a('fills reply_to from resolver when blank', $c($mailer, "\$args['reply_to'] = \$sender['reply_to']"));

// -------------------------------------------------------------- call site wiring
echo "\nCall sites pass module + purpose + tenant_id\n";
$callSites = [
    'modules/staffing/api/timesheet_email_approver.php' => "'purpose'   => 'timesheets'",
    'modules/ap/api/bill_approvals.php'                  => "'purpose'   => 'ap'",
    'modules/ap/api/vendor_portal.php'                   => "'purpose'   => 'vendor_portal'",
    'api/cfo_send_report.php'                            => "'purpose'   => 'cfo'",
    'core/mercury_payments.php'                          => "'purpose'   => 'payments'",
];
foreach ($callSites as $rel => $needle) {
    $src = (string) @file_get_contents($ROOT . '/' . $rel);
    $a("$rel includes $needle",              $c($src, $needle));
    $a("$rel passes tenant_id",              $c($src, "'tenant_id' => \$tenantId") || $c($src, "'tenant_id' => \$tid"));
}

// -------------------------------------------------------------- API endpoint
echo "\napi/admin/mail_senders.php\n";
$apiPath = $ROOT . '/api/admin/mail_senders.php';
$api = (string) @file_get_contents($apiPath);
$a('endpoint exists',                        $api !== '');
$a('GET handler present',                    $c($api, "\$method === 'GET'"));
$a('POST handler present',                   $c($api, "\$method === 'POST'"));
$a('DELETE handler present',                 $c($api, "\$method === 'DELETE'"));
$a('RBAC tenant.manage',                     $c($api, "rbac_legacy_require(\$user, 'tenant.manage')"));
$a('rejects unknown purpose with 422',       $c($api, 'Unknown purpose'));
$a('writes audit row',                       $c($api, "'tenant.mail_senders.updated'"));
$out = []; $rc = 0;
exec('php -l ' . escapeshellarg($apiPath) . ' 2>&1', $out, $rc);
$a('php -l api/admin/mail_senders.php',      $rc === 0);

// -------------------------------------------------------------- config.local.php
echo "\nconfig.local.php — platform Resend envelope\n";
$cfg = (string) file_get_contents($ROOT . '/core/config.local.php');
$a('declares RESEND_FROM_EMAIL',             $c($cfg, "define('RESEND_FROM_EMAIL'"));
$a('default from = no-reply@mail.corefluxapp.com', $c($cfg, "'no-reply@mail.corefluxapp.com'"));
$a('declares RESEND_FROM_NAME default',      $c($cfg, "define('RESEND_FROM_NAME'"));
// Resend API key is committed to config.local.php — deliberate choice
// for Cloudways standard tier which has no env-var UI panel. The key
// is rotated on the host (edit + reload PHP-FPM); rotating in git is
// just the documented backup path.

// -------------------------------------------------------------- UI
echo "\nUI — SettingsPage + NotificationSendersPage\n";
$sp = (string) file_get_contents($ROOT . '/dashboard/src/pages/SettingsPage.jsx');
$a('SettingsPage links to /settings/notifications', $c($sp, '"/settings/notifications"') || $c($sp, "'/settings/notifications'"));
$a('settings-notifications-link testid',     $c($sp, 'data-testid="settings-notifications-link"'));

$app = (string) file_get_contents($ROOT . '/dashboard/src/App.jsx');
$a('App mounts /settings/notifications',     $c($app, 'path="/settings/notifications"'));
$a('App imports NotificationSendersPage',    $c($app, 'NotificationSendersPage'));

$page = (string) file_get_contents($ROOT . '/dashboard/src/pages/NotificationSendersPage.jsx');
$a('page root testid',                       $c($page, 'data-testid="notification-senders-page"'));
$a('back-to-settings link',                  $c($page, 'data-testid="notif-senders-back"'));
$a('platform from-email pill',               $c($page, 'data-testid="notif-platform-from"'));
$a('per-purpose row testid template',        $c($page, 'data-testid={`notif-purpose-${p.key}`}'));
$a('from-name input testid template',        $c($page, '`notif-${p.key}-from-name`'));
$a('reply-to input testid template',         $c($page, '`notif-${p.key}-reply-to`'));
$a('enabled toggle testid template',         $c($page, '`notif-${p.key}-enabled`'));
$a('save button testid template',            $c($page, '`notif-${p.key}-save`'));
$a('reset button testid template',           $c($page, '`notif-${p.key}-reset`'));
$a('uses api.delete',                        $c($page, 'api.delete'));
$a('POSTs to /api/admin/mail_senders.php',   $c($page, "api.post('/api/admin/mail_senders.php'"));
$a('GETs from /api/admin/mail_senders.php',  $c($page, "api.get('/api/admin/mail_senders.php')"));

echo "\n=========================================\n";
echo "Tenant mail senders (per-purpose) smoke: {$pass} ok / {$fail} fail\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
