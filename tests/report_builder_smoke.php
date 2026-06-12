<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/core/ModuleRegistry.php';
require_once $root . '/core/report_builder.php';

$pass = 0;
$fail = 0;
$assert = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok  {$name}\n"; }
    else     { $fail++; echo "  no  {$name}\n"; }
};

echo "Report builder registry\n";
$reg = reportBuilderDatasetRegistry();
$reportDatasetDecls = ModuleRegistry::reset()->getReportDatasetDeclarations();
$assert('people directory dataset exists', isset($reg['people_directory']));
$assert('placements dataset exists', isset($reg['placements_directory']));
$assert('payroll dataset exists', isset($reg['payroll_disbursements']));
$assert('AP payments dataset exists', isset($reg['ap_payments']));
$assert('AP bills dataset exists', isset($reg['ap_bills']));
$assert('AP vendors dataset exists', isset($reg['ap_vendors']));
$assert('billing invoices dataset exists', isset($reg['billing_invoices']));
$assert('billing payments dataset exists', isset($reg['billing_payments']));
$assert('time entries dataset exists', isset($reg['time_entries']));
$assert('staffing clients dataset exists', isset($reg['staffing_clients']));
foreach (array_keys($reg) as $datasetKey) {
    $assert("report dataset manifest declaration exists: {$datasetKey}", isset($reportDatasetDecls[$datasetKey]));
}
$people = $reg['people_directory'] ?? [];
$placements = $reg['placements_directory'] ?? [];
$apPayments = $reg['ap_payments'] ?? [];
$apBills = $reg['ap_bills'] ?? [];
$apVendors = $reg['ap_vendors'] ?? [];
$billingInvoices = $reg['billing_invoices'] ?? [];
$billingPayments = $reg['billing_payments'] ?? [];
$timeEntries = $reg['time_entries'] ?? [];
$staffingClients = $reg['staffing_clients'] ?? [];
$assert('people preserves source dataset', ($people['source_dataset'] ?? null) === 'people_directory');
$assert('people preserves permission', ($people['permission'] ?? null) === 'people.view');
$assert('people custom fields entity exposed', in_array('people', $people['custom_field_entities'] ?? [], true));
$assert('people fields exposed', isset($people['fields']['email_primary']));
$assert('people dimensions exposed', isset($people['dimensions']['email_primary']));
$assert('people filters exposed', isset($people['filters']['status']));
$assert('placements preserves source dataset', ($placements['source_dataset'] ?? null) === 'placements_directory');
$assert('placements custom fields entity exposed', in_array('placements', $placements['custom_field_entities'] ?? [], true));
$assert('placements fields exposed', isset($placements['fields']['person_email']));
$assert('placements person split fields exposed', isset($placements['fields']['person_first_name'], $placements['fields']['person_last_name']));
$assert('placements expiring date exposed', isset($placements['fields']['expiring_date']));
$assert('placements count measure exposed', (($placements['measures']['placement_count']['aggregate'] ?? null) === 'sum'));
$assert('placements filters exposed', isset($placements['filters']['status']));
$assert('AP payments preserves source dataset', ($apPayments['source_dataset'] ?? null) === 'ap_payments');
$assert('AP payment amount classified as measure',
    (($apPayments['measures']['amount']['role'] ?? null) === 'measure'));
$assert('AP payment status filter exposed', isset($apPayments['filters']['status']));
$assert('AP bills preserves source dataset', ($apBills['source_dataset'] ?? null) === 'ap_bills');
$assert('AP bills amount due classified as measure',
    (($apBills['measures']['amount_due']['role'] ?? null) === 'measure'));
$assert('AP bill status filter exposed', isset($apBills['filters']['status']));
$assert('AP vendors preserves source dataset', ($apVendors['source_dataset'] ?? null) === 'ap_vendors');
$assert('AP vendor last4 fields marked sensitive',
    !empty($apVendors['fields']['tax_id_last4']['sensitive'])
    && !empty($apVendors['fields']['payment_account_last4']['sensitive']));
$assert('AP vendor type filter exposed', isset($apVendors['filters']['vendor_type']));
$assert('billing invoices preserves source dataset', ($billingInvoices['source_dataset'] ?? null) === 'billing_invoices');
$assert('billing invoices amount due classified as measure',
    (($billingInvoices['measures']['amount_due']['role'] ?? null) === 'measure'));
