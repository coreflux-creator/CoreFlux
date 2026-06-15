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
$assert('people PII writes require manage permission', ($entities['people']['pii_manage_permission'] ?? null) === 'people.pii.manage');
$assert('placements manage permission', ($entities['placements']['manage_permission'] ?? null) === 'placements.custom_fields.manage');
$assert('placements layout sections declared', !empty($entities['placements']['layouts']['form_sections'] ?? []));
$peopleFormLayout = customFieldSurfaceLayout('people', 'forms');
$assert('people form layout normalized', in_array('profile', $peopleFormLayout['layout']['sections'] ?? [], true));
$assert('people form layout defaults to manifest source', ($peopleFormLayout['source'] ?? null) === 'manifest');
$placementsListLayout = customFieldSurfaceLayout('placements', 'lists');
$assert('placements list layout normalized', in_array('field_label', $placementsListLayout['layout']['columns'] ?? [], true));
$allLayouts = customFieldAllSurfaceLayouts('people');
$assert('all surface layouts include exports', isset($allLayouts['people']['exports']));
$mergedLayout = customFieldMergeSurfaceLayout(['sections' => ['profile'], 'field_order' => ['legacy_id']], 'forms', [
    'field_order' => ['favorite_color', 'legacy_id'],
]);
$assert('tenant layout merge preserves defaults and overrides changed keys',
    ($mergedLayout['sections'] ?? []) === ['profile']
    && ($mergedLayout['field_order'] ?? []) === ['favorite_color', 'legacy_id']);

echo "\nCore service file\n";
$core = (string) file_get_contents($root . '/core/custom_fields.php');
$assert('customFieldEntityRegistry exists', function_exists('customFieldEntityRegistry'));
$assert('customFieldValueUpsert exists', function_exists('customFieldValueUpsert'));
$assert('customFieldSurfaceLayout exists', function_exists('customFieldSurfaceLayout'));
$assert('customFieldAllSurfaceLayouts exists', function_exists('customFieldAllSurfaceLayouts'));
$assert('customFieldSurfaceLayoutSave exists', function_exists('customFieldSurfaceLayoutSave'));
$assert('customFieldSurfaceLayoutReset exists', function_exists('customFieldSurfaceLayoutReset'));
$assert('customFieldTenantSurfaceLayout exists', function_exists('customFieldTenantSurfaceLayout'));
$assert('custom field layout access helpers exist',
    function_exists('customFieldValidateSurfaceLayout')
    && function_exists('customFieldSurfaceLayoutForUser')
    && function_exists('customFieldAllSurfaceLayoutsForUser')
    && function_exists('customFieldFilterSurfaceLayoutForUser'));
$assert('customFieldDefinitionMap exists', function_exists('customFieldDefinitionMap'));
$assert('customFieldDefinitionCreate exists', function_exists('customFieldDefinitionCreate'));
$assert('customFieldDefinitionUpdate exists', function_exists('customFieldDefinitionUpdate'));
$assert('customFieldDefinitionDelete exists', function_exists('customFieldDefinitionDelete'));
$assert('customFieldAudit exists', function_exists('customFieldAudit'));
$assert('customFieldValues exists', function_exists('customFieldValues'));
$assert('customFieldLegacyActiveWhere exists', function_exists('customFieldLegacyActiveWhere'));
$assert('custom field role-access helpers exist',
    function_exists('customFieldUserCanViewDefinition')
    && function_exists('customFieldUserCanEditDefinition')
    && function_exists('customFieldFilterDefinitionsForUser')
    && function_exists('customFieldFilterValuesForUser'));
$roleDefinition = customFieldNormalizeDefinitionRow([
    'visible_to_roles_json' => '["tenant_admin","controller"]',
    'editable_by_roles_json' => '["controller"]',
]);
$assert('definitions normalize field-level role metadata',
    ($roleDefinition['visible_to'] ?? []) === ['tenant_admin', 'controller']
    && ($roleDefinition['editable_by'] ?? []) === ['controller']);
$assert('field-level visibility grants matching tenant role',
    customFieldUserCanViewDefinition(['tenant_role' => 'controller'], $roleDefinition)
    && !customFieldUserCanViewDefinition(['tenant_role' => 'viewer'], $roleDefinition));
$assert('field-level edit grants matching tenant role',
    customFieldUserCanEditDefinition(['tenant_role' => 'controller'], $roleDefinition)
    && !customFieldUserCanEditDefinition(['tenant_role' => 'tenant_admin'], $roleDefinition));
