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
require_once __DIR__ . '/../../core/jobdiva/canonical_graph.php';

function _integrationMappingsJobDivaPluck(array $payload, array $candidates): string
{
    $norm = [];
    foreach ($payload as $k => $v) {
        if (!is_string($k) || (!is_scalar($v) && $v !== null)) continue;
        $nk = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $k));
        if ($nk !== '' && !array_key_exists($nk, $norm)) $norm[$nk] = $v;
    }
    foreach ($candidates as $candidate) {
        $nk = strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $candidate));
        if ($nk === '' || !array_key_exists($nk, $norm)) continue;
        $value = trim((string) $norm[$nk]);
        if ($value !== '') return $value;
    }
    return '';
}

function _integrationMappingsJobDivaMirrorPayload(int $tenantId, string $entityType, string $externalId): ?array
{
    if ($externalId === '') return null;
    $externalId = trim($externalId);
    $externalIds = [$externalId];
    if (str_starts_with($externalId, 'jd:')) {
        $externalIds[] = substr($externalId, 3);
    } else {
        $externalIds[] = 'jd:' . $externalId;
    }
    try {
        $pdo = getDB();
        foreach (array_values(array_unique(array_filter($externalIds))) as $eid) {
            $st = $pdo->prepare(
                "SELECT payload_snapshot
                   FROM external_entity_mappings
                  WHERE tenant_id = :t
                    AND source_system = 'jobdiva'
                    AND internal_entity_type = :et
                    AND external_id = :eid
                    AND payload_snapshot IS NOT NULL
                  LIMIT 1"
            );
            $st->execute(['t' => $tenantId, 'et' => $entityType, 'eid' => $eid]);
            $snap = $st->fetchColumn();
            if (!is_string($snap) || $snap === '') continue;
            $decoded = json_decode($snap, true);
            if (is_array($decoded)) return $decoded;
        }
        return null;
    } catch (\Throwable $e) {
        error_log('[mappings.php] JobDiva mirror lookup failed: ' . $e->getMessage());
        return null;
    }
}

function _integrationMappingsJobDivaMirrorPayloadAny(int $tenantId, array $entityTypes, string $externalId): ?array
{
    foreach ($entityTypes as $entityType) {
        $payload = _integrationMappingsJobDivaMirrorPayload($tenantId, (string) $entityType, $externalId);
        if (is_array($payload) && $payload !== []) return $payload;
    }
    return null;
}

function _integrationMappingsJobDivaCanonicalizeRowPayload(int $tenantId, array $row): array
{
    if (($row['source_system'] ?? '') !== 'jobdiva'
        || ($row['internal_entity_type'] ?? '') !== 'placement'
        || !isset($row['payload_snapshot'])
        || !is_array($row['payload_snapshot'])) {
        return $row;
    }
    $payload = $row['payload_snapshot'];
    $jobId = _integrationMappingsJobDivaPluck($payload, ['job id', 'jobId', 'job_id', 'jobID', 'JOBID', 'reqId', 'req_id']);
    if ($jobId !== '') {
        $mirror = _integrationMappingsJobDivaMirrorPayloadAny($tenantId, ['jobdiva_job', 'staffing_job'], $jobId);
        if (is_array($mirror) && $mirror !== []) {
            if (empty($payload['_jd_job']) || !is_array($payload['_jd_job'])) {
                $payload['_jd_job'] = $mirror;
            }
            if (empty($payload['job']) || !is_array($payload['job'])) {
                $payload['job'] = $mirror;
            }
        }
    }
    $row['payload_snapshot'] = jobdivaCanonicalPlacementPayload($payload);
    return $row;
}

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

if (api_method() !== 'GET') api_error('Method not allowed', 405);
// Sprint 8a originally required `integrations.jobdiva.view`; we now have
// QBO + Airtable + Zoho all surfacing into this read-only mapping
// drawer, so the gate is relaxed to "any integrations.*.view".
// master_admin's `*` continues to satisfy this.
rbac_legacy_require_any($user, [
    'integrations.jobdiva.view',
    'integrations.airtable.view',
    'integrations.qbo.view',
    'integrations.zoho.view',
]);

$action = strtolower(str_replace('-', '_', (string) (api_query('action') ?? 'list_for_internal')));

switch ($action) {
    case 'list_for_internal': {
        $entityType = trim((string) (api_query('entity_type') ?? ''));
        $internalId = (int) (api_query('internal_id') ?? 0);
        if ($entityType === '') api_error('entity_type required', 422);
        if ($internalId <= 0)   api_error('internal_id required', 422);
        $rows = mappingListForInternal($tid, $entityType, $internalId);
        // Coerce numeric ids for the SPA.
        $rows = array_map(static function ($r) use ($entityType) {
            $r['id'] = (int) $r['id'];
            $r['internal_entity_type'] = $r['internal_entity_type'] ?? $entityType;
            return $r;
        }, $rows);
        $rows = array_map(static fn($r) => _integrationMappingsJobDivaCanonicalizeRowPayload($tid, $r), $rows);
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
