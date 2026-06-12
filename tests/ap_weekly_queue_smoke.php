<?php
/**
 * Smoke: AP Weekly Queue + Approve-by-email + PWP NET90 carry + digest blurb.
 *
 * Static contract checks (no live DB). Verifies the full wire-up:
 *   - PWP-defaulting vendors get NET90 due_date + payment_terms='PWP' + pwp_status='awaiting_ar'
 *     on bills built via apBuildDraftFromBundle()
 *   - apEmailApproval* family is wired correctly (mint + consume + body builder)
 *   - /api/ap/approve_by_email.php public endpoint exists, is noindexed, validates token format
 *   - /api/ap/weekly_queue.php exposes GET + 2 POST actions with RBAC
 *   - Sunday cron exists and is wired
 *   - apBillApprovalNotify now uses one-tap tokens (replaces the old plain "Open the approvals inbox" link)
 *   - AI digest exposes aiAgentPwpReleasedBlurb*() helpers and embeds them in the HTML
 *   - APModule.jsx routes /weekly-queue
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};

echo "AP lib: NET90 carry for PWP vendors\n";
$apLib = (string) file_get_contents(__DIR__ . '/../modules/ap/lib/ap.php');
$a('apBuildDraftFromBundle loads vendor PWP flags', str_contains($apLib, 'COALESCE(default_pwp, 0) AS default_pwp'));
$a('PWP carry constant = 90 days',                  str_contains($apLib, '$pwpNetDays = 90'));
$a('billDue = +90 days when vendor is PWP',         str_contains($apLib, '$isPwp ? date(\'Y-m-d\', strtotime("+{$pwpNetDays} days")) : $dueDate'));
$a("bill stamps payment_terms='PWP'",               str_contains($apLib, "'payment_terms' => \$isPwp ? 'PWP' : null"));
$a("bill stamps pwp_status='awaiting_ar'",          str_contains($apLib, "'pwp_status'    => \$isPwp ? 'awaiting_ar' : 'not_pwp'"));
$a('falls back when default_pwp column missing',    str_contains($apLib, "/* default_pwp column not migrated yet"));

echo "\ncore/email_approval.php (token mint + consume)\n";
$emailLib = (string) file_get_contents(__DIR__ . '/../core/email_approval.php');
require_once __DIR__ . '/../core/email_approval.php';
foreach (['apEmailApprovalMint','apEmailApprovalConsume','apEmailApprovalBodyHtml','apEmailApprovalBaseUrl'] as $fn) {
    $a("fn: {$fn}",                                 function_exists($fn));
}
$a('mint actions = [approve, reject]',              str_contains($emailLib, "['approve', 'reject']"));
$a('72h default TTL',                               str_contains($emailLib, 'int $ttlHours = 72'));
$a('consume validates subject_type=ap_bill',       str_contains($emailLib, "(\$row['subject_type'] ?? '') !== 'ap_bill'"));
$a('consume blocks earlier-step bypass',            str_contains($emailLib, "WHERE tenant_id = :t AND bill_id = :b AND step_no < :s AND state = 'pending'"));
$a('consume delegates decision to WorkflowEngine bridge', str_contains($emailLib, 'apWorkflowActBillApproval($tenantId, $bill, $userId, $action, $note, true)'));
$a('consume audits workflow-blocked email decisions', str_contains($emailLib, "apAudit('ap.bill.approval_blocked'") && str_contains($emailLib, "'via' => 'email_approval'"));
$a('consume never directly flips AP bill state',    !str_contains($emailLib, "UPDATE ap_bills SET status = 'disputed'") && !str_contains($emailLib, "UPDATE ap_bills SET status = 'approved'"));
$a('body builder has Approve + Reject buttons',     str_contains($emailLib, '>Approve in one click<') && str_contains($emailLib, '>Reject<'));
$a('body builder warns about 72h expiry',           str_contains($emailLib, 'expire in 72 hours'));

echo "\n/api/ap/approve_by_email.php public endpoint\n";
$pubPath = __DIR__ . '/../api/ap/approve_by_email.php';
$pubSrc  = (string) file_get_contents($pubPath);
$a('endpoint file exists',                          is_file($pubPath));
$a('endpoint parses',                               (int) shell_exec('php -l ' . escapeshellarg($pubPath) . ' >/dev/null 2>&1; echo $?') === 0);
$a('endpoint noindexed',                            str_contains($pubSrc, "header('X-Robots-Tag: noindex, nofollow')"));
$a('endpoint no-store cache headers',               str_contains($pubSrc, 'no-store') && str_contains($pubSrc, 'must-revalidate'));
$a('endpoint validates 64-hex token format',        str_contains($pubSrc, "preg_match('/^[a-f0-9]{64}\$/'"));
$a('endpoint guards action ∈ {approve, reject}',    str_contains($pubSrc, "['approve', 'reject']"));
$a('endpoint renders HTML receipt page',            str_contains($pubSrc, 'cf_email_approval_render') && str_contains($pubSrc, 'ap-email-approval-receipt'));

echo "\nmodules/ap/lib/weekly_queue.php\n";
$wqLib = (string) file_get_contents(__DIR__ . '/../modules/ap/lib/weekly_queue.php');
require_once __DIR__ . '/../modules/ap/lib/weekly_queue.php';
foreach (['apWeeklyQueueList','apWeeklyQueueBucket','apWeeklyQueueSummary'] as $fn) {
    $a("fn: {$fn}",                                 function_exists($fn));
}
$a('queue includes past_due + next 7 days',         str_contains($wqLib, 'b.due_date <  CURDATE()') && str_contains($wqLib, 'b.due_date <= DATE_ADD(CURDATE(), INTERVAL :n DAY)'));
$a('queue excludes paid/void',                      str_contains($wqLib, 'b.status NOT IN ("paid","void")'));
$a('blocker awaiting_client surfaces AR # + status',str_contains($wqLib, '"Awaiting client payment of invoice {$ar[\'invoice_number\']}'));
$a('blocker missing_hours surfaces bundle status',  str_contains($wqLib, "'missing_hours'") && str_contains($wqLib, 'Source time bundle status'));
$a('blocker needs_review for inbox/pending_review', str_contains($wqLib, "in_array(\$b['status'], ['inbox', 'pending_review']"));
$a('blocker approver_pending for pending_approval', str_contains($wqLib, "'approver_pending'") && str_contains($wqLib, "\$b['status'] === 'pending_approval'"));
$a('bucket splits past_due / due_soon',             str_contains($wqLib, "'past_due' => \$past, 'due_soon' => \$soon"));

echo "\nmodules/ap/api/weekly_queue.php\n";
$apiPath = __DIR__ . '/../modules/ap/api/weekly_queue.php';
$apiSrc  = (string) file_get_contents($apiPath);
$a('api file exists + parses',                      is_file($apiPath) && (int) shell_exec('php -l ' . escapeshellarg($apiPath) . ' >/dev/null 2>&1; echo $?') === 0);
$a("GET requires ap.bill.view",                     preg_match("/\\\$method === 'GET'[\s\S]{0,200}rbac_legacy_require\(\\\$user, 'ap\.bill\.view'\)/", $apiSrc) === 1);
$a("POST ?action=finalize",                         str_contains($apiSrc, "\$method === 'POST' && \$action === 'finalize'"));
$a("POST ?action=send_approver_email",              str_contains($apiSrc, "\$method === 'POST' && \$action === 'send_approver_email'"));
$a('finalize refuses PWP awaiting_ar bills',        str_contains($apiSrc, "Awaiting client payment (PWP) — auto-finalizes when AR clears"));
$a('finalize creates ap_bill_approvals rows',       str_contains($apiSrc, 'INSERT INTO ap_bill_approvals'));
$a('finalize transitions to pending_approval',      str_contains($apiSrc, "UPDATE ap_bills SET status = 'pending_approval'"));
$a('finalize emails first step with tokens',        str_contains($apiSrc, 'apEmailApprovalMint($tenantId, $billId, (int) $a[\'id\']'));
$a('finalize idempotency keyed per (bill, approver, step)', str_contains($apiSrc, "'idempotency_key' => 'ap-approval-' . \$billId . '-' . \$a['id'] . '-' . \$minStep"));

echo "\nscripts/ap_weekly_queue_sunday.php cron\n";
$cronPath = __DIR__ . '/../scripts/ap_weekly_queue_sunday.php';
$cronSrc  = (string) file_get_contents($cronPath);
$a('cron file exists + parses',                     is_file($cronPath) && (int) shell_exec('php -l ' . escapeshellarg($cronPath) . ' >/dev/null 2>&1; echo $?') === 0);
$a('cron iterates tenants with in-scope bills',     str_contains($cronSrc, "SELECT DISTINCT tenant_id FROM ap_bills"));
$a('cron resolves recipients via roles',            str_contains($cronSrc, "'ap_clerk','ap_manager','admin','master_admin'"));
$a('cron schema fallback if user_tenants absent',   str_contains($cronSrc, '/* schema absence — leave invoice list empty */') || str_contains($cronSrc, "Schema fallback"));
$a('subject reports past-due + due-soon counts',    str_contains($cronSrc, "Weekly AP queue — %d past due"));
$a('idempotency key per (tenant, user, day)',       str_contains($cronSrc, "'ap-weekly-queue-' . \$tid . '-' . \$u['id'] . '-' . date('Y-m-d')"));
$a('email links to /modules/ap/weekly-queue',       str_contains($cronSrc, "/#/modules/ap/weekly-queue"));

