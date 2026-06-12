<?php
/**
 * CSV Universal Import/Export smoke (2026-02-XX).
 *
 * Per HARD_RULES every primary-entity module MUST expose CSV import +
 * export. Before this round the only entities wired were people,
 * placements, and time entries. This batch adds:
 *   - vendors (AP)         import + export
 *   - clients (Staffing)   import + export
 *   - bills (AP)           export only
 *   - invoices (Billing)   export only
 *   - timesheets           export (import already existed)
 * Plus a shared Core\CsvExportService primitive and a shared
 * CsvImportPage React component to keep modules DRY.
 *
 * Static-analysis style — verifies file shape + schema fields without
 * needing a live DB.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "Core\\CsvExportService primitive\n";
$svc = $read(__DIR__ . '/../core/CsvExportService.php');
$a('CsvExportService file exists',           $svc !== '');
$a('namespaced under Core',                  str_contains($svc, 'namespace Core'));
$a('class CsvExportService declared',        str_contains($svc, 'class CsvExportService'));
$a('static toString() helper',               str_contains($svc, 'public static function toString'));
$a('streaming stream() method',              str_contains($svc, 'public function stream'));
$a('streams via php://output',               str_contains($svc, "fopen('php://output'"));
$a('emits Content-Type text/csv header',     str_contains($svc, 'Content-Type: text/csv'));
$a('emits attachment Content-Disposition',   str_contains($svc, 'Content-Disposition: attachment'));
$a('serialises booleans to 0/1',             str_contains($svc, 'is_bool($v)'));
$a('serialises arrays to JSON',              str_contains($svc, 'json_encode'));

echo "\nVendors CSV import (AP)\n";
$ven = $read(__DIR__ . '/../modules/ap/api/csv_import.php');
$a('vendor csv_import file exists',          $ven !== '');
$a('uses Core\\CsvImportService',            str_contains($ven, 'use Core\\CsvImportService'));
$a('registers ap_vendors schema',            str_contains($ven, "registerSchema('ap_vendors'"));
foreach (['vendor_name','vendor_type','vendor_category','default_terms','remit_to_email','requires_1099','tax_id_last4'] as $f) {
    $a("vendor schema field: {$f}",          str_contains($ven, "'{$f}'"));
}
$a('vendor template action',                 str_contains($ven, "action === 'template'") && str_contains($ven, 'vendors_template.csv'));
$a('vendor dry_run action',                  str_contains($ven, "action === 'dry_run'") && str_contains($ven, "dryRun('ap_vendors'"));
$a('vendor commit action',                   str_contains($ven, "action === 'commit'") && str_contains($ven, "commit('ap_vendors'"));
$a('vendor upsert by name',                  str_contains($ven, 'ON DUPLICATE KEY UPDATE'));
$a('vendor RBAC gate ap.bill.create',        str_contains($ven, "'ap.bill.create'"));
$a('vendor audit emitted',                   str_contains($ven, 'ap.vendor.csv_imported'));

echo "\nClients CSV import (Staffing)\n";
$cli = $read(__DIR__ . '/../modules/staffing/api/csv_import.php');
$a('client csv_import file exists',          $cli !== '');
$a('uses Core\\CsvImportService',            str_contains($cli, 'use Core\\CsvImportService'));
$a('registers staffing_clients schema',      str_contains($cli, "registerSchema('staffing_clients'"));
foreach (['name','legal_name','industry','primary_contact_email','billing_city','payment_terms_days','status'] as $f) {
    $a("client schema field: {$f}",          str_contains($cli, "'{$f}'"));
}
$a('client template action',                 str_contains($cli, "action === 'template'") && str_contains($cli, 'clients_template.csv'));
$a('client dry_run action',                  str_contains($cli, "action === 'dry_run'") && str_contains($cli, "dryRun('staffing_clients'"));
$a('client commit action',                   str_contains($cli, "action === 'commit'") && str_contains($cli, "commit('staffing_clients'"));
$a('client duplicate-name rejection',        str_contains($cli, "already exists"));
$a('client RBAC gate staffing.view',         str_contains($cli, "'staffing.view'"));

echo "\nCSV export endpoints\n";
foreach ([
    'people'     => '/../modules/people/api/csv_export.php',
    'placements' => '/../modules/placements/api/csv_export.php',
    'vendors'    => '/../modules/ap/api/csv_export.php',
    'clients'    => '/../modules/staffing/api/csv_export.php',
    'time'       => '/../modules/time/api/csv_export.php',
    'bills'      => '/../modules/ap/api/bills_csv_export.php',
    'invoices'   => '/../modules/billing/api/csv_export.php',
] as $name => $rel) {
    $body = $read(__DIR__ . $rel);
    $a("{$name} csv_export file exists",     $body !== '');
    $a("{$name} uses CsvExportService",      str_contains($body, 'Core\\CsvExportService'));
    $a("{$name} streams as attachment",      str_contains($body, '->stream('));
    $a("{$name} enforces RBAC",              str_contains($body, 'rbac_legacy_require'));
    $a("{$name} scoped to tenant",
        str_contains($body, ':tenant_id') ||
        str_contains($body, 'exportDatasetFetch') ||
        str_contains($body, 'exportTemplateStreamDatasetCsv'));
}

echo "\nShared CSV Import React component\n";
$cmp = $read(__DIR__ . '/../dashboard/src/components/CsvImportPage.jsx');
$a('CsvImportPage component exists',         $cmp !== '');
$a('parameterised endpoint prop',            str_contains($cmp, 'endpoint'));
$a('three-step flow (template/dry_run/commit)',
    str_contains($cmp, '?action=template') &&
    str_contains($cmp, '?action=dry_run') &&
    str_contains($cmp, '?action=commit'));
$a('skip-invalid flow',                      str_contains($cmp, 'skip_invalid=1'));
$a('preview table renders errors',           str_contains($cmp, 'errorsByRow'));
$a('test ids are prefix-driven',             str_contains($cmp, '${testidPrefix}'));

echo "\nVendor + Client import pages\n";
$vp = $read(__DIR__ . '/../modules/ap/ui/VendorsCsvImport.jsx');
$a('VendorsCsvImport.jsx exists',            $vp !== '');
$a('VendorsCsvImport uses shared component', str_contains($vp, 'CsvImportPage'));
$a('VendorsCsvImport points at /ap',         str_contains($vp, '/modules/ap/api/csv_import.php'));
$a('VendorsCsvImport test prefix',           str_contains($vp, 'ap-vendors-csv-import'));

$cp = $read(__DIR__ . '/../modules/staffing/ui/ClientsCsvImport.jsx');
$a('ClientsCsvImport.jsx exists',            $cp !== '');
$a('ClientsCsvImport uses shared component', str_contains($cp, 'CsvImportPage'));
$a('ClientsCsvImport points at /staffing',   str_contains($cp, '/modules/staffing/api/csv_import.php'));
$a('ClientsCsvImport test prefix',           str_contains($cp, 'staffing-clients-csv-import'));

echo "\nModule router wiring\n";
$apm = $read(__DIR__ . '/../modules/ap/ui/APModule.jsx');
$a('APModule imports VendorsCsvImport',      str_contains($apm, "import VendorsCsvImport from './VendorsCsvImport'"));
$a('APModule mounts vendors/csv_import',     str_contains($apm, 'path="vendors/csv_import"'));

$stm = $read(__DIR__ . '/../modules/staffing/ui/StaffingModule.jsx');
$a('StaffingModule imports ClientsCsvImport', str_contains($stm, "import ClientsCsvImport from './ClientsCsvImport'"));
$a('StaffingModule mounts clients/csv_import', str_contains($stm, 'path="clients/csv_import"'));

echo "\nList-page Export/Import wiring\n";
$vl = $read(__DIR__ . '/../modules/ap/ui/VendorsList.jsx');
$a('VendorsList Import CSV link',            str_contains($vl, 'data-testid="ap-vendors-import-csv"'));
$a('VendorsList Export CSV link',            str_contains($vl, 'data-testid="ap-vendors-export-csv"'));

$cl = $read(__DIR__ . '/../modules/staffing/ui/Clients.jsx');
$a('Clients Import CSV link',                str_contains($cl, 'data-testid="staffing-clients-import-csv"'));
$a('Clients Export CSV link',                str_contains($cl, 'data-testid="staffing-clients-export-csv"'));

$pd = $read(__DIR__ . '/../modules/people/ui/Directory.jsx');
$a('Directory Export CSV link',              str_contains($pd, 'data-testid="people-csv-export-btn"'));

$pl = $read(__DIR__ . '/../modules/placements/ui/List.jsx');
$a('Placements Export CSV link',             str_contains($pl, 'data-testid="placements-csv-export-btn"'));

$bl = $read(__DIR__ . '/../modules/ap/ui/BillsList.jsx');
$a('BillsList Export-all CSV link',          str_contains($bl, 'data-testid="ap-bills-export-all-csv"'));

$il = $read(__DIR__ . '/../modules/billing/ui/InvoicesList.jsx');
$a('InvoicesList Export CSV link',           str_contains($il, 'data-testid="billing-invoices-export-csv"'));

$rq = $read(__DIR__ . '/../modules/time/ui/ReviewQueue.jsx');
$a('Time ReviewQueue Export CSV link',       str_contains($rq, 'data-testid="time-review-export-csv"'));

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
