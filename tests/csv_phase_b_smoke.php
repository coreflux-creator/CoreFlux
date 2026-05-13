<?php
/**
 * CSV Phase B smoke (2026-02-XX).
 *
 * Phase B builds on the universal CSV plumbing from earlier today and adds:
 *   - Multi-line CSV import for AP bills (grouped by bill_number)
 *   - Multi-line CSV import for billing invoices (grouped by invoice_number)
 *   - CSV export for AP payments + billing payments
 *   - Update-if-exists mode (?update_existing=1) on people + clients imports
 *   - Bulk CSV importer wizard: drop multiple CSVs at once, auto-detect
 *     entity from header signature, commit in FK-respecting order.
 *
 * Static-analysis style — verifies file shape + key behaviours without
 * requiring a live DB.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "AP bills multi-line CSV import\n";
$bi = $read(__DIR__ . '/../modules/ap/api/bills_csv_import.php');
$a('bills_csv_import file exists',           $bi !== '');
$a('registers ap_bills schema',              str_contains($bi, "registerSchema('ap_bills'"));
foreach (['bill_number','vendor_name','bill_date','due_date','line_no','line_description','line_quantity','line_unit_price','line_total'] as $f) {
    $a("bill schema field: {$f}",            str_contains($bi, "'{$f}'"));
}
$a('bills template action',                  str_contains($bi, "action === 'template'") && str_contains($bi, 'bills_template.csv'));
$a('bills dry_run groups by bill_number',    str_contains($bi, '$groups[$bn]'));
$a('bills first-row header validation',      str_contains($bi, 'required on first row of bill'));
$a('bills commit groups by bill_number',     str_contains($bi, '$groups[$bn][] = $row'));
$a('bills inserts ap_bill_lines',            str_contains($bi, 'INSERT INTO ap_bill_lines'));
$a('bills skips existing bill_number',       str_contains($bi, 'already exists'));
$a('bills wraps lines in transaction',       str_contains($bi, 'beginTransaction'));
$a('bills computes total from lines',        str_contains($bi, "\$subtotal +=") && str_contains($bi, "\$tax      +="));
$a('bills RBAC gate ap.bill.create',         str_contains($bi, "'ap.bill.create'"));
$a('bills audit emitted',                    str_contains($bi, 'ap.bill.csv_imported'));

echo "\nBilling invoices multi-line CSV import\n";
$ii = $read(__DIR__ . '/../modules/billing/api/csv_import.php');
$a('invoices csv_import file exists',        $ii !== '');
$a('registers billing_invoices schema',      str_contains($ii, "registerSchema('billing_invoices'"));
foreach (['invoice_number','client_name','issue_date','due_date','line_description','line_quantity','line_total'] as $f) {
    $a("invoice schema field: {$f}",         str_contains($ii, "'{$f}'"));
}
$a('invoices template action',               str_contains($ii, "action === 'template'") && str_contains($ii, 'invoices_template.csv'));
$a('invoices dry_run groups by number',      str_contains($ii, '$groups[$inv]'));
$a('invoices first-row header validation',   str_contains($ii, 'required on first row of invoice'));
$a('invoices inserts invoice_lines',         str_contains($ii, 'INSERT INTO billing_invoice_lines'));
$a('invoices skips existing invoice number', str_contains($ii, 'already exists'));
$a('invoices wraps lines in transaction',    str_contains($ii, 'beginTransaction'));
$a('invoices defaults status=draft',         str_contains($ii, "'draft'"));
$a('invoices RBAC gate',                     str_contains($ii, "'billing.invoice.draft'"));

echo "\nPayments CSV export\n";
$apx = $read(__DIR__ . '/../modules/ap/api/payments_csv_export.php');
$a('ap payments csv_export exists',          $apx !== '');
$a('ap payments uses CsvExportService',      str_contains($apx, 'Core\\CsvExportService'));
$a('ap payments RBAC gate ap.view',          str_contains($apx, "'ap.view'"));
$a('ap payments status/from/to/vendor filters',
    str_contains($apx, 'pay_date >= :f') &&
    str_contains($apx, 'pay_date <= :t') &&
    str_contains($apx, 'vendor_name = :v'));

$blx = $read(__DIR__ . '/../modules/billing/api/payments_csv_export.php');
$a('billing payments csv_export exists',     $blx !== '');
$a('billing payments uses CsvExportService', str_contains($blx, 'Core\\CsvExportService'));
$a('billing payments RBAC gate billing.view', str_contains($blx, "'billing.view'"));
$a('billing payments received_at filters',
    str_contains($blx, 'received_at >= :f') &&
    str_contains($blx, 'received_at <= :t'));

echo "\nUpdate-if-exists mode\n";
$pc = $read(__DIR__ . '/../modules/people/api/csv_import.php');
$a('people supports ?update_existing=1',     str_contains($pc, "_GET['update_existing']"));
$a('people upserts on duplicate when flag',  str_contains($pc, '$existing && $updateExisting') && str_contains($pc, "scopedUpdate('people'"));
$a('people audit includes update flag',      str_contains($pc, "'update_existing'"));

$cc = $read(__DIR__ . '/../modules/staffing/api/csv_import.php');
$a('clients supports ?update_existing=1',    str_contains($cc, "_GET['update_existing']"));
$a('clients upserts on duplicate when flag', str_contains($cc, '$existing && $updateExisting') && str_contains($cc, "scopedUpdate('staffing_clients'"));

echo "\nBulk CSV import wizard (React)\n";
$bulk = $read(__DIR__ . '/../dashboard/src/pages/CsvBulkImport.jsx');
$a('CsvBulkImport page exists',              $bulk !== '');
$a('accepts multiple files at once',         str_contains($bulk, 'multiple'));
$a('detects entity from header signature',   str_contains($bulk, 'detectEntity') && str_contains($bulk, 'signature:'));
$a('FK-respecting ENTITY_ORDER constant',
    str_contains($bulk, 'ENTITY_ORDER') &&
    str_contains($bulk, "'people'") &&
    str_contains($bulk, "'ap_vendors'") &&
    str_contains($bulk, "'staffing_clients'") &&
    str_contains($bulk, "'placements'") &&
    str_contains($bulk, "'time'") &&
    str_contains($bulk, "'ap_bills'") &&
    str_contains($bulk, "'billing_invoices'"));
$a('orders commit by ENTITY_ORDER',          str_contains($bulk, 'ENTITY_ORDER') && str_contains($bulk, 'flatMap'));
$a('dry-runs all files before commit',       str_contains($bulk, 'dryRunAll'));
$a('skip-invalid flag on commit',            str_contains($bulk, 'skip_invalid=1'));
$a('per-row entity override dropdown',       str_contains($bulk, 'csv-bulk-row-${idx}-entity'));
$a('top-level testid',                       str_contains($bulk, 'data-testid="csv-bulk-import"'));

echo "\nApp routing\n";
$app = $read(__DIR__ . '/../dashboard/src/App.jsx');
$a('App.jsx imports CsvBulkImport',          str_contains($app, "import CsvBulkImport from './pages/CsvBulkImport'"));
$a('App.jsx routes /data/bulk-import',       str_contains($app, '"/data/bulk-import"'));

$apm = $read(__DIR__ . '/../modules/ap/ui/APModule.jsx');
$a('APModule imports BillsCsvImport',        str_contains($apm, "import BillsCsvImport from './BillsCsvImport'"));
$a('APModule mounts bills/csv_import',       str_contains($apm, 'path="bills/csv_import"'));

$blm = $read(__DIR__ . '/../modules/billing/ui/BillingModule.jsx');
$a('BillingModule imports InvoicesCsvImport', str_contains($blm, "import InvoicesCsvImport from './InvoicesCsvImport'"));
$a('BillingModule mounts invoices/csv_import', str_contains($blm, 'path="invoices/csv_import"'));

echo "\nImport/Export buttons wired on list pages\n";
$bl = $read(__DIR__ . '/../modules/ap/ui/BillsList.jsx');
$a('BillsList Import CSV link',              str_contains($bl, 'data-testid="ap-bills-import-csv"'));

$il = $read(__DIR__ . '/../modules/billing/ui/InvoicesList.jsx');
$a('InvoicesList Import CSV link',           str_contains($il, 'data-testid="billing-invoices-import-csv"'));

$app2 = $read(__DIR__ . '/../modules/ap/ui/PaymentsList.jsx');
$a('AP PaymentsList Export all CSV link',    str_contains($app2, 'data-testid="ap-payments-export-all-csv"'));

$blp = $read(__DIR__ . '/../modules/billing/ui/PaymentsList.jsx');
$a('Billing PaymentsList Export CSV link',   str_contains($blp, 'data-testid="billing-payments-export-csv"'));

$dov = $read(__DIR__ . '/../dashboard/src/pages/DashboardOverview.jsx');
$a('Dashboard surfaces bulk-import shortcut', str_contains($dov, 'dashboard-bulk-csv-import'));
$a('Dashboard imports Upload icon',          str_contains($dov, 'Upload'));

echo "\nActionCard accepts data-testid + SPA Link\n";
$uic = $read(__DIR__ . '/../dashboard/src/components/UIComponents.jsx');
$a('ActionCard accepts data-testid prop',    str_contains($uic, "'data-testid': testId"));
$a('ActionCard uses SPA Link for / hrefs',   str_contains($uic, "href.startsWith('/')"));

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