echo "\napBillApprovalNotify now uses email-approval tokens\n";
$baSrc = (string) file_get_contents(__DIR__ . '/../modules/ap/api/bill_approvals.php');
$a('requires email_approval.php',                   str_contains($baSrc, "require_once __DIR__ . '/../../../core/email_approval.php'"));
$a('mints tokens per approver',                     str_contains($baSrc, 'apEmailApprovalMint($tenantId, $billId,'));
$a('uses apEmailApprovalBodyHtml',                  str_contains($baSrc, 'apEmailApprovalBodyHtml($bill, (string) ($a[\'name\']'));
$a('no more legacy "Open the approvals inbox" link',!str_contains($baSrc, "Open the approvals inbox →"));

echo "\nAI Agent digest: PWP-released-last-week blurb\n";
$aiSrc = (string) file_get_contents(__DIR__ . '/../core/ai_agents.php');
$a('helper aiAgentPwpReleasedBlurb defined',        function_exists('aiAgentPwpReleasedBlurb') || str_contains($aiSrc, 'function aiAgentPwpReleasedBlurb('));
$a('HTML variant present',                          str_contains($aiSrc, 'function aiAgentPwpReleasedBlurbHtml('));
$a('text variant present',                          str_contains($aiSrc, 'function aiAgentPwpReleasedBlurbText('));
$a('queries audit_log for last 7 days',             str_contains($aiSrc, "event = 'ap.bill.pwp.released'") && str_contains($aiSrc, 'INTERVAL 7 DAY'));
$a('embeds HTML blurb in digest body',              str_contains($aiSrc, "aiAgentPwpReleasedBlurbHtml(\$ctaContext['tenant_id'] ?? null)"));
$a('embeds text blurb in digest body',              str_contains($aiSrc, "aiAgentPwpReleasedBlurbText(\$ctaContext['tenant_id'] ?? null)"));
$a('blurb truncates >3 invoices',                   str_contains($aiSrc, "count(\$d['invoice_numbers']) > 3"));

