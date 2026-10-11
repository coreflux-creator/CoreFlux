<?php
/** Generated AP coding contracts, including legacy uncoded bill recovery. */
declare(strict_types=1);

require_once __DIR__ . '/../modules/ap/lib/ap.php';

$checks = 0;
$assert = static function (bool $ok, string $label) use (&$checks): void {
    $checks++;
    if (!$ok) throw new RuntimeException($label);
    echo "PASS {$label}\n";
};

$labor = ['item_type' => 'labor', 'source_type' => 'time_entry', 'placement_id' => 42];
$assert(apGeneratedExpenseAccountCode($labor, '1099_individual') === '5070',
    'individual approved time is contractor cost');
$assert(apGeneratedExpenseAccountCode($labor, 'c2c_corp') === '5010'
    && apGeneratedExpenseAccountCode($labor, 'w9_business') === '5010',
    'business approved time is subcontractor cost');
$assert(apGeneratedExpenseAccountCode(['item_type' => 'fixed_fee'], 'w9_business') === '5080',
    'placement economic fees are other direct placement cost');
$assert(apGeneratedExpenseAccountCode($labor + ['gl_expense_account_code' => '5095'], '1099_individual') === '5095',
    'an explicitly chosen direct-cost account is preserved');
try {
    apGeneratedExpenseAccountCode($labor, 'other');
    throw new RuntimeException('Unclassified labor vendor was accepted');
} catch (RuntimeException $e) {
    $assert(str_contains($e->getMessage(), 'Classify the staffing vendor'),
        'unclassified staffing labor is held for vendor review');
}

$generated = ['source' => 'time_entries'];
$assert(apBillCodingMode($generated, [$labor]) === 'generated',
    'placement-owned approved time can be recoded');
$assert(apBillCodingMode(['source' => 'manual'], [['source_type' => 'manual', 'placement_id' => null]]) === 'direct',
    'manual direct-bill correction remains available');
$assert(apBillCodingMode($generated, [['source_type' => 'time_entry', 'placement_id' => null]]) === null,
    'generated bills without a source placement cannot be recoded as direct bills');

$bills = (string) file_get_contents(__DIR__ . '/../modules/ap/api/bills.php');
$detail = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/BillDetail.jsx');
$choices = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/directBillAccounts.js');
$accounts = (string) file_get_contents(__DIR__ . '/../modules/accounting/api/accounts.php');
$assert(substr_count($bills, 'apRequireGeneratedBillExpenseCoding($tid, $row,') === 2,
    'normal and intercompany posting refuse uncoded generated bills');
$assert(str_contains($bills, 'apBillCodingMode($bill, $storedLines)')
    && str_contains($bills, 'apValidateGeneratedBillExpenseAccounts($tid, [$replacement])'),
    'compare-and-swap coding accepts only valid generated direct-cost accounts');
$assert(str_contains($detail, 'ap-bill-coding-required')
    && str_contains($detail, 'uncodedGenerated')
    && str_contains($choices, "account.subtype === 'cogs'"),
    'bill screen exposes coding gaps and limits generated choices to direct cost');
$assert(str_contains($accounts, 'account_type, subtype, statement_section, normal_side'),
    'accounts API supplies the classification required by the picker');

echo "{$checks} checks\n";
