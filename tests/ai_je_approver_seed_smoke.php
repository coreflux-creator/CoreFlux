<?php
/**
 * Smoke — JE approver demo seed + workflow helpers (P1, 2026-02).
 *
 * Locks the seed/helper surface so the canonical "open a gate-
 * compatible JE-promotion approval" path stays callable. Pairs with
 * the post-approval gate smoke (ai_je_post_approval_gates_smoke.php).
 *
 *  - scripts/seed_je_approver_demo.php  (CLI seed)
 *  - core/ai/workflows/engine.php       — adds
 *      workflowRequireJePromotionApproval()  (from inside a node)
 *      workflowOpenJePromotionApproval()     (out-of-graph, direct)
 *
 * Static-analyzer only — no DB. Pure-function probes exercise the
 * stable parts of the helpers (signature, payload shape via
 * accountingApprovalRequestPayloadForJe is covered by the
 * post-approval gate smoke).
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ──────────────────────────────────────────────────────────────────────
// 1) engine.php helpers.
// ──────────────────────────────────────────────────────────────────────
echo "\n── core/ai/workflows/engine.php helpers ──\n";
$eng = (string) file_get_contents('/app/core/ai/workflows/engine.php');

$a('workflowRequireJePromotionApproval declared',
    $c($eng, 'function workflowRequireJePromotionApproval(int $tenantId, int $jeId, ?string $assignedRole = \'accounting_reviewer\'): void'));
$a('  pulls payload via accountingApprovalRequestPayloadForJe',
    $c($eng, '$payload = accountingApprovalRequestPayloadForJe($tenantId, $jeId)'));
$a('  throws WorkflowAwaitingApproval with type post_journal_entry',
    $c($eng, "throw new WorkflowAwaitingApproval('post_journal_entry', 4, \$payload, \$assignedRole)"));

$a('workflowOpenJePromotionApproval declared',
    $c($eng, 'function workflowOpenJePromotionApproval('));
$a('  signature carries tenant_id, run_id, je_id, assigned_role, node',
    $c($eng, 'int $tenantId,')
    && $c($eng, 'string $runId,')
    && $c($eng, 'int $jeId,')
    && $c($eng, "?string \$assignedRole = 'accounting_reviewer',")
    && $c($eng, "string \$node = 'await_je_approval'"));
$a('  delegates to _workflowInsertApproval (reuses canonical INSERT)',
    $c($eng, 'return _workflowInsertApproval($tenantId, $runId, $node, $w);'));
$a('  uses the same gate-compatible payload helper',
    preg_match('/function\s+workflowOpenJePromotionApproval\b.*?accountingApprovalRequestPayloadForJe/s', $eng) === 1);

$a('both helpers require post_approval_gates.php',
    substr_count($eng, "require_once __DIR__ . '/../../accounting/post_approval_gates.php';") >= 2);

// php -l clean.
exec('php -l /app/core/ai/workflows/engine.php 2>&1', $out1, $rc1);
$a('engine.php passes php -l',                       $rc1 === 0);

// ──────────────────────────────────────────────────────────────────────
// 2) Seed script structure.
// ──────────────────────────────────────────────────────────────────────
echo "\n── scripts/seed_je_approver_demo.php ──\n";
$path = '/app/scripts/seed_je_approver_demo.php';
$src  = (string) file_get_contents($path);
$a('file exists',                                    $src !== '');
$a('declares strict_types',                          $c($src, 'declare(strict_types=1)'));
$a('parses --tenant / --entity / --user / --help getopt',
    $c($src, "getopt('', ['tenant::', 'entity::', 'user::', 'help'])"));
$a('refuses tenant <= 0',                            $c($src, '$tenantId <= 0'));

// Step 1 — pick / create draft JE.
$a('reuses newest existing draft JE',
    $c($src, "WHERE tenant_id = :t AND status = 'draft'")
    && $c($src, 'ORDER BY id DESC LIMIT 1'));
$a('creates 2-line balanced draft when none exists (status=draft)',
    $c($src, "'status'       => 'draft',")
    && $c($src, "accountingPostJe(\$tenantId, ["));
$a('refuses closed/soft_closed period before creating',
    $c($src, "in_array(\$period['status'], ['closed', 'soft_closed']"));
$a('uses first 2 active+postable accounts for the synthetic JE',
    $c($src, 'WHERE tenant_id = :t AND active = 1 AND is_postable = 1'));

// Step 2 — synthetic workflow_runs row.
$a('inserts workflow_runs row with graph_name=manual_je_post_demo',
    $c($src, "'manual_je_post_demo'"));
$a('workflow_runs status seeded as awaiting_approval',
    $c($src, "'awaiting_approval'"));
$a('workflow_runs.current_node = await_je_approval',
    $c($src, "'await_je_approval'"));

// Step 3 — open the approval.
$a('calls workflowOpenJePromotionApproval',
    $c($src, 'workflowOpenJePromotionApproval($tenantId, $runId, $jeId'));
$a('uses accounting_reviewer as the assigned role',
    $c($src, "'accounting_reviewer'"));

// Step 4 — playbook output.
$a('prints reviewer URL',                            $c($src, '/admin/ai-gateway/reviewer'));
$a('prints the canonical aiToolInvoke call shape',
    $c($src, "coreflux.post_approved_journal_entry")
    && $c($src, "['je_id' => \$jeId]"));
$a('prints the snapshot draft_hash for the operator',
    $c($src, "(string) (\$payload['draft_hash']"));
$a('prints the 6-rule gate sanity check',
    $c($src, 'rule 1 (binding)')
    && $c($src, 'rule 2 (single-use)')
    && $c($src, 'rule 3 (SoD)')
    && $c($src, 'rule 4 (expires_at)')
    && $c($src, 'rule 5 (audit trail)')
    && $c($src, 'rule 6 (mutation guard)'));
$a('exits 0 on success, 1 on preflight, 2 on exception',
    $c($src, 'exit(0)') && $c($src, 'exit(1)') && $c($src, 'exit(2)'));
$a('requires the post-approval gates helper',
    $c($src, "require_once __DIR__ . '/../core/accounting/post_approval_gates.php'"));
$a('requires the workflow engine for the helper',
    $c($src, "require_once __DIR__ . '/../core/ai/workflows/engine.php'"));

// php -l clean.
exec("php -l $path 2>&1", $out2, $rc2);
$a('seed script passes php -l',                      $rc2 === 0);

// ──────────────────────────────────────────────────────────────────────
// 3) Regression — gate file still exposes the payload helper used
//    by both the engine helpers and the seed script.
// ──────────────────────────────────────────────────────────────────────
echo "\n── regression: post_approval_gates helper still exposed ──\n";
$gates = (string) file_get_contents('/app/core/accounting/post_approval_gates.php');
$a('accountingApprovalRequestPayloadForJe still declared',
    $c($gates, 'function accountingApprovalRequestPayloadForJe(int $tenantId, int $jeId): array'));
$a('payload still includes je_id + draft_hash + snapshot_at',
    $c($gates, "'je_id'       => \$jeId")
    && $c($gates, "'draft_hash'  => accountingComputeDraftHash(\$tenantId, \$jeId)")
    && $c($gates, "'snapshot_at' => date('c')"));

// ──────────────────────────────────────────────────────────────────────
echo "\n=========================================\n";
echo "JE approver seed smoke: $pass ✓ / $fail ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