echo "\nReact: APModule.jsx + WeeklyQueue.jsx\n";
$apMod = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/APModule.jsx');
$a('imports WeeklyQueue',                           str_contains($apMod, "import WeeklyQueue from './WeeklyQueue'"));
$a('nav contains Weekly Queue',                     str_contains($apMod, "label: 'Weekly Queue'"));
$a('routes /weekly-queue',                          str_contains($apMod, '<Route path="weekly-queue" element={<WeeklyQueue />}'));

$wqJsx = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/WeeklyQueue.jsx');
$a('WeeklyQueue testid root',                       str_contains($wqJsx, 'data-testid="ap-weekly-queue"'));
$a('hits /modules/ap/api/weekly_queue.php',         str_contains($wqJsx, "/modules/ap/api/weekly_queue.php?lookahead=7"));
$a('has finalize-batch button',                     str_contains($wqJsx, 'ap-weekly-queue-finalize-batch'));
$a('has select-all-ready button',                   str_contains($wqJsx, 'ap-weekly-queue-select-eligible'));
$a('row checkboxes',                                str_contains($wqJsx, 'ap-weekly-row-check-'));
$a('blocker chips colour-coded',                    str_contains($wqJsx, 'BLOCKER_META') && str_contains($wqJsx, 'awaiting_client'));
$a('resend approver email button',                  str_contains($wqJsx, 'ap-weekly-row-resend-'));
$a('summary ribbon',                                str_contains($wqJsx, 'ap-weekly-queue-summary'));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