$assert('custom field reads support archived opt-in',
    str_contains($core, 'bool $includeArchived = false')
    && str_contains($core, 'customFieldDefinitions($tenantId, $entityType, $includeArchived)')
    && str_contains($core, 'customFieldValues('));
$archivedDefinition = customFieldNormalizeDefinitionRow(['deleted_at' => '2026-06-01 00:00:00']);
$assert('archived definitions normalize audit metadata',
    !empty($archivedDefinition['archived'])
    && ($archivedDefinition['is_archived'] ?? 0) === 1
    && ($archivedDefinition['deleted_at'] ?? null) === '2026-06-01 00:00:00');
$assert('people upsert path exists', str_contains($core, 'customFieldPeopleValueUpsert'));
$assert('people values read path exists', str_contains($core, 'customFieldPeopleValues'));
$assert('legacy values read path exists', str_contains($core, 'customFieldLegacyValues'));
$assert('legacy upsert path exists', str_contains($core, 'customFieldLegacyValueUpsert'));
$assert('legacy column detection exists', str_contains($core, 'customFieldLegacyColumns'));
$assert('legacy definitions expose PII metadata', str_contains($core, 'AS pii'));
$assert('definitions expose field-level role metadata',
    str_contains($core, 'visible_to_roles_json')
    && str_contains($core, 'editable_by_roles_json')
    && str_contains($core, 'customFieldDefinitionAccess'));
$assert('legacy definitions expose order metadata', str_contains($core, 'AS order_index'));
$assert('legacy reads filter inactive/soft-deleted fields', substr_count($core, 'customFieldLegacyActiveWhere($cols') >= 3);
$assert('surface layout resolves tenant overrides',
    str_contains($core, 'customFieldTenantSurfaceLayout($tenantId, $entityType, $surface)')
    && str_contains($core, "'source'      => \$source"));
$assert('surface layout save persists platform override',
    str_contains($core, 'custom_field_layout_overrides')
    && str_contains($core, 'ON DUPLICATE KEY UPDATE')
    && str_contains($core, 'customFieldSurfaceLayoutSave'));
$assert('surface layout save validates field keys against definitions',
    str_contains($core, 'customFieldValidateSurfaceLayout')
    && str_contains($core, 'customFieldSurfaceLayoutAllowedFieldKeys')
    && str_contains($core, "references unknown custom field"));
$assert('surface layout reads can filter field-level gates',
    str_contains($core, 'customFieldSurfaceLayoutForUser')
    && str_contains($core, 'customFieldFilterSurfaceLayoutForUser')
    && str_contains($core, "'field_access_enforced'"));

echo "\nGovernance migration\n";
$migration = $root . '/core/migrations/119_custom_fields_governance_columns.sql';
$migrationText = is_file($migration) ? (string) file_get_contents($migration) : '';
$assert('custom fields governance migration exists', is_file($migration));
$assert('migration adds pii metadata', str_contains($migrationText, 'COLUMN_NAME = \'pii\''));
$assert('migration adds order metadata', str_contains($migrationText, 'COLUMN_NAME = \'order_index\''));
$assert('migration adds active flag', str_contains($migrationText, 'COLUMN_NAME = \'is_active\''));
$assert('migration adds soft delete', str_contains($migrationText, 'COLUMN_NAME = \'deleted_at\''));
$layoutMigration = $root . '/core/migrations/122_custom_field_layout_overrides.sql';
$layoutMigrationText = is_file($layoutMigration) ? (string) file_get_contents($layoutMigration) : '';
$assert('custom field layout override migration exists', is_file($layoutMigration));
$assert('layout override migration creates tenant surface table',
    str_contains($layoutMigrationText, 'CREATE TABLE IF NOT EXISTS custom_field_layout_overrides')
    && str_contains($layoutMigrationText, 'UNIQUE KEY uniq_cflo_tenant_entity_surface'));
