<?php
/**
 * JobDiva placement discovery smoke — locks in the 2026-02 follow-on to
 * Sprint 8a Slice A3 that activates placement sync without depending on
 * a (non-existent) "NewUpdatedStartRecords" BI delta endpoint.
 *
 * Channels under test:
 *   1. POST /apiv2/jobdiva/searchStart with date-range criteria (primary)
 *   2. NewUpdatedTimesheetRecords → unique placementIds → per-ID searchStart
 *      (safety-net for active placements)
 *   3. Webhook ingestion of `start.created` / `placement.updated` events
 *
 * Plus the auto-create person resolver that satisfies placement.person_id
 * (NOT NULL) without a separate "Employee" sync step.
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

echo "Module — core/jobdiva/sync_placements.php\n";
$path = "{$ROOT}/core/jobdiva/sync_placements.php";
$src  = (string) file_get_contents($path);
$assert('file exists',                                 strlen($src) > 0);
$assert('parses',                                      $lint($path));
$assert('declares strict_types',                       strpos($src, 'declare(strict_types=1)') !== false);
$assert('requires sync.php (for jobdivaPluckField + jobdivaSyncFetchWithRetry)',
    strpos($src, "require_once __DIR__ . '/sync.php'") !== false);
$assert('exports jobdivaPlacementsDiscover',           strpos($src, 'function jobdivaPlacementsDiscover(') !== false);
$assert('exports jobdivaPlacementsFetchViaSearchStart',strpos($src, 'function jobdivaPlacementsFetchViaSearchStart(') !== false);
$assert('exports jobdivaPlacementsFetchViaTimesheets', strpos($src, 'function jobdivaPlacementsFetchViaTimesheets(') !== false);
$assert('exports jobdivaPlacementsExtractList',        strpos($src, 'function jobdivaPlacementsExtractList(') !== false);
$assert('exports jobdivaPlacementsAutoCreatePerson',   strpos($src, 'function jobdivaPlacementsAutoCreatePerson(') !== false);
$assert('declares JOBDIVA_PATH_SEARCH_START constant',
    strpos($src, "const JOBDIVA_PATH_SEARCH_START = '/apiv2/jobdiva/searchStart'") !== false);

echo "\nsearchStart probe — tries multiple criterion shapes\n";
$assert('tries startDateBegin/startDateEnd (canonical)',
    strpos($src, "'startDateBegin'") !== false && strpos($src, "'startDateEnd'") !== false);
$assert('tries lowercase startdatebegin/startdateenddate',
    strpos($src, "'startdatebegin'") !== false && strpos($src, "'startdateenddate'") !== false);
$assert('tries modifyDateBegin/modifyDateEnd',
    strpos($src, "'modifyDateBegin'") !== false && strpos($src, "'modifyDateEnd'") !== false);
$assert('tries dateBegin/dateEnd fallback',
    strpos($src, "'dateBegin'") !== false && strpos($src, "'dateEnd'") !== false);
$assert('uses JobDiva m/d/Y H:i:s date format (NOT ISO-8601)',
    strpos($src, "'m/d/Y H:i:s'") !== false);
$assert('first non-empty array response wins',
    strpos($src, 'if (count($items) > 0) {') !== false);
$assert('401 / authentication errors bubble up (not silently swallowed)',
    strpos($src, "stripos(\$msg, 'HTTP 401') !== false || stripos(\$msg, 'authentication') !== false") !== false);
$assert('records per-attempt diagnostic (criteria + status + count)',
    strpos($src, "'criteria' => array_keys(\$body)") !== false
    && strpos($src, "'status'   => 'ok'") !== false
    && strpos($src, "'status'   => 'error'") !== false);

echo "\nTimesheet-derived fallback\n";
$assert('uses retry-wrapped timesheet pull',
    strpos($src, 'jobdivaSyncFetchWithRetry($tid, JOBDIVA_PATH_TIMESHEETS_DELTA') !== false);
$assert('extracts unique placement IDs from timesheets',
    strpos($src, "jobdivaPluckField(\$ts, ['placementId', 'placement_id', 'startId', 'start_id', 'placement id', 'start id'])") !== false);
$assert('uses dictionary dedupe for unique IDs',
    strpos($src, '$placementIds[$pid] = true;') !== false
    && strpos($src, 'array_keys($placementIds)') !== false);
$assert('per-ID searchStart fetches detail',
    strpos($src, "jobdivaCall(\$tid, 'POST', JOBDIVA_PATH_SEARCH_START, ['startId' => \$pid])") !== false);
$assert('single-ID failures are non-fatal',
    strpos($src, "'status' => 'error', 'error' => substr(\$e->getMessage()") !== false);
$assert('returns discovered_ids[] for audit visibility',
    strpos($src, "'discovered_ids' => \$unique") !== false);

echo "\nExtractList — response envelope tolerance\n";
$assert('handles {data:[...]}',     strpos($src, "'data', 'items', 'starts', 'records', 'results'") !== false);
$assert('handles plain list',       strpos($src, 'array_keys($resp) === range(0, count($resp) - 1)') !== false);
$assert('handles single-record envelope (no list wrapper)',
    strpos($src, "isset(\$resp['id']) || isset(\$resp['startId']) || isset(\$resp['placementId'])") !== false);

echo "\nDiscovery orchestration\n";
$assert('items_override returns early with smoke-test channel',
    strpos($src, "'channel'     => 'items_override'") !== false);
$assert('primary searchStart channel returned on hit',
    strpos($src, "'channel' => 'searchStart'") !== false);
$assert('falls back to timesheets when searchStart empty',
    strpos($src, "'channel' => 'timesheets'") !== false);
$assert('diagnostics surfaces searchStart_attempts on fallback',
    strpos($src, "'searchStart_attempts'   => \$primary['attempts']") !== false);
$assert('diagnostics surfaces timesheet_discovered_ids',
    strpos($src, "'timesheet_discovered_ids'") !== false);

echo "\nAuto-create person resolver\n";
$assert('resolves candidate ID via multiple shapes',
    strpos($src, "'candidateId', 'candidate_id', 'employeeId', 'employee_id'") !== false
    && strpos($src, "'candidateID', 'EmployeeID', 'personId', 'person_id'") !== false);
$assert('returns null when no candidate ID at all',
    strpos($src, "if (\$candidateExtId === '') return null;") !== false);
$assert('reuses existing person mapping when present',
    strpos($src, "mappingFindInternal(\$tid, 'jobdiva', 'person', \$candidateExtId)") !== false
    && strpos($src, "return (int) \$mapping['internal_entity_id']") !== false);
$assert('reuses existing person by email_primary (case-insensitive)',
    strpos($src, 'LOWER(email_primary) = LOWER(:e)') !== false);
$assert('binds mapping after email match (so future syncs are direct)',
    strpos($src, "mappingUpsert(\$tid, 'jobdiva', 'person', \$candidateExtId, \$existingId") !== false);
$assert('refuses to create ghost record when name fully blank',
    strpos($src, "if (\$firstName === '' && \$lastName === '') {") !== false
    && strpos($src, 'return null;') !== false);
$assert('synthesises @no-email.invalid when email missing (RFC 6761)',
    strpos($src, "'jd-emp-%s@no-email.invalid'") !== false);
$assert("source tagged 'jobdiva' so People UI can filter imports",
    strpos($src, "\"jobdiva\"") !== false
    && strpos($src, "INSERT INTO people") !== false);
$assert("classification defaults to 'w2' (most common JobDiva placement)",
    strpos($src, "'cls' => 'w2'") !== false);
$assert("external_id prefixed 'jd:' for cross-source uniqueness",
    strpos($src, "'jd:' . \$candidateExtId") !== false);
$assert('binds person mapping after create',
    strpos($src, "mappingUpsert(\$tid, 'jobdiva', 'person', \$candidateExtId, \$newId") !== false);

echo "\nWiring — core/jobdiva/sync.php Placements driver\n";
$syncSrc = (string) file_get_contents("{$ROOT}/core/jobdiva/sync.php");
$assert('Placements driver requires sync_placements.php',
    strpos($syncSrc, "require_once __DIR__ . '/sync_placements.php'") !== false);
$assert('Placements driver invokes jobdivaPlacementsDiscover',
    strpos($syncSrc, 'jobdivaPlacementsDiscover($tid, $userId, $opts)') !== false);
$assert('non-override path calls jobdivaPlacementsAutoCreatePerson',
    strpos($syncSrc, 'jobdivaPlacementsAutoCreatePerson($tid, $jd, $userId)') !== false);
$assert('audit detail records discovery channel',
    strpos($syncSrc, "'channel'       => \$channel") !== false);
$assert('skip_reasons tracks no_person separately from missing_fields',
    strpos($syncSrc, "'no_person' => 0") !== false);
$assert('Upsert provides title (NOT NULL on placements)',
    strpos($syncSrc, "if (\$title === '') \$title = 'JobDiva Placement ' . \$extId;") !== false
    && strpos($syncSrc, "client_approver_name, client_approver_email, title") !== false);
$assert('Upsert UPDATE path also writes title',
    // Slice 2 refactor: UPDATE assignments are built dynamically so
    // `coreflux_overridden_fields` can selectively skip columns. Verify
    // the title column is in the allow-list dispatched into the UPDATE.
    strpos($syncSrc, "'title'                => ['ti',    \$title]") !== false);
$assert('Upsert pluck-resolves title across JobDiva key shapes',
    strpos($syncSrc, "'jobTitle', 'job_title', 'job title', 'title'") !== false);

// Slice 4 expansion regression — registry-aware writes for the new
// allow-listed columns. Catches the "user can pick the column from the
// dropdown but the upsert silently drops it" failure mode.
echo "\nSlice 4 — registry-aware writes for expanded allow-list\n";
foreach (['engagement_type', 'worksite_state', 'worksite_country',
          'remote_policy', 'notes', 'client_approver_name',
          'client_approver_email', 'actual_end_date', 'due_date'] as $col) {
    $assert("upsert resolves placement.{$col} via tenantIntegrationFieldMapPluckInternal",
        strpos($syncSrc, "'jobdiva', 'placement', '{$col}', \$jd,") !== false);
}
$assert('engagement_type enum coercion handles common upstream variants',
    strpos($syncSrc, "'corp-to-corp' => 'c2c'") !== false
    && strpos($syncSrc, "'temp_to_perm' => 'temp_to_perm'") !== false);
$assert('worksite_country is forced to CHAR(2) (matches column type)',
    strpos($syncSrc, 'strtoupper(substr($worksiteCountry, 0, 2))') !== false);
$assert('remote_policy enum coercion accepts onsite/hybrid/remote synonyms',
    strpos($syncSrc, "'on-site' => 'onsite'") !== false
    && strpos($syncSrc, "'wfh' => 'remote'") !== false);
$assert('actual_end_date / due_date are date-normalised before write',
    strpos($syncSrc, 'jobdivaNormaliseDate($actualEndRaw)') !== false
    && strpos($syncSrc, 'jobdivaNormaliseDate($dueDateRaw)') !== false);

echo "\nWiring — api/jobdiva.php webhook → real-time placement ingest\n";
$api = (string) file_get_contents("{$ROOT}/api/jobdiva.php");
$assert('webhook handler requires sync_placements helper',
    strpos($api, "require_once __DIR__ . '/../core/jobdiva/sync_placements.php'") !== false);
$assert('routes placement.* and start.* events into the sync pipeline',
    strpos($api, "strpos(\$eventLc, 'placement') !== false || strpos(\$eventLc, 'start') !== false") !== false);
$assert('extracts inline payload when JobDiva sends full record',
    strpos($api, "\$payload['data'] ?? \$payload['record'] ?? \$payload['placement'] ?? \$payload['start']") !== false);
$assert('re-fetches via searchStart when only ID provided',
    strpos($api, "jobdivaCall(\$tid, 'POST', JOBDIVA_PATH_SEARCH_START, ['startId' => \$startId])") !== false);
$assert('invokes jobdivaSyncPlacements with items_override',
    strpos($api, "jobdivaSyncPlacements(\$tid, null, ['items_override' => \$items, '_webhook' => true])") !== false);
$assert('marks webhook event "processed" on success',
    strpos($api, "SET status = \"processed\", processed_at = NOW()") !== false);
$assert('webhook processing failures are non-fatal (event stays queued)',
    strpos($api, "'webhook_process_failed'") !== false
    && strpos($api, "SET status = \"error\", process_error = :err") !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
