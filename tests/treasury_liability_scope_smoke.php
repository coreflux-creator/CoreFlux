<?php
declare(strict_types=1);

$pass = 0;
$fail = 0;
$assert = static function (bool $condition, string $label) use (&$pass, &$fail): void {
    if ($condition) {
        echo "  PASS {$label}\n";
        $pass++;
        return;
    }
    echo "  FAIL {$label}\n";
    $fail++;
};

$root = dirname(__DIR__);
$api = (string) file_get_contents($root . '/modules/treasury/api/liability_accounts.php');
$transactions = (string) file_get_contents($root . '/modules/treasury/api/account_transactions.php');
$overview = (string) file_get_contents($root . '/modules/treasury/ui/TreasuryOverview.jsx');
$list = (string) file_get_contents($root . '/modules/treasury/ui/LiabilityAccounts.jsx');

$assert(str_contains($api, 'INNER JOIN treasury_liability_accounts tla'),
    'Treasury lists only explicitly managed liability accounts');
$assert(str_contains($api, 'JOIN treasury_liability_accounts tla')
    && str_contains($api, 'Treasury liability account not found'),
    'Hide and delete cannot target ordinary chart-of-accounts liabilities');
$assert(str_contains($transactions, 'JOIN treasury_liability_accounts tla')
    && str_contains($transactions, "api_error('Treasury liability account not found', 404)"),
    'Liability activity cannot expose an ordinary accounting liability');
$assert(str_contains($overview, 'Math.max(0, Number(balanceOf(r))')
    && str_contains($overview, 'Cash after outstanding debt'),
    'Treasury headline does not turn a card credit into cash');
$assert(str_contains($overview, 'account credit excluded'),
    'Card overpayments are explained instead of shown as negative debt');
$assert(str_contains($list, 'liabilityBalance') && str_contains($list, '} credit`'),
    'Negative card balances are labelled as account credits');
$assert(str_contains($list, 'r.plaid_connected &&'),
    'Manual liabilities do not show an unusable Sync action');

echo "\nPass: {$pass}\nFail: {$fail}\n";
exit($fail === 0 ? 0 : 1);