$assert('billing invoice status filter exposed', isset($billingInvoices['filters']['status']));
$assert('billing payments preserves source dataset', ($billingPayments['source_dataset'] ?? null) === 'billing_payments');
$assert('billing payment amount classified as measure',
    (($billingPayments['measures']['amount']['role'] ?? null) === 'measure'));
$assert('billing payment method filter exposed', isset($billingPayments['filters']['method']));
$assert('time entries preserves source dataset', ($timeEntries['source_dataset'] ?? null) === 'time_entries');
$assert('time entries hours classified as measure',
    (($timeEntries['measures']['hours']['role'] ?? null) === 'measure'));
$assert('time entries work date filter exposed', isset($timeEntries['filters']['work_date']));
$assert('time entries status filter exposed', isset($timeEntries['filters']['status']));
$assert('staffing clients preserves source dataset', ($staffingClients['source_dataset'] ?? null) === 'staffing_clients');
$assert('staffing client payment terms classified as measure',
    (($staffingClients['measures']['payment_terms_days']['role'] ?? null) === 'measure'));
$assert('staffing client active placements classified as measure',
    (($staffingClients['measures']['active_placements']['role'] ?? null) === 'measure'));
$assert('staffing client status filter exposed', isset($staffingClients['filters']['status']));
$assert('report builder text filters support lists', in_array('in', reportBuilderFilterOperators('text'), true));
$assert('report builder date filters support inclusive upper bounds', in_array('less_than_or_equal', reportBuilderFilterOperators('date'), true));
$assert('payroll amount classified as measure', (($reg['payroll_disbursements']['measures']['gross_pay_dollars']['role'] ?? null) === 'measure'));
$assert('payroll bank account marked sensitive', !empty($reg['payroll_disbursements']['fields']['bank_account_number']['sensitive']));
$coreText = (string) file_get_contents($root . '/core/report_builder.php');
$assert('sensitive definition check is tenant-aware',
    str_contains($coreText, 'function reportBuilderDefinitionUsesSensitiveFields(array $definition, ?int $tenantId = null)')
    && str_contains($coreText, 'reportBuilderDatasetGet((string) ($definition[\'dataset\'] ?? \'\'), $tenantId)'));
