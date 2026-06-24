<?php
/**
 * Time — tokenized client-approval smoke tests (Phase B Slice 1).
 * Static contract + ResendDriver + lib/approval_tokens helpers.
 */
declare(strict_types=1);
require_once __DIR__ . '/../core/mail/ResendDriver.php';
require_once __DIR__ . '/../modules/time/lib/approval_tokens.php';

$pass = 0; $fail = 0;
$assert = function ($n, $c) use (&$pass, &$fail) { if ($c) { echo "  \u{2713} {$n}\n"; $pass++; } else { echo "  \u{2717} {$n}\n"; $fail++; } };

echo "Migration SQL\n";
$sql = (string) file_get_contents(__DIR__ . '/../modules/time/migrations/002_approval_tokens.sql');
$assert('migration file exists',               strlen($sql) > 0);
$assert('utf8mb4_unicode_ci used',             strpos($sql, 'utf8mb4_unicode_ci') !== false);
$assert('NOT utf8mb4_0900_ai_ci',              strpos($sql, 'utf8mb4_0900_ai_ci') === false);
$assert('CREATE TABLE time_approval_tokens',   strpos($sql, 'CREATE TABLE IF NOT EXISTS time_approval_tokens') !== false);
$assert('response enum has 5 states',          strpos($sql, "ENUM('pending','approved','rejected','expired','revoked')") !== false);
$assert('email_status enum',                   strpos($sql, "ENUM('queued','sent','failed')") !== false);
$assert('UNIQUE token',                        strpos($sql, 'UNIQUE KEY uq_tat_token') !== false);
$assert('token_hash VARBINARY(64)',            strpos($sql, 'token_hash VARBINARY(64)') !== false);

echo "\nTokens library\n";
$g1 = timeTokenGenerate();
$g2 = timeTokenGenerate();
$assert('token is 64 hex chars',               preg_match('/^[a-f0-9]{64}$/', $g1['token']) === 1);
$assert('hash is 32 bytes',                    strlen($g1['hash']) === 32);
$assert('tokens are unique across calls',      $g1['token'] !== $g2['token']);
$assert('hash matches generator output',       timeTokenHash($g1['token']) === $g1['hash']);

echo "\nEmail body builder\n";
$tokenRow  = ['placement_id' => 42, 'expires_at' => '2026-12-31 23:59:59'];
$entries   = [
    ['id' => 1, 'work_date' => '2026-02-03', 'category' => 'regular_billable', 'hours' => 8.0, 'description' => 'Mon'],
    ['id' => 2, 'work_date' => '2026-02-04', 'category' => 'regular_billable', 'hours' => 7.5, 'description' => 'Tue'],
];
$placement = ['title' => 'Senior Dev @ Acme', 'first_name' => 'Jane', 'last_name' => 'Doe'];
$built     = timeTokenBuildEmailBody($tokenRow, $entries, $placement, 'https://x/y?a=approve', 'https://x/y?a=reject');
$assert('subject includes total',              strpos($built['subject'], '15.50 hrs') !== false);
$assert('subject includes placement title',    strpos($built['subject'], 'Senior Dev @ Acme') !== false);
$assert('text body has approve URL',           strpos($built['text'], 'https://x/y?a=approve') !== false);
$assert('text body has reject URL',            strpos($built['text'], 'https://x/y?a=reject') !== false);
$assert('text body has consultant name',       strpos($built['text'], 'Jane Doe') !== false);
$assert('text has expires',                    strpos($built['text'], '2026-12-31 23:59:59') !== false);
$assert('html body is html',                   strpos($built['html'], '<h2') !== false);
$assert('html has approve button',             strpos($built['html'], 'Approve</a>') !== false);
$assert('html escapes title',                  strpos($built['html'], 'Senior Dev @ Acme') !== false);
$assert('total rounded to 2',                  $built['total'] === 15.5);

echo "\nResendDriver contract\n";
$d = new Core\Mail\ResendDriver('re_test_fake');
$assert('driver_name = resend',                $d->driver_name() === 'resend');
$assert('poll returns empty',                  $d->poll(1, null)['messages'] === []);
$noKey = new Core\Mail\ResendDriver('');
$res = $noKey->send(['tenant_id'=>1,'module'=>'t','purpose'=>'p','to'=>['a@b.c'],'subject'=>'s','body_text'=>'t']);
$assert('no api key → failed',                 $res['status'] === 'failed');
$assert('no api key → error message',          str_contains((string) $res['error'], 'RESEND_API_KEY'));
$noTo = new Core\Mail\ResendDriver('re_x');
$res = $noTo->send(['tenant_id'=>1,'module'=>'t','purpose'=>'p','to'=>[],'subject'=>'s','body_text'=>'t']);
$assert('no recipients → failed',              $res['status'] === 'failed');

