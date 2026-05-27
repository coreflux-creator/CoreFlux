<?php
/**
 * /api/admin/integrations/payload_fields.php — discovery surface
 * that drives the Field Mapping UI's source-side picker.
 *
 * GET /api/admin/integrations/payload_fields.php
 *   → { sources: [
 *         { integration, entity_type, path_count, last_seen_at }
 *     ] }
 *
 * GET /api/admin/integrations/payload_fields.php
 *      ?integration=jobdiva&entity_type=placement[&limit=500]
 *   → { paths: [
 *         { source_path, value_type, sample_value,
 *           occurrence_count, first_seen_at, last_seen_at }
 *     ] }
 *
 * Phase 1 of the generalised field-mapping rebuild. Backs the
 * left-pane of the Field Mapping page — operator picks a JSON path
 * from this list, then picks a (target_module, target_table,
 * target_column) on the right, then saves a mapping row in
 * tenant_integration_field_map (Phase 2).
 *
 * RBAC: tenant_admin.integrations (same gate as the existing
 * field-map admin API).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/integrations/payload_field_index.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];
if (api_method() !== 'GET') api_error('Method not allowed', 405);
rbac_legacy_require($user, 'tenant_admin.integrations');

$integration = trim((string) ($_GET['integration'] ?? ''));
$entityType  = trim((string) ($_GET['entity_type'] ?? ''));
$limit       = (int) ($_GET['limit'] ?? 500);

if ($integration === '' || $entityType === '') {
    // Source-discovery mode: which (integration, entity_type) tuples
    // does this tenant have payloads for?
    api_ok([
        'sources' => integrationPayloadFieldIndexSources($tid),
    ]);
}

// Path-listing mode: drive the picker tree.
api_ok([
    'integration' => $integration,
    'entity_type' => $entityType,
    'paths'       => integrationPayloadFieldIndexList($tid, $integration, $entityType, $limit),
]);
