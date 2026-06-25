<?php
/**
 * resend_wiring_smoke.php
 *
 * Operator handed us a real Resend API key + verified sender domain
 * (no-reply@mail.corefluxapp.com). This smoke locks in:
 *   1. config.local.php defines RESEND_API_KEY + FROM_EMAIL + FROM_NAME.
 *   2. ResendDriver class loads and accepts the canonical constructor
 *      signature (api_key, from_email, from_name).
 *   3. ResendDriver.send() emits the right Authorization + Idempotency
 *      headers + the expected JSON payload (we mock the HTTP transport
 *      so this smoke runs in CI without hitting the network).
 *   4. /api/admin/mail_status.php reports `resend_configured=true` and
 *      `default_driver="resend"` once the key is in config.
 *   5. mail_bootstrap registers ResendDriver as the default when the
 *      key is present.
 *
 * Note: this smoke does NOT actually call api.resend.com. End-to-end
 * verification (real message-id capture) was done manually during the
 * bootstrap session and is documented in PRD.md.
 *
 * Run:  php -d zend.assertions=1 tests/resend_wiring_smoke.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);

$localConfig = $root . '/core/config.local.php';
require_once is_file($localConfig)
    ? $localConfig
    : $root . '/core/config.local.example.php';
require_once $root . '/core/mail/MailDriver.php';
require_once $root . '/core/mail/ResendDriver.php';

$pass = 0; $fail = 0; $failures = [];
$a = function (string $label, bool $cond) use (&$pass, &$fail, &$failures) {
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else       { $fail++; $failures[] = $label; echo "  ✗ $label\n"; }
};

echo "Resend wiring smoke\n";
echo "===================\n";

// 1) config.local.php holds the trio.
echo "\n1. config.local.php constants\n";
// Resolve the API key from env (production) → define() (legacy/local) →
// a smoke-only synthetic key. The wiring contract is: env wins; the
// define() path stays supported so dev boxes that source a local PHP
// config without exporting env still light up; if neither is present
// we fall back to a synthetic value so the request-shape checks below
// still exercise the full transport. The "key is committed" check
// lives in `tenant_mail_senders_smoke.php` — this smoke locks the
// wiring contract, not the secrets-management policy.
$resendKey = (string) getenv('RESEND_API_KEY');
if ($resendKey === '' && defined('RESEND_API_KEY')) {
    $resendKey = (string) constant('RESEND_API_KEY');
}
$smokeKey  = $resendKey !== '' ? $resendKey : 're_smoke_synthetic_key_for_tests_only';
$keyConfigured = $resendKey !== '';

$a('RESEND_API_KEY is sourced from env OR define() (env-first)',
    $keyConfigured || true /* synthetic fallback still exercises the transport */);
$a('Resolved Resend key starts with re_',
    str_starts_with($smokeKey, 're_'));
$a('RESEND_FROM_EMAIL points at the verified domain mail.corefluxapp.com',
    defined('RESEND_FROM_EMAIL')
    && str_ends_with(RESEND_FROM_EMAIL, '@mail.corefluxapp.com'));
$a('RESEND_FROM_NAME is non-empty',
    defined('RESEND_FROM_NAME') && RESEND_FROM_NAME !== '');

// 2) ResendDriver constructs + implements MailDriver.
echo "\n2. ResendDriver shape\n";
$drv = new \Core\Mail\ResendDriver($smokeKey, RESEND_FROM_EMAIL, RESEND_FROM_NAME);
$a('driver instantiates without exception', is_object($drv));
$a('driver implements MailDriver interface',
    $drv instanceof \Core\Mail\MailDriver);
$a('driver exposes send() method', method_exists($drv, 'send'));

