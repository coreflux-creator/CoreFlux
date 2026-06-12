<?php
/**
 * ModuleRegistry smoke test.
 *
 * Run with: php /app/tests/module_registry_smoke.php
 *
 * No DB required. Uses your real /app/modules/* manifests so you'll see
 * a regression the moment a manifest goes wrong.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/ModuleRegistry.php';

$pass = 0; $fail = 0;
$assert = function(string $what, bool $cond) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  ✓ $what\n"; }
    else        { $fail++; echo "  ✗ $what\n"; }
};

echo "Discovery\n";
$reg = ModuleRegistry::reset();
$ids = $reg->getModuleIds();

$assert("discovers people",      in_array('people',      $ids, true));
$assert("discovers placements",  in_array('placements',  $ids, true));
$assert("discovers time",        in_array('time',        $ids, true));
$assert("discovers billing",     in_array('billing',     $ids, true));
$assert("discovers ap",          in_array('ap',          $ids, true));
$assert("discovers payroll",     in_array('payroll',     $ids, true));
$assert("discovers accounting",  in_array('accounting',  $ids, true));
$assert("skips _template",       !in_array('_template',  $ids, true));
$assert("skips folders without manifest", !in_array('private_equity',    $ids, true));
$assert("skips folders without manifest", !in_array('master_admin_panel', $ids, true));

echo "\nManifest accessor\n";
$people = $reg->getModule('people');
$assert("getModule('people') returns manifest", is_array($people));
$assert("manifest has id",                       ($people['id'] ?? null) === 'people');
$assert("manifest has name",                     !empty($people['name']));
$assert("hasModule('payroll') == true",          $reg->hasModule('payroll'));
$assert("hasModule('nope') == false",            !$reg->hasModule('nope'));

echo "\nPermission extraction (both manifest shapes)\n";
$perms = $reg->getAllPermissions();
$assert("assoc-map shape: people.view harvested",      in_array('people.view',      $perms, true));
$assert("assoc-map shape: placements.view harvested",  in_array('placements.view',  $perms, true));
$assert("assoc-map shape: time.view harvested",        in_array('time.view',        $perms, true));
$assert("assoc-map shape: billing.view harvested",     in_array('billing.view',     $perms, true));
$assert("assoc-map shape: ap.view harvested",          in_array('ap.view',          $perms, true));
$assert("assoc-map shape: payroll.view harvested",     in_array('payroll.view',     $perms, true));
$assert("assoc-map shape: accounting.view harvested",  in_array('accounting.view',  $perms, true));
$assert("assoc-map shape: accounting.je.post harvested (Accounting v1.0)",
    in_array('accounting.je.post', $perms, true));
$assert("assoc-map shape: people.merge harvested",     in_array('people.merge',     $perms, true));
$assert("assoc-map shape: placements.financials.approve harvested",
    in_array('placements.financials.approve', $perms, true));
$assert("assoc-map shape: time.tokenized_email.issue harvested",
    in_array('time.tokenized_email.issue', $perms, true));
$assert("assoc-map shape: staffing.export.run harvested",
    in_array('staffing.export.run', $perms, true));

$descs = $reg->getAllPermissionsWithDescriptions();
$assert("permission descriptions for accounting are populated",
    isset($descs['accounting.view']) && $descs['accounting.view'] !== '');
$assert("permission descriptions for billing are populated",
    isset($descs['billing.view']) && $descs['billing.view'] !== '');

echo "\nDefaults applied to partial manifests\n";
$assert("people has 'views' field even though not declared",
    is_array($people['views'] ?? null));
$assert("people has 'audit_events' field populated from spec",
    isset($people['audit_events']) && is_array($people['audit_events']) && count($people['audit_events']) > 0);
$assert("people has export_datasets field",
    is_array($people['export_datasets'] ?? null));
$assert("people has report_datasets field",
    is_array($people['report_datasets'] ?? null));

echo "\nDataset declarations\n";
$exportDatasets = $reg->getExportDatasetDeclarations();
$reportDatasets = $reg->getReportDatasetDeclarations();
$assert("people_directory export dataset declared by people",
    ($exportDatasets['people_directory']['module_id'] ?? null) === 'people');
$assert("placements_directory export dataset declared by placements",
    ($exportDatasets['placements_directory']['module_id'] ?? null) === 'placements');
$assert("payroll_disbursements export dataset declared by payroll",
    ($exportDatasets['payroll_disbursements']['module_id'] ?? null) === 'payroll');
$assert("ap_payments export dataset declared by ap",
    ($exportDatasets['ap_payments']['module_id'] ?? null) === 'ap');
$assert("ap_bills export dataset declared by ap",
    ($exportDatasets['ap_bills']['module_id'] ?? null) === 'ap');
$assert("ap_vendors export dataset declared by ap",
    ($exportDatasets['ap_vendors']['module_id'] ?? null) === 'ap');
$assert("expenses export dataset declared by ap",
    ($exportDatasets['expenses']['module_id'] ?? null) === 'ap');
$assert("billing_invoices export dataset declared by billing",
    ($exportDatasets['billing_invoices']['module_id'] ?? null) === 'billing');
$assert("billing_payments export dataset declared by billing",
    ($exportDatasets['billing_payments']['module_id'] ?? null) === 'billing');
$assert("time_entries export dataset declared by time",
    ($exportDatasets['time_entries']['module_id'] ?? null) === 'time');
$assert("staffing_clients export dataset declared by staffing",
    ($exportDatasets['staffing_clients']['module_id'] ?? null) === 'staffing');
$assert("people_directory report dataset declared by people",
    ($reportDatasets['people_directory']['module_id'] ?? null) === 'people');
$assert("placements_directory report dataset declared by placements",
    ($reportDatasets['placements_directory']['module_id'] ?? null) === 'placements');
$assert("payroll report dataset preserves sensitive field metadata",
    in_array('bank_account_number', $reportDatasets['payroll_disbursements']['sensitive_fields'] ?? [], true));
$assert("ap_payments report dataset declared by ap",
    ($reportDatasets['ap_payments']['module_id'] ?? null) === 'ap');
$assert("ap_bills report dataset declared by ap",
    ($reportDatasets['ap_bills']['module_id'] ?? null) === 'ap');
$assert("ap_vendors report dataset declared by ap",
    ($reportDatasets['ap_vendors']['module_id'] ?? null) === 'ap');
$assert("ap vendors report dataset preserves sensitive field metadata",
    in_array('payment_account_last4', $reportDatasets['ap_vendors']['sensitive_fields'] ?? [], true));
$assert("billing invoice report dataset declared by billing",
    ($reportDatasets['billing_invoices']['module_id'] ?? null) === 'billing');
$assert("billing payment report dataset declared by billing",
    ($reportDatasets['billing_payments']['module_id'] ?? null) === 'billing');
$assert("time entries report dataset declared by time",
    ($reportDatasets['time_entries']['module_id'] ?? null) === 'time');
$assert("staffing clients report dataset declared by staffing",
    ($reportDatasets['staffing_clients']['module_id'] ?? null) === 'staffing');

echo "\nDependencies declared\n";
$placements = $reg->getModule('placements');
$assert("placements depends on people",
    in_array('people', $placements['depends_on'] ?? [], true));
$time = $reg->getModule('time');
$assert("time depends on placements",
    in_array('placements', $time['depends_on'] ?? [], true));
$billing = $reg->getModule('billing');
$assert("billing depends on time",
    in_array('time', $billing['depends_on'] ?? [], true));
$assert("billing depends on placements",
    in_array('placements', $billing['depends_on'] ?? [], true));
$ap = $reg->getModule('ap');
$assert("ap depends on placements",
    in_array('placements', $ap['depends_on'] ?? [], true));
$assert("ap depends on time",
    in_array('time', $ap['depends_on'] ?? [], true));
$assert("ap does NOT depend on accounting yet (Accounting v1.0 pending; GL posting stubbed)",
    !in_array('accounting', $ap['depends_on'] ?? [], true));
$payroll = $reg->getModule('payroll');
$assert("payroll depends on accounting",
    in_array('accounting', $payroll['depends_on'] ?? [], true));

echo "\nValidation\n";
$errs = $reg->getValidationErrors();
$assert("no validation errors against current manifests", empty($errs));

echo "\nRole filter (transitional helper)\n";
$adminMods = $reg->getModulesForRole('master_admin');
$assert("master_admin sees all modules", count($adminMods) === count($reg->getAllModules()));

echo "\n";
echo "Total: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
