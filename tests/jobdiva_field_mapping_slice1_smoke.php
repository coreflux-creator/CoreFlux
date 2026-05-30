<?php
/**
 * JobDiva Slice 1 — Title from real Job Title + Connected Sources panel
 * + Slice 3 scaffolding — Tenant Integration Field Map registry.
 *
 * Slice 1 fixes the regression where placement.title was set to the
 * synthetic "JobDiva Placement <extId>" instead of the actual Job Title.
 * It also extends mappingListForInternal() to return payload_snapshot so
 * the LinkedExternalSystemsPanel can surface per-source identifiers.
 *
 * Slice 3 is scaffolding for the tenant-level integration field map
 * (migration + lib + admin API + UI page). The syncer doesn't read this
 * registry yet — that's Slice 4 (next session). Lock in the surface so
 * the schema and contract can't drift before then.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$lint = function (string $p): bool {
    $o = []; $rc = 0; @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
    return $rc === 0;
};
$ROOT = realpath(__DIR__ . '/..');

echo "Slice 1 — Title resolution prefers real JobDiva Job Title\n";
$syncPath = "{$ROOT}/core/jobdiva/sync.php";
$syncSrc  = (string) file_get_contents($syncPath);
$assert('top-level pluck tries jobTitle/positionTitle/role',
    strpos($syncSrc, "'jobTitle', 'job_title', 'job title', 'title',") !== false
    && strpos($syncSrc, "'positionTitle', 'position_title', 'role', 'roleName',") !== false);
$assert('nested job.* envelopes probed (V2 searchStart often nests title)',
    // The nest walk now lives inside jobdivaPluckFieldDeep() — its
    // $nestOrder default includes `_jd_job` + legacy `job`/`Job`/etc.
    strpos($syncSrc, "'_jd_candidate', '_jd_job', '_jd_customer', '_jd_contact', '_jd_start',") !== false
    && strpos($syncSrc, "'job', 'Job', 'jobInfo', 'jobObj', 'jobRecord',") !== false);
$assert('nested pluck reuses jobdivaPluckField under the hood',
    // jobdivaPluckFieldDeep iterates nests and calls jobdivaPluckField on each.
    strpos($syncSrc, '$v = jobdivaPluckField($item[$nest], $candidates);') !== false);
$assert('placeholder only when JobDiva genuinely sent no title',
    strpos($syncSrc, "// Last-resort placeholder. Kept distinct from the JobDiva ID") !== false
    && strpos($syncSrc, "if (\$title === '') \$title = 'JobDiva Placement ' . \$extId;") !== false);

echo "\nSlice 1 — mappingListForInternal returns payload_snapshot\n";
$emPath = "{$ROOT}/core/integrations/entity_mappings.php";
$emSrc  = (string) file_get_contents($emPath);
$assert('parses',                                $lint($emPath));
$assert('SELECT now includes payload_snapshot',
    strpos($emSrc, 'last_synced_at, last_seen_at, payload_snapshot') !== false);
$assert('decodes JSON payload before returning (no double-parse on client)',
    strpos($emSrc, '$decoded = json_decode($r[\'payload_snapshot\'], true);') !== false);
$assert('non-array decoded payload normalised to null',
    strpos($emSrc, "\$r['payload_snapshot'] = is_array(\$decoded) ? \$decoded : null;") !== false);

echo "\nSlice 1 — LinkedExternalSystemsPanel surfaces source-side identifiers\n";
$panelPath = "{$ROOT}/dashboard/src/components/LinkedExternalSystemsPanel.jsx";
$panelSrc  = (string) file_get_contents($panelPath);
$assert('declares SOURCE_ID_FIELDS per (source, entity_type)',
    strpos($panelSrc, 'const SOURCE_ID_FIELDS = {') !== false
    && strpos($panelSrc, '  jobdiva: {') !== false);
$assert('placement curated row includes JobDiva Job #',
    strpos($panelSrc, "['JobDiva Job #',   ['jobNumber', 'job_number', 'jobId',") !== false);
$assert('placement curated row includes Job Title',
    strpos($panelSrc, "['Job Title',       ['jobTitle', 'job_title', 'title', 'positionTitle']],") !== false);
$assert('placement curated row includes Candidate ID',
    strpos($panelSrc, "['Candidate ID',    ['candidateId', 'candidate_id', 'employeeId', 'employee_id']],") !== false);
$assert('placement curated row includes Bill Rate + Pay Rate',
    strpos($panelSrc, "['Bill Rate',       ['billRate'") !== false
    && strpos($panelSrc, "['Pay Rate',        ['payRate'") !== false);
$assert('person curated row exposed',           strpos($panelSrc, "    person: [") !== false);
$assert('company curated row exposed',          strpos($panelSrc, "    company: [") !== false);
$assert('contact curated row exposed',          strpos($panelSrc, "    contact: [") !== false);
$assert('JS pluck mirrors backend normalisation (case + separator insensitive)',
    strpos($panelSrc, "String(k).toLowerCase().replace(/[^a-z0-9]/g, '')") !== false);
$assert('per-source expand toggle has stable test id',
    strpos($panelSrc, 'data-testid={`linked-systems-expand-${m.source_system}`}') !== false);
$assert('expanded row renders DetailRow component',
    strpos($panelSrc, '{isOpen && <DetailRow') !== false);
$assert('curated field row test ids include source + field slug',
    strpos($panelSrc, 'data-testid={`linked-systems-field-${mapping.source_system}-${slug}`}') !== false);
$assert('raw-payload viewer collapsible (chevron toggle)',
    strpos($panelSrc, 'linked-systems-raw-toggle-') !== false
    && strpos($panelSrc, 'JSON.stringify(payload, null, 2)') !== false);

echo "\nSlice 1 — PlacementDetail wires LinkedExternalSystemsPanel\n";
$pdPath = "{$ROOT}/modules/placements/ui/PlacementDetail.jsx";
$pd     = (string) file_get_contents($pdPath);
$assert('imports panel component',
    strpos($pd, "import LinkedExternalSystemsPanel from '../../../dashboard/src/components/LinkedExternalSystemsPanel'") !== false);
$assert('renders panel for placement entity',
    strpos($pd, '<LinkedExternalSystemsPanel entityType="placement" internalId={placement.id} />') !== false);

echo "\nSlice 3 — Migration 068 (tenant_integration_field_map)\n";
$migPath = "{$ROOT}/core/migrations/068_tenant_integration_field_map.sql";
$mig     = (string) file_get_contents($migPath);
$assert('migration file exists',                 strlen($mig) > 0);
$assert('creates tenant_integration_field_map',  strpos($mig, 'CREATE TABLE IF NOT EXISTS tenant_integration_field_map') !== false);
$assert('composite unique constraint (tenant,integration,entity_type,internal_field)',
    strpos($mig, 'UNIQUE KEY uq_tenant_integration_entity_internal (tenant_id, integration, entity_type, internal_field)') !== false);
$assert('transform column with default "none"',
    strpos($mig, "transform       VARCHAR(32)  NOT NULL DEFAULT 'none'") !== false);
$assert('enabled defaults to 1',
    strpos($mig, 'enabled         BOOLEAN      NOT NULL DEFAULT 1') !== false);
$assert('audit field updated_by_user_id present',
    strpos($mig, 'updated_by_user_id BIGINT UNSIGNED NULL') !== false);

echo "\nSlice 3 — core/integrations/field_map.php lib\n";
$libPath = "{$ROOT}/core/integrations/field_map.php";
$libSrc  = (string) file_get_contents($libPath);
$assert('parses',                                $lint($libPath));
$assert('exports tenantIntegrationFieldMapList',
    strpos($libSrc, 'function tenantIntegrationFieldMapList(') !== false);
$assert('exports tenantIntegrationFieldMapUpsert',
    strpos($libSrc, 'function tenantIntegrationFieldMapUpsert(') !== false);
$assert('exports tenantIntegrationFieldMapDelete',
    strpos($libSrc, 'function tenantIntegrationFieldMapDelete(') !== false);
$assert('exports tenantIntegrationFieldMapAllowedInternalFields',
    strpos($libSrc, 'function tenantIntegrationFieldMapAllowedInternalFields(') !== false);
$assert('placement allow-list includes title (the actual user request)',
    preg_match("/'placement'\s*=>\s*\[[\s\S]*?'title'/", $libSrc) === 1);
$assert('placement allow-list expanded to cover external_id + lifecycle dates + approval toggles',
    strpos($libSrc, "'external_id'") !== false
    && strpos($libSrc, "'actual_end_date'") !== false
    && strpos($libSrc, "'due_date'") !== false
    && strpos($libSrc, "'tokenized_email_approval_enabled'") !== false);
$assert('person allow-list expanded to cover middle_name + home address + work auth + source',
    strpos($libSrc, "'middle_name'") !== false
    && strpos($libSrc, "'home_address_line1'") !== false
    && strpos($libSrc, "'requires_sponsorship'") !== false
    && strpos($libSrc, "'source'") !== false);
$assert('person allow-list excludes tenant_id and id (info-disclosure guard)',
    strpos($libSrc, "'tenant_id'") === false
    && strpos($libSrc, "'created_by_user_id'") === false);
$assert('Upsert validates internal_field against allow-list',
    strpos($libSrc, "if (!in_array(\$internalField, \$allowed, true)) {") !== false);
$assert('Upsert validates transform against const list',
    strpos($libSrc, 'TENANT_INTEGRATION_FIELD_MAP_TRANSFORMS') !== false);
$assert('Upsert is idempotent (ON DUPLICATE KEY UPDATE)',
    strpos($libSrc, 'ON DUPLICATE KEY UPDATE') !== false);
$assert('Delete is tenant-scoped',
    strpos($libSrc, 'WHERE id = :id AND tenant_id = :t') !== false);
$assert('transform list includes date_normalise (common JobDiva need)',
    strpos($libSrc, "'date_normalise'") !== false);

echo "\nSlice 3 — admin API /api/admin/integrations/field_map.php\n";
$apiPath = "{$ROOT}/api/admin/integrations/field_map.php";
$apiSrc  = (string) file_get_contents($apiPath);
$assert('parses',                                $lint($apiPath));
$assert('requires integrations.field_map.manage RBAC',
    strpos($apiSrc, "rbac_legacy_require_any(\$user, ['integrations.field_map.manage', 'tenant_admin.integrations'])") !== false);
$assert('GET returns rows + allow-list + transforms',
    strpos($apiSrc, "'allowed_internal_fields'  => \$allow,") !== false
    && strpos($apiSrc, "'transforms'               => TENANT_INTEGRATION_FIELD_MAP_TRANSFORMS,") !== false);
$assert('POST routes through Upsert and returns the canonical row',
    strpos($apiSrc, 'tenantIntegrationFieldMapUpsert(') !== false
    && strpos($apiSrc, "api_ok(['row' => \$row])") !== false);
$assert('DELETE requires id query param',
    strpos($apiSrc, "if (\$id <= 0) api_error('id required', 400);") !== false);
$assert('422 on validation failure (not 500)',
    strpos($apiSrc, "api_error(\$e->getMessage(), 422);") !== false);

echo "\nSlice 3 — RBAC legacy_map permission entries\n";
$rbacPath = "{$ROOT}/core/rbac/legacy_map.php";
$rbac     = (string) file_get_contents($rbacPath);
$assert("registers 'integrations.field_map.manage' as integrations:admin",
    strpos($rbac, "'integrations.field_map.manage'      => ['integrations', 'admin']") !== false);
$assert("registers 'integrations.field_map.view' as integrations:admin",
    strpos($rbac, "'integrations.field_map.view'        => ['integrations', 'admin']") !== false);

echo "\nSlice 3 — admin UI scaffolding\n";
$uiPath = "{$ROOT}/dashboard/src/pages/IntegrationFieldMapAdmin.jsx";
$ui     = (string) file_get_contents($uiPath);
$assert('component file exists',                 strlen($ui) > 0);
$assert('default exports IntegrationFieldMapAdmin',
    strpos($ui, 'export default function IntegrationFieldMapAdmin') !== false);
$assert('renders live banner (Slice 4 — syncer now consults the registry)',
    strpos($ui, 'Live.') !== false
    && strpos($ui, 'data-testid="field-map-status-banner"') !== false
    && strpos($ui, 'The next sync will use these mappings.') !== false);
$assert('scope dropdown carries integration + entity selects',
    strpos($ui, 'data-testid="field-map-integration-select"') !== false
    && strpos($ui, 'data-testid="field-map-entity-select"') !== false);
$assert('add-form constrains internal_field to allow-list',
    strpos($ui, 'data-testid="field-map-internal-select"') !== false
    && strpos($ui, '{allowedInternal.map(') !== false);
$assert('transform select uses server-provided list',
    strpos($ui, '{transforms.map(') !== false);
$assert('row delete confirms before destructive action',
    strpos($ui, "window.confirm('Remove this mapping?") !== false);
$assert('AdminModule wires /admin/integrations/field-map route',
    strpos((string) file_get_contents("{$ROOT}/dashboard/src/pages/AdminModule.jsx"), '/integrations/field-map') !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
