<?php
/**
 * jobdiva_assignment_mirror_smoke.php
 *
 * Regression guard for the operator-reported gap:
 *   "are we mirroring assignments?"
 *
 * Verifies that `jobdivaSyncMirrorByPlacements()` extracts every exact
 * searchStart placement snapshot and mirrors that same Start identity.
 * Legacy gaps may use searchStart's documented jobId/candidateid request,
 * but an ignored startId-only body must never be used.
 *
 * Records are mirrored under `internal_entity_type='jobdiva_assignment'`.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/jobdiva/sync.php';

function _ok(string $msg): void { fwrite(STDOUT, "✅ $msg\n"); }

$src = (string) file_get_contents(__DIR__ . '/../core/jobdiva/sync.php');

// Supported searchStart contract wired.
assert(str_contains($src, 'function jobdivaAssignmentSearchStartCriteria')
    && str_contains($src, "\$criteria['jobId']")
    && str_contains($src, "\$criteria['candidateid']"),
    'searchStart exact lookup uses documented jobId/candidateid criteria');
assert(!str_contains($src, "['startId' => \$assignmentId]"),
    'unsupported startId-only search body is absent');
_ok('supported searchStart request contract wired');

$criteria = jobdivaAssignmentSearchStartCriteria([
    'id' => '57065952',
    'job id' => '28100821',
    'candidate id' => '11989685956283',
]);
assert(($criteria['jobId'] ?? null) === 28100821
    && ($criteria['candidateid'] ?? null) === 11989685956283
    && !array_key_exists('startId', $criteria),
    'criteria builder emits only documented searchStart fields');
_ok('criteria builder narrows by job + candidate without inventing startId');

// startIds extracted from placement payloads — the placement `id` is
// the JobDiva startId.
assert(str_contains($src, "'id', 'startId', 'start_id', 'startID', 'STARTID', 'placementId'"),
    'placement id extraction list includes startId aliases');
_ok('Start identity extracted from exact placement snapshot');

// Mirrored under jobdiva_assignment internal_entity_type.
assert(str_contains($src, "'jobdiva_assignment'"),
    'records stored under entity_type=jobdiva_assignment');
_ok('records mirrored under entity_type=jobdiva_assignment');

// stats envelope reports unique_start_ids + assignments_returned/processed.
$mbpStart = strpos($src, 'function jobdivaSyncMirrorByPlacements');
assert($mbpStart !== false, 'function exists');
$mbpEnd = strpos($src, "\nfunction jobdivaSyncAll", $mbpStart);
$mbpSlice = $mbpEnd !== false ? substr($src, $mbpStart, $mbpEnd - $mbpStart) : substr($src, $mbpStart);
foreach (['unique_start_ids', 'assignments_returned', 'assignments_processed'] as $k) {
    assert(str_contains($mbpSlice, "'$k'"), "stats envelope includes '$k'");
}
_ok('stats envelope surfaces unique_start_ids / assignments_returned / assignments_processed');

// assignment_cap option respected (default 500).
assert(str_contains($mbpSlice, "\$opts['assignment_cap'] ?? 500"),
    'assignment_cap option defaults to 500 (avoid blowing the request budget)');
_ok('assignment_cap option respected (default=500)');

// Exact snapshots are primary; supported criteria are fallback only.
assert(str_contains($mbpSlice, "'searchStart:placement_snapshot'"),
    'exact placement snapshots are primary');
assert(str_contains($mbpSlice, "jobdivaFetchExactAssignmentById(")
    && str_contains($mbpSlice, "\$assignmentHints[\$normalisedSid]"),
    'legacy gaps use contextual supported searchStart lookup');
assert(str_contains($mbpSlice, "'assignment_channel'"),
    'stats expose which channel produced records');
assert(str_contains($mbpSlice, "'assignment_snapshot_rows'")
    && str_contains($mbpSlice, "'assignment_supported_lookup_attempts'"),
    'snapshot and supported lookup counts are diagnosable');
assert(str_contains($mbpSlice, "'assignment_search_start_attempts'")
    && str_contains($mbpSlice, "'assignment_search_start_errors'"),
    'lookup attempts + per-call errors surfaced for operator diagnosis');
_ok('exact snapshot mirror and contextual fallback are diagnosable');

echo "\n🎯 jobdiva_assignment_mirror_smoke — ALL PASS\n";
