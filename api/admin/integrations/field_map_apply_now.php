<?php
/**
 * Apply tenant integration field mappings to one already-linked CoreFlux row.
 *
 * The field-map registry is tenant-wide configuration, but the linked-system
 * editor needs an operational path: when an operator saves a mapping on a
 * placement, the currently open placement should be hydrated immediately.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/integrations/entity_mappings.php';
require_once __DIR__ . '/../../../core/integrations/field_map_apply.php';

function _fieldMapApplyNowPayload(array $mapping): array
{
    $snap = $mapping['payload_snapshot'] ?? null;
    if (is_array($snap)) return $snap;
    if (is_string($snap) && trim($snap) !== '') {
        $decoded = json_decode($snap, true);
        if (is_array($decoded)) return $decoded;
    }
    return [];
}

function _fieldMapApplyNowPlacementContext(int $tenantId, int $placementId): array
{
    if ($tenantId <= 0 || $placementId <= 0) return [];
    try {
        $st = getDB()->prepare(
            'SELECT person_id, end_client_company_id
               FROM placements
              WHERE tenant_id = :t AND id = :id
              LIMIT 1'
        );
        $st->execute(['t' => $tenantId, 'id' => $placementId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [
            'person_id' => (int) ($row['person_id'] ?? 0),
            'end_client_company_id' => (int) ($row['end_client_company_id'] ?? 0),
        ];
    } catch (\Throwable $e) {
        error_log('[field_map_apply_now] placement context lookup failed: ' . $e->getMessage());
        return [];
    }
}

$ctx = api_require_auth();
$user = $ctx['user'];
$tid = (int) $ctx['tenant_id'];

if (api_method() !== 'POST') api_error('Method not allowed', 405);

rbac_legacy_require_any($user, [
    'integrations.field_map.manage',
    'tenant_admin.integrations',
    'integrations.jobdiva.manage',
]);

$body = api_json_body();
$integration = strtolower(trim((string) ($body['integration'] ?? '')));
$entityType = trim((string) ($body['entity_type'] ?? ''));
$rootEntityType = strtolower(trim((string) ($body['root_entity_type'] ?? $entityType)));
$rootEntityType = $rootEntityType === 'placements' ? 'placement' : $rootEntityType;
$rootInternalId = (int) ($body['root_internal_id'] ?? 0);
$userId = isset($user['id']) ? (int) $user['id'] : null;

if ($integration === '') api_error('integration required', 422);
if ($entityType === '') api_error('entity_type required', 422);
if ($rootEntityType === '') api_error('root_entity_type required', 422);
if ($rootInternalId <= 0) api_error('root_internal_id required', 422);

try {
    if ($integration === 'jobdiva' && $rootEntityType === 'placement') {
        require_once __DIR__ . '/../../../core/jobdiva/sync.php';

        $mapping = mappingFindExternal($tid, 'jobdiva', 'placement', $rootInternalId);
        if (!$mapping) {
            api_error('No JobDiva placement binding exists for this CoreFlux placement.', 404);
        }
        $payload = _fieldMapApplyNowPayload($mapping);
        if ($payload === []) {
            api_error('The JobDiva placement binding has no payload snapshot to apply.', 422);
        }

        $externalId = trim((string) ($mapping['external_id'] ?? ''));
        if (str_starts_with($externalId, 'jd:')) $externalId = substr($externalId, 3);

        // Apply-now is an operational refresh, not a replay of a potentially
        // shallow/stale mapping snapshot. Pull the exact Start, assignment
        // contract, and related JobDiva facets before projecting CoreFlux.
        $enrichmentDiagnostics = [];
        $enriched = jobdivaSyncEnrichRelatedEntities(
            $tid,
            [$payload],
            $userId,
            ['enrich_start' => 1],
            $enrichmentDiagnostics
        );
        if (isset($enriched[0]) && is_array($enriched[0])) {
            $payload = $enriched[0];
        }
        if (empty($enrichmentDiagnostics['financial']['succeeded'])
            || empty($payload['_jd_contract'])
            || !is_array($payload['_jd_contract'])) {
            api_error(
                'The authoritative JobDiva assignment contract could not be loaded; no CoreFlux fields were changed.',
                422,
                ['enrichment' => $enrichmentDiagnostics]
            );
        }

        $placementContext = _fieldMapApplyNowPlacementContext($tid, $rootInternalId);
        $projectionOpts = [
            'external_id' => $externalId,
            'existing_placement_id' => $rootInternalId,
            'force_projection' => true,
        ];
        if (!empty($placementContext['person_id'])) {
            $projectionOpts['person_id'] = (int) $placementContext['person_id'];
        }
        if (!empty($placementContext['end_client_company_id'])) {
            $projectionOpts['end_client_company_id'] = (int) $placementContext['end_client_company_id'];
        }

        $projection = jobdivaProjectorProjectPlacement($tid, $payload, $userId, $projectionOpts);
        if (empty($projection['projected'])) {
            api_error('JobDiva projection failed.', 422, ['projection' => $projection]);
        }

        api_ok([
            'ok' => true,
            'mode' => 'jobdiva_placement_projection',
            'root_entity_type' => $rootEntityType,
            'root_internal_id' => $rootInternalId,
            'enrichment' => $enrichmentDiagnostics,
            'projection' => $projection,
        ]);
    }

    $mapping = mappingFindExternal($tid, $integration, $entityType, $rootInternalId);
    if (!$mapping) {
        api_error('No external binding exists for this CoreFlux record.', 404);
    }
    $payload = _fieldMapApplyNowPayload($mapping);
    if ($payload === []) {
        api_error('The external binding has no payload snapshot to apply.', 422);
    }

    $apply = integrationFieldMapApplyAll($tid, $integration, $entityType, $payload, [
        'self' => $rootInternalId,
        $entityType => $rootInternalId,
        $rootEntityType => $rootInternalId,
    ]);

    api_ok([
        'ok' => true,
        'mode' => 'direct_field_map_apply',
        'root_entity_type' => $rootEntityType,
        'root_internal_id' => $rootInternalId,
        'apply' => $apply,
    ]);
} catch (\Throwable $e) {
    error_log('[field_map_apply_now] ' . get_class($e) . ': ' . $e->getMessage());
    api_error('Server error: ' . $e->getMessage(), 500, ['class' => get_class($e)]);
}
