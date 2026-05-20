<?php
/**
 * mailerSend shim smoke — verifies the global mailerSend() function
 * is defined and routes through Core\MailService (default LogDriver
 * in this CLI context, ResendDriver when RESEND_API_KEY is set).
 *
 * Does not require MySQL — uses MailService::reset() with an in-memory
 * outbox writer.
 *
 *   php -d zend.assertions=1 /app/tests/mailer_send_shim_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ----------------------------------------------------------------- source-level checks
echo "core/mailer.php — shim shape\n";
$mailer = (string) file_get_contents($ROOT . '/core/mailer.php');
$a('defines mailerSend() globally',                        $c($mailer, "function mailerSend(array \$args)"));
$a('idempotent: guarded by function_exists',               $c($mailer, "if (!function_exists('mailerSend'))"));
$a('routes through cf_mail_bootstrap()',                   $c($mailer, 'cf_mail_bootstrap()'));
$a('delegates to MailService::send',                       $c($mailer, '$svc->send('));
$a('falls back to sendEmail() when MailService unavail.',  $c($mailer, '_mailer_fallback_smtp'));
$a('does not require body_html — body_text derived',       $c($mailer, '_mailer_html_to_text'));
$a('respects caller-supplied tenant_id',                   $c($mailer, "\$args['tenant_id']"));
$a('derives tenant from currentTenantId() when absent',    $c($mailer, "currentTenantId()"));
$a('defaults module to "core"',                            $c($mailer, "\$args['module']  ?? 'core'"));
$a('defaults purpose to "notification"',                   $c($mailer, "\$args['purpose'] ?? 'notification'"));

echo "\ncore/mail_bootstrap.php — config.local.php fallback\n";
$boot = (string) file_get_contents($ROOT . '/core/mail_bootstrap.php');
$a('checks env first, then defined() constant',
    $c($boot, "getenv('RESEND_API_KEY')") && $c($boot, "defined('RESEND_API_KEY')"));

echo "\ncore/mail/ResendDriver.php — dual key source\n";
$drv = (string) file_get_contents($ROOT . '/core/mail/ResendDriver.php');
$a('reads RESEND_API_KEY via env or define()',
    $c($drv, "getenv('RESEND_API_KEY')") && $c($drv, "defined('RESEND_API_KEY')"));
$a('reads RESEND_FROM_EMAIL via env or define()',
    $c($drv, "getenv('RESEND_FROM_EMAIL')") && $c($drv, "defined('RESEND_FROM_EMAIL')"));
$a('reads RESEND_FROM_NAME via env or define()',
    $c($drv, "getenv('RESEND_FROM_NAME')") && $c($drv, "defined('RESEND_FROM_NAME')"));

// ----------------------------------------------------------------- runtime: LogDriver path
echo "\nRuntime — LogDriver default (no RESEND_API_KEY)\n";

require_once $ROOT . '/core/MailService.php';
require_once $ROOT . '/core/mail/LogDriver.php';

use Core\MailService;
use Core\Mail\LogDriver;

// In-memory outbox writer so we can introspect what the shim recorded.
$outbox = [];
$writer = function (array $row) use (&$outbox): int {
    static $id = 0;
    $row['id'] = ++$id;
    $outbox[] = $row;
    return $id;
};

$tmpLog = sys_get_temp_dir() . '/cf-mailerSend-' . bin2hex(random_bytes(4)) . '.log';
$testService = MailService::reset(new LogDriver($tmpLog), $writer);

// Pre-empt mail_bootstrap.php's cf_mail_bootstrap(): we declare it FIRST so
// the function_exists guard inside mail_bootstrap.php skips its definition.
// This lets the test inject its own MailService instance + writer that the
// shim will pick up.
if (!function_exists('cf_mail_bootstrap')) {
    function cf_mail_bootstrap(): \Core\MailService {
        return \Core\MailService::getInstance();
    }
}

// Pull in the shim. mailerSend() requires sendEmail() to exist for the
// fallback path. Including core/mailer.php registers both.
require_once $ROOT . '/core/mailer.php';

$a('mailerSend function is defined',                       function_exists('mailerSend'));

$r1 = mailerSend([
    'tenant_id' => 7,
    'module'    => 'cfo',
    'purpose'   => 'weekly_report',
    'to'        => 'alice@example.com',
    'subject'   => 'CFO Weekly',
    'body_html' => '<p>Here is the report.</p>',
]);
$a('returns ok=true via LogDriver',                        ($r1['ok'] ?? false) === true);
$a('reports LogDriver as the driver',                      ($r1['driver'] ?? '') === 'log');
$a('outbox row recorded for tenant 7',                     count($outbox) === 1
                                                           && $outbox[0]['tenant_id'] === 7
                                                           && $outbox[0]['module']    === 'cfo'
                                                           && $outbox[0]['purpose']   === 'weekly_report');
$a('outbox preserves driver field',                        ($outbox[0]['driver'] ?? '') === 'log');
$a('outbox preserves the HTML body',                       $c((string) ($outbox[0]['body_html'] ?? ''), '<p>Here is the report.</p>'));

// body_text auto-derived from HTML when caller omits it.
$plain = (string) ($outbox[0]['body_text'] ?? '');
$a('body_text auto-derived when omitted',                  $c($plain, 'Here is the report.'));

// Multiple recipients deduped + validated.
$outbox = [];
$r2 = mailerSend([
    'tenant_id' => 7,
    'to'        => ['bob@example.com', 'bob@example.com', 'carol@example.com'],
    'subject'   => 'Heads up',
    'body_text' => 'hi',
]);
$a('multi-recipient send returns ok',                      ($r2['ok'] ?? false) === true);
$decoded = json_decode((string) ($outbox[0]['to_addresses_json'] ?? '[]'), true) ?: [];
$a('duplicate recipients deduped',                         count($decoded) === 2 && in_array('bob@example.com', $decoded, true));

// Validation — missing required fields throw.
$threw = false;
try { mailerSend(['to' => 'x@y.com', 'subject' => 'no body']); }
catch (\InvalidArgumentException $_) { $threw = true; }
$a('throws when body_html and body_text both absent',      $threw);

$threw = false;
try { mailerSend(['to' => 'x@y.com', 'body_text' => 'hi']); }
catch (\InvalidArgumentException $_) { $threw = true; }
$a('throws on missing subject',                            $threw);

// ----------------------------------------------------------------- runtime: ResendDriver path via injected transport
echo "\nRuntime — ResendDriver path (with stubbed cURL transport)\n";

require_once $ROOT . '/core/mail/ResendDriver.php';
use Core\Mail\ResendDriver;

$transportCalls = [];
$transport = function (array $req) use (&$transportCalls): array {
    $transportCalls[] = $req;
    // Simulate a successful Resend response.
    return ['ok' => true, 'http' => 200, 'id' => 're_test_' . count($transportCalls)];
};

$resend = new ResendDriver('re_test_key', 'no-reply@coreflux.app', 'CoreFlux', $transport);
$outbox = [];
MailService::reset($resend, $writer);

$r3 = mailerSend([
    'tenant_id' => 42,
    'module'    => 'staffing',
    'purpose'   => 'timesheet_approval',
    'to'        => 'approver@example.com',
    'subject'   => 'Timesheet awaiting approval',
    'body_html' => '<p>Approve or reject below.</p>',
    'reply_to'  => 'no-reply@coreflux.app',
]);

$a('ResendDriver path returns ok',                         ($r3['ok'] ?? false) === true);
$a('driver reported as resend',                            ($r3['driver'] ?? '') === 'resend');
$a('provider_message_id propagated',                       !empty($r3['message_id']) && str_starts_with((string) $r3['message_id'], 're_test_'));
$a('transport was invoked exactly once',                   count($transportCalls) === 1);
$a('Bearer auth header sent',
    isset($transportCalls[0]['headers'])
    && in_array('Authorization: Bearer re_test_key', $transportCalls[0]['headers'], true));
$a('Idempotency-Key header included',
    isset($transportCalls[0]['headers'])
    && (bool) array_filter($transportCalls[0]['headers'], static fn($h) => str_starts_with((string) $h, 'Idempotency-Key:')));
$a('payload uses from <header> shape',                     $c((string) ($transportCalls[0]['payload']['from'] ?? ''), 'CoreFlux <no-reply@coreflux.app>'));
$a('payload preserves subject + html',                     ($transportCalls[0]['payload']['subject'] ?? '') === 'Timesheet awaiting approval'
                                                           && $c((string) ($transportCalls[0]['payload']['html'] ?? ''), 'Approve or reject below.'));
$a('payload propagates reply_to',                          ($transportCalls[0]['payload']['reply_to'] ?? '') === 'no-reply@coreflux.app');
$a('outbox row tagged with resend driver',                 ($outbox[0]['driver'] ?? '') === 'resend');

// Failure path — transport returns failure.
$transportFail = function (array $_req): array {
    return ['ok' => false, 'http' => 422, 'error' => 'invalid sender domain'];
};
$resendFail = new ResendDriver('re_test_key', 'no-reply@bad.example', null, $transportFail);
$outbox = [];
MailService::reset($resendFail, $writer);
$r4 = mailerSend([
    'tenant_id' => 99,
    'to'        => 'who@example.com',
    'subject'   => 'Will fail',
    'body_text' => 'plain',
]);
$a('failure path returns ok=false',                        ($r4['ok'] ?? true) === false);
$a('error message propagated',                             $c((string) ($r4['error'] ?? ''), 'invalid sender domain'));
$a('outbox row marked as failed',                          ($outbox[0]['status'] ?? '') === 'failed');

// ----------------------------------------------------------------- call-site contract
echo "\nCall sites — no regression\n";
$callSites = [
    '/app/api/cfo_send_report.php',
    '/app/modules/staffing/api/timesheet_email_approver.php',
    '/app/modules/ap/api/vendor_portal.php',
    '/app/modules/ap/api/bill_approvals.php',
    '/app/core/mercury_payments.php',
];
foreach ($callSites as $cs) {
    $a("call site still references mailerSend: {$cs}",     file_exists($cs) && $c((string) file_get_contents($cs), 'mailerSend('));
}

// ----------------------------------------------------------------- summary
@unlink($tmpLog);
echo "\n=========================================\n";
echo "mailerSend shim smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
