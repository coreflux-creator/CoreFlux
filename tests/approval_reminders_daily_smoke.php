<?php
/**
 * Smoke: scripts/approval_reminders_daily.php
 *
 * Static contract checks for the daily approval reminder cron:
 *   - walks every active tenant
 *   - reuses aiAgentDigestRecipientCounts() for per-recipient pending counts
 *   - skips when pending_total is zero (no email to no-pending users)
 *   - mints magic-link CTA with 24-hour TTL
 *   - idempotent via tenant_provisioning_log entries
 *   - one email per (user, tenant) per ~20 hours max
 *   - sends through Core\MailService (cf_tenant_mail_sender)
 *   - exit code reflects failures
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};

$path = __DIR__ . '/../scripts/approval_reminders_daily.php';
$src  = (string) file_get_contents($path);

echo "scripts/approval_reminders_daily.php\n";
$a('file exists',                              strlen($src) > 200);
$a('PHP parses cleanly',                       (int) shell_exec('php -l ' . escapeshellarg($path) . ' >/dev/null 2>&1; echo $?') === 0);
$a('walks active tenants',                     str_contains($src, 'is_active = 1'));
$a('iterates user_tenants per tenant',         str_contains($src, 'JOIN user_tenants ut ON ut.user_id = u.id'));
$a('only active memberships',                  str_contains($src, "ut.status = \\'active\\'"));
$a('uses recipient counts helper',             str_contains($src, 'aiAgentDigestRecipientCounts($tenantId, $email)'));
$a('skips users with zero pending',            str_contains($src, "\$counts['pending_total'] <= 0"));

echo "\nIdempotency\n";
$a('tracks last-sent via tenant_provisioning_log',
                                               str_contains($src, "FROM tenant_provisioning_log") &&
                                               str_contains($src, "action = 'approval_reminder'"));
$a('20h cool-down before re-sending',          str_contains($src, '20 * 3600'));
$a('marks sent after success',                 str_contains($src, 'function _approvalReminderMarkSent'));
$a('mark_sent is schema-tolerant (try/catch)', preg_match('/_approvalReminderMarkSent.*?try\s*{/s', $src) === 1);

echo "\nMagic link issuance\n";
$a('mints with 24h TTL (1440 min)',            str_contains($src, '24 * 60'));
$a('passes recipient email',                   str_contains($src, 'magicLinkIssue('));
$a('fallback when mint fails',                 str_contains($src, "fall back to passwordful login"));
$a('uses /workflow deep-link by default',      str_contains($src, "\$counts['deep_link'] ?? '/workflow'"));

echo "\nEmail composition\n";
$a('sends through cf_tenant_mail_sender',      str_contains($src, "cf_tenant_mail_sender(\$tenantId, 'approvals')"));
$a('subject reflects pending count',           str_contains($src, '" approval"') && str_contains($src, "(count(\$parts) === 1 ? '' : 's')"));
$a('HTML CTA uses Review now →',               str_contains($src, 'Review now →'));
$a('plain-text body has fallback URL',         str_contains($src, 'Review now (link expires in 24 hours)'));
$a('AP plural copy',                           str_contains($src, "AP bill\" . (\$apN === 1 ? '' : 's')"));
$a('workflow plural copy',                     str_contains($src, "workflow task\" . (\$wfN === 1 ? '' : 's')"));
$a('mentions tenant name in subject',          str_contains($src, '"[" . $tenantName . "] "'));

echo "\nExit code\n";
$a('exits non-zero on send failures',          str_contains($src, 'exit($totalFailed > 0 ? 1 : 0)'));
$a('logs run summary to stdout',               str_contains($src, "[approval-reminders] sent=") &&
                                               str_contains($src, 'totalSkipped'));

echo "\n--- " . ($pass + $fail) . " assertions, $fail failed ---\n";
exit($fail ? 1 : 0);
