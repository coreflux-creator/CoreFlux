<?php
/**
 * Smoke — Multi-level approval chain advancement (P1.7).
 *
 * Spec re-audit decision: "Multi-level approval chain must
 * actually fire (not stored-only). Rules evaluate by level; each
 * level gates the next."
 *
 * Previously the AP approval API advanced legacy steps directly. The aligned
 * path is now: WorkflowEngine advances the current workflow step, then AP
 * workflow subject sync materializes the mirrored legacy rows for the newly
 * current step.
 *
 * Fix:
 *   - workflow_engine.php checks quorum and advances workflow_instances.
 *   - workflow_sync.php resolves current Workflow approvers and INSERTs the
 *     mirrored ap_bill_approvals rows for the active step.
 *   - Bill status flips to approved only when WorkflowEngine completes the
 *     full workflow instance.
 *   - approval_router.php now explicitly sets step_no=1 on step 1
 *     so advancement reads a canonical step number.
 *
 * Asserts source-level wire-up (live DB exercise belongs in the
 * existing approval-routing functional smokes).
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$ROOT = realpath(__DIR__ . '/..');
$ba  = (string) file_get_contents("{$ROOT}/modules/ap/api/bill_approvals.php");
$rtr = (string) file_get_contents("{$ROOT}/modules/ap/lib/approval_router.php");
$sync = (string) file_get_contents("{$ROOT}/modules/ap/lib/workflow_sync.php");
$engine = (string) file_get_contents("{$ROOT}/core/workflow_engine.php");

echo "\n1. Router seeds step 1 with explicit step_no=1\n";
$a('router INSERT includes step_no column',
    str_contains($rtr, '(tenant_id, bill_id, approver_user_id, step_no, state, created_at)'));
$a('router INSERT sets step_no=1 literal',
    str_contains($rtr, "VALUES (:t, :b, :u, 1, 'pending', NOW())"));

echo "\n2. WorkflowEngine advances the common approval chain\n";
$a('WorkflowEngine checks current-step approval quorum',
    str_contains($engine, "action IN ('approve','skip')")
    && str_contains($engine, '$approved < $quorum'));
$a('WorkflowEngine advances workflow_instances.current_step',
    str_contains($engine, 'SET current_step = :s')
    && str_contains($engine, "'workflow.advanced'"));
$a('WorkflowEngine subject sync fires after step advancement',
    str_contains($engine, '_workflowSubjectSync($tenantId')
    && str_contains($engine, 'WORKFLOW_STATUS_PENDING'));


echo "\n3. AP sync materialises the newly current workflow step\n";
$a('AP sync exposes pending-step mirror helper',
    str_contains($sync, 'function apSyncPendingWorkflowStepApprovers('));
$a('AP sync reads pending ap_bill workflow current_step',
    str_contains($sync, "subject_type = 'ap_bill'")
    && str_contains($sync, 'current_step'));
$a('AP sync skips duplicate pending rows for the active step',
    str_contains($sync, "WHERE tenant_id = :t AND bill_id = :b AND step_no = :s AND state = 'pending'")
    && str_contains($sync, 'fetchColumn() > 0'));
$a('AP sync resolves current workflow approvers',
    str_contains($sync, 'workflowResolveCurrentStepApprovers($tenantId, (int) $row[\'id\'])'));
$a('AP sync inserts mirrored rows with workflow current step_no',
    str_contains($sync, "VALUES (:t, :b, :u, :s, 'pending', NOW())")
    && str_contains($sync, "'s' => \$stepNo"));


echo "\n4. Bill only flips to approved when WorkflowEngine completes the chain\n";
$a('Workflow sync gates final bill update on approved instance status',
    str_contains($sync, "if (\$instanceStatus === 'approved')")
    && str_contains($sync, "SET status = 'approved'"));
$a('bill_approvals API delegates decisions and does not approve bills directly',
    str_contains($ba, 'apWorkflowActBillApproval($tenantId, $bill, $userId, $action, $note, true)')
    && !str_contains($ba, "UPDATE ap_bills SET status = 'approved'"));


echo "\n5. Per-step insert errors do not break the chain\n";
$a('individual approver insert wrapped in try/catch',
    str_contains($sync, '} catch (\Throwable $_) { /* duplicate / schema drift: non-fatal */ }'));
echo "\n6. PHP syntax\n";
foreach ([
    "{$ROOT}/modules/ap/api/bill_approvals.php",
    "{$ROOT}/modules/ap/lib/approval_router.php",
    "{$ROOT}/modules/ap/lib/workflow_sync.php",
    "{$ROOT}/core/workflow_engine.php",
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $a("php -l {$f}", $rc === 0, implode("\n", $out));
}

echo "\n=========================================\n";
echo "Multi-level approval chain (P1.7) smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
