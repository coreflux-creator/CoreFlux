<?php
/**
 * Export Templates smoke test.
 *
 * Validates:
 *   - Migration 008 schema + seed presets
 *   - core/export_datasets.php registry shape
 *   - core/export_templates.php library: validation + render with mappings
 *   - /api/export_templates.php endpoint surface
 *   - Wire-in: payroll runs + AP CSV exports + AP expenses honour template_id
 *   - NACHA UI button removed from PaymentsList; soft-fall-back removed
 *   - ExportTemplatePicker + ExportTemplatesAdmin exist and link
 */

declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $name, $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ {$name}\n"; $pass++; }
    else       { echo "  ✗ {$name}\n"; $fail++; }
};
$lint = function (string $path): bool {
    $rc = 0; @exec('php -l ' . escapeshellarg($path) . ' 2>&1', $_, $rc);
    return $rc === 0;
};

// ─── Migration 008 ───
echo "Migration 008 (export_templates)\n";
$mig = file_get_contents(__DIR__ . '/../core/migrations/008_export_templates.sql');
$assert('migration loads',                    is_string($mig) && strlen($mig) > 200);
$assert('CREATE TABLE export_templates',      strpos($mig, 'CREATE TABLE IF NOT EXISTS export_templates') !== false);
$assert("scope ENUM('platform','tenant')",   strpos($mig, "scope ENUM('platform','tenant')") !== false);
$assert('column_mappings_json column',        strpos($mig, 'column_mappings_json MEDIUMTEXT') !== false);
$assert('idx_xtpl_tenant_dataset',            strpos($mig, 'idx_xtpl_tenant_dataset') !== false);
$assert('seeds Gusto Payroll Import preset',  strpos($mig, 'Gusto Payroll Import (default)') !== false);
$assert('seeds AP Payments — Standard CSV',   strpos($mig, 'AP Payments — Standard CSV') !== false);
$assert('utf8mb4_unicode_ci',                 strpos($mig, 'utf8mb4_unicode_ci') !== false);
$mig120 = file_get_contents(__DIR__ . '/../core/migrations/120_people_placements_export_template_presets.sql');
$assert('seeds People Directory preset',      strpos($mig120, 'People Directory (default)') !== false);
$assert('seeds Placements preset',            strpos($mig120, 'Placements (default)') !== false);
$mig121 = file_get_contents(__DIR__ . '/../core/migrations/121_staffing_clients_export_template_preset.sql');
$assert('seeds Staffing Clients preset',      strpos($mig121, 'Staffing Clients (default)') !== false
                                              && strpos($mig121, 'staffing_clients') !== false);

// ─── Dataset registry ───
echo "Dataset registry\n";
require_once __DIR__ . '/../core/export_datasets.php';
$reg = exportDatasetRegistry();
$assert('payroll_disbursements in registry',  isset($reg['payroll_disbursements']));
$assert('ap_payments in registry',            isset($reg['ap_payments']));
$assert('ap_bills in registry',               isset($reg['ap_bills']));
$assert('ap_vendors in registry',             isset($reg['ap_vendors']));
$assert('expenses in registry',               isset($reg['expenses']));
$assert('accounting_chart_of_accounts in registry', isset($reg['accounting_chart_of_accounts']));
$assert('accounting_journal_entries in registry',   isset($reg['accounting_journal_entries']));
$assert('accounting_gl_detail in registry',         isset($reg['accounting_gl_detail']));
$assert('accounting_periods in registry',           isset($reg['accounting_periods']));
$assert('accounting_bank_statement_lines in registry', isset($reg['accounting_bank_statement_lines']));
$assert('billing_invoices in registry',       isset($reg['billing_invoices']));
$assert('billing_payments in registry',       isset($reg['billing_payments']));
$assert('time_entries in registry',           isset($reg['time_entries']));
$assert('staffing_clients in registry',       isset($reg['staffing_clients']));
$assert('people_directory in registry',        isset($reg['people_directory']));
$assert('placements_directory in registry',    isset($reg['placements_directory']));
$assert('payroll permission declared',         ($reg['payroll_disbursements']['permission'] ?? null) === 'payroll.reports.view');
$assert('payroll sensitive bank fields declared',
                                              in_array('bank_account_number', $reg['payroll_disbursements']['sensitive_fields'] ?? [], true));
