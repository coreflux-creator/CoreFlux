<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/core/ModuleRegistry.php';
require_once $root . '/core/export_datasets.php';
require_once $root . '/core/export_templates.php';
require_once $root . '/core/export_service.php';

$pass = 0;
$fail = 0;
$assert = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok  {$name}\n"; }
    else     { $fail++; echo "  no  {$name}\n"; }
};

echo "Dataset governance\n";
$reg = exportDatasetRegistry();
$moduleRegistry = ModuleRegistry::reset();
$manifestDatasets = $moduleRegistry->getExportDatasetDeclarations();
$required = ['label', 'module_id', 'permission', 'formats', 'audit_event', 'sensitive_fields', 'custom_field_entities', 'fetcher', 'fields'];
foreach ([
    'payroll_disbursements',
    'ap_payments',
    'ap_bills',
    'ap_vendors',
    'expenses',
    'accounting_chart_of_accounts',
    'accounting_journal_entries',
    'accounting_gl_detail',
    'accounting_periods',
    'accounting_bank_statement_lines',
    'billing_invoices',
    'billing_payments',
    'time_entries',
    'staffing_clients',
    'people_directory',
    'placements_directory',
] as $dataset) {
    $assert("dataset registered: {$dataset}", isset($reg[$dataset]));
    foreach ($required as $key) {
        $assert("{$dataset} has {$key}", array_key_exists($key, $reg[$dataset] ?? []));
    }
    $decl = $manifestDatasets[$dataset] ?? null;
    $assert("manifest declares dataset: {$dataset}", is_array($decl));
    if ($decl && isset($reg[$dataset])) {
        $assert("manifest owner matches registry: {$dataset}", ($decl['module_id'] ?? null) === ($reg[$dataset]['module_id'] ?? null));
        $assert("manifest permission matches registry: {$dataset}", ($decl['permission'] ?? null) === ($reg[$dataset]['permission'] ?? null));
        $assert("manifest audit event matches registry: {$dataset}", ($decl['audit_event'] ?? null) === ($reg[$dataset]['audit_event'] ?? null));
    }
}

$assert('payroll bank account sensitive', exportDatasetIsSensitiveField('payroll_disbursements', 'bank_account_number'));
$assert('ap bank routing sensitive', exportDatasetIsSensitiveField('ap_payments', 'bank_routing_number'));
$assert('ap vendor payment last4 sensitive', exportDatasetIsSensitiveField('ap_vendors', 'payment_account_last4'));
$datasetsSrc = (string) file_get_contents($root . '/core/export_datasets.php');
$assert('sensitive helper is tenant-aware for custom fields',
    str_contains($datasetsSrc, 'function exportDatasetIsSensitiveField(string $dataset, string $field, ?int $tenantId = null): bool')
    && str_contains($datasetsSrc, 'exportDatasetFieldRegistry($dataset, $tenantId)'));
$assert('custom-field fetchers opt into sensitive values explicitly',
    str_contains($datasetsSrc, 'include_sensitive_custom_fields')
    && str_contains($datasetsSrc, 'customFieldValues($tenantId, $entityType, $recordId, $includeSensitive)'));
$assert('people directory has custom field entity', in_array('people', $reg['people_directory']['custom_field_entities'] ?? [], true));
$assert('placements directory has custom field entity', in_array('placements', $reg['placements_directory']['custom_field_entities'] ?? [], true));
$assert('billing invoices expose client and amount fields',
    isset($reg['billing_invoices']['fields']['client_name'], $reg['billing_invoices']['fields']['amount_due']));
$assert('billing payments expose method and amount fields',
    isset($reg['billing_payments']['fields']['method'], $reg['billing_payments']['fields']['amount']));
$assert('ap bills expose vendor and amount due fields',
    isset($reg['ap_bills']['fields']['vendor_name'], $reg['ap_bills']['fields']['amount_due']));
$assert('ap vendors expose vendor and tax fields',
    isset($reg['ap_vendors']['fields']['vendor_name'], $reg['ap_vendors']['fields']['tax_id_last4']));
$assert('accounting COA exposes account fields',
    isset($reg['accounting_chart_of_accounts']['fields']['code'], $reg['accounting_chart_of_accounts']['fields']['cash_flow_tag']));
