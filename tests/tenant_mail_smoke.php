<?php
/**
 * Tenant mail settings (Model B) — contract smoke tests.
 *
 * Does NOT require a live DB. Uses environment + schema/contract inspection.
 * Full integration is verified by users running `004_tenant_mail_settings.sql`
 * on Cloudways and exercising the MailSettingsPage UI.
 */
declare(strict_types=1);
require_once __DIR__ . '/../core/mail/ResendDriver.php';
require_once __DIR__ . '/../core/MailService.php';

$pass = 0; $fail = 0;
$assert = function ($n, $c) use (&$pass, &$fail) { if ($c) { echo "  \u{2713} {$n}\n"; $pass++; } else { echo "  \u{2717} {$n}\n"; $fail++; } };

echo "Migration SQL (004_tenant_mail_settings.sql)\n";
$mig = (string) file_get_contents(__DIR__ . '/../core/migrations/004_tenant_mail_settings.sql');
$assert('migration file exists',            strlen($mig) > 0);
$assert('adds mail_reply_to column',        strpos($mig, 'mail_reply_to VARCHAR(255)') !== false);
$assert('adds mail_from_name_override',     strpos($mig, 'mail_from_name_override VARCHAR(120)') !== false);
$assert('idempotent via information_schema',strpos($mig, 'information_schema.COLUMNS') !== false);
$assert('no 0900_ai_ci collation',          strpos($mig, 'utf8mb4_0900_ai_ci') === false);

echo "\nHelper API surface\n";
// Helper depends on DB to look up overrides; we only check function shape.
$helper = (string) file_get_contents(__DIR__ . '/../core/tenant_mail.php');
$assert('cf_tenant_mail_sender defined',     strpos($helper, 'function cf_tenant_mail_sender') !== false);
$assert('returns from/from_name/reply_to/model', strpos($helper, "'model'") !== false);
$assert('falls back to env RESEND_FROM_EMAIL',   strpos($helper, 'RESEND_FROM_EMAIL') !== false);
$assert('falls back to env RESEND_FROM_NAME',    strpos($helper, 'RESEND_FROM_NAME')  !== false);
$assert('SMTP_FROM fallback preserved',          strpos($helper, 'SMTP_FROM_EMAIL') !== false);
$assert('reads mail_reply_to from tenants',      strpos($helper, 'mail_reply_to') !== false);
$assert('reads mail_from_name_override',         strpos($helper, 'mail_from_name_override') !== false);

echo "\nPlatform API (/api/mail_settings.php)\n";
$api = (string) file_get_contents(__DIR__ . '/../api/mail_settings.php');
$assert('GET returns settings',              strpos($api, "method === 'GET'") !== false);
$assert('PUT writes settings',               strpos($api, "method === 'PUT'") !== false);
$assert('validates reply_to email',          strpos($api, 'FILTER_VALIDATE_EMAIL') !== false);
$assert('forbids header injection chars',    strpos($api, "preg_match('/[\\r\\n<>]/") !== false);
$assert('gated by tenant.manage perm',       strpos($api, "'tenant.manage'") !== false);
$assert('audit event emitted',               strpos($api, 'tenant.mail_settings.updated') !== false);
$o = []; $rc = 0; @exec('php -l ' . escapeshellarg(__DIR__ . '/../api/mail_settings.php') . ' 2>&1', $o, $rc);
$assert('api/mail_settings.php parses',      $rc === 0);

echo "\nResendDriver — per-call from_name override\n";
$captured = null;
$driver = new Core\Mail\ResendDriver('re_abc', 'platform@example.com', 'Platform Default',
    function ($req) use (&$captured) { $captured = $req; return ['ok' => true, 'http' => 200, 'id' => 'msg-1']; });
$driver->send([
    'tenant_id' => 9, 'module' => 'time', 'purpose' => 'client_approval_request',
    'to' => ['c@b.co'], 'subject' => 's',
    'body_text' => 't',
    'from_name' => 'Acme Staffing',
]);
$assert('envelope from_name overrides default', strpos($captured['payload']['from'], 'Acme Staffing <platform@example.com>') !== false);

$captured = null;
$driver->send([
    'tenant_id' => 9, 'module' => 'time', 'purpose' => 'p',
    'to' => ['c@b.co'], 'subject' => 's', 'body_text' => 't',
]);
$assert('no override falls back to default name', strpos($captured['payload']['from'], 'Platform Default <platform@example.com>') !== false);

$captured = null;
$driver->send([
    'tenant_id' => 9, 'module' => 'time', 'purpose' => 'p',
    'to' => ['c@b.co'], 'subject' => 's', 'body_text' => 't',
    'from_name' => '',
]);
// Empty string still coalesces to default
$assert('empty from_name keeps default',          strpos($captured['payload']['from'], 'Platform Default') !== false);

echo "\nMailService passes from_name + reply_to through opts\n";
$svcSrc = (string) file_get_contents(__DIR__ . '/../core/MailService.php');
$assert("envelope has 'from_name' key",     strpos($svcSrc, "'from_name'     => \$opts['from_name']") !== false);
$assert("envelope has 'reply_to' key",       strpos($svcSrc, "'reply_to'      => \$opts['reply_to']") !== false);
$assert("envelope has 'idempotency_key'",    strpos($svcSrc, "'idempotency_key'") !== false);

echo "\nTime approval endpoint uses cf_tenant_mail_sender\n";
$at = (string) file_get_contents(__DIR__ . '/../modules/time/api/approval_tokens.php');
$assert('requires core/tenant_mail.php',     strpos($at, "require_once __DIR__ . '/../../../core/tenant_mail.php'") !== false);
$assert('calls cf_tenant_mail_sender',       strpos($at, 'cf_tenant_mail_sender(') !== false);
$assert('passes from/from_name/reply_to',    strpos($at, "'from'            => \$sender['from']")     !== false);
$assert('passes from_name',                  strpos($at, "'from_name'       => \$sender['from_name']") !== false);
$assert('passes reply_to',                   strpos($at, "'reply_to'        => \$sender['reply_to']")  !== false);

echo "\nReact settings UI\n";
$page = (string) file_get_contents(__DIR__ . '/../dashboard/src/pages/MailSettingsPage.jsx');
$assert('page reads /api/mail_settings.php',     strpos($page, "'/api/mail_settings.php'") !== false);
$assert('page issues PUT via api.put',           strpos($page, 'api.put(') !== false);
$assert('page shows preview block',              strpos($page, 'mail-settings-preview-from') !== false);
$assert('page has reply_to input testid',        strpos($page, 'mail-settings-reply-to') !== false);
$assert('page has from_name input testid',       strpos($page, 'mail-settings-from-name') !== false);
$appJsx = (string) file_get_contents(__DIR__ . '/../dashboard/src/App.jsx');
$assert('App.jsx imports MailSettingsPage',      strpos($appJsx, "import MailSettingsPage from './pages/MailSettingsPage'") !== false);
$assert('App.jsx mounts /settings/mail route',   strpos($appJsx, '/settings/mail') !== false);
$settings = (string) file_get_contents(__DIR__ . '/../dashboard/src/pages/SettingsPage.jsx');
$assert('SettingsPage links to /settings/mail',  strpos($settings, '/settings/mail') !== false);

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
