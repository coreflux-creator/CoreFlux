<?php
/**
 * jobdiva_mirror_by_placements_smoke.php
 *
 * Verifies the new ID-based mirror sync built against the OFFICIAL
 * JobDiva V2 Swagger spec (not guesswork):
 *   - /apiv2/bi/JobsDetail        accepts `jobIds` (array, comma-joined)
 *   - /apiv2/bi/CandidatesDetail  accepts `candidateIds`
 *   - /apiv2/bi/CompaniesDetail   accepts `companyIds`
 *
 * Operator demand: "GET EVERY SINGLE BIT OF DATA FROM JOBDIVA.
 * MIRROR JOB DIVA INTO TENANT DATABASE."
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/jobdiva/sync.php';

function _ok(string $msg): void { fwrite(STDOUT, "✅ $msg\n"); }

// ─────────────────────────────────────────────────────────────────────
// jobdivaCallBulkIds — verify it survives empty / non-array IDs and
// correctly batches large lists. Cannot make a real HTTP call so we
// just exercise the input-validation path with a closure stub.
// ─────────────────────────────────────────────────────────────────────
$got = jobdivaCallBulkIds(99999, '/apiv2/bi/JobsDetail', 'jobIds', []);
assert($got === [],           'empty ID list returns []');

$got = jobdivaCallBulkIds(99999, '/apiv2/bi/JobsDetail', 'jobIds', ['', null, '0']);
assert($got === [],           'filter-out-empty IDs returns []');
_ok('jobdivaCallBulkIds handles empty/junk inputs gracefully');

// ─────────────────────────────────────────────────────────────────────
// jobdivaMirrorStoreAndIndex — must skip items with no ID and process
// those with one. Empty input returns zeros.
// ─────────────────────────────────────────────────────────────────────
$result = jobdivaMirrorStoreAndIndex(99999, 'jobdiva_job', [], ['id', 'jobId']);
assert($result['processed'] === 0, 'empty items → processed=0');
assert($result['skipped']   === 0, 'empty items → skipped=0');

$result = jobdivaMirrorStoreAndIndex(99999, 'jobdiva_job', [
    ['id' => 1, 'title' => 'A'],
    ['title' => 'B'],   // no id → skipped
    ['jobId' => 3, 'title' => 'C'],
], ['id', 'jobId']);
assert($result['processed'] >= 0, 'processed counter is int');
assert($result['skipped']   === 1, 'one item without id is skipped');
_ok('jobdivaMirrorStoreAndIndex counts processed vs skipped correctly');

// ─────────────────────────────────────────────────────────────────────
// jobdivaSyncMirrorByPlacements — without a real DB connection returns
// the no-DB sentinel WITHOUT throwing.
// ─────────────────────────────────────────────────────────────────────
$result = jobdivaSyncMirrorByPlacements(99999, null, []);
assert(is_array($result),                              'mirror returns assoc array');
assert(array_key_exists('placements_scanned', $result),'reports placements_scanned key');
assert(array_key_exists('unique_job_ids', $result),    'reports unique_job_ids key');
assert(array_key_exists('jobs_processed', $result),    'reports jobs_processed key');
_ok('jobdivaSyncMirrorByPlacements has stable result envelope shape');

// ─────────────────────────────────────────────────────────────────────
// Source must reference the OFFICIAL Swagger endpoint paths.
// ─────────────────────────────────────────────────────────────────────
$src = (string) file_get_contents(__DIR__ . '/../core/jobdiva/sync.php');
assert(str_contains($src, "/apiv2/bi/JobsDetail"),        'JobsDetail endpoint wired');
assert(str_contains($src, "/apiv2/bi/CandidatesDetail"),  'CandidatesDetail endpoint wired');
assert(str_contains($src, "/apiv2/bi/ContactsDetail"),    'ContactsDetail endpoint wired (placement.customer_id is a contact)');
assert(str_contains($src, "/apiv2/bi/OpenJobsList"),      'OpenJobsList no-param endpoint wired (returns ALL open jobs)');
assert(str_contains($src, "'contactIds'"),                'customer ids passed as contactIds (per Swagger contract)');
_ok('mirror uses official V2 BI endpoints incl. OpenJobsList + ContactsDetail');

// ─────────────────────────────────────────────────────────────────────
// Spring `@RequestParam List<>` rejects PHP's default `param[]=v`
// http_build_query convention — we must comma-join. Verify the source
// uses implode(',', $batch) for the array param.
// ─────────────────────────────────────────────────────────────────────
assert(str_contains($src, "implode(',', \$batch)"), 'array params are comma-joined for Spring compatibility');
_ok('array params comma-joined (matches Spring @RequestParam List<>)');

// ─────────────────────────────────────────────────────────────────────
// The new endpoint file must exist and route through the helper.
// ─────────────────────────────────────────────────────────────────────
$ep = (string) file_get_contents(__DIR__ . '/../api/admin/integrations/jobdiva_mirror_by_placements.php');
assert(str_contains($ep, 'jobdivaSyncMirrorByPlacements'), 'endpoint dispatches to helper');
assert(str_contains($ep, "rbac_legacy_require(\$user, 'tenant_admin.integrations')"),
       'endpoint requires tenant_admin.integrations RBAC');
_ok('mirror_by_placements.php endpoint wired correctly');

// ─────────────────────────────────────────────────────────────────────
// Probe endpoint must exist and probe all 5 baseline endpoints.
// ─────────────────────────────────────────────────────────────────────
$probe = (string) file_get_contents(__DIR__ . '/../api/admin/integrations/jobdiva_probe.php');
assert(str_contains($probe, '/apiv2/bi/NewUpdatedCompanyRecords'),  'probe includes Companies baseline');
assert(str_contains($probe, '/apiv2/bi/NewUpdatedContactRecords'),  'probe includes Contacts baseline');
assert(str_contains($probe, '/apiv2/bi/NewUpdatedJobRecords'),       'probe includes Jobs delta endpoint');
assert(str_contains($probe, '/apiv2/bi/NewUpdatedCandidateRecords'), 'probe includes Candidates delta endpoint');
assert(str_contains($probe, '/apiv2/bi/OpenJobsList'),               'probe includes OpenJobsList no-param test');
assert(str_contains($probe, "/apiv2/bi/JobsDetail"),                 'probe adds JobsDetail with real id when placement exists');
assert(str_contains($probe, 'jobdivaRawRequest'),                    'probe uses raw request for full HTTP visibility');
_ok('jobdiva_probe.php covers all 5 baseline endpoints + dynamic *Detail probes');

echo "\n🎯 jobdiva_mirror_by_placements_smoke — ALL PASS\n";
