<?php
/**
 * AP common workflow controls smoke.
 *
 * Locks the alignment contract for AP bill approvals:
 *   - People Graph-backed Workflow steps are the routing source.
 *   - Legacy AP approval rows mirror resolved Workflow approvers.
 *   - Approve/reject decisions are permissioned and preflighted through
 *     WorkflowEngine gating/SoD before legacy rows change.
 *   - Blocked decisions and Workflow-driven next-step sync are auditable.
 */
declare(strict_types=1);

$ROOT = realpath(__DIR__ . '/..');
$pass = 0;
$fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail): void {
    if ($cond) {
        echo "  OK  {$msg}\n";
        $pass++;
    } else {
        echo "  BAD {$msg}\n";
        $fail++;
    }
};
$lint = function (string $rel) use ($ROOT): bool {
    $out = [];
    $rc = 0;
    exec('php -l ' . escapeshellarg("{$ROOT}/{$rel}") . ' 2>&1', $out, $rc);
    return $rc === 0;
};

$router = (string) file_get_contents("{$ROOT}/modules/ap/lib/approval_router.php");
$api = (string) file_get_contents("{$ROOT}/modules/ap/api/bill_approvals.php");
$sync = (string) file_get_contents("{$ROOT}/modules/ap/lib/workflow_sync.php");
$workflow = (string) file_get_contents("{$ROOT}/core/workflow_engine.php");
$manifest = (string) file_get_contents("{$ROOT}/modules/ap/manifest.php");

echo "Files parse\n";
foreach ([
    'modules/ap/lib/approval_router.php',
    'modules/ap/api/bill_approvals.php',
    'modules/ap/lib/workflow_sync.php',
    'core/workflow_engine.php',
] as $rel) {
    $a("php -l {$rel}", $lint($rel));
}

echo "\nAP routing consumes People Graph Workflow controls\n";
$a('router requires domain_people_graph bridge', str_contains($router, "core/domain_people_graph.php"));
$a('router builds workflow-specific steps', str_contains($router, 'function apWorkflowStepsForPeopleGraph('));
$a('workflow steps use domain People Graph approver resolution',
    str_contains($router, "domainPeopleGraphWorkflowApproverResolution('ap', 'bill'"));
$a('workflow definition receives graph-backed steps',
    preg_match('/workflowEnsureDefinition\(\s*\$tenantId,\s*\$defKey,\s*\'ap_bill\',\s*\$policyName,\s*\$workflowSteps/s', $router) === 1);
$a('workflow payload identifies AP bill resource',
    str_contains($router, "'resource_module' => 'ap'") && str_contains($router, "'resource_type' => 'bill'"));
$a('workflow payload carries SoD evidence',
    str_contains($router, "'separation_of_duties_required' => true") && str_contains($router, "'sod_blocked_user_ids' => \$sodBlockedUserIds"));
$a('workflow definitions strip bill-specific ids from step resolution',
    str_contains($router, "unset(\$resolution['resource_id'], \$resolution['object_id']);"));
$a('workflow instance payload carries source actor evidence',
    str_contains($router, "'source_actor_type' => \$actorUserId") && str_contains($router, "'source_actor_id' => \$actorUserId"));
$a('legacy rows mirror resolved current-step workflow approvers',
    str_contains($router, 'workflowResolveCurrentStepApprovers') && str_contains($router, 'apResetLegacyApprovalRowsForStep('));
$a('amount evaluator handles AP total fallback',
    str_contains($router, 'function apBillApprovalAmount(') && str_contains($router, "\$bill['total']"));

echo "\nAP decision endpoint gates through WorkflowEngine\n";
$a('approve/reject require AP approval permission', str_contains($api, "rbac_legacy_require(\$user, 'ap.bill.approve');"));
$a('decision preflight calls workflow mirror in throwing mode',
    str_contains($api, 'apMirrorToWorkflow($tenantId, $billId, $userId, $action, $note, true)'));
$a('blocked workflow decisions are audited',
    str_contains($api, "apAudit('ap.bill.approval_blocked'") && str_contains($manifest, "'ap.bill.approval_blocked'"));
$a('mirror supports strict control mode',
    str_contains($api, 'bool $throwOnFailure = false') && str_contains($api, 'if ($throwOnFailure) throw $e;'));
$a('post-commit mirror is skipped after successful preflight',
    str_contains($api, 'if (!$workflowDecisionApplied)') && str_contains($api, 'apMirrorToWorkflow($tenantId, $billId, $userId, $action, $note);'));
$a('next legacy step prefers current workflow approvers',
    str_contains($api, 'apCurrentWorkflowApproverUserIds($tenantId, $billId)'));

echo "\nWorkflow sync and shared gate behavior\n";
$a('WorkflowEngine gates reject decisions too',
    str_contains($workflow, "['approve', 'reject', 'skip']"));
$a('WorkflowEngine passes payload source actor into People Graph approval policy requests',
    str_contains($workflow, 'elseif (!empty($payload[$key]))') && str_contains($workflow, 'elseif (!empty($payload[\'context\'][$key]))'));
$a('Workflow->AP sync materializes current resolved step',
    str_contains($sync, 'function apSyncPendingWorkflowStepApprovers(')
    && str_contains($sync, 'workflowResolveCurrentStepApprovers($tenantId'));
$a('sync only fills missing pending legacy rows',
    str_contains($sync, 'state = \'pending\'') && str_contains($sync, 'fetchColumn() > 0'));

echo "\nAP common workflow controls smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
