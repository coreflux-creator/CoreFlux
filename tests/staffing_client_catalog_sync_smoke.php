<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
$assert = static function (string $message, bool $condition) use (&$pass, &$fail): void {
    echo '  ' . ($condition ? "\u{2713}" : "\u{2717}") . " {$message}\n";
    $condition ? $pass++ : $fail++;
};
$read = static fn(string $path): string => (string) file_get_contents($path);

echo "Staffing client catalog source convergence\n";

$clientsApi = $read($root . '/modules/staffing/api/clients.php');
$assert('client API separates active and shared catalog tenants',
    str_contains($clientsApi, '$activeTenantId')
    && str_contains($clientsApi, 'staffingClientCatalogTenantId($activeTenantId)'));
$assert('client API pins scoped CRUD to placements catalog',
    str_contains($clientsApi, "setRequestModuleScope('placements')"));
$assert('client stats retain isolated financial tenant',
    str_contains($clientsApi, "'tenant_id' => \$activeTenantId"));

$clientLib = $read($root . '/modules/staffing/lib/clients.php');
$assert('client catalog helper follows placements scope',
    str_contains($clientLib, "effectiveTenantIdForModule('placements', \$activeTenantId)"));
$assert('client bridge carries canonical company address fields',
    str_contains($clientLib, "'billing_address_line1'  => \$company['address_line1']")
    && str_contains($clientLib, "'billing_postal_code'    => \$company['postal_code']"));

foreach (['csv_import.php', 'csv_export.php'] as $file) {
    $source = $read($root . '/modules/staffing/api/' . $file);
    $assert("{$file} uses shared client catalog",
        str_contains($source, 'staffingClientCatalogTenantId')
        && str_contains($source, "setRequestModuleScope('placements')"));
}
$csvImport = $read($root . '/modules/staffing/api/csv_import.php');
$assert('client CSV import converges through company/client bridge',
    str_contains($csvImport, 'staffingClientEnsureForCompany(')
    && !str_contains($csvImport, "return scopedInsert('staffing_clients'"));
$assert('client bridge preserves existing commercial terms when a source omits them',
    str_contains($clientLib, ': ($existing ? null : 30)'));

$qbo = $read($root . '/core/qbo/sync_in.php');
$assert('QBO customers resolve in shared client tenant',
    str_contains($qbo, '$clientTenantId = staffingClientCatalogTenantId($tenantId)'));
$assert('QBO customers converge through company/client bridge',
    str_contains($qbo, 'staffingClientEnsureForCompany('));
$assert('QBO source mapping remains on connected accounting tenant',
    str_contains($qbo, "mappingUpsert(\$tenantId, QBO_SOURCE, 'customer'"));

$qboInvoices = $read($root . '/core/qbo/sync_invoices.php');
$assert('QBO invoice push resolves customer from shared catalog',
    str_contains($qboInvoices, '$clientTenantId = staffingClientCatalogTenantId($tenantId)')
    && str_contains($qboInvoices, "['t' => \$clientTenantId, 'n' => \$clientName]"));

$billing = $read($root . '/modules/billing/api/invoices.php');
$assert('billing reads client terms from shared catalog',
    str_contains($billing, '$clientCatalogTenantId = staffingClientCatalogTenantId($tid)')
    && str_contains($billing, "['t' => \$clientCatalogTenantId"));
$assert('billing invoices link to the shared canonical company',
    substr_count($billing, 'companiesUpsertByName($clientCatalogTenantId') >= 3);

$migration = $read($root . '/core/migrations/137_staffing_client_catalog_backfill.sql');
$assert('migration creates canonical companies from placement clients',
    str_contains($migration, 'INSERT IGNORE INTO companies')
    && str_contains($migration, 'TRIM(p.end_client_name)'));
$assert('migration creates client consumer rows',
    str_contains($migration, 'INSERT IGNORE INTO staffing_clients')
    && str_contains($migration, 'p.end_client_company_id = c.id'));
$assert('migration relinks placements by canonical company id',
    str_contains($migration, 'sc.company_id = p.end_client_company_id')
    && str_contains($migration, 'SET p.client_id = sc.id'));

$assert('staffing client audit dependency is versioned',
    is_file($root . '/modules/staffing/lib/client_audit.php'));

foreach ([
    'modules/staffing/api/clients.php',
    'modules/staffing/api/csv_import.php',
    'modules/staffing/api/csv_export.php',
    'modules/staffing/lib/clients.php',
    'modules/staffing/lib/client_audit.php',
    'core/qbo/sync_in.php',
    'core/qbo/sync_invoices.php',
    'modules/billing/api/invoices.php',
] as $relative) {
    $out = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($root . '/' . $relative) . ' 2>&1', $out, $code);
    $assert("php -l {$relative}", $code === 0);
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