$assert('people directory custom field entity declared',
                                              in_array('people', $reg['people_directory']['custom_field_entities'] ?? [], true));
$assert('placements directory custom field entity declared',
                                              in_array('placements', $reg['placements_directory']['custom_field_entities'] ?? [], true));
$assert('payroll has employee_first_name',    isset($reg['payroll_disbursements']['fields']['employee_first_name']));
$assert('payroll has net_pay_dollars',        isset($reg['payroll_disbursements']['fields']['net_pay_dollars']));
$assert('payroll has net_pay_cents',          isset($reg['payroll_disbursements']['fields']['net_pay_cents']));
$assert('ap has amount_dollars + cents',      isset($reg['ap_payments']['fields']['amount_dollars'])
                                              && isset($reg['ap_payments']['fields']['amount_cents']));
$assert('ap bills has amount_due',            isset($reg['ap_bills']['fields']['amount_due']));
$assert('ap vendors has vendor_name',         isset($reg['ap_vendors']['fields']['vendor_name']));
$assert('ap vendor last4 marked sensitive',   exportDatasetIsSensitiveField('ap_vendors', 'tax_id_last4'));
$assert('expenses has line_id',               isset($reg['expenses']['fields']['line_id']));
$assert('accounting COA has code',            isset($reg['accounting_chart_of_accounts']['fields']['code']));
$assert('accounting JE has total debit',      isset($reg['accounting_journal_entries']['fields']['total_debit']));
$assert('accounting GL detail has debit',     isset($reg['accounting_gl_detail']['fields']['debit']));
$assert('accounting periods has status',      isset($reg['accounting_periods']['fields']['status']));
$assert('accounting bank lines has match status', isset($reg['accounting_bank_statement_lines']['fields']['match_status']));
$assert('billing invoices has invoice_number', isset($reg['billing_invoices']['fields']['invoice_number']));
$assert('billing payments has payment amount', isset($reg['billing_payments']['fields']['amount']));
$assert('time entries has hours',             isset($reg['time_entries']['fields']['hours']));
$assert('staffing clients has payment terms', isset($reg['staffing_clients']['fields']['payment_terms_days']));
$assert('people has email_primary',           isset($reg['people_directory']['fields']['email_primary']));
$assert('placements has person_email',         isset($reg['placements_directory']['fields']['person_email']));
$assert('field registry helper exists',        function_exists('exportDatasetFieldRegistry'));
$assert('sensitive field helper exists',       function_exists('exportDatasetIsSensitiveField'));
$assert('bank account marked sensitive',       exportDatasetIsSensitiveField('payroll_disbursements', 'bank_account_number'));
$datasetsSrc = file_get_contents(__DIR__ . '/../core/export_datasets.php');
$assert('sensitive helper accepts tenant context',
                                              strpos($datasetsSrc, '?int $tenantId = null') !== false
                                              && strpos($datasetsSrc, 'exportDatasetFieldRegistry($dataset, $tenantId)') !== false);
$assert('custom field values require explicit sensitive opt-in',
                                              strpos($datasetsSrc, 'include_sensitive_custom_fields') !== false
                                              && strpos($datasetsSrc, 'customFieldValues($tenantId, $entityType, $recordId, $includeSensitive, $includeArchived)') !== false);
$assert('custom field templates keep archived fields exportable',
                                              strpos($datasetsSrc, 'include_archived_custom_fields') !== false
                                              && strpos($datasetsSrc, 'customFieldDefinitions($tenantId, (string) $entityType, true)') !== false);

// ─── Library: render + validation ───
echo "core/export_templates.php library\n";
require_once __DIR__ . '/../core/export_templates.php';

// Validation rejects mapping with unknown source_field on a known dataset.
try {
    _exportTplValidateMappings([['output_header' => 'X', 'kind' => 'field', 'source_field' => 'bogus_field']],
                               'payroll_disbursements');
    $assert('rejects unknown source_field', false);
} catch (ExportTemplateException $e) {
    $assert('rejects unknown source_field',  strpos($e->getMessage(), "not in dataset") !== false);
}

