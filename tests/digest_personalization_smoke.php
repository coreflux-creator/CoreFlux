<?php
/**
 * Smoke: digest personalization — "Pending for you" nudge.
 *
 * Verifies:
 *   - aiAgentDigestRecipientCounts() resolves the recipient → user_id and
 *     returns AP-approval + workflow-task pending counts (or zeros on any
 *     SQL/schema error so the digest never breaks).
 *   - aiAgentBuildDigestHtml() renders a personalized banner + plain-text
 *     line when ctaContext is supplied AND there's something pending.
 *   - Empty-queue case: NO banner rendered (don't shout at people with
 *     nothing to do).
 *   - Singular vs plural copy ("1 AP bill" vs "3 AP bills").
 *   - Banner includes a "Review now →" magic-link CTA to /workflow.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};

$src = (string) file_get_contents(__DIR__ . '/../core/ai_agents.php');

echo "core/ai_agents.php — personalization helper\n";
$a('aiAgentDigestRecipientCounts() defined',   str_contains($src, 'function aiAgentDigestRecipientCounts'));
$a('returns ap_approvals_pending key',         str_contains($src, "'ap_approvals_pending'"));
$a('returns workflow_pending key',             str_contains($src, "'workflow_pending'"));
$a('returns pending_total key',                str_contains($src, "'pending_total'"));
$a('deep_link → /workflow',                    str_contains($src, "'deep_link'            => '/workflow'"));
$a('resolves recipient via user_tenants',      str_contains($src, 'JOIN user_tenants ut'));
$a('scoped to tenant only',                    str_contains($src, "ut.tenant_id = :t"));
$a('only active memberships',                  str_contains($src, "ut.status = \\'active\\'"));
$a('AP count uses ap_bill_approvals',          str_contains($src, 'FROM ap_bill_approvals'));
$a('AP count filters state=pending',           str_contains($src, "state = 'pending'"));
$a('AP count safely catches table-missing',    str_contains($src, 'try {') && str_contains($src, 'ap_bill_approvals'));
$a('workflow count via workflowGetPendingForUser',
                                               str_contains($src, 'workflowGetPendingForUser($tenantId, $userId)'));
$a('overall try/catch wraps the whole thing',  preg_match('/function aiAgentDigestRecipientCounts.*?try\s*{/s', $src) === 1);

echo "\nbuilder renders the nudge\n";
$a('reads counts inside builder',              str_contains($src, 'aiAgentDigestRecipientCounts('));
$a('hides nudge when pending_total === 0',     str_contains($src, "\$counts['pending_total'] > 0"));
$a('AP plural copy',                           str_contains($src, "AP bill\" . (\$n === 1 ? '' : 's')"));
$a('workflow plural copy',                     str_contains($src, "workflow task\" . (\$n === 1 ? '' : 's')"));
$a('amber/warning styling on banner',          str_contains($src, '#fef3c7') && str_contains($src, '#f59e0b'));
$a('"Pending for you:" header',                str_contains($src, 'Pending for you'));
$a('"Review now →" CTA link',                  str_contains($src, 'Review now →'));
$a('plain-text fallback line',                 str_contains($src, "Pending for you: \" . strip_tags"));
$a('plain-text Review now URL line',           str_contains($src, "Review now: {\$inboxUrl}"));
$a('mints magic link with /workflow path',     str_contains($src, "\$mintCta(\$counts['deep_link'])"));

echo "\nNon-regression: still works without DB / counts\n";
require_once __DIR__ . '/../core/ai_agents.php';

// No ctaContext → no nudge (existing back-compat).
$noCtx = aiAgentBuildDigestHtml([
    'cfo' => ['ok' => true, 'envelope' => ['content' => 'CFO note.']],
]);
$a('no ctaContext → no "Pending for you"',     stripos($noCtx['html'], 'Pending for you') === false);
$a('no ctaContext → no "Review now"',          stripos($noCtx['html'], 'Review now') === false);

// ctaContext but DB unavailable → graceful zero counts → no banner.
$noDb = aiAgentBuildDigestHtml(
    ['cfo' => ['ok' => true, 'envelope' => ['content' => 'CFO note.']]],
    null, [],
    ['tenant_id' => 1, 'recipient_email' => 'noone@example.com']
);
$a('no DB / no match → empty queue → no banner', stripos($noDb['html'], 'Pending for you') === false);

echo "\n--- " . ($pass + $fail) . " assertions, $fail failed ---\n";
exit($fail ? 1 : 0);
