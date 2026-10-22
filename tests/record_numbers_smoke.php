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
$quantaName = staffingFieldContractRules('people', 'first_name')['remote_contracts']['quanta'];
numberCheck($quantaName['path'] === 'full_name'
    && $quantaName['mappings'][0]['route'] === '/api/v1/integrations/coreflux/worker-profiles'
    && str_contains($quantaName['mappings'][0]['transform'], 'join_trimmed_first_and_last_name'), 'reviewed worker contract records the actual composed name path');
$quantaEmail = staffingFieldContractRules('people', 'email_primary')['remote_contracts']['quanta'];
numberCheck($quantaEmail['direction'] === 'core_to_quanta'
    && $quantaEmail['status'] === 'source_verified_hosted_acceptance_pending'
    && !$quantaEmail['sync_enabled_by_dictionary'], 'worker mapping does not claim hosted acceptance or enable delivery');
numberCheck(staffingFieldContractRules('people', 'external_id')['remote_contracts']['quanta']['status'] === 'unverified', 'existing provider identity is not claimed as a Core ID field');
numberCheck(staffingFieldContractRules('people', 'first_name')['remote_contracts']['purepayroll']['path'] === 'first_name', 'explicit people-review mapping carries a separate first name');
foreach ([['people', 'ssn_ct'], ['people_employees', 'employee_number'], ['placements', 'future_field'],
    ['placement_rates', 'future_field'], ['time_entries', 'approved_at']] as [$table, $field]) {
    foreach (staffingFieldContractRules($table, $field)['remote_contracts'] as $remote) {
        numberCheck(empty($remote['mappings']), 'restricted, legacy, unknown and approval fields never acquire an inferred exchange mapping');
    }
}
foreach (staffingExchangeFieldMappings() as $providers) {
    foreach ($providers as $mappings) foreach ($mappings as $mapping) {
        numberCheck(is_file(__DIR__ . '/../' . $mapping['source_evidence']['core']), 'every source-backed mapping names existing Core implementation evidence');
    }
}
$assignmentFields = staffingFieldContractRules('placements', 'person_id')['remote_contracts']['quanta'];
numberCheck($assignmentFields['status'] === 'source_verified_hosted_acceptance_pending'
    && !$assignmentFields['sync_enabled_by_dictionary'], 'source mapping does not claim deployment or enable writes');
numberCheck($assignmentFields['path'] === 'person'
    && str_contains($assignmentFields['mappings'][0]['transform'], 'typed_reference:person'), 'assignment relationship resolves a typed person, never copies a SQL ID');
$payRate = staffingFieldContractRules('placement_rates', 'pay_rate')['remote_contracts'];
numberCheck(array_column($payRate['purepayroll']['mappings'],'path') === ['components[].rate.pay_rate','terms[].pay_rates.regular']
    && $payRate['quanta']['status'] === 'unverified', 'frozen payroll rate never becomes a Quanta or native employee rate write');
numberCheck(staffingFieldContractRules('placement_rates','bill_rate')['remote_contracts']['purepayroll']['path']==='terms[].bill_rates.regular', 'approved bill rate has an explicit commercial review mapping');
numberCheck(isset(staffingExchangeWorkflowGaps()['native_payroll_employee_provisioning']), 'native provisioning remains an explicit unresolved workflow');
require_once __DIR__ . '/../core/integrations/field_map.php';
foreach (coreRecordNumberFields() as $field) {
    numberCheck(tenantIntegrationFieldMapIsProtectedInternalField($field), 'number excluded from mapping targets');
    numberCheck(numberThrows(fn () => tenantIntegrationFieldMapUpsert(1, ['integration' => 'jobdiva', 'entity_type' => 'person', 'external_field' => 'workerId', 'target_module' => 'people', 'target_table' => 'people', 'target_column' => $field, 'internal_field' => 'first_name'], null)), 'catalog path cannot bypass number protection');
}
echo "Record numbers: {$passed} passed\n";
