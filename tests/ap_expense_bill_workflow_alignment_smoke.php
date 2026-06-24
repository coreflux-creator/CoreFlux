<?php
/**
 * AP expense-to-bill workflow alignment smoke.
 *
 * Expense report approval may create a payable, but it must not create an
 * already-approved payable or stamp the expense approver as the AP approver.
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

$expenses = (string) file_get_contents("{$ROOT}/modules/ap/api/expenses.php");
$manifest = (string) file_get_contents("{$ROOT}/modules/ap/manifest.php");
$spec = (string) file_get_contents("{$ROOT}/modules/ap/SPEC.md");

$approvePattern = <<<'REGEX'
/if \(\$method === 'POST' && \$action === 'approve'\) \{(?<block>[\s\S]*?)\n\}\n\nif \(\$method === 'POST' && \$action === 'reject'\)/
REGEX;
preg_match($approvePattern, $expenses, $m);
$approveBlock = (string) ($m['block'] ?? '');

$billInsertPattern = <<<'REGEX'
/\$billId = scopedInsert\('ap_bills', \[(?<block>[\s\S]*?)\n        \]\);/
REGEX;
preg_match($billInsertPattern, $approveBlock, $bm);
$billInsertBlock = (string) ($bm['block'] ?? '');

echo "Files parse\n";
foreach ([
    'modules/ap/api/expenses.php',
    'modules/ap/manifest.php',
] as $rel) {
    $a("php -l {$rel}", $lint($rel));
}

echo "\nExpense approval creates controlled AP bill\n";
$a('approve block found', $approveBlock !== '');
$a('expenses.php requires AP approval router',
    str_contains($expenses, "../lib/approval_router.php"));
$a('expense report approval still records expense approver',
    str_contains($approveBlock, 'approved_by_user_id = :u')
    && str_contains($approveBlock, 'Two-eye: cannot approve your own report'));
$a('converted AP bill starts pending approval',
    str_contains($billInsertBlock, "'status'            => 'pending_approval'"));
$a('converted AP bill keeps expense source lineage',
    str_contains($billInsertBlock, "'source'            => 'expense_report'")
    && str_contains($billInsertBlock, "'source_ref_id'     => \$id"));
$a('converted AP bill is created by the report submitter',
    str_contains($approveBlock, '$submitterUserId = (int) ($row[\'submitter_user_id\'] ?? 0)')
    && str_contains($billInsertBlock, "'created_by_user_id'=> \$submitterUserId ?: null"));
$a('converted AP bill is not stamped approved by expense approver',
    !str_contains($billInsertBlock, "'approved_by_user_id'")
    && !str_contains($billInsertBlock, "'approved_at'"));
$a('approval router receives submitter as source actor for SoD',
    str_contains($approveBlock, 'apRouteBillForApproval($tid, $billForRouting, $submitterUserId ?: null)')
    && str_contains($approveBlock, "'submitted_by_user_id' => \$submitterUserId ?: null"));
$a('route success and route gap are both audited',
    str_contains($approveBlock, "apAudit('ap.expense.bill_routed_for_approval'")
    && str_contains($approveBlock, "apAudit('ap.expense.bill_routing_failed'"));
$a('expense approval response exposes pending bill status and routing',
    str_contains($approveBlock, "'bill_status' => 'pending_approval'")
    && str_contains($approveBlock, "'routing' => \$routing"));

echo "\nManifest and spec alignment\n";
$a('manifest declares expense bill route events',
    str_contains($manifest, "'ap.expense.bill_routed_for_approval'")
    && str_contains($manifest, "'ap.expense.bill_routing_failed'"));
$a('SPEC documents pending AP approval after expense approval',
    str_contains($spec, 'source=`expense_report` AP bill in `pending_approval`')
    && str_contains($spec, 'Expense approval does not mark the'));

echo "\nAP expense bill workflow alignment smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
