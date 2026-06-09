<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/core/export_datasets.php';
require_once $root . '/core/export_templates.php';

$pass = 0;
$fail = 0;
$assert = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok  {$name}\n"; }
    else     { $fail++; echo "  no  {$name}\n"; }
};

echo "Dataset governance\n";
$reg = exportDatasetRegistry();
$required = ['label', 'module_id', 'permission', 'formats', 'audit_event', 'sensitive_fields', 'custom_field_entities', 'fetcher', 'fields'];
foreach (['payroll_disbursements', 'ap_payments', 'expenses', 'people_directory'] as $dataset) {
    $assert("dataset registered: {$dataset}", isset($reg[$dataset]));
    foreach ($required as $key) {
        $assert("{$dataset} has {$key}", array_key_exists($key, $reg[$dataset] ?? []));
    }
}

$assert('payroll bank account sensitive', exportDatasetIsSensitiveField('payroll_disbursements', 'bank_account_number'));
$assert('ap bank routing sensitive', exportDatasetIsSensitiveField('ap_payments', 'bank_routing_number'));
$assert('people directory has custom field entity', in_array('people', $reg['people_directory']['custom_field_entities'] ?? [], true));
$assert('people field registry has static fields', isset(exportDatasetFieldRegistry('people_directory')['email_primary']));

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
        ['output_header' => 'Nope', 'kind' => 'field', 'source_field' => 'not_a_field'],
    ], 'people_directory');
    $assert('unknown people_directory field rejected', false);
} catch (ExportTemplateException $e) {
    $assert('unknown people_directory field rejected', str_contains($e->getMessage(), 'not in dataset'));
}

echo "\nAPI/docs\n";
$api = (string) file_get_contents($root . '/api/export_templates.php');
$assert('datasets endpoint exposes sensitive_fields', str_contains($api, "'sensitive_fields'"));
$assert('datasets endpoint uses tenant-aware field registry', str_contains($api, 'exportDatasetFieldRegistry($key, $tenantId)'));
$assert('export governance docs exist', is_file($root . '/docs/EXPORT_GOVERNANCE.md'));
$assert('export_datasets parses', _php_lint($root . '/core/export_datasets.php'));
$assert('export_templates parses', _php_lint($root . '/core/export_templates.php'));
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
