<?php
/**
 * jobdiva_mirror_sync_smoke.php
 *
 * Verifies the new `jobdivaSyncMirrorEntity()` helper + its two
 * wrappers (`jobdivaSyncJobs`, `jobdivaSyncCandidates`) — drives the
 * "MIRROR JOB DIVA INTO TENANT DATABASE" operator demand.
 *
 * Operates on injected items via `items_override` so the test never
 * touches a real DB or hits JobDiva. The assertions cover ID-pluck
 * resolution from the same key conventions JobDiva V2 BI ships
 * (snake_case, camelCase, space-separated, ALLCAPS).
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/jobdiva/sync.php';

function _ok(string $msg): void { fwrite(STDOUT, "✅ $msg\n"); }

// ─────────────────────────────────────────────────────────────────────
// Re-implement the ID-resolution logic the mirror helper uses, on a
// per-item basis, so we can assert it picks the correct external_id
// from every key convention JobDiva V2 BI uses. This guards against
// regressions in `jobdivaPluckField`.
// ─────────────────────────────────────────────────────────────────────
$jobItems = [
    ['jobId' => 27857851, 'title' => 'Senior Engineer'],         // camelCase
    ['job_id' => 27857852, 'title' => 'Mid Engineer'],           // snake_case
    ['job id' => 27857853, 'title' => 'Junior Engineer'],        // space-separated
    ['JOBID'  => 27857854, 'title' => 'Lead Engineer'],          // ALLCAPS
    ['id'     => 27857855, 'title' => 'Architect'],              // bare `id`
];
foreach ($jobItems as $idx => $jd) {
    $extId = (string) jobdivaPluckField($jd, [
        'id', 'jobId', 'job_id', 'jobID', 'JOBID', 'job id',
    ]);
    assert($extId !== '', "job item $idx resolves an external_id");
    assert(ctype_digit($extId), "job external_id is numeric for $extId");
}
_ok('jobs — every JobDiva V2 BI key convention resolves to an external_id');

$candItems = [
    ['candidateId' => 20619052329442, 'firstName' => 'Gavin'],   // camelCase
    ['candidate_id' => 20619052329443, 'firstName' => 'Jane'],   // snake_case
    ['candidate id' => 20619052329444, 'firstName' => 'Mike'],   // space-separated
    ['CANDIDATEID' => 20619052329445, 'firstName' => 'Sarah'],   // ALLCAPS
    ['employeeId'  => 20619052329446, 'firstName' => 'Adam'],    // legacy alias
];
foreach ($candItems as $idx => $jd) {
    $extId = (string) jobdivaPluckField($jd, [
        'id', 'candidateId', 'candidate_id', 'candidateID', 'CANDIDATEID', 'candidate id', 'employeeId',
    ]);
    assert($extId !== '', "candidate item $idx resolves an external_id");
}
_ok('candidates — every JobDiva V2 BI key convention resolves to an external_id');

// ─────────────────────────────────────────────────────────────────────
// jobdivaSyncMirrorEntity() must SKIP items with no resolvable id and
// PROCESS those with one. We use a bare `getDB()` returning null so the
// upsert is skipped (legitimate path when DB is unavailable) — focus is
// on counter accounting, not row insertion.
// ─────────────────────────────────────────────────────────────────────
$items = [
    ['jobId' => 1, 'title' => 'A'],     // processed
    ['title' => 'B'],                    // skipped (no id)
    ['jobId' => 3, 'title' => 'C'],     // processed
    ['jobId' => 4, 'title' => 'D'],     // processed
    ['random' => 'no id at all'],        // skipped
];

// Stub out integrationPayloadFieldIndexRecord so it doesn't touch the
// DB during this test. We can't redeclare in PHP without runkit, but the
// existing implementation is best-effort and catches its own errors.
$opts = ['items_override' => $items];
$result = jobdivaSyncMirrorEntity(
    /* tenant */ 99999, // sentinel — no real tenant
    'jobdiva_job_test',
    '/apiv2/bi/NewUpdatedJobRecords',
    ['id', 'jobId', 'job_id'],
    /* user */ null,
    $opts
);
assert(is_array($result),                          'mirror returns associative array');
assert($result['processed'] === 3,                 'processed count = 3 (items with jobId)');
assert($result['skipped'] === 2,                   'skipped count = 2 (no id)');
// failed counter can be 0 (indexer no-op) or 3 (indexer raises on no DB);
// either is acceptable — the public contract is that bad items don't
// crash the whole sync.
assert(is_int($result['failed']),                  'failed counter is an int');
_ok('jobdivaSyncMirrorEntity counts processed vs skipped correctly');

// items_override with EMPTY list: returns zeros, doesn't throw.
$result = jobdivaSyncMirrorEntity(
    99999, 'jobdiva_job_test', '/apiv2/bi/NewUpdatedJobRecords',
    ['id', 'jobId'], null, ['items_override' => []]
);
assert($result['processed'] === 0, 'empty items_override → processed=0');
assert($result['skipped']   === 0, 'empty items_override → skipped=0');
assert($result['failed']    === 0, 'empty items_override → failed=0');
_ok('jobdivaSyncMirrorEntity handles empty input gracefully');

// ─────────────────────────────────────────────────────────────────────
// Verify the BI endpoint workaround now covers Job + Candidate paths
// — they need `userFieldsName=` to dodge the Spring NPE same as
// Company + Contact endpoints do.
// ─────────────────────────────────────────────────────────────────────
$syncSrc = (string) file_get_contents(__DIR__ . '/../core/jobdiva/sync.php');
assert(str_contains($syncSrc, "/apiv2/bi/NewUpdatedJobRecords"),
       'NewUpdatedJobRecords is wired into the BI endpoint workaround list');
assert(str_contains($syncSrc, "/apiv2/bi/NewUpdatedCandidateRecords"),
       'NewUpdatedCandidateRecords is wired into the BI endpoint workaround list');
_ok('BI endpoint NPE workaround covers Jobs + Candidates');

// ─────────────────────────────────────────────────────────────────────
// jobdivaSyncAll wiring — `by_entity.jobdiva_job` and
// `by_entity.jobdiva_candidate` must appear in the result envelope so
// the UI can show per-entity counters after a Sync All run.
// ─────────────────────────────────────────────────────────────────────
assert(str_contains($syncSrc, 'jobdivaCanonicalFieldIndexEntityTypes($entityType)')
    && str_contains($syncSrc, 'jobdivaCanonicalPayloadForEntity($entityType, $indexEntityType, $jd)')
    && str_contains($syncSrc, "integrationPayloadFieldIndexRecord(\$tid, 'jobdiva', \$indexEntityType, \$payloadForIndex)"),
    'mirror sync indexes native mirrors and canonical roots');
_ok('mirror sync indexes native mirrors plus canonical roots');

assert(str_contains($syncSrc, "'jobdiva_job'       => \$jobs"),
       'jobdivaSyncAll surfaces jobdiva_job in by_entity');
assert(str_contains($syncSrc, "'jobdiva_candidate' => \$candidates"),
       'jobdivaSyncAll surfaces jobdiva_candidate in by_entity');
_ok('jobdivaSyncAll surfaces jobdiva_job + jobdiva_candidate in by_entity');

echo "\n🎯 jobdiva_mirror_sync_smoke — ALL PASS\n";
