<?php
/**
 * JobDiva placement discovery (2026-02 follow-on to Sprint 8a Slice A3).
 *
 * BACKGROUND
 *   JobDiva V2 has no "NewUpdatedStartRecords" BI delta endpoint. The only
 *   listing route is POST /apiv2/jobdiva/searchStart, which accepts
 *   explicit criteria. This module wraps three discovery channels so the
 *   operator never has to manually sync placements:
 *
 *   1. searchStart with date-range criteria   ← primary
 *   2. timesheet-derived (NewUpdatedTimesheetRecords → unique placementIds)
 *                                              ← safety-net for active placements
 *   3. webhook ingestion (placement.* events)  ← real-time, handled in api/jobdiva.php
 *
 *   For each newly-discovered placement we auto-create a minimal `people`
 *   record from the searchStart payload (first_name / last_name / email /
 *   phone), tagged `external_id = 'jd:<candidateId>'` and `source = 'jobdiva'`
 *   so the operator can audit imported records.
 *
 * PUBLIC SURFACE
 *   jobdivaPlacementsDiscover(int $tid, ?int $userId, array $opts = []): array
 *     → { items: [...normalised placement records...],
 *         channel: 'searchStart' | 'timesheets' | 'none',
 *         diagnostics: { searchStart_attempts: [...], items_total: int } }
 *
 *   jobdivaPlacementsAutoCreatePerson(int $tid, array $jd, ?int $userId): ?int
 *     → returns the internal person_id, or null on hard failure.
 *
 * Both helpers are pure functions (no global state, no superglobal use)
 * so smoke tests can drive them with items_override.
 */
declare(strict_types=1);

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/sync.php';
require_once __DIR__ . '/../integrations/entity_mappings.php';

const JOBDIVA_PATH_SEARCH_START = '/apiv2/jobdiva/searchStart';

/**
 * Try to fetch placement records via POST /apiv2/jobdiva/searchStart with
 * date-range criteria. JobDiva's swagger doesn't pin down the exact field
 * names for date filtering on this endpoint — different tenants have
 * reported `startDateBegin`/`startDateEnd`, `dateBegin`/`dateEnd`, and
 * `modifyDateBegin`/`modifyDateEnd`. We try each shape in order and
 * accept the first non-empty array response.
 *
 * Returns:
 *   [ 'items' => [...], 'attempts' => [ {criteria, status, error?, count} ] ]
 *
 * `attempts` is exposed in the audit detail so the operator can see exactly
 * what we asked JobDiva and what came back.
 */
function jobdivaPlacementsFetchViaSearchStart(int $tid, array $opts): array
{
    // Build the date window. Default 7 days; first-sync widens to 365.
    $now = new \DateTimeImmutable('now');
    $defaultDays = (int) ($opts['default_window_days'] ?? 7);
    try { $from = !empty($opts['modified_since']) ? new \DateTimeImmutable((string) $opts['modified_since']) : $now->modify("-{$defaultDays} days"); }
    catch (\Throwable $_) { $from = $now->modify("-{$defaultDays} days"); }
    try { $to = !empty($opts['modified_until']) ? new \DateTimeImmutable((string) $opts['modified_until']) : $now; }
    catch (\Throwable $_) { $to = $now; }
    $fmt = 'm/d/Y H:i:s';  // JobDiva BI format (consistent with the rest of the integration)
    $fromStr = $from->format($fmt);
    $toStr   = $to->format($fmt);

    // Criterion shapes to try, in priority order. JobDiva V2 sometimes
    // returns 200 + empty array when the param name is wrong (silently
    // matches "all records but filtered to none"); other shapes 400 or
    // 500 outright. We accept any non-empty array as success.
    $candidates = [
        ['startDateBegin'           => $fromStr, 'startDateEnd'           => $toStr],
        ['startdatebegin'           => $fromStr, 'startdateenddate'       => $toStr],
        ['modifyDateBegin'          => $fromStr, 'modifyDateEnd'          => $toStr],
        ['startDateFrom'            => $fromStr, 'startDateTo'            => $toStr],
        ['dateBegin'                => $fromStr, 'dateEnd'                => $toStr],
    ];

    $attempts = [];
    foreach ($candidates as $body) {
        try {
            $resp  = jobdivaCall($tid, 'POST', JOBDIVA_PATH_SEARCH_START, $body);
            $items = jobdivaPlacementsExtractList($resp);
            $attempts[] = [
                'criteria' => array_keys($body),
                'status'   => 'ok',
                'count'    => count($items),
            ];
            if (count($items) > 0) {
                return ['items' => $items, 'attempts' => $attempts];
            }
        } catch (\Throwable $e) {
            $attempts[] = [
                'criteria' => array_keys($body),
                'status'   => 'error',
                'error'    => substr($e->getMessage(), 0, 300),
            ];
            // 400-class errors are expected when a criterion shape is
            // wrong; keep trying the next one. 401/500-on-auth is fatal
            // and should bubble up so the caller knows the connection
            // itself is broken.
            $msg = $e->getMessage();
            if (stripos($msg, 'HTTP 401') !== false || stripos($msg, 'authentication') !== false) {
                throw $e;
            }
        }
    }
    return ['items' => [], 'attempts' => $attempts];
}

