<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api = (string) file_get_contents($root . '/modules/treasury/api/account_transactions.php');
$ui = (string) file_get_contents($root . '/modules/treasury/ui/AccountTransactions.jsx');
$deposits = (string) file_get_contents($root . '/modules/treasury/ui/DepositAccounts.jsx');
$liabilities = (string) file_get_contents($root . '/modules/treasury/ui/LiabilityAccounts.jsx');

$failures = [];
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) $failures[] = $message;
};

$assert(str_contains($api, "'bulk_update'"), 'bulk transaction action is registered');
$bulkPos = strpos($api, "if (\$action === 'bulk_update')");
$lineIdGuardPos = strpos($api, "if (\$lineId <= 0) api_error('line_id required'");
$assert($bulkPos !== false && $lineIdGuardPos !== false && $bulkPos < $lineIdGuardPos,
    'bulk transaction actions do not require a single line_id');
$assert(str_contains($api, "bulk_action must be ignore, restore, or unmatch"), 'bulk state changes are allowlisted');
$assert(str_contains($api, 'AND {$col} = :a'), 'bulk changes stay scoped to the selected account');
$assert(str_contains($api, "category_account_id"), 'category filter is server-backed');
$assert(str_contains($api, "sortColumns"), 'sort columns are allowlisted');
$assert(str_contains($api, "institution_balance"), 'institution balance is returned');
$assert(str_contains($api, "ledger_balance"), 'ledger balance is returned');
$assert(str_contains($api, "'difference'"), 'bank-to-ledger difference is returned');
$assert(str_contains($api, "'pagination'"), 'pagination metadata is returned');
$assert(str_contains($api, 'This transaction is already resolved. Unmatch it before posting a different category.'),
    'repeat categorization is rejected instead of replaying an old posting');

$assert(str_contains($ui, 'treasury-account-balances'), 'register renders balance comparison');
$assert(str_contains($ui, 'treasury-transactions-search'), 'register renders search');
$assert(str_contains($ui, 'treasury-transaction-advanced-filters'), 'register renders advanced filters');
$assert(str_contains($ui, 'SortableHeader'), 'register renders sortable columns');
$assert(str_contains($ui, 'treasury-bulk-actions'), 'register renders bulk actions');
$assert(str_contains($ui, 'QuickRuleBuilder'), 'register renders the rule builder');
$assert(str_contains($ui, 'treasury-transaction-pagination'), 'register renders paging controls');
$assert(str_contains($deposits, '>Difference<'), 'deposit list shows balance difference');
$assert(str_contains($liabilities, '>Difference<'), 'liability list shows balance difference');

$lintOutput = [];
$lintCode = 0;
exec('php -l ' . escapeshellarg($root . '/modules/treasury/api/account_transactions.php'), $lintOutput, $lintCode);
$assert($lintCode === 0, 'account transactions API parses');

if ($failures) {
    fwrite(STDERR, "FAIL\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "PASS treasury account register workspace\n";
