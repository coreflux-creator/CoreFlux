<?php
/**
 * Integration entity-mappings — read-only.
 * Sprint 8a / Slice A2 follow-on.
 *
 * Routes:
 *   GET /api/integrations/mappings.php?action=list_for_internal
 *       &entity_type=company&internal_id=42
 *       → every external system's mapping for one CoreFlux record.
 *
 *   GET /api/integrations/mappings.php?action=find_internal
 *       &source_system=jobdiva&entity_type=company&external_id=JD-12345
 *       → reverse: which CoreFlux record does this external id point to?
 *
 *   GET /api/integrations/mappings.php?action=find_external
 *       &source_system=jobdiva&entity_type=company&internal_id=42
 *       → which external id does this source have for this CoreFlux record?
 *
 * RBAC: any of `integrations.*.view` is sufficient. master_admin's `*` covers it.
 * For Sprint 8a we require `integrations.jobdiva.view` (the only source today);
 * once Bullhorn etc. ship, this widens to a permission check per source_system.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';
require_once __DIR__ . '/../../core/integrations/entity_mappings.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

if (api_method() !== 'GET') api_error('Method not allowed', 405);
rbac_legacy_require($user, 'integrations.jobdiva.view');

$action = strtolower(str_replace('-', '_', (string) (api_query('action') ?? 'list_for_internal')));

switch ($action) {
    case 'list_for_internal': {
        $entityType = trim((string) (api_query('entity_type') ?? ''));
        $internalId = (int) (api_query('internal_id') ?? 0);
        if ($entityType === '') api_error('entity_type required', 422);
        if ($internalId <= 0)   api_error('internal_id required', 422);
        $rows = mappingListForInternal($tid, $entityType, $internalId);
        // Coerce numeric ids for the SPA.
        $rows = array_map(static function ($r) {
            $r['id'] = (int) $r['id'];
            return $r;
        }, $rows);
        api_ok([
            'entity_type' => $entityType,
            'internal_id' => $internalId,
            'mappings'    => $rows,
        ]);
    }

    case 'find_internal': {
        $source     = trim((string) (api_query('source_system') ?? ''));
        $entityType = trim((string) (api_query('entity_type')   ?? ''));
        $externalId = trim((string) (api_query('external_id')   ?? ''));
        if ($source === '')     api_error('source_system required', 422);
        if ($entityType === '') api_error('entity_type required', 422);
        if ($externalId === '') api_error('external_id required', 422);
        $row = mappingFindInternal($tid, $source, $entityType, $externalId);
        api_ok(['mapping' => $row]);
    }

    case 'find_external': {
        $source     = trim((string) (api_query('source_system') ?? ''));
        $entityType = trim((string) (api_query('entity_type')   ?? ''));
        $internalId = (int) (api_query('internal_id') ?? 0);
        if ($source === '')     api_error('source_system required', 422);
        if ($entityType === '') api_error('entity_type required', 422);
        if ($internalId <= 0)   api_error('internal_id required', 422);
        $row = mappingFindExternal($tid, $source, $entityType, $internalId);
        api_ok(['mapping' => $row]);
    }
}

api_error('Unknown action: ' . $action, 400);
