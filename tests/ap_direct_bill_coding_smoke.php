<?php
/** Direct-bill account eligibility and unposted coding correction contracts. */
declare(strict_types=1);

$checks = 0;
$failures = 0;
$assert = static function (bool $ok, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
};
$read = static fn(string $path): string => (string) file_get_contents(__DIR__ . '/../' . $path);

$accounts = $read('core/accounting/system_accounts.php');
$api = $read('modules/accounting/api/accounts.php');
$drafts = $read('modules/ap/lib/bill_drafts.php');
$bills = $read('modules/ap/api/bills.php');
$create = $read('modules/ap/ui/BillCreate.jsx');
$detail = $read('modules/ap/ui/BillDetail.jsx');
$choices = $read('modules/ap/ui/directBillAccounts.js');
$codingStart = strpos($bills, "if (\$method === 'PATCH' && \$action === 'coding')");
$codingEnd = $codingStart === false ? false : strpos($bills, "if (\$method === 'PATCH')", $codingStart);
$codingRoute = $codingStart === false || $codingEnd === false ? '' : substr($bills, $codingStart, $codingEnd - $codingStart);

$assert(str_contains($accounts, "'6200', 'name' => 'Contract Services Expense'")
    && str_contains($accounts, 'ACCOUNTING_STARTER_ACCOUNTS')
    && str_contains($accounts, '1, 0, :section, :sort, 1)'), 'General contract services is a non-system starter account');
$assert(str_contains($api, "'required_dimensions'") && str_contains($api, "'blocked_dimensions'"), 'Accounts API exposes effective dimension rules');
$assert(str_contains($drafts, 'function apValidateDirectBillExpenseAccounts'), 'Direct-bill account validator exists');
$assert(str_contains($drafts, "array_diff(\$required, ['vendor', 'legal_entity'])"), 'Direct bill rejects missing posting dimensions');
$assert(str_contains($drafts, "['vendor', 'legal_entity'], true"), 'Direct bill rejects blocked document dimensions');
$assert(str_contains($drafts, "if (empty(\$body['placement_id'])) {")
    && str_contains($drafts, "apValidateDirectBillExpenseAccounts(\$tenantId, \$body['lines'])"), 'Placement-free manual bills validate coding before persistence');
$assert(str_contains($choices, "account.account_type") && str_contains($choices, "disabled: missing.length > 0 || blocked.length > 0"), 'Account picker disables ineligible expense codes');
$assert(str_contains($create, 'directBillAccountChoices') && str_contains($create, 'include_required_dimensions=1'), 'Bill creator uses effective account rules');
$assert($codingRoute !== '', 'Coding route is separate from generic bill edit');
$assert(str_contains($codingRoute, "rbac_legacy_require(\$user, 'ap.bill.create')"), 'Coding route requires AP create permission');
$assert(str_contains($codingRoute, "\$bill['status'] === 'approved' && !rbac_legacy_can(\$user, 'ap.bill.post')"), 'Approved bill recoding requires posting permission');
$assert(str_contains($codingRoute, 'apBillHasLedgerOrPaymentActivity($pdo, $tid, $bill)'), 'Posted or paid bill recoding is refused');
$assert(str_contains($codingRoute, "apValidateDirectBillExpenseAccounts(\$tid, [['gl_expense_account_code' => \$replacement]])"), 'Replacement account is validated server-side');
$assert(str_contains($codingRoute, "'expected_gl_expense_account_code'") && str_contains($codingRoute, "'coding_changes' => \$changes"), 'Coding compare-and-swap and audit trail are present');
$assert(str_contains($detail, 'data-testid="ap-bill-edit-coding"') && str_contains($detail, 'data-testid="ap-bill-save-coding"'), 'Approved bill has correction controls');

echo "{$checks} checks; {$failures} failures" . PHP_EOL;
exit($failures === 0 ? 0 : 1);
