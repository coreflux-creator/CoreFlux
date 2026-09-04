<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api  = (string) file_get_contents($root . '/modules/treasury/api/account_transactions.php');
$ui   = (string) file_get_contents($root . '/modules/treasury/ui/AccountTransactions.jsx');
$css  = (string) file_get_contents($root . '/dashboard/src/styles.css');

$passed = 0;
$failed = 0;
$assert = static function (string $label, bool $ok) use (&$passed, &$failed): void {
    echo '  ' . ($ok ? '✓' : '✗') . " {$label}\n";
    $ok ? $passed++ : $failed++;
};

echo "Bank feed review workspace\n";
echo "==========================\n";

$lint = (string) shell_exec('php -l ' . escapeshellarg($root . '/modules/treasury/api/account_transactions.php') . ' 2>&1');
$assert('API parses', str_contains($lint, 'No syntax errors detected'));
$assert('API supports workflow status filter', str_contains($api, "['pending', 'posted', 'excluded', 'all']"));
$assert('API supports server-side search', str_contains($api, "\$_GET['q']") && str_contains($api, 'searchFields'));
$assert('API supports flexible ordering', str_contains($api, 'newest_first') && str_contains($api, 'oldest_first') && str_contains($api, 'amount_desc'));
$assert('API returns all tab counts', str_contains($api, "'status_counts'") && str_contains($api, "'excluded_count'"));
$assert('API returns bank and G/L balances', str_contains($api, "'bank_balance'") && str_contains($api, "'gl_balance'") && str_contains($api, "'balance_difference'"));
$assert('API anchors per-line running balances', str_contains($api, "'running_balance'") && str_contains($api, '$runningById'));
$assert('running balance uses all account activity', str_contains($api, 'filtering never') && str_contains($api, 'ORDER BY posted_date DESC, id DESC'));

$assert('Pending, Posted, and Excluded tabs render', str_contains($ui, "id: 'pending'") && str_contains($ui, "id: 'posted'") && str_contains($ui, "id: 'excluded'"));
$assert('transaction search drives the API', str_contains($ui, 'treasury-bank-feed-search') && str_contains($ui, 'encodeURIComponent(debouncedSearch)'));
$assert('sort control renders', str_contains($ui, 'treasury-bank-feed-sort'));
$assert('balance cards render', str_contains($ui, 'treasury-bank-balance') && str_contains($ui, 'treasury-gl-balance') && str_contains($ui, 'treasury-balance-difference'));
$assert('running balance column renders', str_contains($ui, 'treasury-txn-running-balance-${r.id}'));
$assert('rows and visible set are selectable', str_contains($ui, 'treasury-bank-feed-select-all') && str_contains($ui, 'treasury-txn-select-${r.id}'));
$assert('bulk post and exclude actions render', str_contains($ui, 'treasury-bank-feed-bulk-post') && str_contains($ui, 'treasury-bank-feed-bulk-exclude'));
$assert('bulk restore action renders', str_contains($ui, 'treasury-bank-feed-bulk-restore'));
$assert('bulk JE creation stays sequential', str_contains($ui, 'for (const lineId of ids)'));
$assert('G/L account picker is searchable', str_contains($ui, 'role="combobox"') && str_contains($ui, 'Search G/L accounts'));
$assert('G/L account can be created inline', str_contains($ui, '+ Add new G/L account') && str_contains($ui, 'Create and select') && str_contains($ui, 'api.post(ACCOUNTING_ACCOUNTS_API'));
$assert('searchable picker is used for single and split posting', str_contains($ui, 'treasury-txn-counterpart-${line.id}') && str_contains($ui, 'treasury-txn-split-account-${line.id}-${i}'));
$assert('domain-aware controls disable generic list tools', str_contains($ui, 'data-list-tools="off"'));
$assert('responsive bank feed styles exist', str_contains($css, '.bank-feed-summary') && str_contains($css, '.gl-account-picker__menu'));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
