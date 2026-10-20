<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/record_numbers.php';
require_once __DIR__ . '/../core/CsvImportService.php';
require_once __DIR__ . '/../core/integrations/staffing_field_contract.php';

$passed = 0;
function numberCheck(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    $GLOBALS['passed']++;
}
function numberThrows(callable $call): bool {
    try { $call(); return false; } catch (InvalidArgumentException | LogicException $e) { return true; }
}
foreach (coreRecordNumberDefinitions() as $type => $definition) {
    numberCheck(coreRecordNumberInputId($type, $definition['prefix'] . '333') === 333, $type . ' typed reference');
    numberCheck(coreRecordNumberInputId($type, '333') === 333, $type . ' legacy ID');
    numberCheck(coreRecordNumberInputId($type, strtolower($definition['prefix']) . '000333') === 333, $type . ' normalization');
    numberCheck(numberThrows(fn () => coreRecordNumbersRejectWrite([$definition['field'] => 'new'])), $type . ' cannot be assigned');
}
numberCheck(coreRecordNumberInputId('company', 'C-145') === 145, 'legacy company prefix');
foreach (['V-333', 'PL-333', 'P-0', 'P--1', 'P-3.3', 'P-3e2', 'P-3,33', 'P-333extra', "P-333\0", '9999999999999999999999'] as $bad) {
    numberCheck(numberThrows(fn () => coreRecordNumberInputId('person', $bad)), 'invalid person reference rejected: ' . $bad);
}
numberCheck(numberThrows(fn () => coreRecordNumberSearchSql('person', 'p; DROP', 'q')), 'SQL identifiers cannot be injected');
numberCheck(numberThrows(fn () => coreRecordNumberSearchSql('secret', 'p', 'q')), 'unknown record kinds rejected');

Core\CsvImportService::registerSchema('number_fixture', ['fields' => [
    'person_id' => ['type' => 'integer', 'reference_type' => 'person', 'aliases' => ['Person number']],
    'placement_id' => ['type' => 'integer', 'reference_type' => 'placement', 'aliases' => ['Placement number']],
]]);
foreach ([
    ["person_id,Person number,Placement number\n333,P-333,PL-810", false, 333],
    ["Person number,person_id\nP-333,333", false, 333],
    ["person_id,Person number\n333,", false, 333],
    ["person_id,Person number\n,P-333", false, 333],
    ["person_id,Person number\n333,P-444", true, null],
    ["Person number,person_id\nV-333,333", true, null],
    ["Person number,person_id\nP-333,V-333", true, null],
    ["Person number\nPL-333", true, null],
] as [$csv, $invalid, $expected]) {
    $preview = Core\CsvImportService::dryRun('number_fixture', $csv);
    numberCheck(($preview['error_count'] > 0) === $invalid, 'CSV validation: ' . $csv);
    if (!$invalid) numberCheck(array_values($preview['rows'])[0]['person_id'] === $expected, 'CSV resolves only the typed ID');
}
$calls = 0;
$result = Core\CsvImportService::commit('number_fixture', "Person number,person_id\nP-333,444", function () use (&$calls) { $calls++; return 1; });
numberCheck($calls === 0 && $result['imported_count'] === 0, 'conflicting identity never reaches writer');
$rule = staffingFieldContractRules('people', 'ssn_ct');
numberCheck($rule['disposition'] === 'restricted' && $rule['write_policy'] === 'specialized_workflow_only', 'PII stays restricted');
numberCheck(staffingFieldContractRules('time_entries', 'hours')['unit'] === 'hours', 'decimal hours declared');
numberCheck(staffingFieldContractRules('placements', 'future_field')['definition_status'] === 'review_required', 'new fields cannot silently become verified');
numberCheck(!staffingFieldContractRules('people', 'external_id')['remote_contracts']['quanta']['sync_enabled_by_dictionary'], 'dictionary does not enable outbound writes');
require_once __DIR__ . '/../core/integrations/field_map.php';
foreach (coreRecordNumberFields() as $field) {
    numberCheck(tenantIntegrationFieldMapIsProtectedInternalField($field), 'number excluded from mapping targets');
    numberCheck(numberThrows(fn () => tenantIntegrationFieldMapUpsert(1, ['integration' => 'jobdiva', 'entity_type' => 'person', 'external_field' => 'workerId', 'target_module' => 'people', 'target_table' => 'people', 'target_column' => $field, 'internal_field' => 'first_name'], null)), 'catalog path cannot bypass number protection');
}
echo "Record numbers: {$passed} passed\n";
