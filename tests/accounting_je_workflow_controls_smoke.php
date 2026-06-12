<?php
/**
 * Accounting JE workflow controls smoke.
 *
 * Locks the alignment rule that manual JEs are drafted in Accounting, approved
 * by WorkflowGraph with People Graph routing + SoD, and only then promoted to
 * posted state by a separate posting permission.
 */
declare(strict_types=1);

$ROOT = realpath(__DIR__ . '/..');
$pass = 0;
$fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { echo "  OK  {$msg}\n"; $pass++; }
    else       { echo "  BAD {$msg}\n"; $fail++; }
};
$lint = function (string $rel) use ($ROOT): bool {
    $out = [];
    $rc = 0;
    exec('php -l ' . escapeshellarg("{$ROOT}/{$rel}") . ' 2>&1', $out, $rc);
    return $rc === 0;
};

$engine = (string) file_get_contents("{$ROOT}/core/workflow_engine.php");
$workflow = (string) file_get_contents("{$ROOT}/modules/accounting/lib/workflow.php");
$sync = (string) file_get_contents("{$ROOT}/modules/accounting/lib/workflow_sync.php");
$lib = (string) file_get_contents("{$ROOT}/modules/accounting/lib/accounting.php");
$api = (string) file_get_contents("{$ROOT}/modules/accounting/api/journal_entries.php");
$manifest = (string) file_get_contents("{$ROOT}/modules/accounting/manifest.php");
$mig1 = (string) file_get_contents("{$ROOT}/modules/accounting/migrations/001_init.sql");
$mig24 = (string) file_get_contents("{$ROOT}/modules/accounting/migrations/024_je_workflow_controls.sql");
$bootstrap = (string) file_get_contents("{$ROOT}/core/api_bootstrap.php");
$legacyMap = (string) file_get_contents("{$ROOT}/core/rbac/legacy_map.php");

echo "Files parse\n";
foreach ([
    'core/workflow_engine.php',
    'modules/accounting/lib/accounting.php',
    'modules/accounting/lib/workflow.php',
    'modules/accounting/lib/workflow_sync.php',
    'modules/accounting/api/journal_entries.php',
] as $rel) {
    $a("php -l {$rel}", $lint($rel));
}

echo "\nWorkflowGraph routing\n";
$a('WorkflowEngine routes accounting_journal_entry',
    str_contains($engine, "\$subjectType === 'accounting_journal_entry'")
    && str_contains($engine, 'accountingSyncJournalEntryFromWorkflow('));
$a('workflow bridge consumes domain People Graph',
    str_contains($workflow, "domainPeopleGraphWorkflowApproverResolution('accounting', 'journal_entry'"));
$a('workflow steps strip per-JE ids for reusable definitions',
    str_contains($workflow, "unset(\$resolution['resource_id'], \$resolution['object_id']);"));
$a('workflow starts accounting_journal_entry subject',
    str_contains($workflow, "workflowStart(\$tenantId, \$defKey, 'accounting_journal_entry'"));
$a('workflow actions use WorkflowEngine',
    str_contains($workflow, 'accountingJeWorkflowAct(')
    && str_contains($workflow, 'workflowAct($tenantId, $instanceId, $userId, $action'));
$a('workflow payload identifies accounting.journal_entry resource',
    str_contains($workflow, "'approval_resource' => 'accounting.journal_entry'")
    && str_contains($workflow, "'resource_module' => 'accounting'")
    && str_contains($workflow, "'resource_type' => 'journal_entry'"));
$a('workflow payload carries maker/checker blockers',
    str_contains($workflow, 'accountingJeWorkflowSodBlockedUserIds(')
    && str_contains($workflow, "'sod_blocked_user_ids' => \$blocked")
    && str_contains($workflow, 'created_by_user_id')
    && str_contains($workflow, 'submitted_by_user_id'));

echo "\nAPI gates\n";
$a('journal API requires workflow bridge',
    str_contains($api, "/../lib/workflow.php"));
$a('JE reads use view permission',
    substr_count($api, "rbac_legacy_require(\$user, 'accounting.je.view')") >= 3);
$a('draft creation uses create permission and does not require post',
    str_contains($api, "action === 'draft'")
    && str_contains($api, "rbac_legacy_require(\$user, 'accounting.je.create')")
    && str_contains($api, 'accountingPostJe($tid, $body, $actorUserId, false)'));
$a('submit starts workflow with submit permission',
    str_contains($api, "action === 'submit'")
    && str_contains($api, "rbac_legacy_require(\$user, 'accounting.je.submit')")
    && str_contains($api, 'accountingJeWorkflowStart($tid, $id, $actorUserId)'));
