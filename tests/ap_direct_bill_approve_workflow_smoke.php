<?php
/**
 * AP direct bill approval compatibility smoke.
 *
 * Locks the alignment rule that /modules/ap/api/bills.php?action=approve is
 * a compatibility adapter over WorkflowEngine, not a direct status writer.
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

$bills = (string) file_get_contents("{$ROOT}/modules/ap/api/bills.php");
$bridge = (string) file_get_contents("{$ROOT}/modules/ap/lib/workflow_bridge.php");
$sync = (string) file_get_contents("{$ROOT}/modules/ap/lib/workflow_sync.php");
$approvals = (string) file_get_contents("{$ROOT}/modules/ap/api/bill_approvals.php");

$approvePattern = <<<'REGEX'
/if \(\$method === 'POST' && \$action === 'approve'\) \{(?<block>[\s\S]*?)\n\}\n\nif \(\$method === 'POST' && \$action === 'void'\)/
REGEX;
preg_match($approvePattern, $bills, $m);
$approveBlock = (string) ($m['block'] ?? '');

echo "Files parse\n";
foreach ([
    'modules/ap/api/bills.php',
    'modules/ap/api/bill_approvals.php',
    'modules/ap/lib/workflow_bridge.php',
    'modules/ap/lib/workflow_sync.php',
] as $rel) {
    $a("php -l {$rel}", $lint($rel));
}

echo "\nDirect bill approve is workflow-backed\n";
$a('approve block found', $approveBlock !== '');
$a('bills.php requires workflow bridge', str_contains($bills, "modules/ap/lib/workflow_bridge.php") || str_contains($bills, "../lib/workflow_bridge.php"));
$a('direct approve keeps AP validation gates',
    str_contains($approveBlock, "apBillTransitionAllowed(\$row['status'], 'approved')")
    && str_contains($approveBlock, 'cannot approve your own bill')
    && str_contains($approveBlock, 'apThreeWayMatch($tid, $id)'));
$a('direct approve delegates to WorkflowEngine bridge',
    str_contains($approveBlock, 'apWorkflowActBillApproval(')
    && str_contains($approveBlock, "apWorkflowDecisionHttpStatus(\$e)"));
$a('blocked direct decisions are audited',
    str_contains($approveBlock, "apAudit('ap.bill.approval_blocked'")
    && str_contains($approveBlock, "'control' => 'workflow_engine'"));
$a('direct approve no longer writes approved status itself',
    !str_contains($approveBlock, 'UPDATE ap_bills SET status = "approved"')
    && !str_contains($approveBlock, "UPDATE ap_bills SET status = 'approved'"));
$a('direct approve no longer enqueues accounting itself',
    !str_contains($approveBlock, 'accountingTryEnqueueDraft('));
$a('3-way match gate runs before workflow handoff',
    strpos($approveBlock, 'apThreeWayMatch($tid, $id)') !== false
    && strpos($approveBlock, 'apWorkflowActBillApproval(') !== false
    && strpos($approveBlock, 'apThreeWayMatch($tid, $id)') < strpos($approveBlock, 'apWorkflowActBillApproval('));

echo "\nShared AP workflow bridge\n";
$a('bridge locates pending ap_bill workflow instances',
    str_contains($bridge, "subject_type = 'ap_bill'") && str_contains($bridge, "status = 'pending'"));
$a('bridge can route missing workflow through AP approval router',
    str_contains($bridge, "require_once __DIR__ . '/approval_router.php'")
    && str_contains($bridge, 'apRouteBillForApproval($tenantId, $bill, $routeActorUserId)'));
$a('bridge does not make the acting approver the routing originator',
    str_contains($bridge, "\$routeActorUserId = !empty(\$bill['created_by_user_id'])")
    && str_contains($bridge, 'workflowAct('));
$a('bridge invokes WorkflowEngine action gate',
    str_contains($bridge, "core/workflow_engine.php") && str_contains($bridge, 'workflowAct('));
$a('bridge fails closed when no workflow instance is created',
    str_contains($bridge, 'AP approval route did not create a WorkflowEngine instance'));
$a('bridge maps approver and SoD failures to 403',
    str_contains($bridge, 'not an approver') && str_contains($bridge, 'separation of duties') && str_contains($bridge, 'return 403;'));
$a('legacy bill_approvals includes bridge for shared compatibility',
    str_contains($approvals, "../lib/workflow_bridge.php"));

echo "\nWorkflow sync owns final AP approval side effects\n";
$a('workflow sync stamps final approver and approval time',
    str_contains($sync, 'approved_by_user_id = COALESCE(approved_by_user_id, :u)')
    && str_contains($sync, 'approved_at = COALESCE(approved_at, NOW())'));
$a('workflow sync emits approved audit from workflow source',
    str_contains($sync, "apAudit('ap.bill.approved'")
    && str_contains($sync, "'source' => 'workflow'"));
$a('workflow sync enqueues accounting draft after approved update',
    str_contains($sync, "core/accounting/command_service.php")
    && str_contains($sync, "accountingTryEnqueueDraft(\$tenantId, 'bill', \$bill, \$userId);"));
$a('workflow sync only fires side effects on actual status transition',
    str_contains($sync, '$upd->rowCount() > 0'));

echo "\nAP direct bill approve workflow smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
