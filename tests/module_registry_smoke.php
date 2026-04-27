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

$assert("discovers people",     in_array('people',     $ids, true));
$assert("discovers accounting", in_array('accounting', $ids, true));
$assert("discovers payroll",    in_array('payroll',    $ids, true));
$assert("skips _template",      !in_array('_template', $ids, true));
$assert("skips folders without manifest", !in_array('private_equity', $ids, true));
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
$assert("flat-list shape: payroll.view harvested", in_array('payroll.view', $perms, true));
$assert("flat-list shape: people.view harvested",  in_array('people.view',  $perms, true));
$assert("assoc-map shape: accounting.view harvested",
    in_array('accounting.view', $perms, true));
$assert("assoc-map shape: accounting.journal.post harvested",
    in_array('accounting.journal.post', $perms, true));

$descs = $reg->getAllPermissionsWithDescriptions();
$assert("permission descriptions for accounting are populated",
    isset($descs['accounting.view']) && $descs['accounting.view'] !== '');

echo "\nDefaults applied to partial manifests\n";
$assert("payroll has 'views' field even though not declared",
    is_array($people['views'] ?? null));
$assert("payroll has 'audit_events' field as []",
    isset($people['audit_events']) && is_array($people['audit_events']));

echo "\nValidation\n";
$errs = $reg->getValidationErrors();
$assert("no validation errors against current manifests", empty($errs));

echo "\nRole filter (transitional helper)\n";
// People manifest has no default_roles set → should not match by role
// unless caller passes 'master_admin'.
$adminMods = $reg->getModulesForRole('master_admin');
$assert("master_admin sees all modules", count($adminMods) === count($reg->getAllModules()));

echo "\n";
echo "Total: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
