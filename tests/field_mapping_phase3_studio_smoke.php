<?php
/**
 * Smoke — Field Mapping Studio (Phase 3 UI) + applyAll wired into
 * the person/company/contact JobDiva sync paths (Phase 2 extension).
 *
 * Asserts:
 *   1. FieldMappingStudio.jsx exists with expected testids
 *      (paths pane, targets pane, save bar, existing mappings).
 *   2. Route mounted in AdminModule.jsx.
 *   3. Banner link from legacy IntegrationFieldMapAdmin.jsx points
 *      to the new studio route.
 *   4. JobDiva company sync now calls applyAll with `{self}` context.
 *   5. JobDiva contact sync now calls applyAll with `{self}` context.
 *   6. JobDiva person sync (sync_placements.php) calls applyAll on
 *      BOTH the "found existing person" and "auto-created person"
 *      branches with `{self}` context.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$ui  = (string) file_get_contents('/app/dashboard/src/pages/FieldMappingStudio.jsx');
$adm = (string) file_get_contents('/app/dashboard/src/pages/AdminModule.jsx');
$leg = (string) file_get_contents('/app/dashboard/src/pages/IntegrationFieldMapAdmin.jsx');
$sync = (string) file_get_contents('/app/core/jobdiva/sync.php');
$splp = (string) file_get_contents('/app/core/jobdiva/sync_placements.php');

echo "\n1. FieldMappingStudio.jsx — testids + panes\n";
$a('section root testid',                str_contains($ui, 'data-testid="field-mapping-studio"'));
$a('integration + entity_type dropdowns',
    str_contains($ui, 'data-testid="fms-integration"')
    && str_contains($ui, 'data-testid="fms-entity-type"'));
$a('paths pane + filter + grouped list + empty-state',
    str_contains($ui, 'data-testid="fms-paths-pane"')
    && str_contains($ui, 'data-testid="fms-paths-filter"')
    && str_contains($ui, 'data-testid="fms-paths-grouped"')
    && str_contains($ui, 'data-testid="fms-paths-empty"'));
$a('targets pane + filter + list',
    str_contains($ui, 'data-testid="fms-targets-pane"')
    && str_contains($ui, 'data-testid="fms-targets-filter"')
    && str_contains($ui, 'data-testid="fms-targets-list"'));
$a('save bar with source + target summary + linked_entity + transform + save button',
    str_contains($ui, 'data-testid="fms-save-bar"')
    && str_contains($ui, 'data-testid="fms-source-summary"')
    && str_contains($ui, 'data-testid="fms-target-summary"')
    && str_contains($ui, 'data-testid="fms-linked-entity"')
    && str_contains($ui, 'data-testid="fms-transform"')
    && str_contains($ui, 'data-testid="fms-save-btn"'));
$a('per-path/-target testids interpolated from path/key',
    str_contains($ui, 'data-testid={`fms-path-${p.source_path}`}')
    && str_contains($ui, 'data-testid={`fms-target-${key}`}'));
$a('custom-field code input only renders for "*" target_column',
    str_contains($ui, "selectedTarget.target_column === '*'")
    && str_contains($ui, 'data-testid="fms-custom-field-code"'));
$a('existing mappings table + per-row delete testid',
    str_contains($ui, 'data-testid="fms-existing-table"')
    && str_contains($ui, 'data-testid={`fms-existing-delete-${m.id}`}'));
$a('Promise.all loads paths + targets + existing in parallel',
    (bool) preg_match('/Promise\.all\(\[\s*api\.get\(`\/api\/admin\/integrations\/payload_fields\.php\?integration=/s', $ui));
$a('save posts source_path + target_module/table/column + linked_entity to existing endpoint',
    str_contains($ui, "api.post('/api/admin/integrations/field_map.php', body)")
    && str_contains($ui, 'source_path:   selectedPath.source_path,')
    && str_contains($ui, 'target_module: selectedTarget.target_module,')
    && str_contains($ui, 'target_table:  selectedTarget.target_table,')
    && str_contains($ui, 'linked_entity: linkedEntity,'));
$a('selecting a target pre-fills linked_entity from default_linked_entity',
    str_contains($ui, 'selectedTarget?.default_linked_entity')
    && str_contains($ui, 'setLinkedEntity(selectedTarget.default_linked_entity)'));

echo "\n2. AdminModule.jsx mounts the studio route\n";
$a('imports FieldMappingStudio',
    str_contains($adm, "import FieldMappingStudio from './FieldMappingStudio';"));
$a('route mounted at /integrations/field-map/studio',
    str_contains($adm, '<Route path="/integrations/field-map/studio" element={<FieldMappingStudio'));

echo "\n3. Legacy admin page links to the studio\n";
$a('studio-link banner rendered',
    str_contains($leg, 'data-testid="field-map-studio-link-banner"'));
$a('link points at /admin/integrations/field-map/studio',
    str_contains($leg, 'href="/admin/integrations/field-map/studio"')
    && str_contains($leg, 'data-testid="field-map-studio-link"'));

echo "\n4. JobDiva company sync wires applyAll\n";
$a('company sync requires field_map_apply.php',
    str_contains($sync, "require_once __DIR__ . '/../integrations/field_map_apply.php';")
    && strpos($sync, "require_once __DIR__ . '/../integrations/field_map_apply.php';") < strpos($sync, "integrationFieldMapApplyAll(\$tid, 'jobdiva', 'company', \$jd, ['self' => \$companyId]);"));
$a('company sync calls applyAll with {self => $companyId}',
    str_contains($sync, "integrationFieldMapApplyAll(\$tid, 'jobdiva', 'company', \$jd, ['self' => \$companyId]);"));

echo "\n5. JobDiva contact sync wires applyAll\n";
$a('contact sync calls applyAll with {self => $internalId}',
    str_contains($sync, "integrationFieldMapApplyAll(\$tid, 'jobdiva', 'contact', \$jd, ['self' => \$internalId]);"));

echo "\n6. JobDiva person sync wires applyAll on both branches\n";
$a('person sync (found-existing branch) calls applyAll',
    str_contains($splp, "integrationFieldMapApplyAll(\$tid, 'jobdiva', 'person', \$jd, ['self' => \$existingId]);"));
$a('person sync (auto-create branch) calls applyAll',
    str_contains($splp, "integrationFieldMapApplyAll(\$tid, 'jobdiva', 'person', \$jd, ['self' => \$newId]);"));

echo "\n7. PHP syntax\n";
foreach ([
    '/app/core/jobdiva/sync.php',
    '/app/core/jobdiva/sync_placements.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $a("php -l {$f}", $rc === 0, implode("\n", $out));
}

echo "\n=========================================\n";
echo "Field-mapping Phase 3 (UI) + Phase 2 ext smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
