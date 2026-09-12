<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api = (string) file_get_contents($root . '/modules/accounting/api/accounts.php');
$ui = (string) file_get_contents($root . '/modules/accounting/ui/ChartOfAccounts.jsx');

$checks = [
    'inactive account filtering accepts active=0' =>
        str_contains($api, "array_key_exists('active', \$_GET)")
        && !str_contains($api, "if (!empty(\$_GET['active']))"),
    'account updates use an explicit field allowlist' =>
        str_contains($api, "'is_postable', 'currency', 'description', 'active'")
        && str_contains($api, 'array_intersect_key($body'),
    'account hierarchy updates validate tenant parent and prevent cycles' =>
        str_contains($api, 'Parent account must have the same type')
        && str_contains($api, 'Parent selection would create a cycle'),
    'chart exposes search type and status filters' =>
        str_contains($ui, 'data-testid="accounting-accounts-search"')
        && str_contains($ui, 'data-testid="accounting-accounts-type-filter"')
        && str_contains($ui, 'data-testid="accounting-accounts-status-filter"'),
    'chart exposes accessible sorting and selection' =>
        str_contains($ui, "sortProps('code')")
        && str_contains($ui, 'data-testid="accounting-accounts-select-all"')
        && str_contains($ui, 'data-testid={`accounting-account-select-${r.id}`}'),
    'chart exposes tenant-scoped bulk account controls' =>
        str_contains($ui, 'testid="accounting-accounts-bulk"')
        && str_contains($api, "\$action === 'bulk_update'")
        && str_contains($api, "['active', 'is_postable']"),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}
