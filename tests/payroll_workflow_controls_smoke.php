<?php
/**
 * Payroll workflow controls smoke.
 *
 * Locks Phase 2 payroll enterprise controls:
 *   - computed/imported runs start a payroll_run WorkflowGraph approval
 *   - approvals act through WorkflowEngine + People Graph SoD
 *   - paid/Gusto paid transitions require approved state and disburse actor
 *   - pay-period approved/paid status cannot be patched directly
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

$runs = (string) file_get_contents("{$ROOT}/modules/payroll/api/runs.php");
$periods = (string) file_get_contents("{$ROOT}/modules/payroll/api/pay_periods.php");
$importCsv = (string) file_get_contents("{$ROOT}/modules/payroll/api/import_csv.php");
$workflow = (string) file_get_contents("{$ROOT}/modules/payroll/lib/workflow.php");
$sync = (string) file_get_contents("{$ROOT}/modules/payroll/lib/workflow_sync.php");
$engine = (string) file_get_contents("{$ROOT}/core/workflow_engine.php");
$mig1 = (string) file_get_contents("{$ROOT}/modules/payroll/migrations/001_init.sql");
$mig6 = (string) file_get_contents("{$ROOT}/modules/payroll/migrations/006_run_enterprise_controls.sql");
$manifest = (string) file_get_contents("{$ROOT}/modules/payroll/manifest.php");
$legacyMap = (string) file_get_contents("{$ROOT}/core/rbac/legacy_map.php");
$bootstrap = (string) file_get_contents("{$ROOT}/core/api_bootstrap.php");

echo "Files parse\n";
foreach ([
    'modules/payroll/api/runs.php',
    'modules/payroll/api/import_csv.php',
    'modules/payroll/api/pay_periods.php',
    'modules/payroll/lib/workflow.php',
    'modules/payroll/lib/workflow_sync.php',
    'core/workflow_engine.php',
] as $rel) {
    $a("php -l {$rel}", $lint($rel));
}

echo "\nWorkflowGraph routing\n";
$a('WorkflowEngine sync routes payroll_run',
    str_contains($engine, "\$subjectType === 'payroll_run'")
    && str_contains($engine, 'payrollSyncRunFromWorkflow('));
$a('WorkflowEngine SoD recognizes computed_by_user_id',
    str_contains($engine, "'computed_by_user_id'"));
$a('payroll workflow bridge consumes domain People Graph',
    str_contains($workflow, "domainPeopleGraphWorkflowApproverResolution('payroll', 'run'"));
$a('payroll workflow starts payroll_run subject',
    str_contains($workflow, "workflowStart(\$tenantId, \$defKey, 'payroll_run'"));
$a('workflow payload carries SoD blockers',
    str_contains($workflow, 'payrollRunWorkflowSodBlockedUserIds(')
    && str_contains($workflow, "'sod_blocked_user_ids' => \$blocked"));
$a('recompute cancels stale pending workflow',
    str_contains($workflow, 'function payrollRunWorkflowCancelPending(')
    && str_contains($runs, "payrollRunWorkflowCancelPending(currentTenantId(), \$runId"));

echo "\nRun transitions\n";
$a('create stamps created_by_user_id',
    str_contains($runs, "'created_by_user_id' => \$user['id'] ?? null"));
$a('compute stamps computed_by_user_id',
    str_contains($runs, "'computed_by_user_id'    => \$actorUserId"));
$a('compute starts workflow and fails closed',
    str_contains($runs, 'payrollRunWorkflowStart(currentTenantId(), $runId')
    && str_contains($runs, 'Could not start payroll approval workflow'));
$a('approve delegates through WorkflowEngine',
    str_contains($runs, "payrollRunWorkflowAct(currentTenantId(), \$run, (int) (\$user['id'] ?? 0), 'approve'"));
$a('workflow helper fails closed when no instance can start',
    str_contains($workflow, 'Could not start payroll approval workflow')
    && !str_contains($workflow, "return ['applied' => false"));
$a('workflow helper verifies approved sync applied',
    str_contains($workflow, 'Workflow approved but payroll run sync did not apply'));
$a('approve no longer directly writes payroll_runs approved state',
    !preg_match("/scopedUpdate\('payroll_runs',\s*\$runId,\s*\[[^\]]*'status'\s*=>\s*'approved'/s", $runs));
$a('paid transition requires approved and stamps actor',
    str_contains($runs, "_payrollRequireStatus(\$run, ['approved'], 'Mark paid')")
    && str_contains($runs, "'paid_by_user_id' => \$user['id'] ?? null"));
$a('Gusto paid requires approved/paid and stamps actor',
    str_contains($runs, "_payrollRequireStatus(\$run, ['approved', 'paid'], 'Mark Gusto paid')")
    && str_contains($runs, 'payroll.run.gusto_marked_paid')
    && str_contains($runs, "'paid_by_user_id' => \$user['id'] ?? null"));

echo "\nImported runs\n";
$a('CSV import requires workflow helper',
    str_contains($importCsv, "/../lib/workflow.php"));
$a('CSV import stamps creator/computer and starts workflow',
    str_contains($importCsv, "'created_by_user_id' => \$ctx['user']['id'] ?? null")
    && str_contains($importCsv, 'payrollRunWorkflowStart($tid, (int) $summary[\'run_id\']'));
$a('CSV import fails closed if workflow cannot start',
    str_contains($importCsv, 'Could not start payroll approval workflow for imported run'));

echo "\nWorkflow sync\n";
$a('sync approves run, line items, and pay period',
    str_contains($sync, "UPDATE payroll_runs")
    && str_contains($sync, "status = 'approved'")
    && str_contains($sync, 'UPDATE payroll_line_items')
    && str_contains($sync, 'UPDATE payroll_pay_periods'));
$a('sync emits payroll.run.approved from workflow',
    str_contains($sync, "payrollAudit('payroll.run.approved'")
    && str_contains($sync, "'source' => 'workflow'"));
$a('sync records rejected workflow decisions',
    str_contains($sync, "payroll.run.approval_rejected"));

echo "\nPay-period direct patch guard\n";
$a('GET/POST/PATCH are RBAC-gated',
    str_contains($periods, "rbac_legacy_require(\$user, 'payroll.view')")
    && str_contains($periods, "rbac_legacy_require(\$user, 'payroll.run.build')"));
$a('direct approved/paid period patch is blocked',
    str_contains($periods, "['approved', 'paid']")
    && str_contains($periods, 'controlled by payroll run approval/payment workflows'));
$a('period patch audits before/after status',
    str_contains($periods, "payrollAudit('payroll.period.updated'")
    && str_contains($periods, "'before_status'"));

echo "\nSchema and declarations\n";
foreach (['created_by_user_id', 'computed_by_user_id', 'workflow_instance_id', 'paid_by_user_id'] as $col) {
    $a("base migration has {$col}", str_contains($mig1, $col));
    $a("upgrade migration has {$col}", str_contains($mig6, $col));
    $a("self-heal has {$col}", str_contains($bootstrap, "'{$col}'"));
}
$a('workflow index declared',
    str_contains($mig1, 'idx_run_workflow')
    && str_contains($mig6, 'idx_run_workflow'));
foreach ([
    'payroll.run.create',
    'payroll.run.compute',
    'payroll.run.workflow_started',
    'payroll.run.workflow_start_failed',
    'payroll.run.workflow_cancelled',
    'payroll.run.approval_blocked',
    'payroll.run.approval_rejected',
    'payroll.run.marked_paid',
    'payroll.period.updated',
] as $needle) {
    $a("manifest declares {$needle}", str_contains($manifest, "'{$needle}'"));
}
$a('RBAC map declares create/compute/build/approve/disburse',
    str_contains($legacyMap, "'payroll.run.create'")
    && str_contains($legacyMap, "'payroll.run.compute'")
    && str_contains($legacyMap, "'payroll.run.build'")
    && str_contains($legacyMap, "'payroll.run.approve'")
    && str_contains($legacyMap, "'payroll.run.disburse'"));

echo "\nPayroll workflow controls smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
