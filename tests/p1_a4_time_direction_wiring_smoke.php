<?php
/**
 * P1 follow-on smoke — A4 time direction wiring.
 *
 * Asserts:
 *   - core/jobdiva/sync_time.php parses + exposes pull, push, upsert, category
 *     mapping helpers.
 *   - Pull driver gracefully skips entries when placement mapping missing
 *     (NO auto-create per user requirement).
 *   - Pull driver finds person_id + period_id by joining placements + active
 *     time_period for the work_date.
 *   - Push driver only ships approved entries from last 60 days, content-hash
 *     short-circuits unchanged rows, supports test transport injection.
 *   - sync.php orchestrator honors per-entity time config: pull when
 *     source=jobdiva + direction in (pull, two_way); push when source=coreflux
 *     + direction in (push, two_way); two_way runs both.
 *   - sync.php orchestrator returns combined counts + by_entity envelope
 *     including 'time' key.
 *   - Category mapping helpers cover the basic JobDiva → CoreFlux + reverse.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$lint = function (string $p): bool {
    $o = []; $rc = 0; @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
    return $rc === 0;
};
$ROOT = realpath(__DIR__ . '/..');

echo "Driver — core/jobdiva/sync_time.php\n";
$path = "{$ROOT}/core/jobdiva/sync_time.php";
$src = (string) file_get_contents($path);
$assert('parses',                                  $lint($path));
$assert('declares strict_types',                   strpos($src, 'declare(strict_types=1)') !== false);
$assert('requires entity_mappings',                strpos($src, "require_once __DIR__ . '/../integrations/entity_mappings.php'") !== false);
$assert('exports jobdivaSyncTimePull',             strpos($src, 'function jobdivaSyncTimePull(') !== false);
$assert('exports jobdivaSyncTimePush',             strpos($src, 'function jobdivaSyncTimePush(') !== false);
$assert('exports jobdivaSyncUpsertTimeEntry',      strpos($src, 'function jobdivaSyncUpsertTimeEntry(') !== false);
$assert('exports jobdivaMapTimeCategory',          strpos($src, 'function jobdivaMapTimeCategory(') !== false);
$assert('exports jobdivaUnmapTimeCategory',        strpos($src, 'function jobdivaUnmapTimeCategory(') !== false);

echo "\nPull driver behaviour\n";
$assert('hits V2 BI NewUpdatedTimesheetRecords path',
    strpos($src, "JOBDIVA_PATH_TIMESHEETS_DELTA") !== false);
$assert('falls back across id key spellings',
    strpos($src, "\$jd['timesheetId']") !== false
    && strpos($src, "\$jd['timesheet_id']") !== false);
$assert('resolves placement via existing mapping (NO auto-create)',
    strpos($src, "mappingFindInternal(\$tid, 'jobdiva', 'placement', \$placementExtId)") !== false
    && strpos($src, 'if (!$placementMapping) { $skipped++; continue; }') !== false);
$assert('joins placements + active time_period for work_date',
    strpos($src, "FROM placements p\n                   JOIN time_periods tp") !== false
    && strpos($src, 'tp.start_date <= :wd') !== false
    && strpos($src, 'tp.end_date   >= :wd') !== false);
$assert('skips when no active period covers work_date',
    strpos($src, 'if (!$meta) { $skipped++; continue; }') !== false);
$assert('binds mapping (time_entry)',
    strpos($src, "mappingUpsert(\$tid, 'jobdiva', 'time_entry', \$extId, \$internalId, \$jd, 'pull')") !== false);
$assert('emits audit row entity_type=time direction=pull',
    strpos($src, "'entity_type'     => 'time'") !== false
    && strpos($src, "'direction'       => 'pull'") !== false);
$assert("source tagged 'bulk_upload' (no enum migration needed)",
    strpos($src, "'source'       => 'bulk_upload'") !== false);

echo "\nPush driver behaviour\n";
$assert('only ships approved + non-superseded entries',
    strpos($src, "AND te.status    = 'approved'") !== false
    && strpos($src, 'AND te.superseded_by_id IS NULL') !== false);
$assert('60-day window default',
    strpos($src, "strtotime('-60 days')") !== false);
$assert('resolves placement external_id via reverse mapping',
    strpos($src, "mappingFindExternal(\$tid, 'jobdiva', 'placement', (int) \$te['placement_id'])") !== false);
$assert('content_hash short-circuits unchanged rows',
    strpos($src, '$newHash = mappingHash($payload)') !== false
    && strpos($src, "\$existing['content_hash'] === \$newHash") !== false);
$assert('test transport injection point',
    strpos($src, "isset(\$opts['transport']) && is_callable(\$opts['transport'])") !== false);
$assert('POST to V2 uploadTimesheet (no V2 PUT-by-id endpoint)',
    strpos($src, "'/apiv2/jobdiva/uploadTimesheet'") !== false
    && strpos($src, "if (\$existing) { \$skipped++; continue; }") !== false);
$assert('binds mapping (time_entry, push direction)',
    strpos($src, "mappingUpsert(\$tid, 'jobdiva', 'time_entry', \$extId, \$internalId, \$payload, 'push')") !== false);
$assert('emits audit row entity_type=time direction=push',
    preg_match("/'entity_type'\\s*=>\\s*'time'.*?'direction'\\s*=>\\s*'push'/s", $src) === 1);

echo "\nUpsert helper\n";
$assert('respects approval lock — only updates draft/pending_review',
    strpos($src, 'WHERE id = :id AND tenant_id = :t AND status IN ("draft","pending_review")') !== false);
$assert("INSERT defaults status='draft'",
    strpos($src, "VALUES\n            (:t, :pl, :p, :prd, :wd, :c, :h, :d, :s, \"draft\", :u)") !== false);

echo "\nCategory mapping (runtime)\n";
require_once $path;
$assert("'regular' → 'regular_billable'",  jobdivaMapTimeCategory('regular')  === 'regular_billable');
$assert("'overtime' → 'OT_billable'",      jobdivaMapTimeCategory('overtime') === 'OT_billable');
$assert("'OT' (case-insensitive) → 'OT_billable'", jobdivaMapTimeCategory('OT') === 'OT_billable');
$assert("'PTO' → 'vacation'",              jobdivaMapTimeCategory('PTO')      === 'vacation');
$assert("unknown → 'regular_billable' default", jobdivaMapTimeCategory('zzz')  === 'regular_billable');
$assert("reverse 'OT_billable' → 'overtime'", jobdivaUnmapTimeCategory('OT_billable') === 'overtime');
$assert("reverse 'sick' → 'sick'",           jobdivaUnmapTimeCategory('sick')         === 'sick');

echo "\nOrchestration — sync.php honors time config\n";
$orch = (string) file_get_contents("{$ROOT}/core/jobdiva/sync.php");
$assert('shouldPush helper exists',
    strpos($orch, "(\$row['source'] ?? null) === 'coreflux'") !== false
    && strpos($orch, "in_array(\$row['direction'] ?? 'off', ['push', 'two_way'], true)") !== false);
$assert('time entity dispatched when shouldPull OR shouldPush',
    strpos($orch, "if (\$shouldPull(\$config, 'time') || \$shouldPush(\$config, 'time'))") !== false);
$assert('lazy-requires sync_time.php only when needed',
    strpos($orch, "require_once __DIR__ . '/sync_time.php'") !== false);
$assert('two_way runs both pull AND push',
    strpos($orch, "\$pull = \$shouldPull(\$config, 'time') ? jobdivaSyncTimePull") !== false
    && strpos($orch, "\$push = \$shouldPush(\$config, 'time') ? jobdivaSyncTimePush") !== false);
$assert('time excluded → marked skipped_by_config',
    preg_match("/\\\$shouldPush\\(\\\$config, 'time'\\)\\)\\s*\\{[^}]+\\}\\s*else\\s*\\{\\s*\\\$skipped\\[\\]\\s*=\\s*'time'/s", $orch) === 1);
$assert('time count included in counts envelope',
    strpos($orch, "'time'      => \$timeResult['processed']") !== false);
$assert('by_entity envelope includes time key',
    strpos($orch, "'time'      => \$timeResult,") !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
