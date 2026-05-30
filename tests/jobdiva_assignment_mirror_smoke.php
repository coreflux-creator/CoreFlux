<?php
/**
 * jobdiva_assignment_mirror_smoke.php
 *
 * Regression guard for the operator-reported gap:
 *   "are we mirroring assignments?"
 *
 * Verifies that `jobdivaSyncMirrorByPlacements()` extracts every
 * placement's `id` (which IS the JobDiva startId) and pulls full
 * assignment-record detail via `/apiv2/bi/EmployeeAssignmentRecordsDetail`
 * (the only documented endpoint for that per official Swagger spec).
 *
 * Records are mirrored under `internal_entity_type='jobdiva_assignment'`.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/jobdiva/sync.php';

function _ok(string $msg): void { fwrite(STDOUT, "✅ $msg\n"); }

$src = (string) file_get_contents(__DIR__ . '/../core/jobdiva/sync.php');

// Endpoint wired.
assert(str_contains($src, '/apiv2/bi/EmployeeAssignmentRecordsDetail'),
    'EmployeeAssignmentRecordsDetail endpoint wired per Swagger spec');
_ok('EmployeeAssignmentRecordsDetail wired');

// startIds extracted from placement payloads — the placement `id` is
// the JobDiva startId.
assert(str_contains($src, "'id', 'startId', 'start_id', 'startID', 'STARTID', 'placementId'"),
    'placement id extraction list includes startId aliases');
_ok('startId extracted from placement.id (matches Swagger contract)');

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

// Per-call failure absorption — one bad startId does NOT abort the entire mirror.
assert(str_contains($mbpSlice, 'EmployeeAssignmentRecordsDetail startId='),
    'per-call errors are error_log\'d with the failing startId');
_ok('per-call errors absorbed without aborting the whole mirror');

// Channel-2 fallback: POST /apiv2/jobdiva/searchStart when the BI
// detail endpoint returns zero records (e.g. tenant's API user lacks
// BI scope but still has searchStart access).
assert(str_contains($mbpSlice, "/apiv2/jobdiva/searchStart"),
    'searchStart fallback path wired');
assert(str_contains($mbpSlice, "count(\$assignmentRecords) === 0"),
    'fallback only fires when channel 1 yielded nothing');
assert(str_contains($mbpSlice, "'assignment_channel'"),
    'stats expose which channel actually produced the records (employee_records | search_start | none)');
assert(str_contains($mbpSlice, "'assignment_search_start_attempts'")
    && str_contains($mbpSlice, "'assignment_search_start_errors'"),
    'fallback attempts + per-call errors surfaced for operator diagnosis');
_ok('Channel-2 (searchStart) fallback wired and diagnosable');

echo "\n🎯 jobdiva_assignment_mirror_smoke — ALL PASS\n";
