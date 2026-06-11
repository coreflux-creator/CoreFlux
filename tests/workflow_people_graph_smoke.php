<?php
/**
 * Workflow -> People Graph routing smoke.
 *
 * Static contract for the P2 adoption slice: WorkflowEngine remains backward
 * compatible with approver_user_ids, but can resolve approvers dynamically from
 * People Graph by responsibility, approval policy, role, relationship, named
 * actor, or manager chain.
 */

declare(strict_types=1);

$ROOT = realpath(__DIR__ . '/..');
$pass = 0;
$fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { echo "  OK  {$msg}\n"; $pass++; }
    else       { echo "  BAD {$msg}\n"; $fail++; }
};

$wf = (string) file_get_contents("{$ROOT}/core/workflow_engine.php");
$docs = (string) file_get_contents("{$ROOT}/docs/PEOPLE_GRAPH.md");
$arch = (string) file_get_contents("{$ROOT}/docs/PRODUCT_ARCHITECTURE_ALIGNMENT.md");

echo "Workflow engine People Graph contract\n";
$out = [];
$rc = 0;
exec('php -l ' . escapeshellarg("{$ROOT}/core/workflow_engine.php") . ' 2>&1', $out, $rc);
$a('workflow_engine.php parses', $rc === 0);
$a('requires people_graph.php', str_contains($wf, "require_once __DIR__ . '/people_graph.php';"));
$a('exports workflowResolveCurrentStepApprovers', str_contains($wf, 'function workflowResolveCurrentStepApprovers('));
$a('declares _workflowResolveStepApproverUserIds', str_contains($wf, 'function _workflowResolveStepApproverUserIds('));
$a('push path resolves dynamic approvers', preg_match('/function _workflowPushApprovers[\s\S]+_workflowResolveStepApproverUserIds/', $wf) === 1);
$a('inbox path resolves dynamic approvers', preg_match('/function workflowGetPendingForUser[\s\S]+_workflowResolveStepApproverUserIds/', $wf) === 1);
$a('explicit approver_user_ids fallback preserved', str_contains($wf, "fallback_approver_user_ids") && str_contains($wf, "approver_user_ids"));

echo "\nResolution strategies\n";
$a('supports approval_policy via peopleGraphResolveApprovers', str_contains($wf, "\$strategy === 'approval_policy'") && str_contains($wf, 'peopleGraphResolveApprovers('));
$a('supports responsibility via peopleGraphResolve', str_contains($wf, "\$strategy === 'responsibility'") && str_contains($wf, 'peopleGraphResolve('));
$a('supports named_actor', str_contains($wf, "\$strategy === 'named_actor'"));
$a('supports role lookup', str_contains($wf, "\$strategy === 'role'") && str_contains($wf, "peopleGraphFindByKey('people_graph_roles'"));
$a('supports relationship lookup', str_contains($wf, "\$strategy === 'relationship'") && str_contains($wf, 'peopleGraphListRelationships('));
$a('supports manager_chain through reports_to', str_contains($wf, "\$strategy === 'manager_chain'") && str_contains($wf, "'reports_to'"));
$a('maps People Graph teams to users', str_contains($wf, 'people_graph_team_memberships'));
$a('maps People Graph roles to users', str_contains($wf, 'people_graph_role_assignments'));
$a('emits workflow.people_graph_resolved audit', str_contains($wf, "'workflow.people_graph_resolved'"));

echo "\nDocs\n";
$a('People Graph docs mention Workflow routing', str_contains($docs, 'Workflow approver routing and escalation'));
$a('Architecture docs say workflows consume People Graph', str_contains($arch, 'Workflow Graph') && str_contains($arch, 'People Graph'));

echo "\nWorkflow People Graph smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
