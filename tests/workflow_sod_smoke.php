<?php
/**
 * Workflow separation-of-duties smoke.
 *
 * Static and pure-helper coverage for the workflow guard. No DB required.
 */

declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
require_once $root . '/core/workflow_engine.php';

$pass = 0;
$fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { echo "  OK  {$msg}\n"; $pass++; }
    else       { echo "  BAD {$msg}\n"; $fail++; }
};

$src = (string) file_get_contents($root . '/core/workflow_engine.php');

echo "Workflow SoD guard contract\n";
$out = [];
$rc = 0;
exec('php -l ' . escapeshellarg($root . '/core/workflow_engine.php') . ' 2>&1', $out, $rc);
$a('workflow_engine.php parses', $rc === 0);
$a('workflowAct asserts current approver', str_contains($src, '_workflowAssertCurrentApprover($tenantId, $instance, $currentStepDef, $payload, $userId)'));
$a('workflowAct enforces SoD before action insert',
    strpos($src, '_workflowEnforceSeparationOfDuties($tenantId, $instance, $currentStepDef, $payload, $userId)')
    < strpos($src, 'INSERT INTO workflow_step_actions'));
$a('approver block audit exists', str_contains($src, "'workflow.approver_blocked'"));
$a('sod block audit exists', str_contains($src, "'workflow.sod_blocked'"));
$a('sod policy-required audit exists', str_contains($src, "'workflow.sod_policy_required'"));

echo "\nPolicy and flag detection\n";
foreach ([
    'separation_of_duties_required',
    'requires_separation_of_duties',
    'sod_required',
    'two_eye_required',
] as $flag) {
    $a("supports {$flag}", str_contains($src, "'{$flag}'"));
}
$a('approval policy SoD is checked through People Graph',
    str_contains($src, '_workflowApprovalPolicyRequiresSeparationOfDuties')
    && str_contains($src, 'peopleGraphResolveApprovers($tenantId'));
$a('policy rule separation flag inspected', str_contains($src, "\$rule['separation_of_duties_required']"));

echo "\nBlocker extraction\n";
$blockers = _workflowSeparationOfDutiesBlockedUsers(
    ['started_by_user_id' => 7],
    [
        'sod_blocked_user_ids' => [8],
        'approver_resolution' => ['source_actor_type' => 'user', 'source_actor_id' => 9],
    ],
    [
        'created_by_user_id' => 10,
        'context' => ['prepared_by_user_id' => 11],
        'sod' => ['blocked_user_ids' => [12]],
        'separation_of_duties' => ['requester_user_id' => 13],
    ]
);
foreach ([7, 8, 9, 10, 11, 12, 13] as $uid) {
    $a("blocked user {$uid} captured", isset($blockers[$uid]));
}
$a('blocker sources are retained', in_array('payload.context.prepared_by_user_id', $blockers[11] ?? [], true));

echo "\nPure flag helper\n";
$instance = ['id' => 99, 'subject_type' => 'ap_bill', 'subject_id' => 44, 'current_step' => 1];
$a('explicit step flag requires SoD',
    _workflowStepRequiresSeparationOfDuties(1, $instance, ['separation_of_duties_required' => true], []) === true);
$a('payload flag requires SoD',
    _workflowStepRequiresSeparationOfDuties(1, $instance, [], ['sod_required' => 'true']) === true);
$a('context flag requires SoD',
    _workflowStepRequiresSeparationOfDuties(1, $instance, [], ['context' => ['two_eye_required' => 1]]) === true);

echo "\nWorkflow SoD smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
