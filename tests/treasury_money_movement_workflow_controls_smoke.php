<?php
/**
 * Treasury money-movement workflow controls smoke.
 *
 * Locks the alignment rule that Treasury payment/transfer approval is a
 * WorkflowGraph decision with People Graph routing, SoD evidence, source-row
 * sync, separate execution gates, and explicit audit events.
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
$workflow = (string) file_get_contents("{$ROOT}/modules/treasury/lib/workflow.php");
$sync = (string) file_get_contents("{$ROOT}/modules/treasury/lib/workflow_sync.php");
$payments = (string) file_get_contents("{$ROOT}/api/treasury_payments.php");
$transfers = (string) file_get_contents("{$ROOT}/api/treasury_transfers.php");
$manifest = (string) file_get_contents("{$ROOT}/modules/treasury/manifest.php");
$mig5 = (string) file_get_contents("{$ROOT}/modules/treasury/migrations/005_treasury_payments.sql");
$mig6 = (string) file_get_contents("{$ROOT}/modules/treasury/migrations/006_treasury_transfers.sql");
$mig7 = (string) file_get_contents("{$ROOT}/modules/treasury/migrations/007_money_movement_workflow_controls.sql");
$bootstrap = (string) file_get_contents("{$ROOT}/core/api_bootstrap.php");
$legacyMap = (string) file_get_contents("{$ROOT}/core/rbac/legacy_map.php");

echo "Files parse\n";
foreach ([
    'core/workflow_engine.php',
    'modules/treasury/lib/workflow.php',
    'modules/treasury/lib/workflow_sync.php',
    'api/treasury_payments.php',
    'api/treasury_transfers.php',
] as $rel) {
    $a("php -l {$rel}", $lint($rel));
}

echo "\nWorkflowGraph routing\n";
$a('WorkflowEngine routes treasury_payment',
    str_contains($engine, "\$subjectType === 'treasury_payment'")
    && str_contains($engine, 'treasurySyncPaymentFromWorkflow('));
$a('WorkflowEngine routes treasury_transfer',
    str_contains($engine, "\$subjectType === 'treasury_transfer'")
    && str_contains($engine, 'treasurySyncTransferFromWorkflow('));
$a('payment bridge consumes domain People Graph',
    str_contains($workflow, "domainPeopleGraphWorkflowApproverResolution('treasury', 'payment'"));
$a('transfer bridge consumes domain People Graph',
    str_contains($workflow, "domainPeopleGraphWorkflowApproverResolution('treasury', 'transfer'"));
$a('workflow steps strip per-record ids for reusable definitions',
    substr_count($workflow, "unset(\$resolution['resource_id'], \$resolution['object_id']);") >= 2);
$a('payment starts treasury_payment subject',
    str_contains($workflow, "workflowStart(\$tenantId, \$defKey, 'treasury_payment'"));
$a('transfer starts treasury_transfer subject',
    str_contains($workflow, "workflowStart(\$tenantId, \$defKey, 'treasury_transfer'"));
$a('workflow actions use WorkflowEngine',
    str_contains($workflow, 'treasuryPaymentWorkflowAct(')
    && str_contains($workflow, 'treasuryTransferWorkflowAct(')
    && substr_count($workflow, 'workflowAct($tenantId, $instanceId, $userId, $action') >= 2);
$a('workflow payloads identify Treasury resources and SoD blockers',
    str_contains($workflow, "'approval_resource' => 'treasury.payment'")
    && str_contains($workflow, "'approval_resource' => 'treasury.transfer'")
    && str_contains($workflow, 'treasuryPaymentWorkflowSodBlockedUserIds(')
    && str_contains($workflow, 'treasuryTransferWorkflowSodBlockedUserIds(')
    && substr_count($workflow, "'sod_blocked_user_ids' => \$blocked") >= 2);

echo "\nAPI gates\n";
$a('payments API requires workflow bridge',
    str_contains($payments, "/../modules/treasury/lib/workflow.php"));
$a('transfers API requires workflow bridge',
    str_contains($transfers, "/../modules/treasury/lib/workflow.php"));
$a('payment list uses payment view permission',
    str_contains($payments, "rbac_legacy_require(\$user, 'treasury.payment.view')"));
$a('transfer list uses payment view permission',
    str_contains($transfers, "rbac_legacy_require(\$user, 'treasury.payment.view')"));
$a('payment submit starts workflow',
    str_contains($payments, "action === 'submit'")
    && str_contains($payments, 'treasuryPaymentWorkflowStart($tid, $id'));
$a('transfer submit starts workflow',
    str_contains($transfers, "action === 'submit'")
    && str_contains($transfers, 'treasuryTransferWorkflowStart($tid, $id'));
$a('payment approve/reject act through workflow',
    str_contains($payments, 'treasuryPaymentWorkflowAct($tid, $id')
    && str_contains($payments, "'approve'")
    && str_contains($payments, "'reject'"));
$a('transfer approve/reject act through workflow',
    str_contains($transfers, 'treasuryTransferWorkflowAct($tid, $id')
    && str_contains($transfers, "'approve'")
    && str_contains($transfers, "'reject'"));
$a('approval blocks map SoD/non-approver to 403',
    str_contains($payments, 'Separation of duties')
    && str_contains($payments, 'not an approver')
    && str_contains($transfers, 'Separation of duties')
    && str_contains($transfers, 'not an approver'));
$a('direct approval row updates are retired',
    !preg_match("/action === 'approve'[\\s\\S]{0,500}UPDATE treasury_payments[\\s\\S]{0,120}status=\"approved\"/", $payments)
    && !preg_match("/action === 'approve'[\\s\\S]{0,500}UPDATE treasury_transfers[\\s\\S]{0,120}status=\"approved\"/", $transfers));
$a('execution requires approved/scheduled and execute permission',
    str_contains($payments, "rbac_legacy_require(\$user, 'treasury.execute_payment')")
    && str_contains($payments, "['approved', 'scheduled']")
    && str_contains($transfers, "rbac_legacy_require(\$user, 'treasury.execute_payment')")
    && str_contains($transfers, "['approved', 'scheduled']"));
$a('execution posts accounting events and audits failures',
    str_contains($payments, 'accountingProcessEvent($tid, $event, $actorUserId)')
    && str_contains($payments, 'treasury.payment.execution_failed')
    && str_contains($transfers, 'accountingProcessEvent($tid, $event, $actorUserId)')
    && str_contains($transfers, 'treasury.transfer.execution_failed'));

echo "\nWorkflow sync and auditability\n";
$a('payment sync writes approved/rejected source state',
    str_contains($sync, 'UPDATE treasury_payments')
    && str_contains($sync, "status = 'approved'")
    && str_contains($sync, "status = 'rejected'"));
$a('transfer sync writes approved/rejected source state',
    str_contains($sync, 'UPDATE treasury_transfers')
    && str_contains($sync, "status = 'approved'")
    && str_contains($sync, "status = 'rejected'"));
$a('sync emits source=workflow audit events',
    str_contains($sync, 'treasury.payment.approved')
    && str_contains($sync, 'treasury.payment.approval_rejected')
    && str_contains($sync, 'treasury.transfer.approved')
    && str_contains($sync, 'treasury.transfer.approval_rejected')
    && str_contains($sync, "'source' => 'workflow'"));

echo "\nSchema and declarations\n";
$a('base payment migration has workflow index',
    str_contains($mig5, 'workflow_instance_id BIGINT UNSIGNED NULL')
    && str_contains($mig5, 'idx_tp_workflow'));
$a('base transfer migration has workflow index',
    str_contains($mig6, 'workflow_instance_id BIGINT UNSIGNED NULL')
    && str_contains($mig6, 'idx_tt_workflow'));
$a('upgrade migration adds workflow columns and indexes',
    str_contains($mig7, 'ADD COLUMN workflow_instance_id BIGINT UNSIGNED NULL AFTER status')
    && str_contains($mig7, 'ADD INDEX idx_tp_workflow')
    && str_contains($mig7, 'ADD INDEX idx_tt_workflow'));
$a('self-heal knows treasury workflow columns',
    str_contains($bootstrap, "'treasury_payments'")
    && str_contains($bootstrap, "'treasury_transfers'")
    && str_contains($bootstrap, "'workflow_instance_id' => 'ADD COLUMN workflow_instance_id BIGINT UNSIGNED NULL AFTER status'"));
foreach ([
    'treasury.view_bank_balances',
    'treasury.payment.view',
    'treasury.payment.manage',
    'treasury.create_payment',
    'treasury.approve_payment',
    'treasury.execute_payment',
    'treasury.create_transfer',
    'treasury.approve_transfer',
    'treasury.manage_forecast',
] as $permission) {
    $a("manifest declares {$permission}", str_contains($manifest, "'{$permission}'"));
}
foreach ([
    'treasury.payment.workflow_started',
    'treasury.payment.workflow_start_failed',
    'treasury.payment.workflow_approved',
    'treasury.payment.workflow_rejected',
    'treasury.payment.approval_blocked',
    'treasury.payment.approval_rejected',
    'treasury.payment.execution_failed',
    'treasury.transfer.workflow_started',
    'treasury.transfer.workflow_start_failed',
    'treasury.transfer.workflow_approved',
    'treasury.transfer.workflow_rejected',
    'treasury.transfer.approval_blocked',
    'treasury.transfer.approval_rejected',
    'treasury.transfer.execution_failed',
] as $event) {
    $a("manifest declares {$event}", str_contains($manifest, "'{$event}'"));
}
$a('RBAC bridge declares manage_forecast',
    str_contains($legacyMap, "'treasury.manage_forecast'           => ['treasury', 'write']"));

echo "\nTreasury money-movement workflow controls smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
