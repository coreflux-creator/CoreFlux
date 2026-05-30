<?php
/**
 * airtable_slice3_smoke.php
 *
 * Slice 3 — Airtable integration: Entity drawer polish + Field Mapping
 * Studio wiring + Health & troubleshooting surface.
 *
 *   • core/airtable/sync.php now stamps `_airtable_record_url`,
 *     `_airtable_mapping_id`, `_airtable_base_name`, `_airtable_table_name`
 *     into every payload snapshot so entity drawers can deep-link.
 *   • core/airtable/sync.php applies generalised Studio field mappings
 *     via integrationFieldMapApplyAll() AFTER successful linkage so
 *     Airtable rows write into real CoreFlux columns.
 *   • api/airtable.php exposes a `health` action with tenant-wide
 *     rollup, per-mapping health, hints and field-map coverage.
 *   • api/airtable/health.php shim exists.
 *   • api/integrations/mappings.php now accepts integrations.*.view
 *     (any source) instead of only jobdiva.view.
 *   • dashboard/src/components/LinkedExternalSystemsPanel.jsx renders
 *     an "Open in <source>" deep-link when payload has *_record_url.
 *   • dashboard/src/pages/AirtableSettings.jsx renders the HealthPanel
 *     with tile rollups, hints and per-mapping drilldown.
 *   • dashboard/src/pages/FieldMappingStudio.jsx fallback entity list
 *     for airtable expanded from ['record'] to the full
 *     AIRTABLE_INTERNAL_ENTITIES set.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ✓ {$name}\n"; }
    else     { $fail++; echo "  ✗ {$name}\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "Airtable Slice 3 — Drawer + Studio + Health smoke\n";
echo "==================================================\n\n";

$ROOT = dirname(__DIR__);

// --- core/airtable/sync.php ---------------------------------------
echo "core/airtable/sync.php\n";
$sync = $read("{$ROOT}/core/airtable/sync.php");
$a('file exists',                              $sync !== '');
$a('requires field_map_apply.php',             str_contains($sync, "require_once __DIR__ . '/../integrations/field_map_apply.php'"));
$a('stamps _airtable_record_url',              str_contains($sync, "_airtable_record_url")
                                            && str_contains($sync, 'https://airtable.com/'));
$a('stamps _airtable_mapping_id',              str_contains($sync, "_airtable_mapping_id"));
$a('stamps _airtable_base_name',               str_contains($sync, "_airtable_base_name"));
$a('stamps _airtable_table_name',              str_contains($sync, "_airtable_table_name"));
$a('rawurlencodes base/table/external id',     substr_count($sync, 'rawurlencode(') >= 3);
$a('applies field_map AFTER successful link',  str_contains($sync, "integrationFieldMapApplyAll(")
                                            && str_contains($sync, "\$syncStatus === 'ok'")
                                            && str_contains($sync, "!empty(\$resolved['internal_id'])"));
$a('passes raw $fields as payload',            str_contains($sync, "integrationFieldMapApplyAll(\n                                \$tenantId, 'airtable',"));
$a('builds context with self+entity slug',     str_contains($sync, "\$context = ['self' =>")
                                            && str_contains($sync, "\$context[\$mapping['internal_entity']] = (int) \$resolved['internal_id']"));
$a('tracks fieldMapAttempted',                 str_contains($sync, '$fieldMapAttempted'));
$a('tracks fieldMapWritten',                   str_contains($sync, '$fieldMapWritten'));
$a('tracks fieldMapErrors',                    str_contains($sync, '$fieldMapErrors'));
$a('catches field-map throws (never tank sync)', str_contains($sync, "apply_throw:"));
$a('returns field_map_attempted in result',    str_contains($sync, "'field_map_attempted' => \$fieldMapAttempted"));
$a('returns field_map_written in result',      str_contains($sync, "'field_map_written'   => \$fieldMapWritten"));
$a('returns field_map_errors in result',       str_contains($sync, "'field_map_errors'    => \$fieldMapErrors"));
$a('audit logs include field_map metrics',     str_contains($sync, "'field_map_attempted' => \$fieldMapAttempted"));
// PHP lint
$lint = shell_exec('php -l ' . escapeshellarg("{$ROOT}/core/airtable/sync.php") . ' 2>&1');
$a('PHP -l passes',                            is_string($lint) && str_contains($lint, 'No syntax errors detected'));

// --- api/airtable.php health action --------------------------------
echo "\napi/airtable.php — health action\n";
$api = $read("{$ROOT}/api/airtable.php");
$a("case 'health' exists",                     str_contains($api, "case 'health':"));
$a('rolls up total_records',                   str_contains($api, "'total_records'"));
$a('rolls up linked/unmatched/ambiguous',      str_contains($api, "'linked'")
                                            && str_contains($api, "'unmatched'")
                                            && str_contains($api, "'ambiguous'"));
$a('counts mappings off/unrun/failed',         str_contains($api, "'mappings_off'")
                                            && str_contains($api, "'mappings_unrun'")
                                            && str_contains($api, "'mappings_failed'"));
$a('emits per_mapping array',                  str_contains($api, "'per_mapping'      => \$perMapping"));
$a('computes health_pct per mapping',          str_contains($api, "'health_pct'"));
$a('builds actionable hints array',            str_contains($api, "'hints'            => \$hints"));
$a('hint code: not_connected',                 str_contains($api, "'code'     => 'not_connected'"));
$a('hint code: sync_error',                    str_contains($api, "'code'     => 'sync_error'"));
$a('hint code: ambiguous',                     str_contains($api, "'code'     => 'ambiguous'"));
$a('hint code: unmatched',                     str_contains($api, "'code'     => 'unmatched'"));
$a('hint code: no_strategy',                   str_contains($api, "'code'     => 'no_strategy'"));
$a('queries tenant_integration_field_map for coverage',
                                              str_contains($api, "FROM tenant_integration_field_map")
                                            && str_contains($api, "integration = 'airtable'"));
$a('rbac: airtable.view gate',                 str_contains($api, "case 'health': {")
                                            && str_contains($api, "rbac_legacy_require(\$user, 'integrations.airtable.view');"));

// --- api/airtable/health.php shim ----------------------------------
echo "\napi/airtable/health.php — thin shim\n";
$shim = $read("{$ROOT}/api/airtable/health.php");
$a('shim file exists',                         $shim !== '');
$a('shim delegates to api/airtable.php',       str_contains($shim, "require __DIR__ . '/../airtable.php'"));

// --- api/integrations/mappings.php RBAC --------------------------
echo "\napi/integrations/mappings.php — RBAC relaxation\n";
$mp = $read("{$ROOT}/api/integrations/mappings.php");
$a('uses rbac_legacy_require_any',             str_contains($mp, 'rbac_legacy_require_any'));
$a('accepts integrations.airtable.view',       str_contains($mp, "'integrations.airtable.view'"));
$a('accepts integrations.jobdiva.view',        str_contains($mp, "'integrations.jobdiva.view'"));
$a('accepts integrations.qbo.view',            str_contains($mp, "'integrations.qbo.view'"));
$a('does NOT call legacy_require alone',       !preg_match("/rbac_legacy_require\\(\\\$user, 'integrations\\.jobdiva\\.view'\\)/", $mp));

// --- dashboard/src/components/LinkedExternalSystemsPanel.jsx ------
echo "\nLinkedExternalSystemsPanel.jsx — drawer deep-link\n";
$panel = $read("{$ROOT}/dashboard/src/components/LinkedExternalSystemsPanel.jsx");
$a('pluckPayloadValue covers _airtable_record_url',
                                              str_contains($panel, "'_airtable_record_url'"));
$a('renders external-url anchor when present', str_contains($panel, 'linked-systems-external-url-')
                                            && str_contains($panel, 'target="_blank"'));
$a('"Open in" label uses SOURCE_LABEL',        str_contains($panel, 'Open in {SOURCE_LABEL[mapping.source_system]'));
$a('airtable rec id field surfaced',           str_contains($panel, "['Airtable Rec',"));

// --- dashboard/src/pages/AirtableSettings.jsx — HealthPanel -------
echo "\nAirtableSettings.jsx — HealthPanel\n";
$set = $read("{$ROOT}/dashboard/src/pages/AirtableSettings.jsx");
$a('exports HealthPanel under connected branch',
                                              str_contains($set, '<HealthPanel reload={status.reload} />'));
$a('HealthPanel calls /api/airtable/health.php',
                                              str_contains($set, "useApi('/api/airtable/health.php?action=health')"));
$a('renders rollup tiles (records/linked/unmatched/ambiguous/mappings/fieldmaps)',
                                              str_contains($set, 'testid="airtable-health-tile-records"')
                                            && str_contains($set, 'testid="airtable-health-tile-linked"')
                                            && str_contains($set, 'testid="airtable-health-tile-unmatched"')
                                            && str_contains($set, 'testid="airtable-health-tile-ambiguous"')
                                            && str_contains($set, 'testid="airtable-health-tile-mappings"')
                                            && str_contains($set, 'testid="airtable-health-tile-fieldmaps"'));
$a('hints list with severity coloring',        str_contains($set, 'data-testid={`airtable-health-hint-${h.code}`}'));
$a('per-mapping detail table',                 str_contains($set, "data-testid=\"airtable-health-per-mapping-table\""));
$a('field-map coverage chips',                 str_contains($set, 'data-testid={`airtable-health-coverage-${c.entity_type}`}'));
$a('refresh button wired',                     str_contains($set, "data-testid=\"airtable-health-refresh\""));

// --- dashboard/src/pages/FieldMappingStudio.jsx — fallback ---------
echo "\nFieldMappingStudio.jsx — Airtable entity-type fallback\n";
$fms = $read("{$ROOT}/dashboard/src/pages/FieldMappingStudio.jsx");
$a('airtable fallback lists real entities (not just record)',
                                              str_contains($fms, "airtable:   ['placement', 'person', 'company', 'vendor', 'customer', 'contact', 'note', 'task', 'opportunity', 'generic']"));
$a('NO leftover ["record"] fallback for airtable',
                                              !preg_match('/airtable:\s+\[\s*\'record\'\s*\]/', $fms));
$a('airtable still listed in integrations dropdown',
                                              str_contains($fms, "'jobdiva', 'quickbooks', 'zoho_books', 'airtable'"));
$a('Empty-state link to airtable settings remains',
                                              str_contains($fms, 'fms-paths-empty-airtable-link'));

// --- Summary -------------------------------------------------------
echo "\n\n----------------------------------------\n";
echo "Slice 3 smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