/**
 * Pull NewUpdatedTimesheetRecords (existing wired endpoint) and derive
 * the unique set of placement IDs. For each unique ID, fetch the full
 * start record via POST /apiv2/jobdiva/searchStart with a `startId`
 * criterion (the one criterion shape that's reliably documented).
 *
 * This is the safety-net channel — picks up any active placement that
 * has at least one timesheet in the delta window, even if searchStart's
 * date-range filtering didn't surface it directly.
 */
function jobdivaPlacementsFetchViaTimesheets(int $tid, array $opts): array
{
    // Reuse the resilient retry helper so timesheet-side 500s don't kill us.
    $items = jobdivaSyncFetchWithRetry($tid, JOBDIVA_PATH_TIMESHEETS_DELTA, $opts);
    $placementIds = [];
    foreach ($items as $ts) {
        if (!is_array($ts)) continue;
        $pid = jobdivaPluckField($ts, ['placementId', 'placement_id', 'startId', 'start_id', 'placement id', 'start id']);
        if ($pid !== '') $placementIds[$pid] = true;
    }
    $unique = array_keys($placementIds);

    $records = [];
    $attempts = [];
    foreach ($unique as $pid) {
        try {
            $resp = jobdivaCall($tid, 'POST', JOBDIVA_PATH_SEARCH_START, ['startId' => $pid]);
            $list = jobdivaPlacementsExtractList($resp);
            foreach ($list as $row) $records[] = $row;
            $attempts[] = ['startId' => $pid, 'status' => 'ok', 'count' => count($list)];
        } catch (\Throwable $e) {
            $attempts[] = ['startId' => $pid, 'status' => 'error', 'error' => substr($e->getMessage(), 0, 200)];
            // Single-ID fetch failure is non-fatal — log and continue.
        }
    }
    return ['items' => $records, 'attempts' => $attempts, 'discovered_ids' => $unique];
}

/**
 * Normalise JobDiva's many possible response envelopes into a flat array
 * of records. searchStart has returned `{data: [...]}`, `{items: [...]}`,
 * `{starts: [...]}`, and plain `[...]` across releases.
 */
function jobdivaPlacementsExtractList(mixed $resp): array
{
    if (!is_array($resp)) return [];
    foreach (['data', 'items', 'starts', 'records', 'results'] as $k) {
        if (isset($resp[$k]) && is_array($resp[$k])) return $resp[$k];
    }
    if (array_keys($resp) === range(0, count($resp) - 1)) return $resp;
    // Single-record envelope.
    if (isset($resp['id']) || isset($resp['startId']) || isset($resp['placementId'])) {
        return [$resp];
    }
    return [];
}

/**
 * Orchestrating discovery: try searchStart-with-date-range, fall back to
 * timesheet-derived if that yields nothing. Returns a normalised
 * `items[]` for the placement parser to iterate.
 */
function jobdivaPlacementsDiscover(int $tid, ?int $userId, array $opts = []): array
{
    if (isset($opts['items_override']) && is_array($opts['items_override'])) {
        return [
            'items'       => $opts['items_override'],
            'channel'     => 'items_override',
            'diagnostics' => ['note' => 'items_override (smoke test path)'],
        ];
    }

    // Channel 1: searchStart with date-range
    $primary = jobdivaPlacementsFetchViaSearchStart($tid, $opts);
    if (count($primary['items']) > 0) {
        return [
            'items'   => $primary['items'],
            'channel' => 'searchStart',
            'diagnostics' => [
                'searchStart_attempts' => $primary['attempts'],
                'items_total'          => count($primary['items']),
            ],
        ];
    }

    // Channel 2: timesheet-derived
    $fallback = jobdivaPlacementsFetchViaTimesheets($tid, $opts);
    return [
        'items'   => $fallback['items'],
        'channel' => 'timesheets',
        'diagnostics' => [
            'searchStart_attempts'   => $primary['attempts'],
            'searchStart_yielded'    => 0,
            'timesheet_discovered_ids' => $fallback['discovered_ids'] ?? [],
            'timesheet_fetch_attempts' => $fallback['attempts'] ?? [],
            'items_total'            => count($fallback['items']),
        ],
    ];
}