$roleAccessMigration = $root . '/core/migrations/123_custom_field_role_access.sql';
$roleAccessMigrationText = is_file($roleAccessMigration) ? (string) file_get_contents($roleAccessMigration) : '';
$assert('custom field role-access migration exists', is_file($roleAccessMigration));
$assert('role-access migration covers people and generic custom fields',
    str_contains($roleAccessMigrationText, 'people_custom_field_defs')
    && str_contains($roleAccessMigrationText, 'custom_fields')
    && str_contains($roleAccessMigrationText, 'visible_to_roles_json')
    && str_contains($roleAccessMigrationText, 'editable_by_roles_json'));

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
$assert('definitions API respects PII permissions', str_contains($defsApiText, 'pii_permission') && str_contains($defsApiText, 'pii_manage_permission') && str_contains($defsApiText, 'pii_included'));
$assert('definitions API enforces field-level access metadata',
    str_contains($defsApiText, 'customFieldFilterDefinitionsForUser')
    && str_contains($defsApiText, "'field_access_enforced' => true")
    && str_contains($defsApiText, "'visible_to'")
    && str_contains($defsApiText, "'editable_by'"));
$layoutApi = $root . '/api/custom_field_layouts.php';
$assert('custom field layouts API exists', is_file($layoutApi));
$assert('custom field layouts API parses', _php_lint($layoutApi));
$layoutApiText = (string) file_get_contents($layoutApi);
$assert('layout API exposes surface layouts', str_contains($layoutApiText, 'customFieldSurfaceLayout') && str_contains($layoutApiText, 'customFieldAllSurfaceLayouts'));
$assert('layout API writes tenant overrides',
    str_contains($layoutApiText, "['PUT', 'PATCH', 'DELETE']")
    && str_contains($layoutApiText, 'customFieldSurfaceLayoutSave')
    && str_contains($layoutApiText, 'customFieldSurfaceLayoutReset'));
$assert('layout API filters layouts for the current actor',
    str_contains($layoutApiText, 'customFieldSurfaceLayoutForUser')
    && str_contains($layoutApiText, 'customFieldAllSurfaceLayoutsForUser')
    && str_contains($layoutApiText, "\$presented['can_manage']"));
$assert('layout API audits mutations',
    str_contains($layoutApiText, 'custom_field.layout.updated')
    && str_contains($layoutApiText, 'custom_field.layout.reset')
    && str_contains($layoutApiText, 'customFieldAudit('));
$valuesApi = $root . '/api/custom_field_values.php';
$assert('custom field values API exists', is_file($valuesApi));
$assert('custom field values API parses', _php_lint($valuesApi));
$valuesApiText = (string) file_get_contents($valuesApi);
$assert('values API reads shared service', str_contains($valuesApiText, 'customFieldValues('));
$assert('values API writes shared service', str_contains($valuesApiText, 'customFieldValueUpsert('));
$assert('values API audits writes', str_contains($valuesApiText, 'custom_field.value.updated') && str_contains($valuesApiText, 'customFieldAudit('));
$assert('values API audits PII reads', str_contains($valuesApiText, 'custom_field.value.pii_viewed'));
$assert('values API gates PII fields', str_contains($valuesApiText, 'pii_manage_permission') && str_contains($valuesApiText, 'pii_write_allowed'));
$assert('values API enforces field-level view/edit access',
    str_contains($valuesApiText, 'customFieldFilterValuesForUser')
    && str_contains($valuesApiText, 'customFieldUserCanEditDefinition')
    && str_contains($valuesApiText, 'field-level edit access'));

echo "\nLegacy People adapters\n";
$peopleDefsApi = (string) file_get_contents($root . '/modules/people/api/custom_fields.php');
$peopleValuesApi = (string) file_get_contents($root . '/modules/people/api/custom_field_values.php');
$assert('legacy People definitions adapter uses shared service', str_contains($peopleDefsApi, 'customFieldDefinitionCreate(') && str_contains($peopleDefsApi, 'customFieldDefinitionUpdate('));
$assert('legacy People values adapter uses shared service', str_contains($peopleValuesApi, 'customFieldValues(') && str_contains($peopleValuesApi, 'customFieldValueUpsert('));
$assert('legacy People values adapter preserves pii_redacted', str_contains($peopleValuesApi, "'pii_redacted'") && str_contains($peopleValuesApi, 'peopleCustomFieldHasPiiDefinitions'));
$assert('legacy People adapters enforce field-level access',
    str_contains($peopleDefsApi, 'customFieldFilterDefinitionsForUser')
    && str_contains($peopleValuesApi, 'customFieldFilterValuesForUser')
    && str_contains($peopleValuesApi, 'customFieldUserCanEditDefinition'));

echo "\nIntegration hook\n";
$apply = (string) file_get_contents($root . '/core/integrations/field_map_apply.php');
$assert('field map apply requires core custom fields', str_contains($apply, "/../custom_fields.php"));
$assert('field map apply calls customFieldValueUpsert', str_contains($apply, 'customFieldValueUpsert'));

