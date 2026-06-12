<?php
/**
 * Billing invoice workflow controls smoke.
 *
 * Locks the alignment rule that Billing invoices become approved only after
 * WorkflowGraph approval, with People Graph routing, SoD evidence, source
 * sync, and separate posting permission gates.
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
$workflow = (string) file_get_contents("{$ROOT}/modules/billing/lib/workflow.php");
$sync = (string) file_get_contents("{$ROOT}/modules/billing/lib/workflow_sync.php");
$api = (string) file_get_contents("{$ROOT}/modules/billing/api/invoices.php");
$mig1 = (string) file_get_contents("{$ROOT}/modules/billing/migrations/001_init.sql");
$mig12 = (string) file_get_contents("{$ROOT}/modules/billing/migrations/012_invoice_workflow_controls.sql");
$manifest = (string) file_get_contents("{$ROOT}/modules/billing/manifest.php");
$legacyMap = (string) file_get_contents("{$ROOT}/core/rbac/legacy_map.php");
$bootstrap = (string) file_get_contents("{$ROOT}/core/api_bootstrap.php");

echo "Files parse\n";
foreach ([
    'core/workflow_engine.php',
    'modules/billing/lib/workflow.php',
    'modules/billing/lib/workflow_sync.php',
    'modules/billing/api/invoices.php',
] as $rel) {
    $a("php -l {$rel}", $lint($rel));
}

echo "\nWorkflowGraph routing\n";
$a('WorkflowEngine routes billing_invoice subject',
    str_contains($engine, "\$subjectType === 'billing_invoice'")
    && str_contains($engine, 'billingSyncInvoiceFromWorkflow('));
$a('workflow bridge consumes domain People Graph',
    str_contains($workflow, "domainPeopleGraphWorkflowApproverResolution('billing', 'invoice'"));
$a('workflow steps strip per-invoice ids for reusable definition',
    str_contains($workflow, "unset(\$resolution['resource_id'], \$resolution['object_id']);"));
$a('workflow starts billing_invoice subject',
    str_contains($workflow, "workflowStart(\$tenantId, \$defKey, 'billing_invoice'"));
$a('workflow action uses WorkflowEngine approve',
    str_contains($workflow, 'workflowAct($tenantId, $instanceId, $userId, $action'));
$a('workflow payload identifies billing.invoice resource',
    str_contains($workflow, "'resource_module' => 'billing'")
    && str_contains($workflow, "'resource_type' => 'invoice'")
    && str_contains($workflow, "'approval_resource' => 'billing.invoice'"));
$a('workflow payload carries SoD blockers',
    str_contains($workflow, 'billingInvoiceWorkflowSodBlockedUserIds(')
    && str_contains($workflow, "'sod_blocked_user_ids' => \$blocked"));

echo "\nAPI approval and posting gates\n";
$a('invoices API requires workflow bridge',
    str_contains($api, "/../lib/workflow.php"));
$a('approve acts through workflow, not direct update',
    str_contains($api, "billingInvoiceWorkflowAct(\$tid, \$id, (int) (\$user['id'] ?? 0), 'approve'")
    && !preg_match('/action === \'approve\'[\\s\\S]{0,350}UPDATE billing_invoices SET status = "approved"/', $api));
$a('approve maps SoD/approver blocks to 403',
    str_contains($api, 'Separation of duties')
    && str_contains($api, 'not an approver')
    && str_contains($api, 'api_error($msg, $code)'));
$a('approve response surfaces workflow evidence',
    str_contains($api, "'workflow_instance_id' => \$workflow['instance']['id']")
    && str_contains($api, "'workflow_status' => \$workflow['instance']['status']"));
$a('Jaz draft enqueue happens after workflow approval',
    str_contains($api, "\$updated['status'] ?? null) === 'approved'")
    && str_contains($api, "accountingTryEnqueueDraft(\$tid, 'invoice', \$updated"));
$a('GL post uses billing.invoice.post gate',
    str_contains($api, "rbac_legacy_require(\$user, 'billing.invoice.post');")
    && !preg_match("/action === 'post'[\\s\\S]{0,120}billing\\.invoice\\.approve/", $api));
$a('IC split post uses billing.invoice.post + accounting.je.post',
    preg_match("/action === 'post_with_ic_split'[\\s\\S]{0,180}'billing\\.invoice\\.post'[\\s\\S]{0,120}'accounting\\.je\\.post'/", $api) === 1);

echo "\nWorkflow sync and auditability\n";
$a('sync approves invoice status from workflow',
    str_contains($sync, "UPDATE billing_invoices")
    && str_contains($sync, "status = 'approved'")
    && str_contains($sync, 'approved_by_user_id'));
$a('sync emits billing.invoice.approved from workflow',
    str_contains($sync, "billingWorkflowAudit(\$tenantId, \$userId, 'billing.invoice.approved'")
    && str_contains($sync, "'source' => 'workflow'"));
$a('sync records rejected workflow decisions',
    str_contains($sync, 'billing.invoice.approval_rejected'));
$a('blocked workflow decisions are audited',
    str_contains($workflow, "'billing.invoice.approval_blocked'"));

echo "\nSchema, RBAC, and manifest\n";
$a('base migration has workflow_instance_id + index',
    str_contains($mig1, 'workflow_instance_id BIGINT UNSIGNED NULL')
    && str_contains($mig1, 'idx_bi_workflow'));
$a('upgrade migration adds workflow column + index',
    str_contains($mig12, 'ADD COLUMN workflow_instance_id BIGINT UNSIGNED NULL AFTER approved_at')
    && str_contains($mig12, 'ADD INDEX idx_bi_workflow'));
$a('self-heal knows billing_invoices.workflow_instance_id',
    str_contains($bootstrap, "'billing_invoices'")
    && str_contains($bootstrap, "'workflow_instance_id' => 'ADD COLUMN workflow_instance_id BIGINT UNSIGNED NULL AFTER approved_at'"));
$a('RBAC map declares billing.invoice.post',
    str_contains($legacyMap, "'billing.invoice.post'               => ['billing', 'admin']"));
foreach ([
    'billing.invoice.workflow_started',
    'billing.invoice.workflow_start_failed',
    'billing.invoice.workflow_approved',
    'billing.invoice.approval_blocked',
    'billing.invoice.approval_rejected',
] as $event) {
    $a("manifest declares {$event}", str_contains($manifest, "'{$event}'"));
}

echo "\nBilling invoice workflow controls smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
