<?php
/**
 * JobDiva Start/Assignment identity contract.
 *
 * A CoreFlux placement may only be projected from a JobDiva Start/Assignment.
 * Candidate, job, contact, application, and inferred job-person payloads are
 * enrichment evidence only and must never acquire placement identity.
 */
declare(strict_types=1);

function jobdivaAssignmentIdentityPluck(array $row, array $candidates): string
{
    $normalised = [];
    foreach ($row as $key => $value) {
        if (!is_string($key) || !is_scalar($value) || $value === null) continue;
        $normalisedKey = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));
        if ($normalisedKey === '' || array_key_exists($normalisedKey, $normalised)) continue;
        $normalised[$normalisedKey] = trim((string) $value);
    }
    foreach ($candidates as $candidate) {
        $normalisedKey = strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $candidate));
        if (($normalised[$normalisedKey] ?? '') !== '') return $normalised[$normalisedKey];
    }
    return '';
}

function jobdivaAssignmentIdentityNormaliseId(mixed $value): string
{
    $id = trim((string) $value);
    if (str_starts_with($id, 'jd:')) $id = substr($id, 3);
    return $id;
}

function jobdivaAssignmentStructuralEvidence(array $row): array
{
    $candidateId = jobdivaAssignmentIdentityPluck($row, [
        'candidate id', 'candidateId', 'candidate_id', 'candidateID', 'CANDIDATEID',
        'employeeId', 'employee_id',
    ]);
    $jobId = jobdivaAssignmentIdentityPluck($row, [
        'job id', 'jobId', 'job_id', 'jobID', 'JOBID', 'reqId', 'req_id',
    ]);
    $startDate = jobdivaAssignmentIdentityPluck($row, [
        'start date', 'startDate', 'start_date', 'startdate',
    ]);
    return [
        'candidate_id' => $candidateId,
        'job_id' => $jobId,
        'start_date' => $startDate,
        'complete' => $candidateId !== '' && $jobId !== '' && $startDate !== '',
    ];
}

function jobdivaAssignmentLifecycleEvidence(array $row, string $channel = ''): array
{
    $statuses = [];
    foreach ([
        'status', 'startStatus', 'start_status', 'start status',
        'placementStatus', 'placement_status',
        'assignmentStatus', 'assignment_status',
        'employeeStatus', 'employee_status',
    ] as $key) {
        $value = jobdivaAssignmentIdentityPluck($row, [$key]);
        if ($value === '') continue;
        $normalised = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', ' ', $value)));
        if ($normalised !== '') $statuses[$normalised] = true;
    }
    $statuses = array_keys($statuses);
    $joined = implode(' | ', $statuses);
    $channel = strtolower(trim($channel));

    foreach ([
        'cancelled', 'canceled', 'rejected', 'withdrawn', 'declined',
        'rescinded', 'deleted', 'void', 'not started', 'did not start',
        'inactive', 'screened', 'submitted', 'interview', 'qualified', 'applicant',
    ] as $needle) {
        if ($joined !== '' && str_contains($joined, $needle)) {
            return [
                'qualified' => false,
                'reason' => 'rejected_assignment_state',
                'statuses' => $statuses,
                'channel' => $channel,
            ];
        }
    }
    // A bare Offer is still a candidate-pipeline row. Offer Accepted is a
    // scheduled Start/Assignment and is intentionally handled below.
    if (in_array('offer', $statuses, true)) {
        return [
            'qualified' => false,
            'reason' => 'offer_not_accepted',
            'statuses' => $statuses,
            'channel' => $channel,
        ];
    }

    $qualifiedExact = [
        'offer accepted', 'accepted offer', 'start accepted', 'start approved',
        'scheduled start', 'pending start', 'ready to start',
        'started', 'active', 'working', 'placed', 'hired', 'on assignment',
        'assignment active', 'completed', 'ended', 'terminated',
    ];
    foreach ($statuses as $status) {
        if (in_array($status, $qualifiedExact, true)
            || str_contains($status, 'on assignment')
            || str_contains($status, 'assignment active')
            || str_contains($status, 'scheduled start')
            || str_contains($status, 'pending start')
            || str_contains($status, 'ready to start')) {
            return [
                'qualified' => true,
                'reason' => 'qualified_assignment_state',
                'statuses' => $statuses,
                'channel' => $channel,
            ];
        }
    }

    // EmployeeAssignmentRecordsDetail is the authoritative JobDiva
    // assignment graph. It may omit status fields on sparse records.
    if (str_contains($channel, 'employee_assignment_records')) {
        return [
            'qualified' => true,
            'reason' => 'authoritative_assignment_endpoint',
            'statuses' => $statuses,
            'channel' => $channel,
        ];
    }

    return [
        'qualified' => false,
        'reason' => $statuses ? 'unqualified_assignment_state' : 'missing_assignment_state',
        'statuses' => $statuses,
        'channel' => $channel,
    ];
}