$assert('people execution supported', !empty($people['execution_supported']));
$assert('reportBuilderDatasetGet works', (reportBuilderDatasetGet('people_directory')['key'] ?? null) === 'people_directory');
$presets = reportBuilderPresetRegistry();
$assert('people preset exists', isset($presets['people.active_directory']));
$assert('staffing placement preset exists', isset($presets['staffing.active_placements']));
$assert('staffing preset consumes placements dataset', ($presets['staffing.active_placements']['dataset'] ?? null) === 'placements_directory');
$assert('staffing preset preserves source module metadata', ($presets['staffing.active_placements']['source_module_id'] ?? null) === 'placements');
$assert('placements expiring preset exists', isset($presets['placements.expiring_soon']));
$assert('placements expiring preset consumes placements dataset', ($presets['placements.expiring_soon']['dataset'] ?? null) === 'placements_directory');
$assert('placements expiring preset preserves source module metadata', ($presets['placements.expiring_soon']['source_module_id'] ?? null) === 'placements');
$assert('placements active-by-client preset exists', isset($presets['placements.active_by_client']));
$assert('placements active-by-client preset consumes placements dataset', ($presets['placements.active_by_client']['dataset'] ?? null) === 'placements_directory');
$presetDefinition = reportBuilderValidateDefinition((array) ($presets['people.active_directory']['definition'] ?? []));
$assert('preset definitions validate through governed fields', ($presetDefinition['filters'][0]['field'] ?? null) === 'status');
$definition = reportBuilderValidateDefinition([
    'dataset' => 'people_directory',
    'columns' => ['first_name', 'last_name', 'email_primary'],
    'filters' => [['field' => 'status', 'operator' => 'equals', 'value' => 'active']],
    'sorts' => [['field' => 'last_name', 'direction' => 'asc']],
]);
$assert('definition validates governed fields', ($definition['dataset'] ?? null) === 'people_directory');
$assert('definition normalizes filters', ($definition['filters'][0]['field'] ?? null) === 'status');
$result = reportBuilderApplyDefinitionToRows($definition, [
    ['first_name' => 'Alex', 'last_name' => 'Zephyr', 'email_primary' => 'alex@example.com', 'status' => 'inactive'],
    ['first_name' => 'Jordan', 'last_name' => 'Rivera', 'email_primary' => 'jordan@example.com', 'status' => 'active'],
]);
$assert('execution filters rows', ($result['row_count'] ?? 0) === 1);
$assert('execution projects selected fields', array_key_exists('email_primary', $result['rows'][0] ?? []) && !array_key_exists('status', $result['rows'][0] ?? []));
$placementDefinition = reportBuilderValidateDefinition([
    'dataset' => 'placements_directory',
    'columns' => ['placement_id', 'expiring_date'],
    'filters' => [
        ['field' => 'status', 'operator' => 'in', 'value' => ['active', 'pending_start', 'on_hold']],
        ['field' => 'expiring_date', 'operator' => 'less_than_or_equal', 'value' => '2026-07-01'],
    ],
    'sorts' => [['field' => 'expiring_date', 'direction' => 'asc']],
]);
$placementResult = reportBuilderApplyDefinitionToRows($placementDefinition, [
    ['placement_id' => 1, 'status' => 'active', 'expiring_date' => '2026-06-15'],
    ['placement_id' => 2, 'status' => 'ended', 'expiring_date' => '2026-06-10'],
    ['placement_id' => 3, 'status' => 'active', 'expiring_date' => '2026-08-01'],
]);
$assert('placement report preset filters lists and inclusive dates', ($placementResult['row_count'] ?? 0) === 1 && (($placementResult['rows'][0]['placement_id'] ?? null) === 1));
$aggregateDefinition = reportBuilderValidateDefinition([
    'dataset' => 'placements_directory',
    'dimensions' => ['end_client_name'],
    'measures' => ['placement_count'],
    'filters' => [['field' => 'status', 'operator' => 'equals', 'value' => 'active']],
    'sorts' => [
        ['field' => 'placement_count', 'direction' => 'desc'],
        ['field' => 'end_client_name', 'direction' => 'asc'],
    ],
]);
$aggregateResult = reportBuilderApplyDefinitionToRows($aggregateDefinition, [
    ['end_client_name' => 'Beta', 'status' => 'active', 'placement_count' => 1],
    ['end_client_name' => 'Acme', 'status' => 'active', 'placement_count' => 1],
    ['end_client_name' => 'Beta', 'status' => 'active', 'placement_count' => 1],
    ['end_client_name' => 'Beta', 'status' => 'ended', 'placement_count' => 1],
]);
$assert('grouped measures aggregate source rows', !empty($aggregateResult['aggregated']) && ($aggregateResult['source_row_count'] ?? 0) === 3);
$assert('grouped measures sort by aggregate then dimension', ($aggregateResult['row_count'] ?? 0) === 2 && (($aggregateResult['rows'][0]['end_client_name'] ?? null) === 'Beta') && (($aggregateResult['rows'][0]['placement_count'] ?? null) === 2));
$csv = reportBuilderRenderCsv($result);
$assert('execution renders CSV through platform service', str_contains($csv, '"First name","Last name","Primary email"') && str_contains($csv, 'Jordan,Rivera,jordan@example.com'));
try {
    reportBuilderValidateDefinition(['dataset' => 'people_directory', 'columns' => ['not_a_field']]);
    $assert('definition rejects unknown fields', false);
} catch (ReportBuilderException $e) {
    $assert('definition rejects unknown fields', str_contains($e->getMessage(), 'not available'));
}