try {
    _exportTplValidateMappings([['output_header' => 'Email', 'kind' => 'field', 'source_field' => 'email_primary']],
                               'people_directory');
    $assert('validates people_directory static field', true);
} catch (ExportTemplateException $e) {
    $assert('validates people_directory static field', false);
}

try {
    _exportTplValidateMappings([['output_header' => 'Person email', 'kind' => 'field', 'source_field' => 'person_email']],
                               'placements_directory');
    $assert('validates placements_directory static field', true);
} catch (ExportTemplateException $e) {
    $assert('validates placements_directory static field', false);
}

// Validation accepts and renumbers positions.
$valid = _exportTplValidateMappings([
    ['position' => 99, 'output_header' => 'A', 'kind' => 'fixed', 'fixed_value' => 'a'],
    ['position' => 1,  'output_header' => 'B', 'kind' => 'field', 'source_field' => 'employee_first_name'],
], 'payroll_disbursements');
$assert('validation renumbers positions to 1..N',
        $valid[0]['position'] === 1 && $valid[1]['position'] === 2);

// Pure header-parser check (no DB).
$headers = exportTemplateParseHeaders("First name,Last name,Net pay\nJordan,Rivera,3624.58\n");
$assert('parses CSV headers',                 $headers === ['First name', 'Last name', 'Net pay']);
$headersBom = exportTemplateParseHeaders("\xEF\xBB\xBFA,B,C\n");
$assert('strips UTF-8 BOM',                   $headersBom[0] === 'A');
try {
    exportTemplateParseHeaders(str_repeat('a', 300000));
    $assert('rejects oversized samples',     false);
} catch (ExportTemplateException $e) {
    $assert('rejects oversized samples',     str_contains($e->getMessage(), '256 KB'));
}

$assert('PHP parses cleanly (export_datasets)',   $lint(__DIR__ . '/../core/export_datasets.php'));
$assert('PHP parses cleanly (export_templates)',  $lint(__DIR__ . '/../core/export_templates.php'));
$assert('PHP parses cleanly (export_service)',    $lint(__DIR__ . '/../core/export_service.php'));
$exportServiceSrc = file_get_contents(__DIR__ . '/../core/export_service.php');
$assert('export service includes generation + filter audit metadata',
                                              strpos($exportServiceSrc, 'function exportDatasetAuditMeta') !== false
                                              && strpos($exportServiceSrc, "'generated_at' => \$generatedAt") !== false
                                              && strpos($exportServiceSrc, "\$meta['filter_params'] = \$filterParams") !== false);

// ─── /api/export_templates.php surface ───
echo "/api/export_templates.php\n";
$api = file_get_contents(__DIR__ . '/../api/export_templates.php');
$assert('GET list/single supported',          strpos($api, "if (\$method === 'GET')") !== false);
$assert('POST create',                        strpos($api, "if (\$method === 'POST')") !== false);
$assert('PATCH update',                       strpos($api, "if (\$method === 'PATCH')") !== false);
$assert('DELETE',                             strpos($api, "if (\$method === 'DELETE')") !== false);
$assert('action=datasets',                    strpos($api, "action === 'datasets'") !== false);
$assert('datasets API returns governance metadata',
                                              strpos($api, "'sensitive_fields'") !== false
                                              && strpos($api, 'exportDatasetFieldRegistry') !== false);
$assert('datasets API filters by dataset RBAC', strpos($api, 'exportDatasetAccessibleRegistry($user)') !== false);
$assert('template CRUD gates dataset access', strpos($api, '_xtplRequireDatasetAccess') !== false);
$assert('template CRUD uses explicit manage permission',
                                              strpos($api, "rbac_legacy_can(\$user, 'admin.export_templates.manage')") !== false
                                              && strpos($api, "'required' => 'admin.export_templates.manage'") !== false);
$assert('action=parse_headers',               strpos($api, "action === 'parse_headers'") !== false);
$assert('action=clone',                       strpos($api, "action === 'clone'") !== false);
$assert('master-only platform create',        strpos($lib2 = file_get_contents(__DIR__ . '/../core/export_templates.php'), 'Only master_admin can create platform templates') !== false);
$assert('upload size guard',                  strpos($api, '262144') !== false);
$assert('PHP parses cleanly',                 $lint(__DIR__ . '/../api/export_templates.php'));