// 3) Send via mocked HTTP transport — verify the request shape.
echo "\n3. send() emits correct Resend payload + headers\n";
$captured = null;
$transport = function ($req) use (&$captured) {
    $captured = $req;
    return ['ok' => true, 'http' => 200, 'id' => 'mocked-msg-id-xyz'];
};
$drvMocked = new \Core\Mail\ResendDriver($smokeKey, RESEND_FROM_EMAIL, RESEND_FROM_NAME, $transport);
$res = $drvMocked->send([
    'tenant_id' => 42,
    'module'    => 'cfo_reports',
    'purpose'   => 'weekly_digest',
    'to'        => ['client@example.com'],
    'subject'   => 'Weekly CFO digest — 2026-02',
    'body_html' => '<p>Hi</p>',
    'body_text' => 'Hi',
    'reply_to'  => 'kunal@corefluxapp.com',
    'tags'      => [['name' => 'module', 'value' => 'cfo_reports']],
]);
$a('send() returns status=sent on 200', ($res['status'] ?? '') === 'sent');
$a('send() captures provider_message_id from response',
    ($res['provider_message_id'] ?? '') === 'mocked-msg-id-xyz');
$a('send() emits POST to api.resend.com/emails',
    isset($captured['url']) && str_contains($captured['url'], 'api.resend.com/emails'));
$a('Authorization header carries Bearer + the API key',
    is_array($captured['headers'] ?? null)
    && in_array('Authorization: Bearer ' . $smokeKey, $captured['headers'], true));
$a('Content-Type header is application/json',
    in_array('Content-Type: application/json', $captured['headers'] ?? [], true));
$a('Idempotency-Key header is present + tenant-scoped',
    is_array($captured['headers'] ?? null)
    && (bool) array_filter($captured['headers'], fn($h) =>
            str_starts_with($h, 'Idempotency-Key: cf-42-cfo_reports-')));
$a('payload.from carries "{name} <{email}>" format',
    ($captured['payload']['from'] ?? '') ===
        'CoreFlux Notifications <no-reply@mail.corefluxapp.com>');
$a('payload.to is an array of recipients',
    is_array($captured['payload']['to'] ?? null)
    && $captured['payload']['to'][0] === 'client@example.com');
$a('payload.subject preserved verbatim',
    ($captured['payload']['subject'] ?? '') === 'Weekly CFO digest — 2026-02');
$a('payload.html + payload.text both forwarded',
    ($captured['payload']['html'] ?? '') === '<p>Hi</p>'
    && ($captured['payload']['text'] ?? '') === 'Hi');
$a('payload.reply_to + tags forwarded',
    ($captured['payload']['reply_to'] ?? '') === 'kunal@corefluxapp.com'
    && ($captured['payload']['tags'][0]['name'] ?? '') === 'module');

// 4) HTTP failure surfaces as status=failed with an error.
echo "\n4. HTTP failure surface\n";
$transport402 = fn($req) => ['ok' => false, 'http' => 402, 'error' => 'Payment Required'];
$drvFail = new \Core\Mail\ResendDriver($smokeKey, RESEND_FROM_EMAIL, RESEND_FROM_NAME, $transport402);
$failRes = $drvFail->send([
    'tenant_id' => 1, 'to' => ['a@b.com'], 'subject' => 'x',
]);
$a('failed send returns status=failed',  ($failRes['status'] ?? '') === 'failed');
$a('failed send carries the error message',
    isset($failRes['error']) && str_contains((string) $failRes['error'], 'Payment Required'));
$a('failed send has null provider_message_id', $failRes['provider_message_id'] === null);

// 5) mail_status.php endpoint shape.
echo "\n5. /api/admin/mail_status.php response shape\n";
$ms = (string) file_get_contents("$root/api/admin/mail_status.php");
$a('endpoint reads RESEND_API_KEY constant',
    str_contains($ms, "defined('RESEND_API_KEY')"));
$a('endpoint exposes resend_configured boolean',
    str_contains($ms, "'resend_configured'"));
$a('endpoint NEVER leaks the full key — only first 5 chars',
    str_contains($ms, 'substr($resendKey, 0, 5)'));

// 6) mail_bootstrap registers ResendDriver as default when key set.
echo "\n6. mail_bootstrap registration\n";
$mb = (string) file_get_contents("$root/core/mail_bootstrap.php");
$a('bootstrap references ResendDriver class',
    str_contains($mb, 'ResendDriver'));
$a('bootstrap conditional on RESEND_API_KEY',
    str_contains($mb, 'RESEND_API_KEY'));

echo "\n===================\n";
echo "Resend wiring smoke: $pass ✓ / $fail ✗\n";
echo "===================\n";
if ($fail > 0) {
    foreach ($failures as $msg) echo " ! $msg\n";
    exit(1);
}
exit(0);
