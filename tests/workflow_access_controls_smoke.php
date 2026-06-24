<?php
/**
 * Smoke: WorkflowEngine API access controls.
 *
 * Locks the P2 rule that workflow detail reads/comments are not tenant-wide:
 * they are visible to workflow participants, current approvers, prior actors,
 * delegates, and admin/auditor oversight roles. State-changing workflow actions
 * remain current-step gated.
 */
declare(strict_types=1);

$ROOT = realpath(__DIR__ . '/..');
require_once "{$ROOT}/core/workflow_engine.php";

$pass = 0;
$fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { echo "  OK  {$msg}\n"; $pass++; }
    else       { echo "  BAD {$msg}\n"; $fail++; }
};

$lint = static function (string $path): bool {
    $out = [];
    $rc = 0;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $rc);
    return $rc === 0;
};

$wf = (string) file_get_contents("{$ROOT}/core/workflow_engine.php");
$api = (string) file_get_contents("{$ROOT}/api/workflow.php");
$doc = (string) file_get_contents("{$ROOT}/docs/PRODUCT_ARCHITECTURE_ALIGNMENT.md");

echo "Workflow access control surface\n";
$a('workflow_engine.php parses', $lint("{$ROOT}/core/workflow_engine.php"));
$a('api/workflow.php parses', $lint("{$ROOT}/api/workflow.php"));
$a('workflowCanViewInstance public helper exists', function_exists('workflowCanViewInstance'));
$a('participant helper exists', str_contains($wf, 'function _workflowCanViewRow('));
$a('payload participant helper exists', str_contains($wf, 'function _workflowPayloadReferencesUser('));
$a('prior actor/delegate helper exists', str_contains($wf, 'function _workflowStepActionsReferenceUser('));

echo "\nAction authority\n";
$a('approve/reject/skip/delegate/escalate assert current approver',
    str_contains($wf, "['approve', 'reject', 'skip', 'delegate', 'escalate']")
    && str_contains($wf, '_workflowAssertCurrentApprover($tenantId, $instance, $currentStepDef, $payload, $userId)'));
$a('empty current-step approver resolution fails closed',
    str_contains($wf, 'no_current_step_approvers')
    && str_contains($wf, 'Workflow step has no current approvers'));
$a('SoD remains limited to approval decisions',
    str_contains($wf, "['approve', 'reject', 'skip']")
    && str_contains($wf, '_workflowEnforceSeparationOfDuties($tenantId, $instance, $currentStepDef, $payload, $userId)'));

echo "\nAPI gates\n";
$detailGate = strpos($api, 'if (!workflowCanViewInstance($tenantId, $instanceId, $ctx)) api_error(\'Forbidden\', 403);');
$detailReturn = strpos($api, "api_ok(['instance' => \$row]);", $detailGate === false ? 0 : $detailGate);
$a('GET detail requires workflowCanViewInstance before response',
    $detailGate !== false && $detailReturn !== false && $detailGate < $detailReturn);
$a('comment action requires workflow visibility',
    str_contains($api, "(string) \$body['action'] === 'comment'")
    && str_contains($api, '!workflowCanViewInstance($tenantId, $instanceId, $ctx)'));
$a('API maps non-approver failures to 403',
    str_contains($api, "str_contains(\$msg, 'not an approver')")
    && str_contains($api, "str_contains(\$msg, 'no current approvers')")
    && str_contains($api, "str_contains(\$msg, 'Separation of duties')")
    && str_contains($api, 'api_error($msg, 403)'));
$a('API maps missing/completed instances to 404/409',
    str_contains($api, "api_error('Instance not found', 404)")
    && str_contains($api, 'api_error($msg, 409)'));

echo "\nPure helper checks\n";
$a('tenant admin can audit workflow detail', _workflowContextCanAudit(['role' => 'tenant_admin']) === true);
$a('external auditor can audit workflow detail', _workflowContextCanAudit(['role' => 'external_auditor']) === true);
$a('ordinary employee is not privileged', _workflowContextCanAudit(['role' => 'employee']) === false);
$a('payload context user participant is recognized',
    _workflowPayloadReferencesUser(['context' => ['prepared_by_user_id' => 42]], 42) === true);
$a('payload source actor requires user actor type',
    _workflowPayloadReferencesUser(['source_actor_type' => 'ai_worker', 'source_actor_id' => 42], 42) === false);
$a('payload source user actor is recognized',
    _workflowPayloadReferencesUser(['source_actor_type' => 'user', 'source_actor_id' => 42], 42) === true);

echo "\nDocs\n";
$a('alignment doc states participant-scoped workflow detail',
    str_contains($doc, 'Workflow detail reads are participant-scoped'));
$a('alignment doc states delegate/escalate current-step authority',
    str_contains($doc, 'delegate and') && str_contains($doc, 'current-step authority'));

echo "\nWorkflow access controls smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
