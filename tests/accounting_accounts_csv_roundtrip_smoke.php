<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$import = (string) file_get_contents($root . '/modules/accounting/api/accounts_csv_import.php');
$export = (string) file_get_contents($root . '/modules/accounting/api/export.php');
$datasets = (string) file_get_contents($root . '/core/export_datasets.php');
$bulk = (string) file_get_contents($root . '/dashboard/src/pages/CsvBulkImport.jsx');
$chart = (string) file_get_contents($root . '/modules/accounting/ui/ChartOfAccounts.jsx');
$route = (string) file_get_contents($root . '/modules/accounting/ui/AccountingModule.jsx');

$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok) use (&$passed, &$failed): void {
    if ($ok) { $passed++; echo "PASS: {$label}\n"; return; }
    $failed++; echo "FAIL: {$label}\n";
};

$check('account importer uses shared CSV service', str_contains($import, 'use Core\\CsvImportService'));
$check('round-trip schema carries stable ID and parent code',
    str_contains($import, "'account_id'") && str_contains($import, "'parent_account_code'"));
$check('updates are explicit and stable-ID first',
    str_contains($import, "_GET['update_existing']")
    && str_contains($import, "if (!empty(\$row['account_id']))"));
$check('posted account classification is protected',
    str_contains($import, 'posted accounts cannot change accounting classification')
    && str_contains($import, 'must remain postable'));
$check('custom validation errors are skipped before persistence',
    str_contains($import, "if (isset(\$errors[\$rowNumber]))")
    && str_contains($import, 'no custom-invalid row can leak into the ledger'));
$check('granular cash-flow classifications survive a round trip',
    str_contains($import, 'supports granular prefix-based classifications')
    && !preg_match("/'cash_flow_tag'.*?'enum'/s", $import));
$check('parent integrity and cycle protection are enforced',
    str_contains($import, 'Parent and child must have the same account type')
    && str_contains($import, 'Parent selection would create a cycle'));
$check('export carries stable and portable parent identity',
    str_contains($export, "'account_id'")
    && str_contains($export, "'parent_account_code'")
    && str_contains($datasets, 'parent.code AS parent_account_code'));
$check('bulk hub exposes chart round trip before dependent item data',
    str_contains($bulk, "'accounting_accounts', 'billing_items'")
    && str_contains($bulk, "endpoint: '/modules/accounting/api/accounts_csv_import.php'"));
$check('chart exposes adjacent import and export controls',
    str_contains($chart, 'data-testid="accounting-accounts-export"')
    && str_contains($chart, 'data-testid="accounting-accounts-import"'));
$check('accounting module exposes dedicated account import route',
    str_contains($route, 'path="accounts/import"') && str_contains($route, 'AccountsCsvImport'));

foreach ([$root . '/modules/accounting/api/accounts_csv_import.php', $root . '/modules/accounting/api/export.php', $root . '/core/export_datasets.php'] as $file) {
    $out = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
    $check('PHP syntax: ' . basename($file), $code === 0);
}

echo "Accounting account CSV round-trip smoke: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
