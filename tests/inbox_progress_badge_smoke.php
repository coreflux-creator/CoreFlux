<?php
/**
 * Smoke: inbox progress badge + summary endpoint.
 *
 * Verifies:
 *   - GET /api/workflow/inbox_summary.php returns the right shape and
 *     reuses aiAgentDigestRecipientCounts() for AP + workflow counts
 *   - schema-tolerant on workflow_step_actions missing
 *   - InboxProgressBadge.jsx renders pending / inbox-zero / hidden states
 *   - WorkflowInbox.jsx mounts the badge and bumps refreshKey on act()
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};

echo "/api/workflow/inbox_summary.php\n";
$path = __DIR__ . '/../api/workflow/inbox_summary.php';
$src  = (string) file_get_contents($path);
$a('endpoint exists',                          strlen($src) > 200);
$a('PHP parses cleanly',                       (int) shell_exec('php -l ' . escapeshellarg($path) . ' >/dev/null 2>&1; echo $?') === 0);
$a('GET only',                                 str_contains($src, "api_method() !== 'GET'"));
$a('reuses recipient counts helper',           str_contains($src, 'aiAgentDigestRecipientCounts($tenantId, $email)'));
$a('counts cleared in last 24h',               str_contains($src, 'DATE_SUB(NOW(), INTERVAL 24 HOUR)'));
$a('reads workflow_step_actions',              str_contains($src, 'FROM workflow_step_actions'));
$a('filters by actor_user_id',                 str_contains($src, 'actor_user_id = :u'));
$a('try/catch around cleared count',           str_contains($src, "} catch (\\Throwable \$_) { /* legacy schema */ }"));
$a('ETA heuristic 1.5min × pending',           str_contains($src, '$pending * 1.5'));
$a('ETA capped @ 120min',                      str_contains($src, 'min(120,'));
$a('progress_pct = cleared / (cleared+pending)',
                                               str_contains($src, '($cleared / $denom) * 100'));
$a('returns expected keys',                    str_contains($src, "'pending_total'") &&
                                               str_contains($src, "'ap_pending'") &&
                                               str_contains($src, "'workflow_pending'") &&
                                               str_contains($src, "'cleared_today'") &&
                                               str_contains($src, "'eta_minutes'") &&
                                               str_contains($src, "'progress_pct'"));

echo "\nInboxProgressBadge.jsx\n";
$jsx = (string) file_get_contents(__DIR__ . '/../dashboard/src/pages/InboxProgressBadge.jsx');
$a('default export',                           str_contains($jsx, 'export default function InboxProgressBadge'));
$a('hits inbox_summary endpoint',              str_contains($jsx, '/api/workflow/inbox_summary.php'));
$a('uses refreshKey for cache-bust',           str_contains($jsx, "useApi('/api/workflow/inbox_summary.php?_=' + (refreshKey || 0))"));
$a('hides when nothing pending+cleared',       str_contains($jsx, 'pending === 0 && cleared === 0'));
$a('inbox-zero celebration state',             str_contains($jsx, 'data-state="zero"'));
$a('inbox-zero copy',                          str_contains($jsx, 'Inbox zero today'));
$a('pending state',                            str_contains($jsx, 'data-state="pending"'));
$a('badge test-id',                            str_contains($jsx, 'data-testid="inbox-progress-badge"'));
$a('pending count test-id',                    str_contains($jsx, 'data-testid="inbox-progress-pending"'));
$a('eta test-id',                              str_contains($jsx, 'data-testid="inbox-progress-eta"'));
$a('progress bar test-id',                     str_contains($jsx, 'data-testid="inbox-progress-bar"'));
$a('cleared today subtext',                    str_contains($jsx, 'data-testid="inbox-progress-cleared"'));
$a('AP plural-aware copy',                     str_contains($jsx, "data.ap_pending === 1 ? '' : 's'"));
$a('Workflow plural-aware copy',               str_contains($jsx, "data.workflow_pending === 1 ? '' : 's'"));

echo "\nWorkflowInbox.jsx wiring\n";
$wf = (string) file_get_contents(__DIR__ . '/../dashboard/src/pages/WorkflowInbox.jsx');
$a('imports InboxProgressBadge',               str_contains($wf, "import InboxProgressBadge from './InboxProgressBadge'"));
$a('renders <InboxProgressBadge refreshKey={badgeKey}/>',
                                               str_contains($wf, '<InboxProgressBadge refreshKey={badgeKey} />'));
$a('badgeKey state declared',                  str_contains($wf, 'const [badgeKey, setBadgeKey] = useState(0)'));
$a('act() bumps badgeKey on success',          str_contains($wf, 'setBadgeKey(k => k + 1)'));

echo "\n--- " . ($pass + $fail) . " assertions, $fail failed ---\n";
exit($fail ? 1 : 0);
