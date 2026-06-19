<?php
/**
 * Payroll audit evidence controls smoke.
 *
 * Locks the rule that payroll run control evidence writes through the platform
 * audit writer with before/after source-row snapshots.
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
$containsAll = function (string $haystack, array $needles): bool {
    foreach ($needles as $needle) {
        if (!str_contains($haystack, (string) $needle)) return false;
    }
    return true;
};

$lib = (string) file_get_contents("{$ROOT}/modules/payroll/lib/payroll.php");
$workflow = (string) file_get_contents("{$ROOT}/modules/payroll/lib/workflow.php");
$sync = (string) file_get_contents("{$ROOT}/modules/payroll/lib/workflow_sync.php");
$runs = (string) file_get_contents("{$ROOT}/modules/payroll/api/runs.php");
$gustoSubmit = (string) file_get_contents("{$ROOT}/modules/payroll/api/gusto_submit.php");
$periods = (string) file_get_contents("{$ROOT}/modules/payroll/api/pay_periods.php");
$auditDoc = (string) file_get_contents("{$ROOT}/docs/AUDIT_GOVERNANCE.md");
$alignment = (string) file_get_contents("{$ROOT}/docs/PRODUCT_ARCHITECTURE_ALIGNMENT.md");

echo "Files parse\n";
foreach ([
    'modules/payroll/lib/payroll.php',
    'modules/payroll/lib/workflow.php',
    'modules/payroll/lib/workflow_sync.php',
    'modules/payroll/api/runs.php',
    'modules/payroll/api/gusto_submit.php',
    'modules/payroll/api/pay_periods.php',
] as $rel) {
    $a("php -l {$rel}", $lint($rel));
}

echo "\nPayroll audit writer\n";
$a('payrollAudit requires shared platform audit writer',
    str_contains($lib, "require_once __DIR__ . '/../../../core/audit.php'")
    && str_contains($lib, 'platformAuditLogWrite('));
$a('payrollAudit accepts platform audit options',
    str_contains($lib, 'function payrollAudit(string $event, array $meta = [], ?int $targetId = null, array $opts = [])'));
$a('payrollAudit stamps Payroll source/object metadata',
    $containsAll($lib, ["'object_type' => payrollAuditObjectType(\$event)", "'source' => \$meta['source'] ?? 'payroll'"]));
$a('payrollAudit maps high-risk Payroll object types',
    $containsAll($lib, ['payroll_run', 'payroll_period', 'payroll_profile', 'payroll_gusto', 'payroll_tax_liability']));
$a('payrollAudit no longer inserts audit_log directly',
    !preg_match('/function payrollAudit[\s\S]*INSERT INTO audit_log/', $lib));

echo "\nWorkflow evidence\n";
$a('workflow start snapshots run before/after',
    $containsAll($workflow, [
        '$latest = payrollRunWorkflowRow($runId) ?? $run',
        "'before' => \$run",
        "'after' => \$latest",
    ]));
$a('workflow start failures and blocked approvals include source run',
    $containsAll($workflow, [
        "payroll.run.workflow_start_failed",
        "payroll.run.approval_blocked",
        "'before' => \$run",
    ]));
$a('workflow sync snapshots rejected and approved runs',
    $containsAll($sync, [
        '$beforeRun = payrollSyncRunRow($tenantId, $runId)',
        "payrollAudit('payroll.run.approval_rejected'",
        "payrollAudit('payroll.run.approved'",
        "'before' => \$beforeRun",
        "'after' => \$updated",
    ]));

echo "\nRun API evidence\n";
$a('run API has tenant-scoped audit row helper',
    str_contains($runs, 'function payrollRunAuditRow(')
    && str_contains($runs, 'WHERE tenant_id = :t AND id = :id'));
$a('create and compute audits snapshot run rows',
    $containsAll($runs, [
        "payrollAudit('payroll.run.created'",
        "'after' => payrollRunAuditRow((int) currentTenantId(), \$runId)",
        "payrollAudit('payroll.run.built'",
        "'before' => \$run",
    ]));
$a('paid and rail origination audits snapshot run rows',
    $containsAll($runs, [
        "payrollAudit('payroll.run.marked_paid'",
        '$paidRun = payrollRunAuditRow((int) currentTenantId(), $runId)',
        "payrollAudit('payroll.run.originated'",
        '$originatedRun = payrollRunAuditRow((int) currentTenantId(), $runId)',
        "payrollAudit('payroll.run.originate_failed'",
    ]));
$a('Gusto status actions in runs API snapshot run rows',
    $containsAll($runs, [
        "payrollAudit('payroll.run.gusto_synced'",
        '$syncedRun = payrollRunAuditRow((int) currentTenantId(), $runId)',
        "payrollAudit('payroll.run.gusto_marked_paid'",
        '$gustoPaidRun = payrollRunAuditRow((int) currentTenantId(), $runId)',
        "payrollAudit('payroll.run.gusto_unlinked'",
        '$unlinkedRun = payrollRunAuditRow((int) currentTenantId(), $runId)',
    ]));
$a('Gusto API submission success/failure snapshots run rows',
    $containsAll($gustoSubmit, [
        'function payrollGustoRunAuditRow(',
        "payrollAudit('payroll.gusto.run_submitted'",
        "payrollAudit('payroll.gusto.run_submission_failed'",
        "'before' => \$run",
        'payrollGustoRunAuditRow((int) $ctx[\'tenant_id\'], $runId)',
    ]));
$a('pay-period patch audits before/after period rows',
    $containsAll($periods, [
        "payrollAudit('payroll.period.updated'",
        "'before' => \$before",
        "'after' => \$after",
    ]));

echo "\nDocs\n";
$a('audit governance names Payroll controls',
    str_contains($auditDoc, 'Payroll run controls'));
$a('architecture alignment records Payroll audit evidence',
    $containsAll($alignment, [
        '`payrollAudit` delegates to the shared `platformAuditLogWrite` writer',
        'Payroll run WorkflowGraph start, approval sync, rejection sync',
        'pay-period patch events capture before/after source-row snapshots',
    ]));

echo "\nPayroll audit evidence controls smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