$assert('accounting JE exposes approval and amount fields',
    isset($reg['accounting_journal_entries']['fields']['approval_state'], $reg['accounting_journal_entries']['fields']['total_debit']));
$assert('accounting GL detail exposes account and debit/credit fields',
    isset($reg['accounting_gl_detail']['fields']['account_code'], $reg['accounting_gl_detail']['fields']['debit'], $reg['accounting_gl_detail']['fields']['credit']));
$assert('accounting periods expose status fields',
    isset($reg['accounting_periods']['fields']['period_number'], $reg['accounting_periods']['fields']['status']));
$assert('accounting bank statement lines expose match fields',
    isset($reg['accounting_bank_statement_lines']['fields']['match_status'], $reg['accounting_bank_statement_lines']['fields']['matched_je_id']));
$assert('time entries expose placement and hours fields',
    isset($reg['time_entries']['fields']['placement_external_id'], $reg['time_entries']['fields']['hours']));
$assert('staffing clients expose contact and terms fields',
    isset($reg['staffing_clients']['fields']['primary_contact_email'], $reg['staffing_clients']['fields']['payment_terms_days']));
$assert('people field registry has static fields', isset(exportDatasetFieldRegistry('people_directory')['email_primary']));
$assert('placements field registry has static fields', isset(exportDatasetFieldRegistry('placements_directory')['person_email']));
$assert('dataset access helper exists', function_exists('exportDatasetUserCanAccess'));
$assert('accessible registry helper exists', function_exists('exportDatasetAccessibleRegistry'));
$assert('dataset fetch helper exists', function_exists('exportDatasetFetchRows'));
$assert('template dataset validator exists', function_exists('exportTemplateGetForDataset'));
$assert('shared template stream helper exists', function_exists('exportTemplateStreamDatasetCsv'));
$assert('shared export audit helper exists', function_exists('exportDatasetAudit'));
$assert('dataset access defaults true without RBAC', exportDatasetUserCanAccess([], $reg['people_directory']));

echo "\nTemplate validation\n";
try {
    _exportTplValidateMappings([
        ['output_header' => 'Email', 'kind' => 'field', 'source_field' => 'email_primary'],
    ], 'people_directory');
    $assert('people_directory static mapping accepted', true);
} catch (Throwable $e) {
    $assert('people_directory static mapping accepted', false);
}

try {
    _exportTplValidateMappings([
        ['output_header' => 'Person email', 'kind' => 'field', 'source_field' => 'person_email'],
    ], 'placements_directory');
    $assert('placements_directory static mapping accepted', true);
} catch (Throwable $e) {
    $assert('placements_directory static mapping accepted', false);
}

try {
    _exportTplValidateMappings([
        ['output_header' => 'Nope', 'kind' => 'field', 'source_field' => 'not_a_field'],
    ], 'people_directory');
    $assert('unknown people_directory field rejected', false);
} catch (ExportTemplateException $e) {
    $assert('unknown people_directory field rejected', str_contains($e->getMessage(), 'not in dataset'));
}

