<?php
/**
 * Smoke for the in-place field-map editor inside LinkedExternalSystemsPanel.
 *
 * The user's complaint (2026-02): "we're supposed be able to see which
 * fields map and fix them. The JobDiva placement ID is still the placement
 * name. There's nowhere to set the mapping."
 *
 * Before this slice the panel only exposed:
 *   • A "Suggest mappings" AI modal
 *   • A "View raw payload" button
 * There was NO list of currently-configured (external_field → internal_field)
 * mappings and no way to manually add / edit one inline.
 *
 * This smoke asserts the on-page editor renders the necessary affordances:
 *   1. A current-mappings table keyed off
 *      /api/admin/integrations/field_map.php
 *   2. Inline edit (external_field text input + transform select)
 *   3. Inline delete with stable test ids
 *   4. "Add mapping" affordance that picks from unmapped internal fields
 *   5. Autocomplete via <datalist> sourced from top-level payload keys
 *   6. Row click anywhere expands the detail row (not just the chevron)
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$ROOT = realpath(__DIR__ . '/..');
$panel = (string) file_get_contents("{$ROOT}/dashboard/src/components/LinkedExternalSystemsPanel.jsx");

echo "FieldMapEditor — current-mappings list\n";
$assert('declares FieldMapEditor component',
    strpos($panel, 'function FieldMapEditor({ integration, entityType, payload })') !== false);
$assert('reads mappings from /api/admin/integrations/field_map.php (filtered by integration + entity_type)',
    strpos($panel, "`/api/admin/integrations/field_map.php?integration=\${encodeURIComponent(integration)}&entity_type=\${encodeURIComponent(entityType)}`") !== false);
$assert('renders root container with stable test id',
    strpos($panel, 'data-testid={`field-map-editor-${integration}-${entityType}`}') !== false);
$assert('renders the mappings table with stable test id',
    strpos($panel, 'data-testid="field-map-table"') !== false);
$assert('per-row test id is keyed off the internal_field (operator-friendly)',
    strpos($panel, 'data-testid={`field-map-row-${r.internal_field}`}') !== false);
$assert('shows source_path before legacy external_field as <code>',
    strpos($panel, "const sourcePath = r.source_path || r.external_field || '';") !== false
    && strpos($panel, 'data-testid={`field-map-external-${r.internal_field}`}>{sourcePath}') !== false);

echo "\nFieldMapEditor — inline edit\n";
$assert('Edit button per row',
    strpos($panel, 'data-testid={`field-map-edit-${r.internal_field}`}') !== false);
$assert('Inline external_field input on edit',
    strpos($panel, 'data-testid={`field-map-edit-external-${r.internal_field}`}') !== false);
$assert('Inline transform <select> on edit',
    strpos($panel, 'data-testid={`field-map-edit-transform-${r.internal_field}`}') !== false);
$assert('Save button POSTs to upsert endpoint',
    strpos($panel, "api.post('/api/admin/integrations/field_map.php'") !== false);
$assert('inline save preserves generalised source_path + target routing',
    strpos($panel, 'source_path:    sourcePath') !== false
    && strpos($panel, 'defaultFieldMapTarget(entityType, r.internal_field)') !== false
    && strpos($panel, 'target_table: r.target_table') !== false
    && strpos($panel, "linked_entity: r.linked_entity || 'self'") !== false);
$assert('Save button has stable test id',
    strpos($panel, 'data-testid={`field-map-save-${r.internal_field}`}') !== false);
$assert('Cancel exits edit mode without writing',
    strpos($panel, 'data-testid={`field-map-cancel-${r.internal_field}`}') !== false);

echo "\nFieldMapEditor — inline delete\n";
$assert('Delete button per row',
    strpos($panel, 'data-testid={`field-map-delete-${r.internal_field}`}') !== false);
$assert('Delete calls DELETE /api/admin/integrations/field_map.php?id=',
    strpos($panel, "api.delete(`/api/admin/integrations/field_map.php?id=\${r.id}`)") !== false);
$assert('Delete confirms before destructive write',
    strpos($panel, 'window.confirm(') !== false);

echo "\nFieldMapEditor — add new mapping\n";
$assert('"Add mapping" trigger',
    strpos($panel, 'data-testid="field-map-add"') !== false);
$assert('new-row container test id',
    strpos($panel, 'data-testid="field-map-row-new"') !== false);
$assert('new-row internal_field select drives from unmappedInternal',
    strpos($panel, 'data-testid="field-map-new-internal"') !== false
    && strpos($panel, 'unmappedInternal.map(f =>') !== false);
$assert('new-row external_field input',
    strpos($panel, 'data-testid="field-map-new-external"') !== false);
$assert('new-row transform select',
    strpos($panel, 'data-testid="field-map-new-transform"') !== false);
$assert('Save new mapping POSTs to upsert',
    strpos($panel, 'data-testid="field-map-new-save"') !== false);
$assert('new mapping writes generalised source_path + target routing',
    strpos($panel, 'defaultFieldMapTarget(entityType, newRow.internal_field)') !== false
    && strpos($panel, 'source_path:    sourcePath') !== false
    && strpos($panel, '...target') !== false);
$assert('default target routing includes staffing_job integration mappings',
    strpos($panel, "if (entityType === 'staffing_job')") !== false
    && strpos($panel, "target_table: 'staffing_jobs'") !== false
    && strpos($panel, "linked_entity: 'staffing_job'") !== false);
$assert('Cancel new mapping clears state',
    strpos($panel, 'data-testid="field-map-new-cancel"') !== false);
$assert('hides the "Add" button when every internal field is already mapped',
    strpos($panel, 'Every mappable internal field is already configured') !== false);

echo "\nFieldMapEditor — payload key autocomplete\n";
$assert('declares scalar path flattener for nested payloads',
    strpos($panel, 'function flattenPayloadScalarPaths(value') !== false
    && strpos($panel, 'flattenPayloadScalarPaths(v, next, out)') !== false);
$assert('builds payloadKeys from nested scalar paths',
    strpos($panel, 'flattenPayloadScalarPaths(payload)') !== false
    && strpos($panel, "path.startsWith('job.')") !== false);
$assert('merges indexed source paths from payload_fields endpoint',
    strpos($panel, '/api/admin/integrations/payload_fields.php?integration=') !== false
    && strpos($panel, 'fieldIndexPathOptions(indexedPathData)') !== false);
$assert('placement editor aliases staffing_job indexed paths as job.* options',
    strpos($panel, 'entity_type=staffing_job') !== false
    && strpos($panel, "fieldIndexPathOptions(staffingJobPathData, 'job')") !== false);
$assert('renders a shared <datalist> for autocomplete',
    strpos($panel, '<datalist id={`payloadkeys-${integration}-${entityType}`}>') !== false);
$assert('inline edit input wired to the same datalist',
    strpos($panel, 'list={`payloadkeys-${integration}-${entityType}`}') !== false);

echo "\nLinkedExternalSystemsPanel — discoverability fixes\n";
$assert('row is clickable anywhere (not just chevron)',
    strpos($panel, "onClick={() => setExpanded(s => ({ ...s, [m.id]: !s[m.id] }))}") !== false
    && strpos($panel, "cursor: 'pointer'") !== false);
$assert('chevron click stops propagation so it does not double-toggle',
    strpos($panel, 'e.stopPropagation(); setExpanded(') !== false);
$assert('panel help text mentions the mapping editor (not just raw payload)',
    strpos($panel, 'current field mappings') !== false);
$assert('FieldMapEditor is rendered inside the expanded DetailRow',
    strpos($panel, '<FieldMapEditor') !== false
    && strpos($panel, 'integration={mapping.source_system}') !== false
    && strpos($panel, 'entityType={selectedMappingEntity}') !== false);
$assert('JobDiva placement panel uses integration mapping entity buckets',
    strpos($panel, 'function integrationMappingEntityOptions(sourceSystem, entityType)') !== false
    && strpos($panel, "sourceSystem === 'jobdiva' && entityType === 'placement'") !== false
    && strpos($panel, "{ key: 'staffing_job', label: 'Job / Role' }") !== false
    && strpos($panel, 'data-testid={`field-map-entity-tab-${opt.key}`}') !== false);
$assert('Suggest mappings follows the selected integration mapping bucket',
    strpos($panel, 'const selectedMapping = { ...mapping, payload_snapshot: selectedMappingPayload };') !== false
    && strpos($panel, 'entityType={selectedMappingEntity}') !== false);

echo "\nServer side — verifies the admin field_map endpoint still wires correctly\n";
$api = (string) file_get_contents("{$ROOT}/api/admin/integrations/field_map.php");
$assert('GET returns rows + allowed_internal_fields + transforms',
    strpos($api, "'rows'                     => \$rows") !== false
    && strpos($api, "'allowed_internal_fields'  => \$allow") !== false
    && strpos($api, "'transforms'               => TENANT_INTEGRATION_FIELD_MAP_TRANSFORMS") !== false);
$assert('POST upserts via tenantIntegrationFieldMapUpsert',
    strpos($api, 'tenantIntegrationFieldMapUpsert($tid, $body, $user[\'id\'] ?? null)') !== false);
$assert('DELETE requires id query param',
    strpos($api, "(int) (api_query('id') ?? 0)") !== false
    && strpos($api, "tenantIntegrationFieldMapDelete") !== false);

echo "\nServer side — linked mapping payload enrichment\n";
$mappingsApi = (string) file_get_contents("{$ROOT}/api/integrations/mappings.php");
$assert('mappings endpoint loads JobDiva canonical graph helper',
    strpos($mappingsApi, "core/jobdiva/canonical_graph.php") !== false);
$assert('mappings endpoint resolves placement jobID to jobdiva_job mirror payload',
    strpos($mappingsApi, "_integrationMappingsJobDivaMirrorPayloadAny(\$tenantId, ['jobdiva_job', 'staffing_job'], \$jobId)") !== false
    && strpos($mappingsApi, "['job id', 'jobId', 'job_id', 'jobID', 'JOBID', 'reqId', 'req_id']") !== false);
$assert('mappings endpoint tolerates jd:-prefixed mirror ids',
    strpos($mappingsApi, "str_starts_with(\$externalId, 'jd:')") !== false
    && strpos($mappingsApi, "\$externalIds[] = 'jd:' . \$externalId") !== false);
$assert('mappings endpoint returns canonicalized placement payload',
    strpos($mappingsApi, 'jobdivaCanonicalPlacementPayload($payload)') !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