echo "\nUI consumers\n";
$customFieldsUi = (string) file_get_contents($root . '/modules/people/ui/CustomFields.jsx');
$personDetailUi = (string) file_get_contents($root . '/modules/people/ui/PersonDetail.jsx');
$placementsCustomFieldsUi = (string) file_get_contents($root . '/modules/placements/ui/CustomFields.jsx');
$placementDetailUi = (string) file_get_contents($root . '/modules/placements/ui/PlacementDetail.jsx');
$placementsModuleUi = (string) file_get_contents($root . '/modules/placements/ui/PlacementsModule.jsx');
$placementsManifest = (string) file_get_contents($root . '/modules/placements/manifest.php');
$assert('People custom fields admin uses v1 platform definitions API', str_contains($customFieldsUi, '/api/v1/people/custom-field-definitions'));
$assert('People custom fields delete uses query id', str_contains($customFieldsUi, '?id=${id}'));
$assert('Person detail uses v1 platform definitions API', str_contains($personDetailUi, '/api/v1/people/custom-field-definitions'));
$assert('Person detail uses v1 platform values API', str_contains($personDetailUi, '/api/v1/people/custom-field-values/'));
$assert('Person detail uses v1 platform layout API', str_contains($personDetailUi, '/api/v1/people/custom-field-layouts/detail'));
$assert('Person detail applies shared layout ordering', str_contains($personDetailUi, 'fieldOrder.indexOf') && str_contains($personDetailUi, 'orderedDefs.map'));
$assert('Placements manifest exposes custom fields action', str_contains($placementsManifest, "'route' => 'custom_fields'") && str_contains($placementsManifest, "'permission' => 'placements.custom_fields.manage'"));
$assert('Placements module routes custom fields admin', str_contains($placementsModuleUi, '<Route path="custom_fields" element={<CustomFields />} />'));
$assert('Placements custom fields admin uses v1 platform definitions API', str_contains($placementsCustomFieldsUi, '/api/v1/placements/custom-field-definitions'));
$assert('Placements custom fields admin deletes by query id', str_contains($placementsCustomFieldsUi, '?id=${id}'));
$assert('Placement detail exposes custom fields tab', str_contains($placementDetailUi, "slug: 'custom'") && str_contains($placementDetailUi, 'CustomFieldsTab placementId={placement.id}'));
$assert('Placement detail uses v1 platform definitions API', str_contains($placementDetailUi, '/api/v1/placements/custom-field-definitions'));
$assert('Placement detail uses v1 platform values API', str_contains($placementDetailUi, '/api/v1/placements/custom-field-values/'));
$assert('Placement detail uses v1 platform layout API', str_contains($placementDetailUi, '/api/v1/placements/custom-field-layouts/detail'));
$assert('Placement detail gates editing on layout permission', str_contains($placementDetailUi, 'can_manage') && str_contains($placementDetailUi, 'placements.custom_fields.manage'));
$assert('Placement detail applies shared layout ordering', str_contains($placementDetailUi, 'fieldOrder.indexOf') && str_contains($placementDetailUi, 'orderedDefs.map'));

echo "\nDocs\n";
$assert('custom fields docs exist', is_file($root . '/docs/CUSTOM_FIELDS_LAYOUTS.md'));
$customFieldsDocs = (string) file_get_contents($root . '/docs/CUSTOM_FIELDS_LAYOUTS.md');
$assert('custom fields docs require archived exportability',
    str_contains($customFieldsDocs, 'Archived definitions and values carry')
    && str_contains($customFieldsDocs, 'export/report surfaces can still include'));
$assert('custom fields docs cover tenant layout overrides',
    str_contains($customFieldsDocs, 'custom_field_layout_overrides')
    && str_contains($customFieldsDocs, 'PUT    /api/v1/people/custom-field-layouts/forms')
    && str_contains($customFieldsDocs, 'custom_field.layout.updated'));
$assert('custom fields docs cover field-level role gates',
    str_contains($customFieldsDocs, 'visible_to_roles_json')
    && str_contains($customFieldsDocs, 'customFieldUserCanViewDefinition')
    && str_contains($customFieldsDocs, 'Field-level role gates are platform controls'));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

function _php_lint(string $path): bool
{
    $output = [];
    $rc = 0;
    @exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $rc);
    return $rc === 0;
}
