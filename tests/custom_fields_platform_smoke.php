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

echo "\nCore service file\n";
$core = (string) file_get_contents($root . '/core/custom_fields.php');
$assert('customFieldEntityRegistry exists', function_exists('customFieldEntityRegistry'));
$assert('customFieldValueUpsert exists', function_exists('customFieldValueUpsert'));
$assert('people upsert path exists', str_contains($core, 'customFieldPeopleValueUpsert'));
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

echo "\nIntegration hook\n";
$apply = (string) file_get_contents($root . '/core/integrations/field_map_apply.php');
$assert('field map apply requires core custom fields', str_contains($apply, "/../custom_fields.php"));
$assert('field map apply calls customFieldValueUpsert', str_contains($apply, 'customFieldValueUpsert'));

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
