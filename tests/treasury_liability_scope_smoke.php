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
$deposits = (string) file_get_contents($root . '/modules/treasury/ui/DepositAccounts.jsx');
$accountDetail = (string) file_get_contents($root . '/modules/accounting/ui/AccountDetail.jsx');
$accountTerms = (string) file_get_contents($root . '/modules/accounting/api/account_terms.php');
$chart = (string) file_get_contents($root . '/modules/accounting/ui/ChartOfAccounts.jsx');

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
$assert(str_contains($overview, 'useAccountingEntityScope()')
    && str_contains($overview, 'Number(row.entity_id) === scope.entityId')
    && str_contains($overview, '(dep.data?.rows || []).filter(inScope)')
    && str_contains($overview, '(lia.data?.rows || []).filter(inScope)')
    && str_contains($overview, 'treasury-overview-entity'),
    'Treasury overview totals and rows follow the selected legal entity');
$assert(str_contains($overview, "scope.withScope('../deposits')")
    && str_contains($overview, "scope.withScope('../liabilities')")
    && str_contains($overview, 'scope.withScope(`../deposits/${r.id}`)')
    && str_contains($overview, 'scope.withScope(`../liabilities/${r.id}`)'),
    'Treasury overview links preserve the selected entity');
$assert(str_contains($deposits, 'useAccountingEntityScope()')
    && str_contains($deposits, 'scope.allEntities || Number(row.entity_id) === scope.entityId')
    && str_contains($deposits, 'treasury-deposits-entity'),
    'Deposit accounts support selected and all-entity views');
$assert(str_contains($list, 'useAccountingEntityScope()')
    && str_contains($list, 'scope.allEntities || Number(row.entity_id) === scope.entityId')
    && str_contains($list, 'treasury-liabilities-entity')
    && str_contains($list, 'selectedEntityId={scope.entityId}'),
    'Liability accounts and their new-account form follow the selected entity');
$assert(str_contains($api, "require_once __DIR__ . '/../../../core/accounting/account_mutation.php'")
    && str_contains($api, 'accountingReviewAccountChange((int) $ctx[\'tenant_id\'], null, [')
    && str_contains($api, "'account_type' => 'liability'")
    && str_contains($api, "scopedInsert('accounting_accounts', \$accountFields)"),
    'Treasury liability creation uses the shared credit-normal account defaults and validation');
$assert(str_contains($accountTerms, 'SELECT id, account_id, entity_id, subtype')
    && str_contains($accountDetail, 'preferredAccountEntityId(entities, connections, activeEntityId)')
    && str_contains($accountDetail, 'activeEntityLoaded')
    && str_contains($accountDetail, "next.set('entity_id', preferred)"),
    'Account detail selects a linked owner or active legal entity after data loads');
$assert(str_contains($accountDetail, 'accounting-liability-normal-side-warning')
    && str_contains($accountDetail, "{ normal_side: 'credit' }")
    && str_contains($accountDetail, 'accountApi.reload()'),
    'Legacy unposted debit-normal Treasury liabilities can be reviewed and corrected');
$assert(str_contains($api, 'aa.account_type, aa.normal_side, aa.active')
    && str_contains($list, 'treasury-liability-normal-side-')
    && str_contains($overview, 'Review normal balance'),
    'Treasury list and overview flag legacy misclassified liabilities');
$assert(str_contains($chart, 'entityId={detailEntityId}')
    && str_contains($accountDetail, "addEntityScope('/modules/accounting/accounts', entityId)")
    && str_contains($accountDetail, 'addEntityScope(`/modules/treasury/liabilities/${liability.account_id}`, liability.entity_id)'),
    'Account chart, detail and linked Treasury activity retain entity context');

echo "\nPass: {$pass}\nFail: {$fail}\n";
exit($fail === 0 ? 0 : 1);
