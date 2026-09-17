<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (string $label, bool $passed) use (&$failures): void {
    echo ($passed ? 'OK  ' : 'BAD ') . $label . PHP_EOL;
    if (!$passed) $failures[] = $label;
};
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

$import = $read('modules/billing/api/items_csv_import.php');
$export = $read('modules/billing/api/items_csv_export.php');
$page = $read('modules/billing/ui/ItemsCsvImport.jsx');
$catalog = $read('modules/billing/ui/ItemsCatalog.jsx');
$module = $read('modules/billing/ui/BillingModule.jsx');
$manifest = $read('modules/billing/manifest.php');
$samples = $read('core/csv_samples.php');
$deployPath = $root . '/.github/workflows/deploy-light-workspace.yml';
$deploy = is_file($deployPath) ? (string) file_get_contents($deployPath) : null;

foreach (['modules/billing/api/items_csv_import.php', 'modules/billing/api/items_csv_export.php'] as $file) {
    $output = [];
    $status = 0;
    exec('php -l ' . escapeshellarg($root . '/' . $file) . ' 2>&1', $output, $status);
    $check("PHP lint {$file}", $status === 0);
}

$check('catalog import uses the shared CSV service', str_contains($import, 'use Core\\CsvImportService'));
$check('catalog schema covers editable business fields',
    str_contains($import, "'item_id' =>")
    && str_contains($import, "'default_unit_price' =>")
    && str_contains($import, "'gl_revenue_account_code' =>")
    && str_contains($import, "'taxable' =>")
    && str_contains($import, "'active' =>"));
$check('import offers template sample inspection AI mapping preview and commit',
    str_contains($import, "['template', 'sample']")
    && str_contains($import, "action === 'inspect'")
    && str_contains($import, "action === 'ai_suggest_map'")
    && str_contains($import, "action === 'dry_run'")
    && str_contains($import, "action === 'commit'"));
$check('round trip matches existing items by ID external ID then code',
    str_contains($import, "!empty(\$row['item_id'])")
    && str_contains($import, "!empty(\$row['external_id'])")
    && str_contains($import, 'WHERE tenant_id = :tenant_id AND code = :code LIMIT 1'));
$check('updates require the explicit update-existing switch',
    str_contains($import, "\$updateExisting = !empty(\$_GET['update_existing'])")
    && str_contains($import, 'enable Update existing rows'));
$check('revenue account references are validated during preview and commit',
    substr_count($import, 'account_type = "revenue"') >= 2
    && str_contains($import, 'not an active postable revenue account'));
$check('export contains the same fields needed for re-import',
    str_contains($export, "'item_id' => 'Item ID'")
    && str_contains($export, "'default_unit_price' => 'Default unit price'")
    && str_contains($export, "'external_id' => 'External ID (audit / integration)'")
    && str_contains($export, "'source_system' => 'Source system'"));
$check('export follows status type and search filters',
    str_contains($export, "\$_GET['active']")
    && str_contains($export, "\$_GET['item_type']")
    && str_contains($export, "\$_GET['q']"));
$check('catalog page exposes import and current-view export',
    str_contains($catalog, 'billing-items-import-csv')
    && str_contains($catalog, 'billing-items-export-csv')
    && str_contains($catalog, 'Export current view'));
$check('import page defaults to safe round-trip updates',
    str_contains($page, 'defaultUpdateExisting')
    && str_contains($page, 'Update matching item IDs, external IDs, or codes'));
$check('Billing router mounts the catalog import page',
    str_contains($module, "import ItemsCsvImport from './ItemsCsvImport'")
    && str_contains($module, 'path="items/csv_import"'));
$check('sample pack includes placement-free catalog rows', str_contains($samples, "'billing_items' => ["));
$check('catalog import and export emit audit events',
    str_contains($manifest, "'billing.item.csv_imported'")
    && str_contains($manifest, "'billing.item.csv_exported'"));
if ($deploy !== null) {
    $check('light deployment ships and verifies the new surfaces',
        substr_count($deploy, 'modules/billing/api/items_csv_import.php') >= 2
        && substr_count($deploy, 'modules/billing/api/items_csv_export.php') >= 2
        && str_contains($deploy, 'modules/billing/ui/ItemsCsvImport.jsx')
        && str_contains($deploy, 'php tests/billing_items_csv_roundtrip_smoke.php'));
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Billing items CSV round-trip smoke passed.' . PHP_EOL;
