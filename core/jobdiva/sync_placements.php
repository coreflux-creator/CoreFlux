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
 *   1. paginated searchStart census           ← primary
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
 *         channel: 'searchStart_census' | 'timesheets' | 'none',
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
 * Fetch a complete Start/Assignment census with the request fields in
 * JobDiva's official V2 SearchStartDef: startDateFrom, maxreturned, offset.
 * `modified_since` is deliberately ignored here. It belongs to BI delta
 * endpoints and must never be reinterpreted as an assignment start date.
 *
 * Returns:
 *   [ 'items' => [...current rows...], 'terminal_items' => [...],
 *     'review_items' => [...], 'complete' => bool, 'attempts' => [...] ]
 *
 * `attempts` is exposed in the audit detail so the operator can see exactly
 * what we asked JobDiva and what came back.
 */
function jobdivaPlacementCensusStartDate(array $opts): string
{
    $raw = trim((string) ($opts['census_start_date'] ?? ''));
    if ($raw !== '') {
        try {
            return (new \DateTimeImmutable($raw))->format('Y-m-d\TH:i:s');
        } catch (\Throwable $_) {}
    }
    // This is an inventory lower bound, not an incremental cursor. A broad
    // default keeps long-running active assignments inside the census.
    return '2000-01-01T00:00:00';
}

function jobdivaPlacementCorroborationStartDate(array $opts): string
{
    $raw = trim((string) ($opts['census_corroboration_start_date'] ?? ''));
    if ($raw !== '') {
        try {
            return (new \DateTimeImmutable($raw))->format('Y-m-d\TH:i:s');
        } catch (\Throwable $_) {}
    }
    return (new \DateTimeImmutable('today -3 years'))->format('Y-m-d\TH:i:s');
}

/**
 * Merge independent SearchStart walks by Start ID. Conflicting observations
 * are conservative: review wins over current, and an explicit terminal row
 * wins over both. A Start seen only as current remains current, which still
 * lets a shifted page walk recover rows missed by the broad census.
 */
function jobdivaPlacementsMergeSearchStartCensuses(array ...$censuses): array
{
    $merged = ['current' => [], 'terminal' => [], 'review' => []];
    $sourceKeys = [
        'current' => 'items',
        'review' => 'review_items',
        'terminal' => 'terminal_items',
    ];
    foreach ($sourceKeys as $bucket => $sourceKey) {
        foreach ($censuses as $census) {
            foreach ((array) ($census[$sourceKey] ?? []) as $row) {
                if (!is_array($row)) continue;
                $assignmentId = jobdivaAssignmentRowId($row);
                if ($assignmentId === '') continue;
                foreach (array_keys($merged) as $otherBucket) {
                    unset($merged[$otherBucket][$assignmentId]);
                }
                $merged[$bucket][$assignmentId] = $row;
            }
        }
    }
    return [
        'items' => array_values($merged['current']),
        'terminal_items' => array_values($merged['terminal']),
        'review_items' => array_values($merged['review']),
        'current_ids' => array_map('strval', array_keys($merged['current'])),
    ];
}

/** @return array{bucket:string,status:string,source_status:string} */
function jobdivaPlacementCensusClassify(array $row): array
{
    $sourceStatus = jobdivaAssignmentIdentityPluck($row, [
        'startStatus', 'start_status', 'start status',
        'assignmentStatus', 'assignment_status', 'status',
    ]);
    $endDate = jobdivaNormaliseDate(jobdivaAssignmentIdentityPluck($row, [
        'end date', 'endDate', 'end_date', 'assignment end date',
    ]));
    $lifecycle = jobdivaAssignmentCanonicalPlacementStatus($sourceStatus, $endDate);
    $status = (string) ($lifecycle['status'] ?? '');

    if (in_array($status, ['ended', 'cancelled'], true)) {
        return ['bucket' => 'terminal', 'status' => $status, 'source_status' => $sourceStatus];
    }

    $evidence = jobdivaAssignmentLifecycleEvidence($row, 'searchStart:census');
    if (!empty($evidence['qualified']) && in_array($status, ['active', 'pending_start', 'on_hold'], true)) {
        return ['bucket' => 'current', 'status' => $status, 'source_status' => $sourceStatus];
    }

    return ['bucket' => 'review', 'status' => $status, 'source_status' => $sourceStatus];
}

