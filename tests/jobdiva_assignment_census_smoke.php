<?php
/**
 * JobDiva assignment census safety regression.
 */
declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
$pass = 0;
$fail = 0;
$assert = static function (string $message, bool $ok) use (&$pass, &$fail): void {
    echo '  ' . ($ok ? "\u{2713}" : "\u{2717}") . " {$message}\n";
    $ok ? $pass++ : $fail++;
};

$discovery = (string) file_get_contents($root . '/core/jobdiva/sync_placements.php');
$sync = (string) file_get_contents($root . '/core/jobdiva/sync.php');
$client = (string) file_get_contents($root . '/core/jobdiva/client.php');
$mappings = (string) file_get_contents($root . '/core/integrations/entity_mappings.php');
$settings = (string) file_get_contents($root . '/dashboard/src/pages/JobDivaSettings.jsx');

echo "JobDiva current-assignment census\n";
$assert('uses the official SearchStartDef shape',
    str_contains($discovery, "'startDateFrom' => \$startDateFrom")
    && str_contains($discovery, "'maxreturned' => \$pageSize")
    && str_contains($discovery, "'offset' => \$offset"));
$assert('incremental modified timestamps cannot narrow the assignment census',
    !str_contains($discovery, "\$opts['modified_since']"));
$assert('a complete page walk is required before the census is authoritative',
    str_contains($discovery, 'if ($rawCount < $pageSize)')
    && str_contains($discovery, "'authoritative' => true")
    && str_contains($discovery, "'authoritative' => false"));
$assert('partial census and timesheet evidence are merged without stale cleanup',
    str_contains($discovery, "array_merge(\$primary['items'] ?? [], \$fallback['items'] ?? [])"));
$assert('current and terminal source lifecycles are kept separate',
    str_contains($discovery, "'bucket' => 'current'")
    && str_contains($discovery, "'bucket' => 'terminal'"));

require_once $root . '/core/jobdiva/sync.php';
require_once $root . '/core/jobdiva/sync_placements.php';
$assert('census lower bound uses the ISO format accepted by SearchStartDef',
    jobdivaPlacementCensusStartDate([]) === '2000-01-01T00:00:00'
    && jobdivaPlacementCensusStartDate(['census_start_date' => '2026-02-03 10:11:12']) === '2026-02-03T10:11:12');
$assert('page offsets overlap by one record before Start-ID dedupe',
    str_contains($discovery, '$pageStride = max(1, $pageSize - 1)')
    && str_contains($discovery, '$offset = $page * $pageStride'));
$assert('a recent corroboration census shifts JobDiva page boundaries before ID-union',
    str_contains($discovery, 'jobdivaPlacementCorroborationStartDate')
    && str_contains($discovery, 'jobdivaPlacementsMergeSearchStartCensuses')
    && str_contains($discovery, "'corroboration_start_date_from'"));
$mergedCensus = jobdivaPlacementsMergeSearchStartCensuses(
    [
        'items' => [[
            'id' => 1001, 'candidate id' => 2001, 'job id' => 3001,
            'start date' => '01/15/2026', 'startStatus' => 'Active',
        ]],
        'terminal_items' => [],
        'review_items' => [[
            'id' => 1002, 'candidate id' => 2002, 'job id' => 3002,
            'start date' => '01/15/2026', 'startStatus' => 'Submitted',
        ]],
    ],
    [
        'items' => [[
            'id' => 1002, 'candidate id' => 2002, 'job id' => 3002,
            'start date' => '01/15/2026', 'startStatus' => 'Active',
        ], [
            'id' => 1003, 'candidate id' => 2003, 'job id' => 3003,
            'start date' => '01/15/2026', 'startStatus' => 'Active',
        ]],
        'terminal_items' => [],
        'review_items' => [],
    ]
);
$assert('corroboration unions missed Start IDs and conflicting evidence requires review',
    count($mergedCensus['items']) === 2
    && count($mergedCensus['review_items']) === 1
    && $mergedCensus['current_ids'] === ['1001', '1003']
    && (string) ($mergedCensus['review_items'][0]['id'] ?? '') === '1002');
$active = jobdivaPlacementCensusClassify([
    'id' => 1001,
    'candidate id' => 2001,
    'job id' => 3001,
    'start date' => '01/15/2026',
    'end date' => '12/31/2099',
    'startStatus' => 'Active',
]);
$ended = jobdivaPlacementCensusClassify([
    'id' => 1002,
    'candidate id' => 2002,
    'job id' => 3002,
    'start date' => '01/15/2025',
    'end date' => '02/15/2025',
    'startStatus' => 'Active',
]);
$pipeline = jobdivaPlacementCensusClassify([
    'id' => 1003,
    'candidate id' => 2003,
    'job id' => 3003,
    'start date' => '01/15/2027',
    'startStatus' => 'Submitted',
]);
$held = jobdivaPlacementCensusClassify([
    'id' => 1004,
    'candidate id' => 2004,
    'job id' => 3004,
    'start date' => '01/15/2026',
    'startStatus' => 'On Hold',
]);
$assert('active source row is current',
    $active['bucket'] === 'current' && $active['status'] === 'active');
$assert('on-hold source row is current',
    $held['bucket'] === 'current' && $held['status'] === 'on_hold');
$assert('past end date is terminal even when source text still says active',
    $ended['bucket'] === 'terminal' && $ended['status'] === 'ended');
$assert('candidate-pipeline rows cannot become placements',
    $pipeline['bucket'] === 'review');

echo "\nCanonical replay eligibility\n";
$assert('only a complete census reconciles replay mappings',
    str_contains($sync, 'if ($authoritativeCensus)')
    && str_contains($sync, 'jobdivaSyncReconcileAssignmentCensusMappings('));
$assert('absent rows are quarantined, not deleted',
    str_contains($sync, "SET sync_status = 'stale'")
    && !str_contains($sync, 'DELETE FROM placements WHERE'));
$assert('only explicit terminal rows change placement lifecycle',
    str_contains($sync, 'jobdivaSyncApplyTerminalAssignmentLifecycle(')
    && str_contains($sync, "in_array(\$desired, ['ended', 'cancelled'], true)"));
$assert('stored assignment replay reads healthy mappings only',
    str_contains($sync, "internal_entity_type = 'jobdiva_assignment'")
    && str_contains($sync, "AND sync_status = 'ok'"));
$assert('unchanged rows recover from a prior stale census',
    str_contains($mappings, 'SET sync_status = "ok",')
    && str_contains($mappings, 'last_error = NULL,')
    && str_contains($mappings, 'last_seen_at = NOW()'));

echo "\nRate-limit resilience\n";
$assert('JobDiva 429 responses honor Retry-After with bounded retries',
    str_contains($client, "\$resp['status'] === 429")
    && str_contains($client, "\$resp['headers']['retry-after']")
    && str_contains($client, '$rateAttempt < 3'));
$assert('placement discovery avoids duplicate per-record graph fetches',
    str_contains($sync, "'kinds' => ['start']")
    && str_contains($sync, 'jobdivaSyncMirrorByPlacements('));
$assert('Sync results expose the census status mix to the operator',
    str_contains($settings, 'complete census')
    && str_contains($settings, 'census.active')
    && str_contains($settings, 'census.terminal'));

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