echo "\nAPI/docs\n";
$api = (string) file_get_contents($root . '/api/export_templates.php');
$service = (string) file_get_contents($root . '/core/export_service.php');
$peopleExport = (string) file_get_contents($root . '/modules/people/api/csv_export.php');
$placementsExport = (string) file_get_contents($root . '/modules/placements/api/csv_export.php');
$payrollRuns = (string) file_get_contents($root . '/modules/payroll/api/runs.php');
$apPayments = (string) file_get_contents($root . '/modules/ap/api/payments.php');
$apLegacyExport = (string) file_get_contents($root . '/modules/ap/api/export.php');
$apPaymentsCsv = (string) file_get_contents($root . '/modules/ap/api/payments_csv_export.php');
$apBillsCsv = (string) file_get_contents($root . '/modules/ap/api/bills_csv_export.php');
$apVendorsCsv = (string) file_get_contents($root . '/modules/ap/api/csv_export.php');
$apExpenses = (string) file_get_contents($root . '/modules/ap/api/expenses.php');
$accountingExport = (string) file_get_contents($root . '/modules/accounting/api/export.php');
$billingInvoices = (string) file_get_contents($root . '/modules/billing/api/csv_export.php');
$billingPayments = (string) file_get_contents($root . '/modules/billing/api/payments_csv_export.php');
$timeExport = (string) file_get_contents($root . '/modules/time/api/csv_export.php');
$staffingClientsExport = (string) file_get_contents($root . '/modules/staffing/api/csv_export.php');
$peopleUi = (string) file_get_contents($root . '/modules/people/ui/Directory.jsx');
$placementsUi = (string) file_get_contents($root . '/modules/placements/ui/List.jsx');
$timeReviewUi = (string) file_get_contents($root . '/modules/time/ui/ReviewQueue.jsx');
$staffingClientsUi = (string) file_get_contents($root . '/modules/staffing/ui/Clients.jsx');
$apBillsUi = (string) file_get_contents($root . '/modules/ap/ui/BillsList.jsx');
$apVendorsUi = (string) file_get_contents($root . '/modules/ap/ui/VendorsList.jsx');
$seed = (string) file_get_contents($root . '/core/migrations/120_people_placements_export_template_presets.sql');
$staffingSeed = (string) file_get_contents($root . '/core/migrations/121_staffing_clients_export_template_preset.sql');
$assert('datasets endpoint exposes sensitive_fields', str_contains($api, "'sensitive_fields'"));
$assert('datasets endpoint uses tenant-aware field registry', str_contains($api, 'exportDatasetFieldRegistry($key, $tenantId)'));
$assert('datasets endpoint filters by accessible registry', str_contains($api, 'exportDatasetAccessibleRegistry($user)'));
$assert('template API requires dataset access', str_contains($api, '_xtplRequireDatasetAccess'));
$assert('shared service validates template dataset', str_contains($service, 'exportTemplateGetForDataset') && str_contains($service, "template's dataset must be"));
$assert('shared service emits dataset audit event', str_contains($service, 'exportDatasetAudit') && str_contains($service, "'audit_event'"));
$assert('shared service normalizes filenames', str_contains($service, 'exportTemplateCsvFilename'));
$assert('people export supports template_id', str_contains($peopleExport, 'template_id') && str_contains($peopleExport, 'people_directory'));
$assert('placements export supports template_id', str_contains($placementsExport, 'template_id') && str_contains($placementsExport, 'placements_directory'));
$assert('payroll template export uses shared runner', str_contains($payrollRuns, 'exportTemplateStreamDatasetCsv') && str_contains($payrollRuns, 'payroll_disbursements'));
$assert('ap payments template export uses shared runner', str_contains($apPayments, 'exportTemplateStreamDatasetCsv') && str_contains($apPayments, 'ap_payments'));
$assert('ap payments CSV template export uses shared runner', str_contains($apPaymentsCsv, 'exportTemplateStreamDatasetCsv') && str_contains($apPaymentsCsv, 'ap_payments'));
$assert('ap bills CSV template export uses shared runner', str_contains($apBillsCsv, 'exportTemplateStreamDatasetCsv') && str_contains($apBillsCsv, 'ap_bills'));
$assert('ap vendors CSV template export uses shared runner', str_contains($apVendorsCsv, 'exportTemplateStreamDatasetCsv') && str_contains($apVendorsCsv, 'ap_vendors'));
$assert('ap raw exports audit dataset events',
    str_contains($apPaymentsCsv, 'ap.payments.exported')
    && str_contains($apBillsCsv, 'ap.bills.exported')
    && str_contains($apVendorsCsv, 'ap.vendors.exported')
    && str_contains($apPaymentsCsv, "mode' => 'raw'")
    && str_contains($apBillsCsv, "mode' => 'raw'")
    && str_contains($apVendorsCsv, "mode' => 'raw'"));
$assert('ap legacy export endpoint consumes governed datasets',
    str_contains($apLegacyExport, 'exportDatasetFetchRows')
    && str_contains($apLegacyExport, "'ap_bills'")
    && str_contains($apLegacyExport, "'ap_payments'")
    && str_contains($apLegacyExport, "'expenses'")
    && str_contains($apLegacyExport, "mode' => 'raw'"));
$assert('ap expenses template export uses shared runner', str_contains($apExpenses, 'exportTemplateStreamDatasetCsv') && str_contains($apExpenses, "'expenses'"));
$assert('ap expenses raw export uses shared dataset service',
    str_contains($apExpenses, 'exportDatasetFetchExpenses')
    && str_contains($apExpenses, 'Core\\CsvExportService')
    && str_contains($apExpenses, 'ap.expense.export_selected'));
