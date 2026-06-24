<?php
/**
 * AP approval submit workflow alignment smoke.
 *
 * Locks the rule that approval submission routes through the shared AP
 * approval policy router and WorkflowEngine, not the legacy AP-only workflow
 * tables.
 */
declare(strict_types=1);

$ROOT = realpath(__DIR__ . '/..');
$pass = 0;
$fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail): void {
    if ($cond) {
        echo "  OK  {$msg}\n";
        $pass++;
    } else {
        echo "  BAD {$msg}\n";
        $fail++;
    }
};
$lint = function (string $rel) use ($ROOT): bool {
    $out = [];
    $rc = 0;
    exec('php -l ' . escapeshellarg("{$ROOT}/{$rel}") . ' 2>&1', $out, $rc);
    return $rc === 0;
};

$bridge = (string) file_get_contents("{$ROOT}/modules/ap/lib/workflow_bridge.php");
$approvals = (string) file_get_contents("{$ROOT}/modules/ap/api/bill_approvals.php");
$weekly = (string) file_get_contents("{$ROOT}/modules/ap/api/weekly_queue.php");

$submitPattern = <<<'REGEX'
/if \(\$action === 'submit'\) \{(?<block>[\s\S]*?)\n\}\n\nif \(\$action !== 'approve'/
REGEX;
preg_match($submitPattern, $approvals, $m);
$submitBlock = (string) ($m['block'] ?? '');

$finalizePattern = <<<'REGEX'
/function ap_weekly_queue_finalize_one\(int \$tenantId, int \$billId, array \$actor\): array \{(?<block>[\s\S]*?)\n\}\n\n\/\*\*/
REGEX;
preg_match($finalizePattern, $weekly, $wm);
$finalizeBlock = (string) ($wm['block'] ?? '');

echo "Files parse\n";
foreach ([
    'modules/ap/lib/workflow_bridge.php',
    'modules/ap/api/bill_approvals.php',
    'modules/ap/api/weekly_queue.php',
] as $rel) {
    $a("php -l {$rel}", $lint($rel));
}

echo "\nShared submit helper\n";
$a('workflow submit helper exists', str_contains($bridge, 'function apWorkflowSubmitBillForApproval('));
$a('helper routes through AP approval policy router',
    str_contains($bridge, "require_once __DIR__ . '/approval_router.php'")
    && str_contains($bridge, 'apRouteBillForApproval($tenantId, $bill, $routeActorUserId)'));
$a('helper requires WorkflowEngine instance',
    str_contains($bridge, "empty(\$routing['workflow_instance_id'])")
    && str_contains($bridge, 'AP approval route did not create a WorkflowEngine instance'));
$a('helper sets bill pending approval only after routing',
    strpos($bridge, 'apRouteBillForApproval($tenantId, $bill, $routeActorUserId)') !== false
    && strpos($bridge, "SET status = 'pending_approval'") !== false
    && strpos($bridge, 'apRouteBillForApproval($tenantId, $bill, $routeActorUserId)') < strpos($bridge, "SET status = 'pending_approval'"));
$a('helper audits workflow submission evidence',
    str_contains($bridge, "apAudit('ap.bill.approval_submitted'")
    && str_contains($bridge, "'workflow_instance_id' => \$routing['workflow_instance_id']"));

echo "\nCompatibility submit endpoints\n";
$a('bill_approvals submit block found', $submitBlock !== '');
$a('bill_approvals submit delegates to shared helper',
    str_contains($submitBlock, "apWorkflowSubmitBillForApproval(\$tenantId, \$bill, \$userId, 'bill_approvals_submit')"));
$a('bill_approvals submit no longer reads legacy workflow rules',
    !str_contains($submitBlock, 'ap_approval_workflows')
    && !str_contains($submitBlock, 'ap_approval_workflow_rules')
    && !str_contains($submitBlock, ':a1 >= min_amount'));
$a('bill_approvals submit notifies current mirrored step',
    str_contains($submitBlock, 'apBillApprovalCurrentStepApprovers($pdo, $tenantId, $billId)')
    && str_contains($approvals, 'function apBillApprovalCurrentStepApprovers('));
$a('weekly finalize block found', $finalizeBlock !== '');
$a('weekly finalize delegates to shared helper',
    str_contains($finalizeBlock, "apWorkflowSubmitBillForApproval(")
    && str_contains($finalizeBlock, "'weekly_queue_finalize'"));
$a('weekly finalize no longer reads legacy workflow rules',
    !str_contains($finalizeBlock, 'ap_approval_workflows')
    && !str_contains($finalizeBlock, 'ap_approval_workflow_rules')
    && !str_contains($finalizeBlock, ':a1 >= min_amount'));
$a('weekly finalize returns workflow evidence',
    str_contains($finalizeBlock, "'workflow_instance_id' => \$routing['workflow_instance_id'] ?? null")
    && str_contains($finalizeBlock, "'policy_id' => \$routing['policy_id'] ?? null"));

echo "\nAP approval submit workflow alignment smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