// ─── Wire-in: payroll runs.php ───
echo "payroll/runs.php template export\n";
$pr = file_get_contents(__DIR__ . '/../modules/payroll/api/runs.php');
$assert('export_template action accepted',    strpos($pr, "'export_template'") !== false);
$assert('rejects mismatched dataset',         strpos($pr, 'payroll_disbursements') !== false);
$assert('audits payroll.run.exported_template',
                                              strpos(file_get_contents(__DIR__ . '/../core/export_datasets.php'), 'payroll.run.exported_template') !== false);
$assert('streams via shared export runner',
                                              strpos($pr, 'exportTemplateStreamDatasetCsv(') !== false);

// ─── Wire-in: ap/payments.php ───
echo "ap/payments.php template export\n";
$ap = file_get_contents(__DIR__ . '/../modules/ap/api/payments.php');
$assert('export_template GET branch',         strpos($ap, "GET' && \$action === 'export_template'") !== false);
$assert('rejects mismatched dataset',         strpos($ap, 'ap_payments') !== false
                                              && strpos(file_get_contents(__DIR__ . '/../core/export_service.php'), "template's dataset must be") !== false);
$assert('audits ap.payments.exported',
                                              strpos(file_get_contents(__DIR__ . '/../core/export_datasets.php'), 'ap.payments.exported') !== false);

// ─── Wire-in: AP CSV exports ───
echo "ap CSV template exports\n";
$apLegacyExport = file_get_contents(__DIR__ . '/../modules/ap/api/export.php');
$apPayCsv = file_get_contents(__DIR__ . '/../modules/ap/api/payments_csv_export.php');
$apBillsCsv = file_get_contents(__DIR__ . '/../modules/ap/api/bills_csv_export.php');
$apVendorsCsv = file_get_contents(__DIR__ . '/../modules/ap/api/csv_export.php');
$assert('ap legacy export honors template_id', strpos($apLegacyExport, "(int) (\$_GET['template_id'] ?? 0)") !== false);
$assert('ap legacy export uses governed datasets',
                                              strpos($apLegacyExport, 'exportDatasetFetchRows') !== false
                                              && strpos($apLegacyExport, 'exportTemplateStreamDatasetCsv') !== false
                                              && strpos($apLegacyExport, 'ap_bills') !== false
                                              && strpos($apLegacyExport, 'ap_payments') !== false
                                              && strpos($apLegacyExport, "'expenses'") !== false);
$assert('ap legacy raw CSV audits dataset',
                                              strpos($apLegacyExport, 'exportDatasetAudit') !== false
                                              && strpos($apLegacyExport, "mode' => 'raw'") !== false);
$assert('ap legacy raw CSV uses shared audit metadata',
                                              strpos($apLegacyExport, 'exportDatasetAuditMeta([') !== false);
$assert('ap payments CSV honors template_id', strpos($apPayCsv, "(int) (\$_GET['template_id'] ?? 0)") !== false);
$assert('ap payments CSV uses governed dataset',
                                              strpos($apPayCsv, 'ap_payments') !== false
                                              && strpos($apPayCsv, 'exportDatasetFetchApPayments') !== false);
$assert('ap payments raw CSV audits dataset', strpos($apPayCsv, 'ap.payments.exported') !== false
                                              && strpos($apPayCsv, "mode' => 'raw'") !== false);
$assert('ap payments raw CSV uses shared audit metadata',
                                              strpos($apPayCsv, 'exportDatasetAuditMeta([') !== false);
$assert('ap payments CSV requires export permission',
                                              strpos($apPayCsv, "'ap.export.run'") !== false);
$assert('ap bills CSV honors template_id',    strpos($apBillsCsv, "(int) (\$_GET['template_id'] ?? 0)") !== false);
$assert('ap bills CSV uses governed dataset',
                                              strpos($apBillsCsv, 'ap_bills') !== false
                                              && strpos($apBillsCsv, 'exportDatasetFetchApBills') !== false);
$assert('ap bills raw CSV audits dataset',    strpos($apBillsCsv, 'ap.bills.exported') !== false
                                              && strpos($apBillsCsv, "mode' => 'raw'") !== false);
