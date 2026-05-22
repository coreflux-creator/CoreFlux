<?php
/**
 * Tenant Integration Field Map — admin CRUD (Slice 3 scaffolding).
 *
 *   GET    /api/admin/integrations/field_map.php
 *          [?integration=jobdiva][&entity_type=placement]
 *            → { rows: [...], allowed_internal_fields: {entity_type: [...]},
 *                transforms: [...] }
 *
 *   POST   /api/admin/integrations/field_map.php
 *          body: { integration, entity_type, external_field, internal_field,
 *                  transform?, enabled?, notes? }
 *            → { row: {...} }
 *
 *   DELETE /api/admin/integrations/field_map.php?id=42
 *            → { ok: true }
 *
 * RBAC: `integrations.field_map.manage` (master_admin + tenant_admin via
 * RBAC role bundles). Read uses the same permission so tenant operators
 * can't peek at the config; tightens info-disclosure surface.
 *
 * WIRING STATUS: scaffolding — the syncer doesn't consult this registry
 * yet. Slice 4 will wire it into jobdivaSyncUpsertPlacement /
 * jobdivaPlacementsAutoCreatePerson / etc.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';
require_once __DIR__ . '/../../core/integrations/field_map.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];
$method = api_method();

// `integrations.field_map.manage` is a new permission bundled into the
// master_admin and tenant_admin roles via the RBAC seed. Read access is
// gated the same way for now; broaden later if a "viewer" role wants
// read-only inspection.
rbac_legacy_require($user, 'integrations.field_map.manage');

switch ($method) {
    case 'GET': {
        $integration = (string) (api_query('integration') ?? '');
        $entityType  = (string) (api_query('entity_type') ?? '');
        $rows = tenantIntegrationFieldMapList($tid, $integration ?: null, $entityType ?: null);
        // Expose the allow-list per entity_type so the UI can render
        // a constrained dropdown for `internal_field` (prevents
        // operators from typing arbitrary column names).
        $allow = [];
        foreach (['placement', 'person', 'company', 'contact'] as $et) {
            $allow[$et] = tenantIntegrationFieldMapAllowedInternalFields($et);
        }
        api_ok([
            'rows'                     => $rows,
            'allowed_internal_fields'  => $allow,
            'transforms'               => TENANT_INTEGRATION_FIELD_MAP_TRANSFORMS,
        ]);
    }

    case 'POST': {
        $body = api_json_body();
        try {
            $row = tenantIntegrationFieldMapUpsert($tid, $body, $user['id'] ?? null);
        } catch (\InvalidArgumentException $e) {
            api_error($e->getMessage(), 422);
        }
        api_ok(['row' => $row]);
    }

    case 'DELETE': {
        $id = (int) (api_query('id') ?? 0);
        if ($id <= 0) api_error('id required', 400);
        $ok = tenantIntegrationFieldMapDelete($tid, $id, $user['id'] ?? null);
        api_ok(['ok' => $ok]);
    }
}

api_error('Method not allowed', 405);
