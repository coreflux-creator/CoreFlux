<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/core/ModuleRegistry.php';
require_once $root . '/core/custom_fields.php';

$pass = 0;
$fail = 0;
$assert = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok  {$name}\n"; }
    else     { $fail++; echo "  no  {$name}\n"; }
};

echo "Manifest registry\n";
$reg = ModuleRegistry::reset($root . '/modules');
$entities = $reg->getCustomFieldEntities();
$assert('people entity registered', isset($entities['people']));
$assert('placements entity registered', isset($entities['placements']));
$assert('people record id key', ($entities['people']['record_id_key'] ?? null) === 'person_id');
$assert('people definition table', ($entities['people']['definition_table'] ?? null) === 'people_custom_field_defs');
$assert('people surfaces include exports', in_array('exports', $entities['people']['surfaces'] ?? [], true));
$assert('people surfaces include reports', in_array('reports', $entities['people']['surfaces'] ?? [], true));
$assert('placements manage permission', ($entities['placements']['manage_permission'] ?? null) === 'placements.custom_fields.manage');
$assert('placements layout sections declared', !empty($entities['placements']['layouts']['form_sections'] ?? []));
$peopleFormLayout = customFieldSurfaceLayout('people', 'forms');
$assert('people form layout normalized', in_array('profile', $peopleFormLayout['layout']['sections'] ?? [], true));
$placementsListLayout = customFieldSurfaceLayout('placements', 'lists');
$assert('placements list layout normalized', in_array('field_label', $placementsListLayout['layout']['columns'] ?? [], true));
$allLayouts = customFieldAllSurfaceLayouts('people');
$assert('all surface layouts include exports', isset($allLayouts['people']['exports']));

echo "\nCore service file\n";
$core = (string) file_get_contents($root . '/core/custom_fields.php');
$assert('customFieldEntityRegistry exists', function_exists('customFieldEntityRegistry'));
$assert('customFieldValueUpsert exists', function_exists('customFieldValueUpsert'));
$assert('customFieldSurfaceLayout exists', function_exists('customFieldSurfaceLayout'));
$assert('customFieldAllSurfaceLayouts exists', function_exists('customFieldAllSurfaceLayouts'));
$assert('customFieldDefinitionMap exists', function_exists('customFieldDefinitionMap'));
$assert('customFieldDefinitionCreate exists', function_exists('customFieldDefinitionCreate'));
$assert('customFieldDefinitionUpdate exists', function_exists('customFieldDefinitionUpdate'));
$assert('customFieldDefinitionDelete exists', function_exists('customFieldDefinitionDelete'));
$assert('customFieldAudit exists', function_exists('customFieldAudit'));
$assert('customFieldValues exists', function_exists('customFieldValues'));
$assert('people upsert path exists', str_contains($core, 'customFieldPeopleValueUpsert'));
$assert('people values read path exists', str_contains($core, 'customFieldPeopleValues'));
$assert('legacy values read path exists', str_contains($core, 'customFieldLegacyValues'));
$assert('legacy upsert path exists', str_contains($core, 'customFieldLegacyValueUpsert'));
$assert('legacy column detection exists', str_contains($core, 'customFieldLegacyColumns'));

echo "\nDiscovery API\n";
$api = $root . '/api/custom_field_entities.php';
$assert('custom field entities API exists', is_file($api));
$assert('custom field entities API parses', _php_lint($api));
$apiText = (string) file_get_contents($api);
$assert('API uses platform service', str_contains($apiText, '/../core/custom_fields.php'));
$assert('API computes can_view', str_contains($apiText, "'can_view'"));
$assert('API computes can_manage', str_contains($apiText, "'can_manage'"));
$defsApi = $root . '/api/custom_field_definitions.php';
$assert('custom field definitions API exists', is_file($defsApi));
$assert('custom field definitions API parses', _php_lint($defsApi));
$defsApiText = (string) file_get_contents($defsApi);
$assert('definitions API reads shared service', str_contains($defsApiText, 'customFieldDefinitions('));
$assert('definitions API creates shared definitions', str_contains($defsApiText, 'customFieldDefinitionCreate('));
$assert('definitions API updates shared definitions', str_contains($defsApiText, 'customFieldDefinitionUpdate('));
$assert('definitions API deletes shared definitions', str_contains($defsApiText, 'customFieldDefinitionDelete('));
$assert('definitions API audits mutations', str_contains($defsApiText, 'customFieldAudit(') && str_contains($defsApiText, 'custom_field.definition.created'));
$assert('definitions API respects PII permission', str_contains($defsApiText, 'pii_permission') && str_contains($defsApiText, 'pii_included'));
$layoutApi = $root . '/api/custom_field_layouts.php';
$assert('custom field layouts API exists', is_file($layoutApi));
$assert('custom field layouts API parses', _php_lint($layoutApi));
$layoutApiText = (string) file_get_contents($layoutApi);
$assert('layout API exposes surface layouts', str_contains($layoutApiText, 'customFieldSurfaceLayout') && str_contains($layoutApiText, 'customFieldAllSurfaceLayouts'));
$valuesApi = $root . '/api/custom_field_values.php';
$assert('custom field values API exists', is_file($valuesApi));
$assert('custom field values API parses', _php_lint($valuesApi));
$valuesApiText = (string) file_get_contents($valuesApi);
$assert('values API reads shared service', str_contains($valuesApiText, 'customFieldValues('));
$assert('values API writes shared service', str_contains($valuesApiText, 'customFieldValueUpsert('));
$assert('values API audits writes', str_contains($valuesApiText, 'custom_field.value.updated') && str_contains($valuesApiText, 'customFieldAudit('));
$assert('values API gates PII fields', str_contains($valuesApiText, 'pii_permission') && str_contains($valuesApiText, 'pii_included'));

echo "\nIntegration hook\n";
$apply = (string) file_get_contents($root . '/core/integrations/field_map_apply.php');
$assert('field map apply requires core custom fields', str_contains($apply, "/../custom_fields.php"));
$assert('field map apply calls customFieldValueUpsert', str_contains($apply, 'customFieldValueUpsert'));

echo "\nUI consumers\n";
$customFieldsUi = (string) file_get_contents($root . '/modules/people/ui/CustomFields.jsx');
$personDetailUi = (string) file_get_contents($root . '/modules/people/ui/PersonDetail.jsx');
$assert('People custom fields admin uses v1 platform definitions API', str_contains($customFieldsUi, '/api/v1/people/custom-field-definitions'));
$assert('Person detail uses v1 platform definitions API', str_contains($personDetailUi, '/api/v1/people/custom-field-definitions'));
$assert('Person detail uses v1 platform values API', str_contains($personDetailUi, '/api/v1/people/custom-field-values/'));
$assert('Person detail uses v1 platform layout API', str_contains($personDetailUi, '/api/v1/people/custom-field-layouts/detail'));
$assert('Person detail applies shared layout ordering', str_contains($personDetailUi, 'fieldOrder.indexOf') && str_contains($personDetailUi, 'orderedDefs.map'));

echo "\nDocs\n";
$assert('custom fields docs exist', is_file($root . '/docs/CUSTOM_FIELDS_LAYOUTS.md'));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

function _php_lint(string $path): bool
{
    $output = [];
    $rc = 0;
    @exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $rc);
    return $rc === 0;
}
