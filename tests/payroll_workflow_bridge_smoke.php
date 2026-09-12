<?php
declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
$files = [
    'modules/payroll/api/runs.php',
    'modules/payroll/lib/payroll.php',
    'modules/payroll/lib/workflow.php',
    'modules/payroll/lib/workflow_sync.php',
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
$manifest = file_get_contents("{$root}/modules/payroll/manifest.php");
$legacyMap = file_get_contents("{$root}/core/rbac/legacy_map.php");

$checks = [
    'runs includes an existing workflow bridge' => str_contains($runs, "../lib/workflow.php") && is_file("{$root}/modules/payroll/lib/workflow.php"),
    'computed runs start a workflow' => str_contains($runs, 'payrollRunWorkflowStart('),
    'approvals act through WorkflowGraph' => str_contains($runs, 'payrollRunWorkflowAct('),
    'workflow uses People Graph approver resolution' => str_contains($workflow, "domainPeopleGraphWorkflowApproverResolution('payroll', 'run'"),
    'workflow sync updates payroll-owned records' => str_contains($sync, "UPDATE payroll_runs") && str_contains($sync, "UPDATE payroll_pay_periods"),
    'schema migration adds workflow evidence' => str_contains($migration, 'workflow_instance_id') && str_contains($migration, 'computed_by_user_id'),
    'manifest declares create and compute permissions' => str_contains($manifest, "'payroll.run.create'") && str_contains($manifest, "'payroll.run.compute'"),
    'legacy RBAC maps create and compute permissions' => str_contains($legacyMap, "'payroll.run.create'") && str_contains($legacyMap, "'payroll.run.compute'"),
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