/**
 * Resolve (or create) the internal person_id for a JobDiva placement
 * record. Order of resolution:
 *   1. Existing person mapping via external_entity_mappings
 *   2. Existing person by email_primary (case-insensitive)
 *   3. Auto-create a minimal people row
 *
 * Returns the internal person_id, or null if we couldn't produce one
 * (e.g. no candidate ID at all — placement is uninterpretable).
 *
 * AUTO-CREATE notes:
 *   - email_primary is NOT NULL on `people`. If JobDiva doesn't provide
 *     one, we synthesise `jd-emp-<extId>@no-email.invalid` which is
 *     guaranteed unique (RFC 6761 reserves .invalid).
 *   - classification falls back to 'w2' (most common JobDiva placement
 *     type); the operator can correct it in the People UI.
 *   - source = 'jobdiva' so the People list filter can isolate imports.
 */
function jobdivaPlacementsAutoCreatePerson(int $tid, array $jd, ?int $userId): ?int
{
    require_once __DIR__ . '/../integrations/field_map.php';
    $candidateExtId = jobdivaPluckField($jd, [
        'candidateId', 'candidate_id', 'employeeId', 'employee_id',
        'candidateID', 'EmployeeID', 'personId', 'person_id',
    ]);
    if ($candidateExtId === '') return null;

    // Channel 1: existing mapping
    $mapping = mappingFindInternal($tid, 'jobdiva', 'person', $candidateExtId);
    if ($mapping) return (int) $mapping['internal_entity_id'];

    // Slice 4 wiring — each person field consults the tenant registry
    // first; built-in candidate lists are the fallback when no override
    // is configured. The deep pluck variant looks into the enriched
    // `_jd_candidate` record (fetched by jobdivaSyncEnrichRelatedEntities)
    // so person data comes from JobDiva's full Candidate detail, not
    // just whatever the placement BI feed happened to denormalise.
    $firstName = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'person', 'first_name', $jd,
        static fn() => jobdivaPluckFieldDeep($jd, [
            'candidateFirstName', 'firstName', 'first_name', 'first name',
            'candidate_first_name',
        ])
    );
    $lastName = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'person', 'last_name', $jd,
        static fn() => jobdivaPluckFieldDeep($jd, [
            'candidateLastName',  'lastName',  'last_name',  'last name',
            'candidate_last_name',
        ])
    );
    $email = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'person', 'email_primary', $jd,
        static fn() => jobdivaPluckFieldDeep($jd, [
            'candidateEmail', 'email', 'email_primary', 'emailAddress',
            'candidate_email', 'primary email',
        ])
    );
    $phone = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'person', 'phone_primary', $jd,
        static fn() => jobdivaPluckFieldDeep($jd, [
            'candidatePhone', 'phone', 'phone_primary', 'phoneNumber',
            'candidate_phone', 'phone 1',
        ])
    );

    if ($firstName === '' && $lastName === '') {
        // Truly blank candidate identity — refuse to create a ghost record.
        return null;
    }
    if ($firstName === '') $firstName = 'JobDiva';
    if ($lastName  === '') $lastName  = 'Candidate-' . $candidateExtId;
    if ($email     === '') $email     = sprintf('jd-emp-%s@no-email.invalid', $candidateExtId);

    $pdo = getDB();
    // Channel 2: existing person by email (covers manual-create-before-sync race).
    $stmt = $pdo->prepare(
        'SELECT id FROM people
          WHERE tenant_id = :t AND LOWER(email_primary) = LOWER(:e) AND deleted_at IS NULL
          LIMIT 1'
    );
    $stmt->execute(['t' => $tid, 'e' => $email]);
    $existingId = (int) $stmt->fetchColumn();
    if ($existingId > 0) {
        // Bind the mapping so future syncs find this person directly.
        mappingUpsert($tid, 'jobdiva', 'person', $candidateExtId, $existingId, $jd, 'pull', $userId);
        return $existingId;
    }

    // Channel 3: auto-create.
    $pdo->prepare(
        'INSERT INTO people
            (tenant_id, external_id, first_name, last_name,
             email_primary, phone_primary, classification, status,
             work_auth_status, source, created_by_user_id)
         VALUES
            (:t, :ext, :fn, :ln, :em, :ph, :cls, "active",
             "unknown", "jobdiva", :u)'
    )->execute([
        't'   => $tid,
        'ext' => 'jd:' . $candidateExtId,
        'fn'  => $firstName,
        'ln'  => $lastName,
        'em'  => $email,
        'ph'  => $phone !== '' ? $phone : null,
        'cls' => 'w2',  // sensible default; operator can correct in People UI
        'u'   => $userId,
    ]);
    $newId = (int) $pdo->lastInsertId();
    mappingUpsert($tid, 'jobdiva', 'person', $candidateExtId, $newId, $jd, 'pull', $userId);
    return $newId;
}
