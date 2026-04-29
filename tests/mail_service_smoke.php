<?php
/**
 * MailService smoke test — exercises the skinny 3b surface.
 *
 * Covers:
 *  - Default driver is LogDriver
 *  - Send: validation (tenant_id, recipients, subject)
 *  - Send: persists envelope to log + invokes outbox writer
 *  - Send: unknown driver slug falls back to default
 *  - Poll: empty list with default driver
 *  - OAuth flow stub: provider validation + state token shape
 *  - Custom driver registration
 *
 * Does not require MySQL or any external provider. mail_outbox writes
 * are simulated via an in-memory callable injected through reset().
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/MailService.php';

use Core\MailService;
use Core\Mail\LogDriver;
use Core\Mail\MailDriver;

$pass = 0;
$fail = 0;
$assert = function (string $name, $cond) use (&$pass, &$fail) {
    if ($cond) {
        echo "  ✓ {$name}\n";
        $pass++;
    } else {
        echo "  ✗ {$name}\n";
        $fail++;
    }
};

// In-memory outbox + throwaway log path
$outbox = [];
$nextId = 0;
$writer = function (array $row) use (&$outbox, &$nextId) {
    $nextId++;
    $row['id'] = $nextId;
    $outbox[] = $row;
    return $nextId;
};

$tmpLog = sys_get_temp_dir() . '/cf-mail-test-' . bin2hex(random_bytes(4)) . '.log';
$svc = MailService::reset(new LogDriver($tmpLog), $writer);

echo "Driver wiring\n";
$assert("default driver is 'log'",                 $svc->default_driver_name() === 'log');
$assert("driver('log') resolves",                  $svc->driver('log') instanceof LogDriver);
$assert("driver('m365') is null (not wired yet)",  $svc->driver('m365') === null);

echo "\nValidation\n";
try { $svc->send(0, 'time', 'x', ['a@b.co'], 's', 'b'); $assert("rejects tenant_id=0", false); }
catch (\InvalidArgumentException $e) { $assert("rejects tenant_id=0", true); }

try { $svc->send(7, 'time', 'x', [], 's', 'b'); $assert("rejects empty recipients", false); }
catch (\InvalidArgumentException $e) { $assert("rejects empty recipients", true); }

try { $svc->send(7, 'time', 'x', ['not-an-email'], 's', 'b'); $assert("rejects invalid recipient", false); }
catch (\InvalidArgumentException $e) { $assert("rejects invalid recipient", true); }

try { $svc->send(7, 'time', 'x', ['a@b.co'], '', 'b'); $assert("rejects empty subject", false); }
catch (\InvalidArgumentException $e) { $assert("rejects empty subject", true); }

echo "\nSend (LogDriver) end-to-end\n";
$res = $svc->send(
    7,
    'time',
    'token_approval',
    ['client@acme.com', 'cc@acme.com'],
    'Approve timesheet — Jane Doe — Week of 2026-02-09',
    'Plaintext body',
    '<p>HTML body</p>',
    [101, 102],
    ['from' => 'timesheets@acmestaffing.com', 'reply_to' => 'no-reply@acmestaffing.com']
);
$assert("send returns status=sent",                 ($res['status'] ?? null) === 'sent');
$assert("send returns provider_message_id",         !empty($res['provider_message_id']));
$assert("send returns outbox_id",                   ($res['outbox_id'] ?? null) === 1);
$assert("send reports driver=log",                  ($res['driver'] ?? null) === 'log');

echo "\nOutbox writer received structured row\n";
$assert("1 row written to outbox",                  count($outbox) === 1);
$row = $outbox[0] ?? [];
$assert("row.tenant_id=7",                          ($row['tenant_id'] ?? null) === 7);
$assert("row.module=time",                          ($row['module']    ?? null) === 'time');
$assert("row.purpose=token_approval",               ($row['purpose']   ?? null) === 'token_approval');
$assert("row.from preserved",                       ($row['from_address'] ?? null) === 'timesheets@acmestaffing.com');
$assert("row.to_addresses_json is JSON array",      is_array(json_decode($row['to_addresses_json'] ?? '', true)));
$assert("row.attachments_json is JSON array",       is_array(json_decode($row['attachments_json'] ?? '', true)));
$assert("row.status=sent",                          ($row['status']    ?? null) === 'sent');
$assert("row.driver=log",                           ($row['driver']    ?? null) === 'log');

echo "\nLogDriver wrote to log file\n";
$assert("log file exists",                          is_file($tmpLog));
$lines = is_file($tmpLog) ? array_filter(explode("\n", (string) file_get_contents($tmpLog))) : [];
$assert("log has 1 line",                           count($lines) === 1);
$logged = json_decode($lines[0] ?? '', true) ?: [];
$assert("logged subject matches",                   ($logged['subject'] ?? null) === 'Approve timesheet — Jane Doe — Week of 2026-02-09');
$assert("logged has_html=true",                     ($logged['has_html'] ?? null) === true);
$assert("logged attach_n=2",                        ($logged['attach_n'] ?? null) === 2);

echo "\nDuplicate recipients deduped\n";
$res2 = $svc->send(7, 'time', 'x', ['a@b.co', 'a@b.co', 'c@d.co'], 'subj', 'body');
$row2 = end($outbox);
$decoded = json_decode($row2['to_addresses_json'], true);
$assert("duplicate recipient deduped", count($decoded) === 2);

echo "\nUnknown driver slug falls back to default\n";
$res3 = $svc->send(7, 'time', 'x', ['a@b.co'], 'subj', 'body', null, [], ['driver' => 'doesnotexist']);
$assert("fallback to default driver",               ($res3['driver'] ?? null) === 'log');

echo "\nPoll with default driver returns empty list\n";
$poll = $svc->poll_folder(99);
$assert("poll returns array of messages",           is_array($poll['messages'] ?? null));
$assert("poll returns 0 messages with LogDriver",   count($poll['messages']) === 0);

echo "\nOAuth flow stub\n";
$flow = $svc->start_oauth_flow(7, 'm365', 'inbound');
$assert("oauth stub: provider=m365",                ($flow['provider'] ?? null) === 'm365');
$assert("oauth stub: state token issued",           !empty($flow['state']) && strlen($flow['state']) >= 16);
$assert("oauth stub: tenant_id preserved",          ($flow['tenant_id'] ?? null) === 7);

try { $svc->start_oauth_flow(7, 'icloud', 'inbound'); $assert("rejects unsupported provider", false); }
catch (\InvalidArgumentException $e) { $assert("rejects unsupported provider", true); }

echo "\nCustom driver registration\n";
// Anonymous driver implementing the interface
$mock = new class implements MailDriver {
    public array $sent = [];
    public function poll(int $folderId, ?string $cursor): array {
        return ['messages' => [['id' => 'mock-1']], 'next_cursor' => 'c1'];
    }
    public function send(array $envelope): array {
        $this->sent[] = $envelope;
        return ['provider_message_id' => 'mock-id', 'sent_at' => '2026-02-15 00:00:00', 'status' => 'sent', 'error' => null];
    }
    public function refresh_oauth(int $connectionId): void {}
    public function revoke(int $connectionId): void {}
    public function driver_name(): string { return 'mock'; }
};
$svc->register_driver($mock);
$assert("custom driver registered",                 $svc->driver('mock') === $mock);

$res4 = $svc->send(7, 'time', 'x', ['a@b.co'], 'subj', 'body', null, [], ['driver' => 'mock']);
$assert("custom driver invoked on send",            count($mock->sent) === 1);
$assert("custom driver provider_message_id used",   ($res4['provider_message_id'] ?? null) === 'mock-id');

$poll2 = $svc->poll_folder(1, 'mock');
$assert("custom driver poll returns messages",      count($poll2['messages']) === 1);
$assert("custom driver poll returns cursor",        ($poll2['next_cursor'] ?? null) === 'c1');

// Cleanup
@unlink($tmpLog);

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