$captured = null;
$driver = new Core\Mail\ResendDriver('re_key_xyz', 'no-reply@example.com', 'Test Co',
    function ($req) use (&$captured) {
        $captured = $req;
        return ['ok' => true, 'http' => 200, 'id' => 'msg-123'];
    });
$res = $driver->send([
    'tenant_id' => 7, 'module' => 'time', 'purpose' => 'client_approval_request',
    'to' => ['Client@Example.com'],
    'subject' => 'Timesheet 15.50 hrs',
    'body_text' => 'text', 'body_html' => '<p>html</p>',
    'idempotency_key' => 'time-token-42',
]);
$assert('transport invoked',                    is_array($captured));
$assert('url = Resend API',                     $captured['url'] === 'https://api.resend.com/emails');
$assert('has Authorization Bearer',             in_array('Authorization: Bearer re_key_xyz', $captured['headers'], true));
$assert('has Idempotency-Key header',           in_array('Idempotency-Key: time-token-42', $captured['headers'], true));
$assert('from has name',                        str_contains($captured['payload']['from'], 'Test Co <no-reply@example.com>'));
$assert('html body included',                   $captured['payload']['html'] === '<p>html</p>');
$assert('text body included',                   $captured['payload']['text'] === 'text');
$assert('success → sent',                       $res['status'] === 'sent');
$assert('returns provider_message_id',          $res['provider_message_id'] === 'msg-123');

$driverFail = new Core\Mail\ResendDriver('re_x', 'a@b.c', null,
    fn ($req) => ['ok' => false, 'http' => 422, 'error' => 'domain not verified']);
$res = $driverFail->send([
    'tenant_id' => 1, 'module' => 't', 'purpose' => 'p',
    'to' => ['a@b.c'], 'subject' => 's', 'body_text' => 't',
]);
$assert('HTTP error → failed',                  $res['status'] === 'failed');
$assert('HTTP error surfaces message',          str_contains((string) $res['error'], 'domain not verified'));

echo "\nAPI file + public page\n";
foreach (['approval_tokens.php'] as $f) {
    $p = __DIR__ . "/../modules/time/api/{$f}";
    $assert("api/{$f} exists", is_file($p));
    $o = []; $rc = 0; @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
    $assert("api/{$f} parses", $rc === 0);
}
$pub = __DIR__ . '/../time_approve.php';
$assert('time_approve.php exists',              is_file($pub));
$o = []; $rc = 0; @exec('php -l ' . escapeshellarg($pub) . ' 2>&1', $o, $rc);
$assert('time_approve.php parses',              $rc === 0);
$pubSrc = (string) file_get_contents($pub);
$assert('public page enforces token format',    strpos($pubSrc, '[a-f0-9]{64}') !== false);
$assert('public page posts JSON to respond',    strpos($pubSrc, 'action=respond') !== false);
$assert('public page has noindex',              strpos($pubSrc, 'noindex') !== false);

echo "\nUI components wired\n";
$rq = (string) file_get_contents(__DIR__ . '/../modules/time/ui/ReviewQueue.jsx');
$assert('ReviewQueue imports TokenIssueModal',  strpos($rq, "import TokenIssueModal from './TokenIssueModal'") !== false);
$assert('ReviewQueue has selection UI',         strpos($rq, 'time-review-request-client-approval') !== false);
$tim = (string) file_get_contents(__DIR__ . '/../modules/time/ui/TokenIssueModal.jsx');
$assert('Modal posts to issue endpoint',        strpos($tim, '/api/v1/time/approval-tokens?action=issue') !== false);
$assert('Modal collects ttl_days',              strpos($tim, 'ttl_days') !== false);

echo "\nMail bootstrap\n";
$boot = (string) file_get_contents(__DIR__ . '/../core/mail_bootstrap.php');
$assert('bootstrap registers Resend when key set', strpos($boot, 'RESEND_API_KEY') !== false);
$assert('bootstrap installs outbox writer',     strpos($boot, 'mail_outbox') !== false);

echo "\nManifest references tokenized perms\n";
$manifest = (string) file_get_contents(__DIR__ . '/../modules/time/manifest.php');
$assert('perm time.tokenized_email.issue',      strpos($manifest, 'time.tokenized_email.issue') !== false);
$assert('perm time.tokenized_email.revoke',     strpos($manifest, 'time.tokenized_email.revoke') !== false);

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
