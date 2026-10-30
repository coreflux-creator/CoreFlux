<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api = (string) file_get_contents($root . '/modules/accounting/api/accounts.php');
$ui = (string) file_get_contents($root . '/modules/accounting/ui/ChartOfAccounts.jsx');
$detail = (string) file_get_contents($root . '/modules/accounting/ui/AccountDetail.jsx');
$mutation = (string) file_get_contents($root . '/core/accounting/account_mutation.php');

$checks = [
    'inactive account filtering accepts active=0' =>
        str_contains($api, "array_key_exists('active', \$_GET)")
        && !str_contains($api, "if (!empty(\$_GET['active']))"),
    'account updates use an explicit field allowlist' =>
        str_contains($api, "'is_postable', 'currency', 'cash_flow_tag', 'description', 'active'")
        && str_contains($api, 'array_intersect_key($body')
        && str_contains($api, 'accountingReviewAccountChange($tid, $current, $body)'),
    'account hierarchy updates validate tenant parent and prevent cycles' =>
        str_contains($mutation, 'Parent account must have the same account type.')
        && str_contains($mutation, 'Parent selection would create a cycle.'),
    'chart exposes search type and status filters' =>
        str_contains($ui, 'data-testid="accounting-accounts-search"')
        && str_contains($ui, 'data-testid="accounting-accounts-type-filter"')
        && str_contains($ui, 'data-testid="accounting-accounts-status-filter"'),
    'chart uses canonical account types in filters and account creation' =>
        str_contains($ui, 'const accountTypes = data?.types ?? Object.keys(TYPE_META)')
        && substr_count($ui, 'accountTypes.map((t)') === 2
        && str_contains($ui, "other_income: { label: 'Other income'")
        && str_contains($ui, "other_expense: { label: 'Other expense'"),
    'chart defaults to active accounts instead of legacy inactive rows' =>
        str_contains($ui, "useState('1')")
        && str_contains($ui, '<option value="1">Active</option>'),
    'chart paginates large account lists and reports the visible range' =>
        str_contains($ui, 'data-testid="accounting-accounts-pagination"')
        && str_contains($ui, 'data-testid="accounting-accounts-result-count"')
        && str_contains($ui, 'visibleTree.slice((page - 1) * perPage, page * perPage)')
        && str_contains($ui, 'pagedTree.map(item => item.row)'),
    'chart exposes accessible sorting and selection' =>
        str_contains($ui, "sortProps('code')")
        && str_contains($ui, 'data-testid="accounting-accounts-select-all"')
        && str_contains($ui, 'data-testid={`accounting-account-select-${r.id}`}'),
    'chart exposes tenant-scoped bulk account controls' =>
        str_contains($ui, 'testid="accounting-accounts-bulk"')
        && str_contains($api, "\$action === 'bulk_update'")
        && str_contains($api, "['active', 'is_postable']"),
    'account detail offers validated account corrections' =>
        str_contains($detail, 'data-testid="accounting-account-edit-trigger"')
        && str_contains($detail, 'data-testid="accounting-account-edit-form"')
        && str_contains($detail, 'api.patch(`/modules/accounting/api/accounts.php?id=${account.id}`')
        && str_contains($detail, 'Existing journal history will remain available.'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}