function jobdivaAssignmentContextEvidence(array $assignment, array $placement): array
{
    $assignmentEvidence = jobdivaAssignmentStructuralEvidence($assignment);
    $placementEvidence = jobdivaAssignmentStructuralEvidence($placement);
    $compared = [];
    $mismatches = [];
    foreach (['candidate_id', 'job_id'] as $key) {
        $assignmentValue = trim((string) ($assignmentEvidence[$key] ?? ''));
        $placementValue = trim((string) ($placementEvidence[$key] ?? ''));
        if ($assignmentValue === '' || $placementValue === '') continue;
        $compared[$key] = [$placementValue, $assignmentValue];
        if ($assignmentValue !== $placementValue) $mismatches[$key] = $compared[$key];
    }
    return [
        'matches' => $mismatches === [],
        'compared' => $compared,
        'mismatches' => $mismatches,
    ];
}

function jobdivaAssignmentRowId(array $row): string
{
    $dedicated = jobdivaAssignmentIdentityPluck($row, [
        'startId', 'start_id', 'startID', 'STARTID',
        'placementId', 'placement_id', 'placementID', 'PLACEMENTID',
    ]);
    if ($dedicated !== '') return jobdivaAssignmentIdentityNormaliseId($dedicated);

    $markerType = strtolower(jobdivaAssignmentIdentityPluck($row, ['__cf_jobdiva_source_object']));
    $markerId = jobdivaAssignmentIdentityPluck($row, ['__cf_jobdiva_assignment_id']);
    if ($markerType === 'assignment' && $markerId !== '') {
        return jobdivaAssignmentIdentityNormaliseId($markerId);
    }

    // JobDiva searchStart commonly calls the Start ID simply "id". Treat that
    // generic key as assignment identity only when the row also carries the
    // candidate + job + start-date tuple unique to a Start/Assignment.
    $evidence = jobdivaAssignmentStructuralEvidence($row);
    if (!empty($evidence['complete'])) {
        return jobdivaAssignmentIdentityNormaliseId(
            jobdivaAssignmentIdentityPluck($row, ['id'])
        );
    }
    return '';
}

function jobdivaAssignmentMarkVerified(array $payload, string $assignmentId, string $channel): array
{
    $assignmentId = jobdivaAssignmentIdentityNormaliseId($assignmentId);
    if ($assignmentId === '') return $payload;
    $payload['__cf_jobdiva_source_object'] = 'assignment';
    $payload['__cf_jobdiva_assignment_id'] = $assignmentId;
    $payload['__cf_jobdiva_assignment_verified_by'] = trim($channel) ?: 'unknown';
    return $payload;
}

function jobdivaAssignmentSanitisePayload(array $payload, ?string $expectedId = null): array
{
    $expectedId = jobdivaAssignmentIdentityNormaliseId(
        $expectedId ?? jobdivaAssignmentRowId($payload)
    );
    foreach (['_jd_start', 'assignment', 'start', 'Start', 'jobdiva_assignment'] as $key) {
        if (!isset($payload[$key]) || !is_array($payload[$key])) continue;
        $nested = $payload[$key];
        $nestedId = jobdivaAssignmentRowId($nested);
        $identityMismatch = $expectedId !== '' && $nestedId !== '' && $nestedId !== $expectedId;
        $context = jobdivaAssignmentContextEvidence($nested, $payload);
        if ($identityMismatch || empty($context['matches'])) unset($payload[$key]);
    }
    return $payload;
}

/**
 * @return array{valid:bool,assignment_id:string,expected_id:string,reason:string,channel:string,evidence:array}
 */
