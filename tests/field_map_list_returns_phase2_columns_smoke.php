<?php
/**
 * field_map_list_returns_phase2_columns_smoke.php
 *
 * Regression guard for the operator-reported issue:
 *   "Can you save a mapping? -> no"
 *
 * Root cause discovered: `tenantIntegrationFieldMapList()` was
 * SELECTing only the legacy `(external_field, internal_field, …)`
 * columns and DROPPING the Phase 2 columns added by migration 077
 * (`source_path`, `target_module`, `target_table`, `target_column`,
 * `linked_entity`).
 *
 * Effect: saves wrote those columns correctly, but the GET that
 * drives the UI returned the row WITHOUT them — the studio rendered
 * the mapping as "missing", and operators interpreted that as
 * "save didn't work."
 */
declare(strict_types=1);

function _ok(string $msg): void { fwrite(STDOUT, "✅ $msg\n"); }

$src = (string) file_get_contents(__DIR__ . '/../core/integrations/field_map.php');
$listStart = strpos($src, 'function tenantIntegrationFieldMapList');
assert($listStart !== false, 'list function exists');
$listSlice = substr($src, $listStart, 1200);

foreach ([
    'source_path',
    'target_module',
    'target_table',
    'target_column',
    'linked_entity',
] as $col) {
    assert(str_contains($listSlice, $col),
        "list query SELECTs Phase 2 column `$col`");
}
_ok('tenantIntegrationFieldMapList SELECTs every Phase 2 column');

// Also confirm the legacy columns are still selected.
foreach ([
    'external_field',
    'internal_field',
    'transform',
    'enabled',
    'notes',
    'updated_by_user_id',
] as $col) {
    assert(str_contains($listSlice, $col),
        "list query still SELECTs legacy column `$col`");
}
_ok('list query preserves every legacy column');

echo "\n🎯 field_map_list_returns_phase2_columns_smoke — ALL PASS\n";
