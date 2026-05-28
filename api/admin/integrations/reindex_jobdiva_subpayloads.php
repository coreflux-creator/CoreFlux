<?php
/**
 * /api/admin/integrations/reindex_jobdiva_subpayloads.php
 *
 * One-shot backfill endpoint that walks every existing placement
 * `payload_snapshot` already stored in external_entity_mappings for
 * the current tenant, extracts joined sub-records (Person / Job /
 * Customer / Contact / Assignment) via jobdivaExtractJoinedSubPayloads,
 * and indexes them under their own entity_type via
 * integrationPayloadFieldIndexRecord.
 *
 * Why this endpoint exists: prior placement syncs (before the
 * joined-entity indexing side-effect was added) stored full payloads
 * but only indexed them under entity_type=placement. Without this
 * backfill, the Field Mapping Studio's entity dropdown shows only
 * `placement (N)` even though the operator has hundreds of placements
 * each carrying flat `candidate_*`, `job_*`, `customer_*` fields that
 * SHOULD be mappable per-entity.
 *
 * POST /api/admin/integrations/reindex_jobdiva_subpayloads.php
 *   → 200 { ok: true,
 *           placements_walked: N,
 *           sub_records_indexed: { person, job, jobdiva_customer, contact, assignment }
 *         }
 *
 * RBAC: tenant_admin.integrations (same gate as other field-map admin).
 * Idempotent: re-running just bumps occurrence_count on existing paths.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/jobdiva/sync.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];
if (api_method() !== 'POST') api_error('Method not allowed', 405);
rbac_legacy_require($user, 'tenant_admin.integrations');

$summary = jobdivaBackfillJoinedIndexes($tid);
api_ok([
    'ok'                   => true,
    'placements_walked'    => (int) ($summary['placements_walked'] ?? 0),
    'sub_records_indexed'  => $summary['sub_records_indexed'] ?? [],
    'enrichment_ran_for'   => (int) ($summary['enrichment_ran_for'] ?? 0),
    'enrichment_errors'    => $summary['enrichment_errors'] ?? [],
    'endpoint_diagnostics' => $summary['endpoint_diagnostics'] ?? [],
]);