$assert('ap bills raw CSV uses shared audit metadata',
                                              strpos($apBillsCsv, 'exportDatasetAuditMeta([') !== false);
$assert('ap vendors CSV honors template_id',  strpos($apVendorsCsv, "(int) (\$_GET['template_id'] ?? 0)") !== false);
$assert('ap vendors CSV uses governed dataset',
                                              strpos($apVendorsCsv, 'ap_vendors') !== false
                                              && strpos($apVendorsCsv, 'exportDatasetFetchApVendors') !== false);
$assert('ap vendors raw CSV audits dataset',  strpos($apVendorsCsv, 'ap.vendors.exported') !== false
                                              && strpos($apVendorsCsv, "mode' => 'raw'") !== false);
$assert('ap vendors raw CSV uses shared audit metadata',
                                              strpos($apVendorsCsv, 'exportDatasetAuditMeta([') !== false);

// ─── Wire-in: ap/expenses.php ───
echo "ap/expenses.php template export\n";
$ex = file_get_contents(__DIR__ . '/../modules/ap/api/expenses.php');
$assert('honors template_id query',           strpos($ex, "(int) (\$_GET['template_id'] ?? 0)") !== false);
$assert('rejects non-expenses dataset',       strpos($ex, "'expenses'") !== false
                                              && strpos(file_get_contents(__DIR__ . '/../core/export_service.php'), "template's dataset must be") !== false);
$assert('audits export_selected_template',
                                              strpos(file_get_contents(__DIR__ . '/../core/export_datasets.php'), 'ap.expense.export_selected_template') !== false);
$assert('raw export uses governed expenses dataset',
                                              strpos($ex, 'exportDatasetFetchExpenses') !== false
                                              && strpos($ex, 'Core\\CsvExportService') !== false
                                              && strpos($ex, 'ap.expense.export_selected') !== false);
$assert('raw expense export uses shared audit metadata',
                                              strpos($ex, 'exportDatasetAuditMeta([') !== false);

// ─── Wire-in: billing CSV exports ───
echo "accounting export datasets\n";
$accountingExport = file_get_contents(__DIR__ . '/../modules/accounting/api/export.php');
$assert('accounting export honors template_id', strpos($accountingExport, "(int) (\$_GET['template_id'] ?? 0)") !== false);
$assert('accounting export uses governed datasets',
                                              strpos($accountingExport, 'exportDatasetFetchRows') !== false
                                              && strpos($accountingExport, 'exportTemplateStreamDatasetCsv') !== false
                                              && strpos($accountingExport, 'accounting_chart_of_accounts') !== false
                                              && strpos($accountingExport, 'accounting_journal_entries') !== false
                                              && strpos($accountingExport, 'accounting_gl_detail') !== false
                                              && strpos($accountingExport, 'accounting_periods') !== false
                                              && strpos($accountingExport, 'accounting_bank_statement_lines') !== false);
$assert('accounting raw CSV audits dataset',
                                              strpos($accountingExport, 'exportDatasetAudit') !== false
                                              && strpos($accountingExport, "mode' => 'raw'") !== false
                                              && strpos($accountingExport, 'accounting.ledger.exported') !== false);
$assert('accounting raw CSV uses shared audit metadata',
                                              strpos($accountingExport, 'exportDatasetAuditMeta([') !== false);

echo "billing CSV template exports\n";
$billingInv = file_get_contents(__DIR__ . '/../modules/billing/api/csv_export.php');
$billingPay = file_get_contents(__DIR__ . '/../modules/billing/api/payments_csv_export.php');
$assert('invoice export honors template_id', strpos($billingInv, "(int) (\$_GET['template_id'] ?? 0)") !== false);
$assert('invoice export rejects mismatched dataset',
                                              strpos($billingInv, 'billing_invoices') !== false
                                              && strpos(file_get_contents(__DIR__ . '/../core/export_service.php'), "template's dataset must be") !== false);
$assert('invoice raw export audits dataset',  strpos($billingInv, 'billing.invoice.exported') !== false
                                              && strpos($billingInv, "mode' => 'raw'") !== false);
$assert('invoice raw export uses shared audit metadata',
                                              strpos($billingInv, 'exportDatasetAuditMeta([') !== false);