echo "\nAPI/docs/manifest\n";
$api = $root . '/api/report_builder.php';
$assert('report builder API exists', is_file($api));
$assert('report builder API parses', _php_lint($api));
$apiText = (string) file_get_contents($api);
$assert('API requires auth', str_contains($apiText, 'api_require_auth()'));
$assert('API filters dataset access', str_contains($apiText, 'reportBuilderUserCanAccessDataset'));
$assert('API supports governed execution', str_contains($apiText, "action === 'run'") && str_contains($apiText, 'reportBuilderRunDefinition'));
$assert('API supports governed CSV export', str_contains($apiText, "action === 'export'") && str_contains($apiText, 'reportBuilderRenderCsv'));
$assert('API gates sensitive execution', str_contains($apiText, 'reportBuilderDefinitionUsesSensitiveFields') && str_contains($apiText, "'reports.export'"));
$assert('API checks sensitive fields with tenant context', str_contains($apiText, 'reportBuilderDefinitionUsesSensitiveFields($definition, $tenantId)'));
$assert('API opts into sensitive custom fields only after the gate', str_contains($apiText, "\$runOptions['include_sensitive_custom_fields'] = true"));
$assert('API audits execution', str_contains($apiText, "'reports.custom.executed'"));
$assert('API audits CSV export', str_contains($apiText, "'reports.custom.exported'"));
$assert('API audits saved report lifecycle', str_contains($apiText, "'reports.custom.saved'") && str_contains($apiText, "'reports.custom.updated'") && str_contains($apiText, "'reports.custom.deleted'"));
$assert('API exposes governed presets', str_contains($apiText, "action === 'presets'") && str_contains($apiText, 'reportBuilderPresetRegistry'));
$assert('API resolves preset keys', str_contains($apiText, 'reportBuilderApiResolveDefinition') && str_contains($apiText, "'preset_key'"));
$assert('API saves presets as report definitions', str_contains($apiText, 'reportBuilderApiHydratePresetBody'));
$assert('API supports saved report list', str_contains($apiText, "action === 'reports'"));
$assert('API supports create/update/delete', str_contains($apiText, 'if ($method === \'POST\')') && str_contains($apiText, 'if ($method === \'PATCH\')') && str_contains($apiText, 'if ($method === \'DELETE\')'));
$manifest = require $root . '/modules/reports/manifest.php';
$routes = array_map(fn ($a) => $a['route'] ?? '', $manifest['actions'] ?? []);
$assert('reports manifest exposes custom route', in_array('custom', $routes, true));
$assert('reports manifest exposes other reports route', in_array('other', $routes, true));
$assert('report builder docs exist', is_file($root . '/docs/REPORT_BUILDER.md'));
$reportBuilderDocs = (string) file_get_contents($root . '/docs/REPORT_BUILDER.md');
$assert('report builder docs cover presets', str_contains($reportBuilderDocs, '/api/v1/reports/report-builder/presets') && str_contains($reportBuilderDocs, 'preset_key'));
$assert('core report builder parses', _php_lint($root . '/core/report_builder.php'));
$migration = (string) file_get_contents($root . '/core/migrations/115_report_builder_saved_reports.sql');
$assert('saved reports migration creates table', str_contains($migration, 'CREATE TABLE IF NOT EXISTS report_builder_reports'));
$legacyMap = (string) file_get_contents($root . '/core/rbac/legacy_map.php');
$assert('legacy RBAC maps report builder perms', str_contains($legacyMap, "'reports.custom.build'") && str_contains($legacyMap, "'reports.custom.share'"));

echo "\nUI\n";
$ui = (string) file_get_contents($root . '/modules/reports/ui/ReportBuilder.jsx');
$moduleUi = (string) file_get_contents($root . '/modules/reports/ui/ReportsModule.jsx');
$assert('ReportBuilder UI exists', is_file($root . '/modules/reports/ui/ReportBuilder.jsx'));
$assert('ReportBuilder hits dataset API', str_contains($ui, '/api/v1/reports/report-builder/datasets'));
$assert('ReportBuilder hits preset API', str_contains($ui, '/api/v1/reports/report-builder/presets'));
$assert('ReportBuilder hits saved reports API', str_contains($ui, '/api/v1/reports/report-builder/reports'));
$assert('ReportBuilder saves through platform API', str_contains($ui, "api.post('/api/v1/reports/report-builder'"));
$assert('ReportBuilder deletes through platform API', str_contains($ui, 'api.delete(`/api/v1/reports/report-builder/${id}`)'));
$assert('ReportBuilder previews through platform API', str_contains($ui, "/api/v1/reports/report-builder/run") && str_contains($ui, 'report-builder-preview-results'));
$assert('ReportBuilder exports through platform API', str_contains($ui, "/api/v1/reports/report-builder/export") && str_contains($ui, 'report-builder-export'));
$assert('ReportBuilder applies presets with conditions', str_contains($ui, 'report-builder-preset-select') && str_contains($ui, 'report-builder-definition-conditions'));
$assert('ReportBuilder loads saved reports', str_contains($ui, 'loadReport') && str_contains($ui, 'report-builder-load-'));
$assert('ReportsModule routes custom to ReportBuilder', str_contains($moduleUi, '<ReportBuilder session={session} />'));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

function _php_lint(string $path): bool
{
    $output = [];
    $rc = 0;
    @exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $rc);
    return $rc === 0;
}
