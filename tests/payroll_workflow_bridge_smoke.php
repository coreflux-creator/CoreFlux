<?php
declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
require_once "{$root}/core/rbac/legacy_map.php";
require_once "{$root}/core/ModuleRegistry.php";
$files = [
    'modules/payroll/api/runs.php',
    'modules/payroll/lib/payroll.php',
    'modules/payroll/lib/workflow.php',
    'modules/payroll/lib/workflow_sync.php',
    'modules/payroll/lib/approval_settings.php',
    'modules/payroll/api/approval_settings.php',
    'modules/payroll/manifest.php',
    'core/workflow_engine.php',
    'core/rbac/legacy_map.php',
];
$failures = [];

foreach ($files as $file) {
    $output = [];
    $code = 0;
    exec('php -l ' . escapeshellarg("{$root}/{$file}") . ' 2>&1', $output, $code);
    if ($code !== 0) $failures[] = "PHP lint failed: {$file}";
}

$runs = file_get_contents("{$root}/modules/payroll/api/runs.php");
$workflow = file_get_contents("{$root}/modules/payroll/lib/workflow.php");
$sync = file_get_contents("{$root}/modules/payroll/lib/workflow_sync.php");
$migration = file_get_contents("{$root}/modules/payroll/migrations/006_run_enterprise_controls.sql");
$reviewMigration = file_get_contents("{$root}/core/migrations/158_payroll_workflow_review_attempts.sql");
$manifest = file_get_contents("{$root}/modules/payroll/manifest.php");
$approvalSettings = file_get_contents("{$root}/modules/payroll/lib/approval_settings.php");
$approvalApi = file_get_contents("{$root}/modules/payroll/api/approval_settings.php");
$preflight = file_get_contents("{$root}/modules/payroll/api/preflight.php");
$runDetailUi = file_get_contents("{$root}/modules/payroll/ui/PayrollRunDetail.jsx");
$settingsUi = file_get_contents("{$root}/modules/payroll/ui/PayrollSettings.jsx");
$legacyMap = file_get_contents("{$root}/core/rbac/legacy_map.php");
$registry = ModuleRegistry::reset("{$root}/modules");
$peopleGraphContract = $registry->getPeopleGraphContract('payroll');

$checks = [
    'runs includes an existing workflow bridge' => str_contains($runs, "../lib/workflow.php") && is_file("{$root}/modules/payroll/lib/workflow.php"),
    'runs loads the legacy RBAC class used by the compatibility read gate' =>
        str_contains($runs, "../../../core/RBAC.php")
        && is_file("{$root}/core/RBAC.php"),
    'computed runs start a workflow' => str_contains($runs, 'payrollRunWorkflowStart('),
    'approvals act through WorkflowGraph' => str_contains($runs, 'payrollRunWorkflowAct('),
    'workflow uses People Graph approver resolution' => str_contains($workflow, "domainPeopleGraphWorkflowApproverResolution('payroll', 'run'"),
    'payroll run is registered for People Graph approval' =>
        ($peopleGraphContract['object_types']['run']['approval_resource'] ?? null) === 'payroll.run'
        && in_array('approver', $peopleGraphContract['object_types']['run']['responsibilities'] ?? [], true),
    'reviewer settings use the shared approval policy' =>
        str_contains($approvalSettings, 'peopleGraphCreateApprovalPolicy(')
        && str_contains($approvalSettings, 'peopleGraphCreateApprovalRule(')
        && str_contains($approvalSettings, 'payroll.run.approve'),
    'reviewer setup is admin gated and visible in Payroll Settings' =>
        str_contains($approvalApi, "rbac_legacy_require(\$user, 'payroll.schedules.manage')")
        && str_contains($approvalApi, "'payroll', 'admin'")
        && str_contains($settingsUi, '<PayrollApprovalSettings />'),
    'preflight and compute surface missing independent reviewers' =>
        str_contains($preflight, 'payrollRunApprovalReadiness(')
        && str_contains($runs, 'payrollRunApprovalReadiness(')
        && str_contains($runDetailUi, 'payroll-preflight-approval')
        && str_contains($runDetailUi, '/modules/payroll/settings'),
    'computed approval is not blocked by draft preflight' =>
        str_contains($runDetailUi, "run.status === 'draft' && run.pay_period_id")
        && str_contains($runDetailUi, "onClick={approve} disabled={busy === 'approve' || lines.length === 0}"),
    'workflow sync updates payroll-owned records' => str_contains($sync, "UPDATE payroll_runs") && str_contains($sync, "UPDATE payroll_pay_periods"),
    'schema migration adds workflow evidence' => str_contains($migration, 'workflow_instance_id') && str_contains($migration, 'computed_by_user_id'),
    'cancelled payroll reviews release the unique workflow slot' =>
        str_contains($reviewMigration, "subject_type = 'payroll_run'")
        && str_contains($reviewMigration, "'cancelled', 'rejected', 'expired'")
        && str_contains($reviewMigration, 'THEN NULL'),
    'manifest declares create and compute permissions' => str_contains($manifest, "'payroll.run.create'") && str_contains($manifest, "'payroll.run.compute'"),
    'legacy RBAC maps create and compute permissions' => str_contains($legacyMap, "'payroll.run.create'") && str_contains($legacyMap, "'payroll.run.compute'"),
    'legacy RBAC maps payroll list permissions to read access' =>
        str_contains($legacyMap, "'payroll.view'")
        && str_contains($legacyMap, "'payroll.runs.view'")
        && RbacLegacyMap::resolve('payroll.view') === ['payroll', 'read']
        && RbacLegacyMap::resolve('payroll.runs.view') === ['payroll', 'read'],
    'read-only payroll requests honor either RBAC grant during cutover' =>
        str_contains($runs, 'function _payrollRequireRead(')
        && str_contains($runs, 'if ($legacyOk || $membershipOk) return;')
        && str_contains($runs, "_payrollRequireRead(\$user, 'payroll.view')")
        && str_contains($runs, "_payrollRequireRead(\$user, 'payroll.reports.view')"),
    'payroll writes remain on the strict bridge' =>
        str_contains($runs, "rbac_legacy_require(\$user, 'payroll.run.create')")
        && str_contains($runs, "rbac_legacy_require(\$user, 'payroll.run.approve')"),
];

foreach ($checks as $label => $passed) {
    echo ($passed ? 'OK  ' : 'BAD ') . $label . PHP_EOL;
    if (!$passed) $failures[] = $label;
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Payroll workflow bridge smoke passed.' . PHP_EOL;