function jobdivaPlacementsFetchViaSearchStart(int $tid, array $opts): array
{
    $pageSize = max(1, min(100, (int) ($opts['census_page_size'] ?? 100)));
    $maxPages = max(1, min(250, (int) ($opts['census_max_pages'] ?? 100)));
    $startDateFrom = jobdivaPlacementCensusStartDate($opts);
    $attempts = [];
    $buckets = ['current' => [], 'terminal' => [], 'review' => []];
    $seen = [];
    $complete = false;
    $rawTotal = 0;
    $duplicates = 0;
    $rejected = 0;

    // JobDiva installations disagree on whether SearchStart's undocumented
    // offset is zero- or one-based. Walk with a one-record overlap and dedupe
    // by Start ID so neither interpretation can drop the boundary assignment.
    $pageStride = max(1, $pageSize - 1);
    for ($page = 0; $page < $maxPages; $page++) {
        $offset = $page * $pageStride;
        $body = [
            'startDateFrom' => $startDateFrom,
            'maxreturned' => $pageSize,
            'offset' => $offset,
        ];
        try {
            $resp = jobdivaCall($tid, 'POST', JOBDIVA_PATH_SEARCH_START, $body);
            $rawItems = jobdivaPlacementsExtractList($resp);
        } catch (\Throwable $e) {
            $attempts[] = [
                'page' => $page + 1,
                'offset' => $offset,
                'criteria' => array_keys($body),
                'status' => 'error',
                'error' => substr($e->getMessage(), 0, 300),
            ];
            break;
        }

        $rawCount = count($rawItems);
        $rawTotal += $rawCount;
        $newOnPage = 0;
        $pageCounts = ['current' => 0, 'terminal' => 0, 'review' => 0];
        foreach ($rawItems as $row) {
            if (!is_array($row)) {
                $rejected++;
                continue;
            }
            $assignmentId = jobdivaAssignmentRowId($row);
            if ($assignmentId === '') {
                $rejected++;
                continue;
            }
            if (isset($seen[$assignmentId])) {
                $duplicates++;
                continue;
            }
            $seen[$assignmentId] = true;
            $newOnPage++;
            $classification = jobdivaPlacementCensusClassify($row);
            $bucket = (string) ($classification['bucket'] ?? 'review');
            $row['__cf_jobdiva_census_scope'] = $bucket;
            $row['__cf_jobdiva_canonical_status'] = (string) ($classification['status'] ?? '');
            if ($bucket === 'current') {
                $row = jobdivaAssignmentMarkVerified($row, $assignmentId, 'searchStart:census');
            }
            $buckets[$bucket][$assignmentId] = $row;
            $pageCounts[$bucket]++;
        }

        $attempts[] = [
            'page' => $page + 1,
            'offset' => $offset,
            'criteria' => array_keys($body),
            'status' => 'ok',
            'raw_count' => $rawCount,
            'new_count' => $newOnPage,
            'current' => $pageCounts['current'],
            'terminal' => $pageCounts['terminal'],
            'review' => $pageCounts['review'],
        ];

        if ($rawCount < $pageSize) {
            $complete = true;
            break;
        }
        // An endpoint ignoring offset would otherwise make a partial first
        // page look authoritative. No forward progress means incomplete.
        if ($newOnPage === 0) break;
    }

    return [
        'items' => array_values($buckets['current']),
        'terminal_items' => array_values($buckets['terminal']),
        'review_items' => array_values($buckets['review']),
        'current_ids' => array_map('strval', array_keys($buckets['current'])),
        'complete' => $complete,
        'attempts' => $attempts,
        'raw_count' => $rawTotal,
        'duplicates' => $duplicates,
        'rejected' => $rejected,
        'start_date_from' => $startDateFrom,
    ];
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
    $placementHints = [];
    foreach ($items as $ts) {
        if (!is_array($ts)) continue;
        $pid = jobdivaPluckField($ts, ['placementId', 'placement_id', 'startId', 'start_id', 'placement id', 'start id']);
        if ($pid !== '') {
            $placementIds[$pid] = true;
            $placementHints[$pid] = $ts;
        }
    }
    $unique = array_keys($placementIds);

    $records = [];
    $attempts = [];
    foreach ($unique as $pid) {
        try {
            $exact = jobdivaFetchExactAssignmentById(
                $tid,
                (string) $pid,
                $placementHints[(string) $pid] ?? []
            );
            if (($exact['status'] ?? '') === 'verified' && is_array($exact['row'] ?? null)) {
                $records[] = $exact['row'];
                $attempts[] = ['startId' => $pid, 'status' => 'verified', 'count' => 1];
            } else {
                $attempts[] = [
                    'startId' => $pid,
                    'status' => (string) ($exact['status'] ?? 'not_found'),
                    'count' => 0,
                    'error' => (string) ($exact['error'] ?? ''),
                ];
            }
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
 * Orchestrating discovery: run the paginated searchStart census, falling back
 * to timesheet-derived discovery only when the census is empty or incomplete.
 * Returns a normalised
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

    // Channel 1: two independent paginated SearchStart walks. JobDiva's
    // broad-query paging has skipped valid boundary rows in production, so a
    // recent-start walk changes the page boundaries and corroborates the live
    // roster while the broad walk still retains long-running assignments.
    $primary = jobdivaPlacementsFetchViaSearchStart($tid, $opts);
    $corroborationOpts = $opts;
    $corroborationOpts['census_start_date'] = jobdivaPlacementCorroborationStartDate($opts);
    $corroboration = ($corroborationOpts['census_start_date'] === ($primary['start_date_from'] ?? ''))
        ? $primary
        : jobdivaPlacementsFetchViaSearchStart($tid, $corroborationOpts);
    if (!empty($primary['complete'])
        && !empty($corroboration['complete'])
        && (int) ($primary['raw_count'] ?? 0) > 0) {
        $merged = jobdivaPlacementsMergeSearchStartCensuses($primary, $corroboration);
        return [
            'items'   => $merged['items'],
            'terminal_items' => $merged['terminal_items'],
            'review_items' => $merged['review_items'],
            'current_ids' => $merged['current_ids'],
            'authoritative' => true,
            'channel' => 'searchStart_census',
            'diagnostics' => [
                'searchStart_attempts' => array_merge($primary['attempts'], $corroboration['attempts']),
                'primary_searchStart_attempts' => $primary['attempts'],
                'corroboration_searchStart_attempts' => $corroboration['attempts'],
                'items_total'          => count($merged['items']),
                'active'               => count(array_filter($merged['items'], static fn(array $row): bool => ($row['__cf_jobdiva_canonical_status'] ?? '') === 'active')),
                'pending_start'        => count(array_filter($merged['items'], static fn(array $row): bool => ($row['__cf_jobdiva_canonical_status'] ?? '') === 'pending_start')),
                'on_hold'              => count(array_filter($merged['items'], static fn(array $row): bool => ($row['__cf_jobdiva_canonical_status'] ?? '') === 'on_hold')),
                'terminal'             => count($merged['terminal_items']),
                'review'               => count($merged['review_items']),
                'raw_count'            => (int) ($primary['raw_count'] ?? 0) + (int) ($corroboration['raw_count'] ?? 0),
                'complete'             => true,
                'start_date_from'      => (string) ($primary['start_date_from'] ?? ''),
                'corroboration_start_date_from' => (string) ($corroboration['start_date_from'] ?? ''),
            ],
        ];
    }

    // Channel 2: timesheet-derived
    $fallback = jobdivaPlacementsFetchViaTimesheets($tid, $opts);
    $mergedItems = [];
    foreach (array_merge($primary['items'] ?? [], $fallback['items'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $rowId = jobdivaAssignmentRowId($row);
        if ($rowId === '') continue;
        $mergedItems[$rowId] = $row;
    }
    return [
        'items'   => array_values($mergedItems),
        'terminal_items' => [],
        'review_items' => [],
        'current_ids' => [],
        'authoritative' => false,
        'channel' => 'timesheets',
        'diagnostics' => [
            'searchStart_attempts'   => $primary['attempts'],
            'searchStart_yielded'    => count($primary['items'] ?? []),
            'searchStart_complete'   => !empty($primary['complete']),
            'searchStart_raw_count'  => (int) ($primary['raw_count'] ?? 0),
            'corroboration_searchStart_attempts' => $corroboration['attempts'] ?? [],
            'corroboration_searchStart_complete' => !empty($corroboration['complete']),
            'timesheet_discovered_ids' => $fallback['discovered_ids'] ?? [],
            'timesheet_fetch_attempts' => $fallback['attempts'] ?? [],
            'items_total'            => count($mergedItems),
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
 *   - people.classification is derived from explicit JobDiva person/worker
 *     evidence when present, otherwise defaults to 'candidate'. Placement
 *     engagement_type remains the operational source for W2 / C2C workflow
 *     routing.
 *   - source = 'jobdiva' so the People list filter can isolate imports.
 */
function jobdivaPersonClassificationFromPlacementPayload(int $tid, array $jd): string
{
    require_once __DIR__ . '/../integrations/field_map.php';
    $raw = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'person', 'classification', $jd,
        static fn() => jobdivaPluckFieldDeep($jd, [
            'classification', 'workerType', 'worker_type',
            'employmentType', 'employment type', 'employeeType',
            'employee type', 'taxType', 'tax type',
            'payrollType', 'payroll type',
        ])
    );
    $engagement = jobdivaNormalisePlacementEngagementType($raw, '');
    if ($engagement === '') {
        $engagement = jobdivaInferPlacementEngagementTypeFromPayload($jd, '');
    }
    return match ($engagement) {
        'w2', '1099', 'c2c' => $engagement,
        'temp_to_perm' => 'temp',
        'direct_hire' => 'perm',
        default => 'candidate',
    };
}

function jobdivaPersonExternalIdConflicts(string $personExternalId, string $candidateExtId): bool
{
    $personExternalId = trim($personExternalId);
    $candidateExtId = trim($candidateExtId);
    if ($personExternalId === '' || $candidateExtId === '') return false;
    if (stripos($personExternalId, 'jd:') !== 0) return false;
    return substr($personExternalId, 3) !== $candidateExtId;
}

function jobdivaPersonIdentityNormalise(string $value): string
{
    return strtolower((string) preg_replace('/[^a-z0-9]/i', '', trim($value)));
}

/** @return array{name:string,email:string} */
function jobdivaPersonIdentityFromPlacement(array $jd): array
{
    $candidateId = jobdivaPluckField($jd, [
        'candidate id', 'candidateId', 'candidate_id', 'employeeId', 'employee_id',
    ]);
    $name = jobdivaPluckField($jd, [
        'candidateName', 'candidate_name', 'candidate name', 'employeeName', 'employee_name',
    ]);
    if ($name === '') {
        $first = jobdivaPluckField($jd, [
            'candidateFirstName', 'candidate_first_name', 'firstName', 'first_name',
        ]);
        $last = jobdivaPluckField($jd, [
            'candidateLastName', 'candidate_last_name', 'lastName', 'last_name',
        ]);
        $name = trim($first . ' ' . $last);
    }
    $email = jobdivaPluckField($jd, [
        'candidateEmail', 'candidate_email', 'candidate email', 'emailAddress', 'email',
    ]);

    // SearchStart often omits candidate email. Use only joined records whose
    // candidate identity agrees with this Start; never inspect financial or
    // generic joined arrays for person identity.
    foreach (['_jd_candidate', '_jd_start'] as $key) {
        $candidate = $jd[$key] ?? null;
        if (!is_array($candidate)) continue;
        $nestedId = jobdivaPluckField($candidate, [
            'candidate id', 'candidateId', 'candidate_id', 'employeeId', 'employee_id', 'id',
        ]);
        if ($candidateId !== '' && $nestedId !== '' && $nestedId !== $candidateId) continue;
        if ($name === '') {
            $name = jobdivaPluckField($candidate, [
                'candidateName', 'candidate_name', 'candidate name', 'fullName', 'full_name', 'name',
            ]);
            if ($name === '') {
                $name = trim(
                    jobdivaPluckField($candidate, ['firstName', 'first_name', 'candidateFirstName'])
                    . ' '
                    . jobdivaPluckField($candidate, ['lastName', 'last_name', 'candidateLastName'])
                );
            }
        }
        if ($email === '') {
            $email = jobdivaPluckField($candidate, [
                'candidateEmail', 'candidate_email', 'candidate email', 'emailAddress', 'email',
            ]);
        }
    }

    return ['name' => $name, 'email' => strtolower(trim($email))];
}

/**
 * A durable candidate mapping is invalid when both independent human
 * identifiers disagree with the current Start. Requiring both name and email
 * avoids splitting a person after an ordinary name or email change.
 */
function jobdivaPersonIdentityConflicts(array $jd, array $person): bool
{
    $source = jobdivaPersonIdentityFromPlacement($jd);
    $personName = trim((string) ($person['first_name'] ?? '') . ' ' . (string) ($person['last_name'] ?? ''));
    $personEmail = strtolower(trim((string) ($person['email_primary'] ?? '')));
    $sourceName = jobdivaPersonIdentityNormalise($source['name']);
    $mappedName = jobdivaPersonIdentityNormalise($personName);
    if ($sourceName === '' || $mappedName === '' || $sourceName === $mappedName) return false;
    if ($source['email'] === '' || $personEmail === '') return false;
    return $source['email'] !== $personEmail;
}

function jobdivaPlacementsAutoCreatePerson(int $tid, array $jd, ?int $userId): ?int
{
    require_once __DIR__ . '/../integrations/field_map.php';
    $candidateExtId = jobdivaPluckField($jd, [
        'candidate id',
        'candidateId', 'candidate_id', 'employeeId', 'employee_id',
        'candidateID', 'CANDIDATEID', 'EmployeeID', 'personId', 'person_id',
    ]);
    if ($candidateExtId === '') return null;

    $canonicalPersonExternalId = 'jd:' . $candidateExtId;

    // Channel 1: existing mapping. The mapping alone is not sufficient:
    // historical alignment bugs could point candidate A at a person row whose
    // durable external_id belongs to candidate B. Refuse that cross-candidate
    // reuse and let the exact-id/email/create channels repair the mapping.
    $mapping = mappingFindInternal($tid, 'jobdiva', 'person', $candidateExtId);
    if ($mapping) {
        $mappedPersonId = (int) $mapping['internal_entity_id'];
        if ($mappedPersonId > 0) {
            // Lifecycle repair may retire or archive a source-backed person
            // when its placement mapping is temporarily misclassified. A
            // later verified Start is authoritative current-use evidence:
            // restore the same canonical person instead of leaving it hidden
            // or creating a duplicate.
            try {
                $pdo = getDB();
                if ($pdo instanceof \PDO) {
                    $identityStmt = $pdo->prepare(
                        'SELECT external_id, first_name, last_name, email_primary, source FROM people
                          WHERE tenant_id = :t AND id = :id
                          LIMIT 1'
                    );
                    $identityStmt->execute(['t' => $tid, 'id' => $mappedPersonId]);
                    $mappedPerson = $identityStmt->fetch(\PDO::FETCH_ASSOC);
                    $mappedExternalId = is_array($mappedPerson)
                        ? (string) ($mappedPerson['external_id'] ?? '')
                        : '';
                    $identityConflict = is_array($mappedPerson)
                        && jobdivaPersonIdentityConflicts($jd, $mappedPerson);
                    if (!is_array($mappedPerson)
                        || jobdivaPersonExternalIdConflicts($mappedExternalId, $candidateExtId)
                        || $identityConflict) {
                        mappingDelete($tid, 'jobdiva', 'person', $candidateExtId);
                        if ($identityConflict && $mappedExternalId === $canonicalPersonExternalId) {
                            $pdo->prepare(
                                "UPDATE people
                                    SET external_id = NULL, updated_at = NOW()
                                  WHERE tenant_id = :t AND id = :id AND external_id = :ext"
                            )->execute([
                                't' => $tid,
                                'id' => $mappedPersonId,
                                'ext' => $canonicalPersonExternalId,
                            ]);
                        }
                        $mappedPersonId = 0;
                    }
                }
                if ($pdo instanceof \PDO && $mappedPersonId > 0) {
                    $stmt = $pdo->prepare(
                        "UPDATE people
                            SET status = 'active', deleted_at = NULL, updated_at = NOW()
                          WHERE tenant_id = :t
                            AND id = :id
                            AND (status <> 'active' OR deleted_at IS NOT NULL)"
                    );
                    $stmt->execute(['t' => $tid, 'id' => $mappedPersonId]);
                }
            } catch (\Throwable $e) {
                error_log('[jobdiva person sync] lifecycle reactivation failed: ' . $e->getMessage());
            }
            if ($mappedPersonId <= 0) {
                $mapping = null;
            } else {
                // Re-observation also clears stale/deleted mapping state and
                // refreshes the source snapshot used by field mapping.
                mappingUpsert(
                    $tid,
                    'jobdiva',
                    'person',
                    $candidateExtId,
                    $mappedPersonId,
                    $jd,
                    'pull',
                    $userId
                );
            }
        }
        if ($mappedPersonId > 0) return $mappedPersonId;
    }

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
    $classification = jobdivaPersonClassificationFromPlacementPayload($tid, $jd);

    $pdo = getDB();
    // Channel 2: a person already carrying this exact JobDiva candidate ID.
    $stmt = $pdo->prepare(
        'SELECT id FROM people
          WHERE tenant_id = :t AND external_id = :ext
          ORDER BY CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END, id ASC
          LIMIT 1'
    );
    $stmt->execute(['t' => $tid, 'ext' => $canonicalPersonExternalId]);
    $existingId = (int) $stmt->fetchColumn();
    if ($existingId > 0) {
        $pdo->prepare(
            "UPDATE people SET status = 'active', deleted_at = NULL, updated_at = NOW()
              WHERE tenant_id = :t AND id = :id"
        )->execute(['t' => $tid, 'id' => $existingId]);
        mappingUpsert($tid, 'jobdiva', 'person', $candidateExtId, $existingId, $jd, 'pull', $userId);
        return $existingId;
    }

    // Channel 3: existing person by email (covers manual-create-before-sync
    // races), excluding a row explicitly owned by another JobDiva candidate.
    $stmt = $pdo->prepare(
        "SELECT id FROM people
          WHERE tenant_id = :t AND LOWER(email_primary) = LOWER(:e) AND deleted_at IS NULL
            AND (external_id IS NULL OR external_id = '' OR external_id = :ext OR external_id NOT LIKE 'jd:%')
          ORDER BY CASE WHEN external_id = :ext THEN 0 WHEN external_id IS NULL OR external_id = '' THEN 1 ELSE 2 END,
                   id ASC
          LIMIT 1"
    );
    $stmt->execute(['t' => $tid, 'e' => $email, 'ext' => $canonicalPersonExternalId]);
    $existingId = (int) $stmt->fetchColumn();
    if ($existingId > 0) {
        $pdo->prepare(
            "UPDATE people
                SET external_id = CASE WHEN external_id IS NULL OR external_id = '' THEN :ext ELSE external_id END,
                    updated_at = NOW()
              WHERE tenant_id = :t AND id = :id"
        )->execute(['ext' => $canonicalPersonExternalId, 't' => $tid, 'id' => $existingId]);
        // Bind the mapping so future syncs find this person directly.
        mappingUpsert($tid, 'jobdiva', 'person', $candidateExtId, $existingId, $jd, 'pull', $userId);
        try {
            require_once __DIR__ . '/../integrations/field_map_apply.php';
            integrationFieldMapApplyAll($tid, 'jobdiva', 'person', $jd, ['self' => $existingId]);
        } catch (\Throwable $e) {
            error_log('[jobdiva person sync] applyAll failed: ' . $e->getMessage());
        }
        return $existingId;
    }

    // Channel 4: auto-create.
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
        'ext' => $canonicalPersonExternalId,
        'fn'  => $firstName,
        'ln'  => $lastName,
        'em'  => $email,
        'ph'  => $phone !== '' ? $phone : null,
        'cls' => $classification,
        'u'   => $userId,
    ]);
    $newId = (int) $pdo->lastInsertId();
    mappingUpsert($tid, 'jobdiva', 'person', $candidateExtId, $newId, $jd, 'pull', $userId);
    try {
        require_once __DIR__ . '/../integrations/field_map_apply.php';
        integrationFieldMapApplyAll($tid, 'jobdiva', 'person', $jd, ['self' => $newId]);
    } catch (\Throwable $e) {
        error_log('[jobdiva person sync] applyAll failed: ' . $e->getMessage());
    }
    return $newId;
}