function jobdivaAssignmentValidate(array $payload, ?string $expectedId = null, ?string $channel = null): array
{
    $expectedId = jobdivaAssignmentIdentityNormaliseId($expectedId ?? '');
    $records = [['label' => 'root', 'row' => $payload]];
    foreach (['_jd_start', 'assignment', 'start', 'Start', 'jobdiva_assignment'] as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            $records[] = ['label' => $key, 'row' => $payload[$key]];
        }
    }

    $mismatches = [];
    $lifecycleFailures = [];
    foreach ($records as $record) {
        $row = $record['row'];
        $id = jobdivaAssignmentRowId($row);
        if ($id === '') continue;
        if ($expectedId !== '' && $id !== $expectedId) {
            $mismatches[] = $id;
            continue;
        }

        $markerType = strtolower(jobdivaAssignmentIdentityPluck($row, ['__cf_jobdiva_source_object']));
        $markerChannel = jobdivaAssignmentIdentityPluck($row, ['__cf_jobdiva_assignment_verified_by']);
        if ($markerType === '' && $record['label'] === 'root') {
            $markerType = strtolower(jobdivaAssignmentIdentityPluck($payload, ['__cf_jobdiva_source_object']));
            $markerChannel = jobdivaAssignmentIdentityPluck($payload, ['__cf_jobdiva_assignment_verified_by']);
        }
        $evidence = jobdivaAssignmentStructuralEvidence($row);
        if ($markerType === 'assignment' || !empty($evidence['complete'])) {
            $effectiveChannel = trim((string) ($channel ?? ''));
            if ($effectiveChannel === '') {
                $effectiveChannel = $markerChannel !== '' ? $markerChannel : (string) $record['label'];
            }
            $lifecycle = jobdivaAssignmentLifecycleEvidence($row, $effectiveChannel);
            if (empty($lifecycle['qualified'])) {
                $lifecycleFailures[] = $lifecycle;
                // The root Start row owns placement eligibility. Nested mirrors
                // may enrich it, but must never promote an offer or cancelled row.
                if ((string) $record['label'] === 'root') {
                    break;
                }
                continue;
            }
            return [
                'valid' => true,
                'assignment_id' => $id,
                'expected_id' => $expectedId,
                'reason' => 'verified_assignment',
                'channel' => $effectiveChannel,
                'evidence' => [
                    'structural' => $evidence,
                    'lifecycle' => $lifecycle,
                ],
            ];
        }
    }

    return [
        'valid' => false,
        'assignment_id' => '',
        'expected_id' => $expectedId,
        'reason' => $lifecycleFailures
            ? (string) ($lifecycleFailures[0]['reason'] ?? 'unqualified_assignment_state')
            : ($mismatches
                ? 'assignment_id_mismatch:' . implode(',', array_values(array_unique($mismatches)))
                : 'missing_assignment_identity_evidence'),
        'channel' => '',
        'evidence' => $lifecycleFailures
            ? ['lifecycle' => $lifecycleFailures[0]]
            : [],
    ];
}

/**
 * Decide how reconciliation should treat a placement when the exact-source
 * lookup and the last source-backed snapshot disagree.
 *
 * Empty/error responses are not deletion evidence. A previously captured,
 * structurally valid Start remains authoritative until JobDiva echoes the
 * same Start ID with an explicit terminal lifecycle state.
 *
 * @return array{action:string,reason:string}
 */
function jobdivaAssignmentSourceDecision(
    array $remote,
    array $storedValidation,
    string $expectedId,
    bool $storedObservedInLatestSync = false
): array {
    $expectedId = jobdivaAssignmentIdentityNormaliseId($expectedId);
    $remoteStatus = strtolower(trim((string) ($remote['status'] ?? 'error')));
    if ($remoteStatus === 'verified' && is_array($remote['row'] ?? null)) {
        return ['action' => 'remote_verified', 'reason' => 'exact_source_assignment'];
    }

    if (!empty($storedValidation['valid']) && $storedObservedInLatestSync) {
        return ['action' => 'trusted_stored', 'reason' => 'stored_source_assignment'];
    }

    $seenIds = [];
    foreach ((array) ($remote['seen_ids'] ?? []) as $seenId) {
        $seenId = jobdivaAssignmentIdentityNormaliseId($seenId);
        if ($seenId !== '') $seenIds[$seenId] = true;
    }
    $remoteReason = (string) (($remote['identity']['reason'] ?? '') ?: '');
    $terminalReasons = ['rejected_assignment_state', 'offer_not_accepted'];
    if ($remoteStatus === 'not_assignment'
        && $expectedId !== ''
        && isset($seenIds[$expectedId])
        && in_array($remoteReason, $terminalReasons, true)) {
        return ['action' => 'terminal', 'reason' => $remoteReason];
    }

    return [
        'action' => 'review',
        'reason' => $remoteStatus === 'not_found'
            ? 'source_lookup_inconclusive'
            : ($remoteReason !== '' ? $remoteReason : 'source_verification_failed'),
    ];
}

function jobdivaAssignmentObservedInLatestSync(
    array $storedValidation,
    ?string $mappingLastSeenAt,
    ?string $connectionLastSyncAt,
    int $toleranceSeconds = 21600
): bool {
    if (empty($storedValidation['valid'])) return false;

    $lastSeen = strtotime(trim((string) $mappingLastSeenAt));
    $lastSync = strtotime(trim((string) $connectionLastSyncAt));
    if ($lastSeen === false || $lastSync === false) return false;

    return $lastSeen >= ($lastSync - max(0, $toleranceSeconds));
}

function jobdivaAssignmentRowsFromResponse(mixed $response): array
{
    if (!is_array($response)) return [];
    foreach (['data', 'items', 'starts', 'records', 'results'] as $key) {
        if (!isset($response[$key]) || !is_array($response[$key])) continue;
        $rows = $response[$key];
        if ($rows === []) return [];
        return array_keys($rows) === range(0, count($rows) - 1) ? $rows : [$rows];
    }
    if ($response !== [] && array_keys($response) === range(0, count($response) - 1)) return $response;
    if ($response !== []) return [$response];
    return [];
}