$a('approve/reject act through workflow with approve permission',
    str_contains($api, "in_array(\$action, ['approve', 'reject'], true)")
    && str_contains($api, "rbac_legacy_require(\$user, 'accounting.je.approve')")
    && str_contains($api, 'accountingJeWorkflowAct('));
$a('approval blocks map SoD/non-approver to 403',
    str_contains($api, 'Separation of duties')
    && str_contains($api, 'not an approver')
    && str_contains($api, 'api_error($msg, $code)'));
$a('existing-draft post requires post permission and approved helper',
    str_contains($api, "action === 'post' && (int) (\$_GET['id'] ?? 0) > 0")
    && str_contains($api, "rbac_legacy_require(\$user, 'accounting.je.post')")
    && str_contains($api, 'accountingPostApprovedDraftJe($tid, $id, $actorUserId)'));
$a('direct create-and-post remains post gated',
    str_contains($api, "\$method === 'POST' && (\$action === '' || \$action === 'post')")
    && str_contains($api, 'accountingPostJe($tid, $body, $actorUserId, true)'));
$a('void is a separate permission and helper',
    str_contains($api, "rbac_legacy_require(\$user, 'accounting.je.void')")
    && str_contains($api, 'accountingVoidDraftJe($tid, $id, $reason, $actorUserId)'));
$a('API does not directly approve JE rows',
    !preg_match("/action.{0,80}approve[\\s\\S]{0,700}UPDATE accounting_journal_entries[\\s\\S]{0,80}approval_state = 'approved'/", $api));

echo "\nPosting, sync, and auditability\n";
$a('approved-draft posting requires WorkflowGraph approval',
    str_contains($lib, 'function accountingPostApprovedDraftJe(')
    && str_contains($lib, 'Workflow approval required before posting this journal entry')
    && str_contains($lib, "AND workflow_instance_id IS NOT NULL"));
$a('approved-draft posting blocks approver/poster collision',
    str_contains($lib, 'approver cannot post the same journal entry')
    && str_contains($lib, 'requires_approval'));
$a('sync writes approved/rejected approval_state',
    str_contains($sync, "approval_state = 'approved'")
    && str_contains($sync, "approval_state = 'rejected'"));
$a('sync emits source=workflow audit events',
    str_contains($sync, 'accounting.je.approved')
    && str_contains($sync, 'accounting.je.rejected')
    && str_contains($sync, "'source' => 'workflow'"));
$a('workflow bridge audits started/blocked outcomes',
    str_contains($workflow, 'accounting.je.workflow_started')
    && str_contains($workflow, 'accounting.je.workflow_start_failed')
    && str_contains($workflow, 'accounting.je.approval_blocked'));

echo "\nSchema, RBAC, and manifest\n";
$a('base migration has approval lifecycle columns and workflow index',
    str_contains($mig1, "approval_state ENUM('draft','pending_approval','approved','rejected')")
    && str_contains($mig1, 'workflow_instance_id BIGINT UNSIGNED NULL')
    && str_contains($mig1, 'idx_aje_workflow'));
$a('upgrade migration adds approval lifecycle columns and indexes',
    str_contains($mig24, 'ADD COLUMN approval_state')
    && str_contains($mig24, 'ADD COLUMN workflow_instance_id')
    && str_contains($mig24, 'ADD INDEX idx_aje_tenant_approval_state')
    && str_contains($mig24, 'ADD INDEX idx_aje_workflow'));
$a('self-heal knows accounting JE workflow columns',
    str_contains($bootstrap, "'accounting_journal_entries'")
    && str_contains($bootstrap, "'approval_state' =>")
    && str_contains($bootstrap, "'workflow_instance_id' => 'ADD COLUMN workflow_instance_id BIGINT UNSIGNED NULL'"));
foreach ([
    'accounting.je.create',
    'accounting.je.edit_draft',
    'accounting.je.submit',
    'accounting.je.approve',
    'accounting.je.post',
    'accounting.je.reverse',
    'accounting.je.void',
    'accounting.je.view',
] as $permission) {
    $a("manifest declares {$permission}", str_contains($manifest, "'{$permission}'"));
    $a("RBAC bridge declares {$permission}", str_contains($legacyMap, "'{$permission}'"));
}
foreach ([
    'accounting.je.workflow_started',
    'accounting.je.workflow_start_failed',
    'accounting.je.workflow_approved',
    'accounting.je.workflow_rejected',
    'accounting.je.approval_blocked',
] as $event) {
    $a("manifest declares {$event}", str_contains($manifest, "'{$event}'"));
}

echo "\nAccounting JE workflow controls smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