$assert('payment export honors template_id', strpos($billingPay, "(int) (\$_GET['template_id'] ?? 0)") !== false);
$assert('payment export rejects mismatched dataset',
                                              strpos($billingPay, 'billing_payments') !== false
                                              && strpos(file_get_contents(__DIR__ . '/../core/export_service.php'), "template's dataset must be") !== false);
$assert('payment raw export audits dataset', strpos($billingPay, 'billing.payment.exported') !== false
                                              && strpos($billingPay, "mode' => 'raw'") !== false);
$assert('payment raw export uses shared audit metadata',
                                              strpos($billingPay, 'exportDatasetAuditMeta([') !== false);

// ─── Wire-in: time CSV exports ───
echo "time CSV template exports\n";
$time = file_get_contents(__DIR__ . '/../modules/time/api/csv_export.php');
$assert('time export honors template_id',     strpos($time, "(int) (\$_GET['template_id'] ?? 0)") !== false);
$assert('time export rejects mismatched dataset',
                                              strpos($time, 'time_entries') !== false
                                              && strpos(file_get_contents(__DIR__ . '/../core/export_service.php'), "template's dataset must be") !== false);
$assert('time raw export audits dataset',     strpos($time, 'time.entries.exported') !== false
                                              && strpos($time, "mode' => 'raw'") !== false);
$assert('time raw export uses shared audit metadata',
                                              strpos($time, 'exportDatasetAuditMeta([') !== false);

// ─── Wire-in: staffing CSV exports ───
echo "staffing CSV template exports\n";
$staffingClients = file_get_contents(__DIR__ . '/../modules/staffing/api/csv_export.php');
$assert('staffing clients export honors template_id', strpos($staffingClients, "(int) (\$_GET['template_id'] ?? 0)") !== false);
$assert('staffing clients export uses governed dataset',
                                              strpos($staffingClients, 'staffing_clients') !== false
                                              && strpos($staffingClients, 'exportDatasetFetchStaffingClients') !== false);
$assert('staffing clients raw export audits dataset',
                                              strpos($staffingClients, 'staffing.clients.exported') !== false
                                              && strpos($staffingClients, "mode' => 'raw'") !== false);
$assert('staffing clients raw export uses shared audit metadata',
                                              strpos($staffingClients, 'exportDatasetAuditMeta([') !== false);
$assert('staffing clients export requires export permission',
                                              strpos($staffingClients, "'staffing.export.run'") !== false);

// ─── NACHA hidden ───
echo "NACHA hidden from UI\n";
$pl = file_get_contents(__DIR__ . '/../modules/ap/ui/PaymentsList.jsx');
$assert('NACHA originate-batch button removed',
                                              strpos($pl, 'data-testid="ap-payments-originate-batch"') === false);
$assert('Originate-NACHA copy gone',          strpos($pl, 'Originate NACHA batch') === false);
$assert('NACHA driver code preserved',        is_file(__DIR__ . '/../core/payment_rails/nacha_driver.php'));

// ─── Soft-fall-back removed ───
echo "Soft-fall-back to NACHA removed\n";
$oh = file_get_contents(__DIR__ . '/../core/payment_rails/originate_helpers.php');
$assert('error mentions Export Templates path',
                                              strpos($oh, 'Admin → Export Templates') !== false);
$assert('no longer auto-rewires to nacha',
                                              strpos($oh, "= paymentRailsGetDriver('nacha');") === false);

// ─── React UI ───
echo "React UI\n";
$picker = file_get_contents(__DIR__ . '/../dashboard/src/components/ExportTemplatePicker.jsx');
$assert('ExportTemplatePicker.jsx exists',    is_string($picker) && strlen($picker) > 200);
$assert('hits v1 export templates API',       strpos($picker, '/api/v1/reports/export-templates?dataset=') !== false);
$assert('shows manage link when empty',       strpos($picker, '/admin/export-templates') !== false);
$assert('platform badge rendered',            strpos($picker, 'PLATFORM') !== false);

