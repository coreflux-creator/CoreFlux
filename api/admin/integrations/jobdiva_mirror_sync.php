<?php
/**
 * /api/admin/integrations/jobdiva_mirror_sync.php
 *
 * Triggers a full mirror sync of JobDiva Jobs + Candidates (via the
 * `/apiv2/bi/NewUpdatedJobRecords` and `/apiv2/bi/NewUpdatedCandidateRecords`
 * bulk endpoints). Stores every returned record's full payload in
 * `external_entity_mappings` and indexes every field in
 * `payload_field_index` so the Field Mapping Studio's source-side
 * picker rolls them into canonical `placement` / `person` paths while
 * the native mirror rows remain available for diagnostics.
 *
 * Why this exists: JobDiva's per-record `/searchJob` and `/searchCandidate`
 * endpoints return EMPTY for many tenants (account-scope auth on those
 * endpoints), but the BI bulk endpoints reliably return full records.
 * Operator ask: "MIRROR JOB DIVA INTO TENANT DATABASE IF THAT'S WHAT
 * YOU NEED TO DO."
 *
 * POST /api/admin/integrations/jobdiva_mirror_sync.php
 *      [?days=365]      // override the lookback window
 *      [?entity=jobs|candidates|both]
 *
 * Returns:
 *   {
 *     ok: true,
 *     jobs:       { processed, skipped, failed, errors },
 *     candidates: { processed, skipped, failed, errors }
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

$entity = trim((string) ($_GET['entity'] ?? 'both'));
$days   = (int) ($_GET['days'] ?? 365);
if ($days < 1)    $days = 1;
if ($days > 3650) $days = 3650;

$opts = ['default_window_days' => $days];

$out = ['ok' => true];
try {
    if ($entity === 'jobs' || $entity === 'both') {
        $out['jobs'] = jobdivaSyncJobs($tid, (int) ($user['id'] ?? 0), $opts);
    }
    if ($entity === 'candidates' || $entity === 'both') {
        $out['candidates'] = jobdivaSyncCandidates($tid, (int) ($user['id'] ?? 0), $opts);
    }
} catch (\Throwable $e) {
    api_error('Mirror sync failed: ' . $e->getMessage(), 500);
}
api_ok($out);