$assert('accounting export endpoint consumes governed datasets',
    str_contains($accountingExport, 'exportDatasetFetchRows')
    && str_contains($accountingExport, 'accounting_chart_of_accounts')
    && str_contains($accountingExport, 'accounting_journal_entries')
    && str_contains($accountingExport, 'accounting_gl_detail')
    && str_contains($accountingExport, 'accounting_periods')
    && str_contains($accountingExport, 'accounting_bank_statement_lines')
    && str_contains($accountingExport, "mode' => 'raw'"));
$assert('accounting export endpoint supports dataset templates',
    str_contains($accountingExport, 'exportTemplateStreamDatasetCsv')
    && str_contains($accountingExport, 'template_id'));
$assert('billing invoices template export uses shared runner', str_contains($billingInvoices, 'exportTemplateStreamDatasetCsv') && str_contains($billingInvoices, 'billing_invoices'));
$assert('billing payments template export uses shared runner', str_contains($billingPayments, 'exportTemplateStreamDatasetCsv') && str_contains($billingPayments, 'billing_payments'));
$assert('billing raw exports audit dataset events',
    str_contains($billingInvoices, 'billing.invoice.exported')
    && str_contains($billingPayments, 'billing.payment.exported')
    && str_contains($billingInvoices, "mode' => 'raw'")
    && str_contains($billingPayments, "mode' => 'raw'"));
$assert('time entries template export uses shared runner', str_contains($timeExport, 'exportTemplateStreamDatasetCsv') && str_contains($timeExport, 'time_entries'));
$assert('time raw export audits dataset event',
    str_contains($timeExport, 'time.entries.exported') && str_contains($timeExport, "mode' => 'raw'"));
$assert('staffing clients template export uses shared runner',
    str_contains($staffingClientsExport, 'exportTemplateStreamDatasetCsv') && str_contains($staffingClientsExport, 'staffing_clients'));
$assert('staffing clients raw export audits dataset event',
    str_contains($staffingClientsExport, 'staffing.clients.exported') && str_contains($staffingClientsExport, "mode' => 'raw'"));
$assert('staffing clients export uses export permission',
    str_contains($staffingClientsExport, "'staffing.export.run'"));
$assert('people UI uses export template picker', str_contains($peopleUi, 'ExportTemplatePicker') && str_contains($peopleUi, 'dataset="people_directory"'));
$assert('placements UI uses export template picker', str_contains($placementsUi, 'ExportTemplatePicker') && str_contains($placementsUi, 'dataset="placements_directory"'));
$assert('time review UI uses export template picker', str_contains($timeReviewUi, 'ExportTemplatePicker') && str_contains($timeReviewUi, 'dataset="time_entries"'));
$assert('staffing clients UI uses export template picker', str_contains($staffingClientsUi, 'ExportTemplatePicker') && str_contains($staffingClientsUi, 'dataset="staffing_clients"'));
$assert('ap bills UI uses export template picker', str_contains($apBillsUi, 'ExportTemplatePicker') && str_contains($apBillsUi, 'dataset="ap_bills"'));
$assert('ap vendors UI uses export template picker', str_contains($apVendorsUi, 'ExportTemplatePicker') && str_contains($apVendorsUi, 'dataset="ap_vendors"'));
$assert('people/placements template presets seeded', str_contains($seed, 'People Directory (default)') && str_contains($seed, 'Placements (default)'));
$assert('staffing clients template preset seeded', str_contains($staffingSeed, 'Staffing Clients (default)') && str_contains($staffingSeed, 'staffing_clients'));
$assert('export governance docs exist', is_file($root . '/docs/EXPORT_GOVERNANCE.md'));
$assert('export_datasets parses', _php_lint($root . '/core/export_datasets.php'));
$assert('export_templates parses', _php_lint($root . '/core/export_templates.php'));
$assert('export_service parses', _php_lint($root . '/core/export_service.php'));
$assert('export API parses', _php_lint($root . '/api/export_templates.php'));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

function _php_lint(string $path): bool
{
    $output = [];
    $rc = 0;
    @exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $rc);
    return $rc === 0;
}
