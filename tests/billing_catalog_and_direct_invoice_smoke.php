<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$passed = 0; $failed = 0;
$check = static function (string $label, bool $ok) use (&$passed, &$failed): void {
    echo ($ok ? "  OK  " : "  FAIL ") . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

$migration = (string) file_get_contents($root . '/modules/billing/migrations/013_item_catalog.sql');
$api = (string) file_get_contents($root . '/modules/billing/api/items.php');
$invoices = (string) file_get_contents($root . '/modules/billing/api/invoices.php');
$module = (string) file_get_contents($root . '/modules/billing/ui/BillingModule.jsx');
$catalog = (string) file_get_contents($root . '/modules/billing/ui/ItemsCatalog.jsx');
$create = (string) file_get_contents($root . '/modules/billing/ui/InvoiceCreate.jsx');
$list = (string) file_get_contents($root . '/modules/billing/ui/InvoicesList.jsx');
$editor = (string) file_get_contents($root . '/dashboard/src/components/LineItemEditor.jsx');
$billingLib = (string) file_get_contents($root . '/modules/billing/lib/billing.php');

$check('catalog migration creates tenant item table', str_contains($migration, 'CREATE TABLE IF NOT EXISTS billing_items') && str_contains($migration, 'tenant_id BIGINT UNSIGNED NOT NULL'));
$check('catalog code is unique per tenant', str_contains($migration, 'uq_billing_items_tenant_code (tenant_id, code)'));
$check('invoice lines retain catalog reference', str_contains($migration, 'catalog_item_id') && str_contains($migration, 'idx_bil_catalog_item'));
$check('catalog API is authenticated and tenant scoped', str_contains($api, 'api_require_auth()') && substr_count($api, 'tenant_id') >= 8);
$check('catalog API supports search, filters, sorting and paging', str_contains($api, "\$_GET['q']") && str_contains($api, "\$_GET['active']") && str_contains($api, '$sortMap') && str_contains($api, '$perPage'));
$check('catalog API supports create, edit and bulk status', str_contains($api, "action === 'bulk_update'") && str_contains($api, "method === 'PATCH'") && str_contains($api, "method === 'POST' && \$action === ''"));
$check('catalog API validates revenue accounts', str_contains($api, 'account_type = "revenue"') && str_contains($api, 'is_postable = 1'));
$check('billing navigation exposes Products & services', str_contains($module, "label: 'Products & services'") && str_contains($module, '<ItemsCatalog />'));
$check('catalog UI has familiar table controls', str_contains($catalog, 'billing-items-search') && str_contains($catalog, 'billing-items-type-filter') && str_contains($catalog, 'billing-items-status-filter') && str_contains($catalog, 'billing-items-bulk-bar'));
$check('invoice editor supports catalog and custom lines', str_contains($editor, 'catalogItems = null') && str_contains($editor, '<option value="">Custom line</option>'));
$check('direct invoice loads catalog without placement', str_contains($create, '/modules/billing/api/items.php?active=1') && str_contains($create, 'catalogItems={itemsApi.data?.rows ?? []}'));
$check('direct invoice sends selected catalog id', str_contains($create, 'catalog_item_id: l.catalog_item_id || null'));

$manualStart = strpos($invoices, "if (\$method === 'POST' && \$action === '')");
$manualEnd = strpos($invoices, "if (\$method === 'PATCH')", $manualStart ?: 0);
$manual = ($manualStart === false || $manualEnd === false) ? '' : substr($invoices, $manualStart, $manualEnd - $manualStart);
$check('direct invoice does not require placement or approved time', !str_contains($manual, "api_require_fields(\$body, ['placement") && !str_contains($manual, 'approved time'));
$check('direct invoice rejects cross-tenant or inactive catalog items', str_contains($invoices, 'FROM billing_items WHERE tenant_id = ?{$activeSql}'));
$check('direct invoice snapshots catalog defaults', str_contains($invoices, "\$line['description'] = \$item") && str_contains($invoices, "\$line['unit_price'] = \$item"));
$check('direct invoice stores catalog reference', str_contains($invoices, 'catalog_item_id, item_type') && str_contains($invoices, "'catalog_item_id' =>"));
$check('direct draft lines can be edited without breaking time-sourced invoices', str_contains($invoices, 'billingPrepareDirectInvoiceLines($pdo, $tid, $body[\'lines\'], false)') && str_contains($invoices, 'source_type <> "manual"'));
$check('direct invoice edit route and button are exposed', str_contains($module, 'invoices/:id/edit') && str_contains((string) file_get_contents($root . '/modules/billing/ui/InvoiceDetail.jsx'), 'billing-invoice-edit'));
$check('tax input is honored and validated', str_contains($manual, "array_key_exists('tax_rate_pct', \$body)") && str_contains($manual, 'Tax rate must be between 0 and 100'));
$check('default due date is based on issue date', str_contains($manual, 'strtotime("+{$netDays} days", strtotime($issueDate))'));
$check('non-taxable catalog items receive zero line tax', str_contains($billingLib, "array_key_exists('taxable', \$l)") && str_contains($billingLib, '$lineTaxPct'));
$check('normal invoice is primary list action', str_contains($list, 'btn btn--primary') && str_contains($list, 'billing-new-invoice'));
$check('staffing invoice paths are grouped separately', str_contains($list, 'billing-create-from-time-menu') && str_contains($list, 'Create from time'));
$check('manual invoices never use the accrued-time reclassification path', str_contains($invoices, '$allLinesWereAccrued') && str_contains($invoices, "source_type IN (\"time\", \"time_entry\", \"economic_item\")"));
$check('negative discount revenue posts as a debit', str_contains($invoices, "'debit' => \$amt < 0 ? abs(\$amt) : 0") && str_contains($invoices, "'credit' => \$amt > 0 ? \$amt : 0"));

require_once $root . '/modules/billing/lib/billing.php';
$taxed = billingComputeTax([
    ['quantity' => 1, 'unit_price' => 100, 'taxable' => true],
    ['quantity' => 1, 'unit_price' => 50, 'taxable' => false],
], 10.0);
$check('tax calculation excludes non-taxable catalog lines', (float) $taxed['tax_total'] === 10.0 && (float) $taxed['total'] === 160.0);

echo "Passed: {$passed}; Failed: {$failed}" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
