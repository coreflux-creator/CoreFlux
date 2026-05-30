<?php
/**
 * /api/admin/integrations/jobdiva_mirror_by_placements.php
 *
 * Triggers the operator-demanded "MIRROR JOB DIVA INTO TENANT DATABASE"
 * sync via the V2 BI `*Detail` endpoints (`/JobsDetail`,
 * `/CandidatesDetail`, `/CompaniesDetail`) using IDs extracted from
 * already-synced placement payloads.
 *
 * Returns:
 *   {
 *     ok: true,
 *     placements_scanned, unique_job_ids, unique_candidate_ids, unique_customer_ids,
 *     jobs_returned, candidates_returned, customers_returned,
 *     jobs_processed, candidates_processed, customers_processed
 *   }
 *
 * RBAC: tenant_admin.integrations.
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

try {
    $out = jobdivaSyncMirrorByPlacements($tid, (int) ($user['id'] ?? 0), []);
} catch (\Throwable $e) {
    api_error('Mirror sync failed: ' . $e->getMessage(), 500);
}
api_ok(['ok' => true] + $out);