$admin = file_get_contents(__DIR__ . '/../dashboard/src/pages/ExportTemplatesAdmin.jsx');
$assert('ExportTemplatesAdmin.jsx exists',    is_string($admin) && strlen($admin) > 500);
$assert('admin uses v1 export templates API', strpos($admin, '/api/v1/reports/export-templates') !== false);
$assert('upload sample CSV',                  strpos($admin, 'data-testid="xtpl-upload-sample"') !== false);
$assert('column mapping rows',                strpos($admin, 'data-testid={`xtpl-row-${i}-source-field`}') !== false);
$assert('fixed-value input',                  strpos($admin, 'data-testid={`xtpl-row-${i}-fixed-value`}') !== false);
$assert('save button',                        strpos($admin, 'data-testid="xtpl-save"') !== false);
$assert('master_admin sees scope picker',     strpos($admin, 'data-testid="xtpl-scope"') !== false);

$amod = file_get_contents(__DIR__ . '/../dashboard/src/pages/AdminModule.jsx');
$assert('AdminModule sidebar Export Templates link',
                                              strpos($amod, "label: 'Export Templates'") !== false);
$assert('AdminModule routes /export-templates',
                                              strpos($amod, '<Route path="/export-templates"') !== false);

$pdetail = file_get_contents(__DIR__ . '/../modules/payroll/ui/PayrollRunDetail.jsx');
$assert('PayrollRunDetail uses picker',       strpos($pdetail, 'ExportTemplatePicker') !== false);
$assert('payroll picker dataset=payroll_disbursements',
                                              strpos($pdetail, 'dataset="payroll_disbursements"') !== false);

$el = file_get_contents(__DIR__ . '/../modules/ap/ui/ExpensesList.jsx');
$assert('ExpensesList uses picker',           strpos($el, 'ExportTemplatePicker') !== false);
$assert('expenses picker dataset=expenses',   strpos($el, 'dataset="expenses"') !== false);

$pl2 = file_get_contents(__DIR__ . '/../modules/ap/ui/PaymentsList.jsx');
$assert('PaymentsList uses picker',           strpos($pl2, 'ExportTemplatePicker') !== false);
$assert('ap payments picker dataset=ap_payments',
                                              strpos($pl2, 'dataset="ap_payments"') !== false);

$billsList = file_get_contents(__DIR__ . '/../modules/ap/ui/BillsList.jsx');
$assert('BillsList uses picker',              strpos($billsList, 'ExportTemplatePicker') !== false);
$assert('ap bills picker dataset=ap_bills',
                                              strpos($billsList, 'dataset="ap_bills"') !== false);

$vendorsList = file_get_contents(__DIR__ . '/../modules/ap/ui/VendorsList.jsx');
$assert('VendorsList uses picker',            strpos($vendorsList, 'ExportTemplatePicker') !== false);
$assert('ap vendors picker dataset=ap_vendors',
                                              strpos($vendorsList, 'dataset="ap_vendors"') !== false);

$peopleDir = file_get_contents(__DIR__ . '/../modules/people/ui/Directory.jsx');
$assert('People Directory uses picker',       strpos($peopleDir, 'ExportTemplatePicker') !== false);
$assert('people picker dataset=people_directory',
                                              strpos($peopleDir, 'dataset="people_directory"') !== false);

$placementsList = file_get_contents(__DIR__ . '/../modules/placements/ui/List.jsx');
$assert('Placements list uses picker',        strpos($placementsList, 'ExportTemplatePicker') !== false);
$assert('placements picker dataset=placements_directory',
                                              strpos($placementsList, 'dataset="placements_directory"') !== false);

$timeReview = file_get_contents(__DIR__ . '/../modules/time/ui/ReviewQueue.jsx');
$assert('Time review uses picker',            strpos($timeReview, 'ExportTemplatePicker') !== false);
$assert('time picker dataset=time_entries',
                                              strpos($timeReview, 'dataset="time_entries"') !== false);

$staffingClientsUi = file_get_contents(__DIR__ . '/../modules/staffing/ui/Clients.jsx');
$assert('Staffing Clients uses picker',       strpos($staffingClientsUi, 'ExportTemplatePicker') !== false);
$assert('staffing clients picker dataset=staffing_clients',
                                              strpos($staffingClientsUi, 'dataset="staffing_clients"') !== false);

echo "\n";
echo "Pass: {$pass}\n";
echo "Fail: {$fail}\n";
exit($fail === 0 ? 0 : 1);
