<?php
/**
 * CoreStaffing — Weekly Timesheet helpers.
 *
 * The header (`timesheets`) is the unit of submission/approval. Detail
 * rows live in `time_entries` (extended with timesheet_id + hour_type
 * per migration 002).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../core/tenant_scope.php';
require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../core/sub_tenants.php';
require_once __DIR__ . '/../../../core/tx_helpers.php';
require_once __DIR__ . '/../../../core/audit.php';
require_once __DIR__ . '/../../../core/ai/artifacts.php';
require_once __DIR__ . '/../../time/lib/time.php';
require_once __DIR__ . '/dimensions.php';

const STAFFING_HOUR_TYPES = [
    'regular','overtime','doubletime','holiday','pto','sick','bereavement','unpaid','nonbillable',
];

// hour_type → legacy time_entries.category mapping (for downstream feeds that
// still read `category`). Keeps the new model writeable without breaking
// settlement/AR/AP modules that haven't been migrated yet.
const STAFFING_HOUR_TYPE_TO_CATEGORY = [
    'regular'     => 'regular_billable',
    'overtime'    => 'OT_billable',
    'doubletime'  => 'OT_billable',
    'holiday'     => 'holiday',
    'pto'         => 'vacation',
    'sick'        => 'sick',
    'bereavement' => 'bereavement',
    'unpaid'      => 'unpaid_leave',
    'nonbillable' => 'regular_nonbillable',
];

/** Resolve the staffing settings for the current tenant (with defaults). */
function staffingSettings(): array {
    $row = scopedFind('SELECT * FROM tenant_staffing_settings WHERE tenant_id = :tenant_id LIMIT 1');
    return [
        'week_starts_on'             => (int) ($row['week_starts_on'] ?? 1),  // 0=Sun, 1=Mon
        'contracted_hours_per_week'  => (float) ($row['contracted_hours_per_week'] ?? 40.0),
        'overtime_threshold'         => (float) ($row['overtime_threshold'] ?? 40.0),
    ];
}

function staffingPeopleTenantId(?int $tenantId = null): ?int
{
    $tenantId = $tenantId ?? currentTenantId();
    if (!$tenantId) return null;
    try {
        return effectiveTenantIdForModule('people', $tenantId) ?? $tenantId;
    } catch (\Throwable $_) {
        return $tenantId;
    }
}

function staffingPlacementsTenantId(?int $tenantId = null): ?int
{
    $tenantId = $tenantId ?? currentTenantId();
    if (!$tenantId) return null;
    try {
        return effectiveTenantIdForModule('placements', $tenantId) ?? $tenantId;
    } catch (\Throwable $_) {
        return $tenantId;
    }
}

function staffingTimesheetDate(string $value, string $label): string
{
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new \RuntimeException("{$label} must be a valid YYYY-MM-DD date");
    }
    return $value;
}

function staffingTimesheetValidateWeek(int $personId, string $periodStart, string $periodEnd): void
{
    if ($personId <= 0) throw new \RuntimeException('person_id required');
    staffingTimesheetDate($periodStart, 'period_start');
    staffingTimesheetDate($periodEnd, 'period_end');
    $start = new \DateTimeImmutable($periodStart);
    $end = new \DateTimeImmutable($periodEnd);
    if ((int) $start->diff($end)->format('%r%a') !== 6) {
        throw new \RuntimeException('A weekly timesheet must cover exactly 7 consecutive days');
    }

    $peopleTenantId = staffingPeopleTenantId();
    $stmt = getDB()->prepare(
        'SELECT id FROM people WHERE tenant_id = :tenant_id AND id = :id AND deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute(['tenant_id' => $peopleTenantId, 'id' => $personId]);
    if (!$stmt->fetchColumn()) throw new \RuntimeException("Person #{$personId} was not found");
}

function staffingTimesheetValidatePlacement(int $placementId, int $personId, string $workDate): void
{
    if ($placementId <= 0) throw new \RuntimeException('placement_id required');
    staffingTimesheetDate($workDate, 'work_date');
    $stmt = getDB()->prepare(
        'SELECT id, person_id, start_date, end_date
           FROM placements
          WHERE tenant_id = :tenant_id AND id = :id AND deleted_at IS NULL
          LIMIT 1'
    );
    $stmt->execute(['tenant_id' => staffingPlacementsTenantId(), 'id' => $placementId]);
    $placement = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    if (!$placement) throw new \RuntimeException("Placement #{$placementId} was not found");
    if ((int) $placement['person_id'] !== $personId) {
        throw new \RuntimeException("Placement #{$placementId} belongs to a different worker");
    }
    if ($workDate < (string) $placement['start_date']) {
        throw new \RuntimeException("{$workDate} is before placement #{$placementId} starts");
    }
    if (!empty($placement['end_date']) && $workDate > (string) $placement['end_date']) {
        throw new \RuntimeException("{$workDate} is after placement #{$placementId} ends");
    }
}

function staffingTimesheetAssertWorkDateInWeek(string $workDate, string $periodStart, string $periodEnd): void
{
    if ($workDate < $periodStart || $workDate > $periodEnd) {
        throw new \RuntimeException("{$workDate} is outside this timesheet week ({$periodStart} through {$periodEnd})");
    }
}

function staffingTimesheetAssertEntryNotSettled(array $entry): void
{
    $destinations = [];
    if (!empty($entry['bill_extracted_at']) || !empty($entry['bill_extracted_ref'])) $destinations[] = 'billing';
    if (!empty($entry['ap_extracted_at']) || !empty($entry['ap_extracted_ref'])) $destinations[] = 'accounts payable';
    if (!empty($entry['payroll_extracted_at']) || !empty($entry['payroll_extracted_ref'])) $destinations[] = 'payroll';
    if ($destinations) {
        throw new \RuntimeException(
            'This time entry is already used in ' . implode(', ', $destinations)
            . '. Correct or reverse the downstream record before changing the source time.'
        );
    }
}

function staffingTimesheetAssertDailyHours(
    int $personId,
    string $workDate,
    float $hours,
    int $excludeEntryId = 0
): void {
    if (!is_finite($hours) || $hours <= 0 || $hours > 24) {
        throw new \RuntimeException('hours must be greater than 0 and no more than 24');
    }
    $params = ['pid' => $personId, 'wd' => $workDate];
    $exclude = '';
    if ($excludeEntryId > 0) {
        $exclude = ' AND id != :exclude_id';
        $params['exclude_id'] = $excludeEntryId;
    }
    $sum = scopedFind(
        "SELECT COALESCE(SUM(hours), 0) AS h
           FROM time_entries
          WHERE tenant_id = :tenant_id
            AND person_id = :pid
            AND work_date = :wd
            AND status != 'superseded'{$exclude}",
        $params
    );
    if ((float) ($sum['h'] ?? 0) + $hours > 24.0) {
        throw new \RuntimeException("Total hours for {$workDate} would exceed 24");
    }
}

function staffingTimesheetDisplayId(int $timesheetId): string
{
    return 'TS-' . $timesheetId;
}

function staffingTimesheetDecorate(array $header): array
{
    if (!empty($header['id'])) {
        $header['display_id'] = staffingTimesheetDisplayId((int) $header['id']);
    }
    return $header;
}

function staffingTimesheetArtifactStatus(string $status): string
{
    return match ($status) {
        'submitted' => 'review',
        'approved', 'payroll_ready', 'billing_ready' => 'approved',
        'locked' => 'final',
        'rejected' => 'rejected',
        default => 'draft',
    };
}

function staffingTimesheetArtifactPayload(array $header): array
{
    return [
        'timesheet_id'  => (int) $header['id'],
        'display_id'    => staffingTimesheetDisplayId((int) $header['id']),
        'person_id'     => (int) $header['person_id'],
        'period_start'  => (string) $header['period_start'],
        'period_end'    => (string) $header['period_end'],
        'status'        => (string) ($header['status'] ?? 'draft'),
        'total_hours'   => (float) ($header['total_hours'] ?? 0),
        'origin_source' => (string) ($header['origin_source'] ?? 'manual_entry'),
        'origin_system' => $header['origin_system'] ?? null,
    ];
}

function staffingTimesheetRow(int $tenantId, int $timesheetId, bool $forUpdate = false): ?array
{
    $stmt = getDB()->prepare(
        'SELECT * FROM staffing_timesheets
          WHERE tenant_id = :tenant_id AND id = :id
          LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
    );
    $stmt->execute(['tenant_id' => $tenantId, 'id' => $timesheetId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    return $row ? staffingTimesheetDecorate($row) : null;
}

/** Ensure the domain header has exactly one corresponding artifact object. */
function staffingTimesheetEnsureArtifact(array $header, array $context = []): array
{
    $tenantId = (int) ($context['tenant_id'] ?? $header['tenant_id'] ?? currentTenantId());
    $timesheetId = (int) ($header['id'] ?? 0);
    if ($tenantId <= 0 || $timesheetId <= 0) {
        throw new \RuntimeException('A tenant and timesheet are required to materialize the artifact.');
    }

    $pdo = getDB();
    $ownsTxn = cf_tx_begin($pdo);
    try {
        $locked = staffingTimesheetRow($tenantId, $timesheetId, true);
        if (!$locked) throw new \RuntimeException("Timesheet #{$timesheetId} was not found.");

        $artifactId = trim((string) ($locked['artifact_id'] ?? ''));
        if ($artifactId === '') {
            $artifactId = artifactGenerateUuid();
            $stmt = $pdo->prepare(
                'UPDATE staffing_timesheets SET artifact_id = :artifact_id
                  WHERE tenant_id = :tenant_id AND id = :id
                    AND (artifact_id IS NULL OR artifact_id = "")'
            );
            $stmt->execute([
                'artifact_id' => $artifactId,
                'tenant_id' => $tenantId,
                'id' => $timesheetId,
            ]);
            if ($stmt->rowCount() !== 1) {
                $locked = staffingTimesheetRow($tenantId, $timesheetId, true);
                $artifactId = trim((string) ($locked['artifact_id'] ?? ''));
            } else {
                $locked['artifact_id'] = $artifactId;
            }
        }
        if ($artifactId === '') throw new \RuntimeException("Timesheet #{$timesheetId} has no artifact identity.");

        $artifact = artifactGet($tenantId, $artifactId);
        $artifactWasCreated = false;
        if (!$artifact) {
            $artifact = artifactCreate($tenantId, 'staffing_timesheet', [
                'id' => $artifactId,
                'title' => staffingTimesheetDisplayId($timesheetId) . ' - week of ' . $locked['period_start'],
                'source_module' => 'staffing',
                'source_record_type' => 'staffing_timesheet',
                'source_record_id' => $timesheetId,
                'payload' => staffingTimesheetArtifactPayload($locked),
                'created_by_user_id' => $locked['created_by_user_id'] ?? ($context['created_by_user_id'] ?? null),
                'initial_status' => staffingTimesheetArtifactStatus((string) ($locked['status'] ?? 'draft')),
            ]);
            $artifactWasCreated = true;
        }

        $link = $pdo->prepare(
            "INSERT INTO artifact_relationships
                (tenant_id, source_artifact_id, target_table, target_record_id,
                 relationship_type, created_by_user_id, created_at)
             SELECT :tenant_id, :artifact_id, 'staffing_timesheets', :timesheet_id,
                    'represents', :created_by_user_id, NOW()
              WHERE NOT EXISTS (
                    SELECT 1 FROM artifact_relationships
                     WHERE tenant_id = :tenant_check
                       AND source_artifact_id = :artifact_check
                       AND target_table = 'staffing_timesheets'
                       AND target_record_id = :timesheet_check
                       AND relationship_type = 'represents'
              )"
        );
        $link->execute([
            'tenant_id' => $tenantId,
            'artifact_id' => $artifactId,
            'timesheet_id' => $timesheetId,
            'created_by_user_id' => $locked['created_by_user_id'] ?? ($context['created_by_user_id'] ?? null),
            'tenant_check' => $tenantId,
            'artifact_check' => $artifactId,
            'timesheet_check' => $timesheetId,
        ]);

        if ($artifactWasCreated) {
            artifactWriteEvent($tenantId, $artifactId, 'timesheet.materialized', [
                'prior_status' => null,
                'new_status' => $artifact['status'] ?? 'draft',
                'actor_user_id' => $context['created_by_user_id'] ?? null,
                'payload' => [
                    'timesheet_id' => $timesheetId,
                    'origin_source' => $locked['origin_source'] ?? null,
                    'origin_system' => $locked['origin_system'] ?? null,
                ],
            ]);
            platformAuditLogWrite($tenantId, $context['created_by_user_id'] ?? null, 'staffing.timesheet.created', $timesheetId, [
                'artifact_id' => $artifactId,
                'display_id' => staffingTimesheetDisplayId($timesheetId),
                'person_id' => (int) $locked['person_id'],
                'period_start' => $locked['period_start'],
                'period_end' => $locked['period_end'],
                'source' => $locked['origin_source'] ?? null,
                'source_system' => $locked['origin_system'] ?? null,
            ], ['object_type' => 'staffing_timesheet', 'source' => 'staffing']);
        }

        cf_tx_commit($pdo, $ownsTxn);
        $locked['artifact_id'] = $artifactId;
        $locked['artifact_status'] = $artifact['status'] ?? null;
        $locked['artifact_version'] = isset($artifact['version']) ? (int) $artifact['version'] : null;
        return staffingTimesheetDecorate($locked);
    } catch (\Throwable $e) {
        cf_tx_rollback($pdo, $ownsTxn);
        throw $e;
    }
}

/** Find a legal path through the generic artifact lifecycle state machine. */
function staffingTimesheetArtifactTransitionPath(string $from, string $to): array
{
    if ($from === $to) return [];
    $queue = [[$from, []]];
    $seen = [$from => true];
    while ($queue) {
        [$status, $path] = array_shift($queue);
        foreach (ARTIFACT_TRANSITIONS[$status] ?? [] as $next) {
            if (isset($seen[$next])) continue;
            $nextPath = array_merge($path, [$next]);
            if ($next === $to) return $nextPath;
            $seen[$next] = true;
            $queue[] = [$next, $nextPath];
        }
    }
    throw new \RuntimeException("Artifact lifecycle cannot move from {$from} to {$to}.");
}

/** Refresh artifact payload/lifecycle and append a domain event. */
function staffingTimesheetRecordArtifactEvent(
    int $timesheetId,
    string $eventType,
    ?int $actorUserId = null,
    array $eventPayload = [],
    ?int $tenantId = null
): array {
    $tenantId = (int) ($tenantId ?? currentTenantId());
    $header = staffingTimesheetRow($tenantId, $timesheetId);
    if (!$header) throw new \RuntimeException("Timesheet #{$timesheetId} was not found.");
    $header = staffingTimesheetEnsureArtifact($header, [
        'tenant_id' => $tenantId,
        'created_by_user_id' => $actorUserId,
    ]);
    $artifactId = (string) $header['artifact_id'];
    $artifact = artifactGet($tenantId, $artifactId);
    if (!$artifact) throw new \RuntimeException("Artifact {$artifactId} was not found.");

    $targetStatus = staffingTimesheetArtifactStatus((string) ($header['status'] ?? 'draft'));
    foreach (staffingTimesheetArtifactTransitionPath((string) $artifact['status'], $targetStatus) as $nextStatus) {
        $artifact = artifactTransition($tenantId, $artifactId, $nextStatus, $actorUserId, null, [
            'timesheet_id' => $timesheetId,
            'timesheet_status' => $header['status'],
        ]);
    }
    $artifact = artifactUpdate($tenantId, $artifactId, [
        'title' => staffingTimesheetDisplayId($timesheetId) . ' - week of ' . $header['period_start'],
        'payload' => staffingTimesheetArtifactPayload($header),
    ], $actorUserId);
    artifactWriteEvent($tenantId, $artifactId, $eventType, [
        'prior_status' => $artifact['status'],
        'new_status' => $artifact['status'],
        'actor_user_id' => $actorUserId,
        'payload' => array_merge([
            'timesheet_id' => $timesheetId,
            'display_id' => staffingTimesheetDisplayId($timesheetId),
        ], $eventPayload),
    ]);
    platformAuditLogWrite($tenantId, $actorUserId, 'staffing.' . $eventType, $timesheetId, array_merge([
        'artifact_id' => $artifactId,
        'display_id' => staffingTimesheetDisplayId($timesheetId),
    ], $eventPayload), ['object_type' => 'staffing_timesheet', 'source' => 'staffing']);

    $header['artifact_status'] = $artifact['status'];
    $header['artifact_version'] = (int) $artifact['version'];
    return staffingTimesheetDecorate($header);
}

/**
 * Get or create the first-class timesheet artifact for one person/week.
 * Every ingestion path must use this function so manual, CSV, document, and
 * integration-created weeks have identical identity and lifecycle behavior.
 */
function staffingTimesheetUpsert(
    int $personId,
    string $periodStart,
    string $periodEnd,
    array $context = []
): array {
    $tenantId = (int) ($context['tenant_id'] ?? currentTenantId());
    if ($tenantId <= 0) throw new \RuntimeException('A tenant is required to create a timesheet.');
    if ($personId <= 0) throw new \RuntimeException('A person is required to create a timesheet.');
    staffingTimesheetDate($periodStart, 'period_start');
    staffingTimesheetDate($periodEnd, 'period_end');
    $start = new \DateTimeImmutable($periodStart);
    $end = new \DateTimeImmutable($periodEnd);
    if ((int) $start->diff($end)->format('%r%a') !== 6) {
        throw new \RuntimeException('A weekly timesheet must cover exactly 7 consecutive days');
    }
    $source = substr(trim((string) ($context['source'] ?? 'manual_entry')), 0, 40) ?: 'manual_entry';
    $sourceSystem = substr(trim((string) ($context['source_system'] ?? '')), 0, 80) ?: null;
    $createdBy = isset($context['created_by_user_id']) && (int) $context['created_by_user_id'] > 0
        ? (int) $context['created_by_user_id']
        : null;
    $pdo = getDB();
    $ownsTxn = cf_tx_begin($pdo);
    try {
        $find = $pdo->prepare(
            'SELECT * FROM staffing_timesheets
              WHERE tenant_id = :tenant_id AND person_id = :person_id AND period_start = :period_start
              LIMIT 1 FOR UPDATE'
        );
        $find->execute(['tenant_id' => $tenantId, 'person_id' => $personId, 'period_start' => $periodStart]);
        $header = $find->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$header) {
            $artifactId = artifactGenerateUuid();
            try {
                $insert = $pdo->prepare(
                    'INSERT INTO staffing_timesheets
                        (tenant_id, artifact_id, person_id, period_start, period_end,
                         status, total_hours, origin_source, origin_system,
                         created_by_user_id, created_at, updated_at)
                     VALUES
                        (:tenant_id, :artifact_id, :person_id, :period_start, :period_end,
                         "draft", 0, :origin_source, :origin_system,
                         :created_by_user_id, NOW(), NOW())'
                );
                $insert->execute([
                    'tenant_id' => $tenantId,
                    'artifact_id' => $artifactId,
                    'person_id' => $personId,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'origin_source' => $source,
                    'origin_system' => $sourceSystem,
                    'created_by_user_id' => $createdBy,
                ]);
                $header = staffingTimesheetRow($tenantId, (int) $pdo->lastInsertId(), true);
            } catch (\PDOException $e) {
                if ((string) $e->getCode() !== '23000') throw $e;
                $find->execute(['tenant_id' => $tenantId, 'person_id' => $personId, 'period_start' => $periodStart]);
                $header = $find->fetch(\PDO::FETCH_ASSOC) ?: null;
            }
        }
        if (!$header) throw new \RuntimeException('Could not create or load the weekly timesheet.');
        if ((string) $header['period_end'] !== $periodEnd) {
            throw new \RuntimeException(
                "The existing timesheet for {$periodStart} ends on {$header['period_end']}, not {$periodEnd}."
            );
        }
        $header = staffingTimesheetEnsureArtifact($header, [
            'tenant_id' => $tenantId,
            'created_by_user_id' => $createdBy,
        ]);
        cf_tx_commit($pdo, $ownsTxn);
        return staffingTimesheetDecorate($header);
    } catch (\Throwable $e) {
        cf_tx_rollback($pdo, $ownsTxn);
        throw $e;
    }
}

function staffingTimesheetFind(int $personId, string $periodStart): ?array
{
    $row = scopedFind(
        'SELECT * FROM staffing_timesheets WHERE tenant_id = :tenant_id AND person_id = :pid AND period_start = :ps LIMIT 1',
        ['pid' => $personId, 'ps' => $periodStart]
    );
    return $row ? staffingTimesheetDecorate($row) : null;
}

/** Snapshot of a worker's week — header + grouped entries by placement × day. */
function staffingTimesheetWeek(int $personId, string $periodStart, string $periodEnd): array {
    staffingTimesheetValidateWeek($personId, $periodStart, $periodEnd);
    $header = staffingTimesheetFind($personId, $periodStart) ?? [
        'id' => null,
        'person_id' => $personId,
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'status' => 'draft',
        'total_hours' => 0.0,
    ];

    $entries = scopedQuery(
        "SELECT te.id, te.placement_id, te.work_date, te.hour_type, te.category,
                te.hours, te.billable, te.payable, te.description, te.status,
                p.title AS placement_title,
                COALESCE(p.end_client_name, '') AS client_name
           FROM time_entries te
           LEFT JOIN placements p ON p.id = te.placement_id AND p.tenant_id = :placements_tid
          WHERE te.tenant_id = :tenant_id
            AND te.person_id = :pid
            AND te.work_date BETWEEN :ps AND :pe
            AND te.status != 'superseded'
          ORDER BY te.placement_id, te.work_date, te.id",
        ['pid' => $personId, 'ps' => $periodStart, 'pe' => $periodEnd, 'placements_tid' => staffingPlacementsTenantId()]
    );

    return [
        'timesheet' => $header,
        'entries'   => $entries,
    ];
}

/**
 * Bulk save draft entries for a week.
 *
 * Input payload:
 *   [
 *     'period_start' => 'YYYY-MM-DD',
 *     'period_end'   => 'YYYY-MM-DD',
 *     'person_id'    => 123,
 *     'rows' => [
 *       [
 *         'id'           => 42|null,         // null = create
 *         'placement_id' => 7,
 *         'work_date'    => 'YYYY-MM-DD',
 *         'hour_type'    => 'regular'|...,
 *         'hours'        => 8.0,
 *         'description'  => '…' | null,
 *         '_delete'      => true|false,
 *       ], ...
 *     ]
 *   ]
 *
 * Returns the refreshed week snapshot.
 */
function staffingTimesheetBulkSave(int $userId, array $payload): array {
    $personId    = (int) ($payload['person_id'] ?? 0);
    $periodStart = (string) ($payload['period_start'] ?? '');
    $periodEnd   = (string) ($payload['period_end'] ?? '');
    $rows        = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];

    staffingTimesheetValidateWeek($personId, $periodStart, $periodEnd);

    $header = staffingTimesheetUpsert($personId, $periodStart, $periodEnd, [
        'source' => 'manual_entry',
        'created_by_user_id' => $userId,
    ]);
    $headerId = (int) $header['id'];

    $pdo = getDB();
    $ownsTxn = cf_tx_begin($pdo);
    try {
        if (($header['status'] ?? 'draft') !== 'draft') {
            $header = staffingTimesheetReopen($userId, $headerId, 'bulk edit');
        }
        foreach ($rows as $r) {
            $hourType = $r['hour_type'] ?? 'regular';
            if (!in_array($hourType, STAFFING_HOUR_TYPES, true)) {
                throw new \RuntimeException("Invalid hour_type: {$hourType}");
            }
            if (isset($r['hours']) && !is_numeric($r['hours'])) {
                throw new \RuntimeException('hours must be numeric');
            }
            $hours = isset($r['hours']) ? (float) $r['hours'] : 0.0;
            $entryId = (int) ($r['id'] ?? 0);
            $existing = null;
            if ($entryId > 0) {
                $existing = scopedFind(
                    'SELECT * FROM time_entries WHERE tenant_id = :tenant_id AND id = :id LIMIT 1',
                    ['id' => $entryId]
                );
                if (!$existing || (int) ($existing['timesheet_id'] ?? 0) !== $headerId || (int) ($existing['person_id'] ?? 0) !== $personId) {
                    throw new \RuntimeException("Time entry #{$entryId} does not belong to this timesheet");
                }
                staffingTimesheetAssertEntryNotSettled($existing);
            }

            if (!empty($r['_delete']) && $entryId > 0) {
                scopedDelete('time_entries', $entryId);
                continue;
            }

            // Allow zero-hours rows to be skipped (no need to persist empty cells).
            if ($hours < 0) throw new \RuntimeException('hours cannot be negative');
            if ($hours <= 0 && empty($r['id'])) continue;
            // Zero-hours on existing row = delete.
            if ($hours <= 0 && $entryId > 0) {
                scopedDelete('time_entries', $entryId);
                continue;
            }

            $placementId = (int) ($r['placement_id'] ?? 0);
            $workDate    = (string) ($r['work_date'] ?? '');
            if ($placementId <= 0 || $workDate === '') {
                throw new \RuntimeException('placement_id and work_date are required for every positive-hour row');
            }
            staffingTimesheetAssertWorkDateInWeek($workDate, $periodStart, $periodEnd);
            staffingTimesheetValidatePlacement($placementId, $personId, $workDate);
            staffingTimesheetAssertDailyHours($personId, $workDate, $hours, $entryId);

            // Resolve period_id (legacy NOT-NULL column on time_entries).
            // Distinct :wd_lo/:wd_hi to satisfy PDO_MYSQL native prepares.
            $period = scopedFind(
                "SELECT id FROM time_periods
                  WHERE tenant_id = :tenant_id AND start_date <= :wd_lo AND end_date >= :wd_hi AND status != 'closed'
                  ORDER BY start_date DESC LIMIT 1",
                ['wd_lo' => $workDate, 'wd_hi' => $workDate]
            );
            if (!$period) {
                // Auto-create a weekly period if missing — keeps the UX flowing
                // for tenants who haven't pre-seeded periods.
                $pid = scopedInsert('time_periods', [
                    'period_type' => 'weekly',
                    'start_date'  => $periodStart,
                    'end_date'    => $periodEnd,
                    'label'       => "Week of {$periodStart}",
                    'status'      => 'open',
                ]);
            } else {
                $pid = (int) $period['id'];
            }

            $category = STAFFING_HOUR_TYPE_TO_CATEGORY[$hourType] ?? 'regular_billable';
            $billable = in_array($hourType, ['regular','overtime','doubletime'], true) ? 1 : 0;
            $payable  = in_array($hourType, ['nonbillable'], true) ? 1 : 1;

            $base = [
                'placement_id' => $placementId,
                'person_id'    => $personId,
                'period_id'    => $pid,
                'timesheet_id' => $headerId,
                'work_date'    => $workDate,
                'hour_type'    => $hourType,
                'category'     => $category,
                'hours'        => $hours,
                'billable'     => $billable,
                'payable'      => $payable,
                'description'  => $r['description'] ?? null,
                'source'       => 'manual_entry',
                'status'       => 'draft',
            ];

            if ($entryId > 0) {
                scopedUpdate('time_entries', $entryId, $base);
            } else {
                $base['created_by_user_id'] = $userId;
                scopedInsert('time_entries', $base);
            }
        }

        // Recompute total_hours on the header.
        $sum = scopedFind(
            "SELECT COALESCE(SUM(hours), 0) AS h FROM time_entries
              WHERE tenant_id = :tenant_id AND timesheet_id = :tid AND status != 'superseded'",
            ['tid' => $headerId]
        );
        scopedUpdate('staffing_timesheets', $headerId, ['total_hours' => (float) ($sum['h'] ?? 0)]);
        staffingTimesheetRecordArtifactEvent($headerId, 'timesheet.entries_saved', $userId, [
            'entry_count' => count($rows),
            'source' => 'manual_entry',
        ]);

        cf_tx_commit($pdo, $ownsTxn);
    } catch (\Throwable $e) {
        cf_tx_rollback($pdo, $ownsTxn);
        throw $e;
    }

    return staffingTimesheetWeek($personId, $periodStart, $periodEnd);
}

/** Submit the whole week → flips header + all rows to submitted/pending_review. */
function staffingTimesheetSubmit(int $userId, int $personId, string $periodStart, string $periodEnd): array {
    staffingTimesheetValidateWeek($personId, $periodStart, $periodEnd);
    $header = staffingTimesheetFind($personId, $periodStart);
    if (!$header) throw new \RuntimeException('Add at least one positive-hour entry before submitting this timesheet.');
    if (!in_array($header['status'], ['draft','rejected'], true)) {
        throw new \RuntimeException("Cannot submit a {$header['status']} timesheet");
    }
    $headerId = (int) $header['id'];

    staffingTimesheetRequirePositiveEntries($headerId, 'submit');

    $pdo = getDB();
    $ownsTxn = cf_tx_begin($pdo);
    try {
        scopedUpdate('staffing_timesheets', $headerId, [
            'status'       => 'submitted',
            'submitted_at' => date('Y-m-d H:i:s'),
        ]);
        // Flip every non-superseded row to pending_review.
        $upd = $pdo->prepare(
            "UPDATE time_entries
                SET status = 'pending_review'
              WHERE tenant_id = :t AND timesheet_id = :tid AND status IN ('draft','rejected')"
        );
        $upd->execute(['t' => currentTenantId(), 'tid' => $headerId]);
        staffingTimesheetRecordArtifactEvent($headerId, 'timesheet.submitted', $userId, [
            'entry_count' => $upd->rowCount(),
        ]);
        cf_tx_commit($pdo, $ownsTxn);
    } catch (\Throwable $e) {
        cf_tx_rollback($pdo, $ownsTxn);
        throw $e;
    }

    return staffingTimesheetWeek($personId, $periodStart, $periodEnd);
}

/** Reject the whole week — rows return to draft, reason captured on header. */
function staffingTimesheetReject(int $userId, int $personId, string $periodStart, string $periodEnd, string $reason): array {
    staffingTimesheetValidateWeek($personId, $periodStart, $periodEnd);
    $header = staffingTimesheetFind($personId, $periodStart);
    if (!$header) throw new \RuntimeException('Timesheet not found');
    if ($header['status'] !== 'submitted') {
        throw new \RuntimeException("Cannot reject a {$header['status']} timesheet");
    }
    $headerId = (int) $header['id'];

    $pdo = getDB();
    $ownsTxn = cf_tx_begin($pdo);
    try {
        scopedUpdate('staffing_timesheets', $headerId, [
            'status'              => 'rejected',
            'rejected_at'         => date('Y-m-d H:i:s'),
            'rejected_by_user_id' => $userId,
            'rejection_reason'    => $reason,
        ]);
        $upd = $pdo->prepare(
            "UPDATE time_entries
                SET status = 'rejected', rejected_reason = :r
              WHERE tenant_id = :t AND timesheet_id = :tid AND status = 'pending_review'"
        );
        $upd->execute(['t' => currentTenantId(), 'tid' => $headerId, 'r' => $reason]);
        staffingTimesheetRecordArtifactEvent($headerId, 'timesheet.rejected', $userId, [
            'reason' => $reason,
        ]);
        cf_tx_commit($pdo, $ownsTxn);
    } catch (\Throwable $e) {
        cf_tx_rollback($pdo, $ownsTxn);
        throw $e;
    }

    return staffingTimesheetWeek($personId, $periodStart, $periodEnd);
}

/** Snapshot of the prior week — used by `prefill_from_last_week`. Returns
 *  rows in the bulk_save shape (no id → all will be CREATE on save).
 */
function staffingTimesheetPriorWeekTemplate(int $personId, string $periodStart, string $periodEnd): array {
    // The "prior" week is the 7 days ending the day before period_start.
    $priorEnd   = date('Y-m-d', strtotime($periodStart . ' -1 day'));
    $priorStart = date('Y-m-d', strtotime($priorEnd      . ' -6 day'));
    $rows = scopedQuery(
        "SELECT placement_id, work_date, hour_type, hours, description
           FROM time_entries
          WHERE tenant_id = :tenant_id
            AND person_id = :pid
            AND work_date BETWEEN :ps AND :pe
            AND status != 'superseded'
            AND hours > 0
          ORDER BY placement_id, work_date",
        ['pid' => $personId, 'ps' => $priorStart, 'pe' => $priorEnd]
    );

    // Day-shift each row forward by 7 days so it lands in the current week.
    $shifted = [];
    foreach ($rows as $r) {
        $newDate = date('Y-m-d', strtotime($r['work_date'] . ' +7 day'));
        // Safety: only include if it falls inside the target week.
        if ($newDate < $periodStart || $newDate > $periodEnd) continue;
        $shifted[] = [
            'id'           => null,
            'placement_id' => (int) $r['placement_id'],
            'work_date'    => $newDate,
            'hour_type'    => $r['hour_type'] ?: 'regular',
            'hours'        => (float) $r['hours'],
            'description'  => $r['description'],
        ];
    }
    return [
        'prior_period_start' => $priorStart,
        'prior_period_end'   => $priorEnd,
        'rows'               => $shifted,
    ];
}

/** Normalize and cap a bulk timesheet selection. */
function staffingTimesheetBulkIds(array $ids): array {
    $clean = [];
    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id > 0) $clean[$id] = $id;
    }
    $clean = array_values($clean);
    if (!$clean) throw new \RuntimeException('Select at least one timesheet.');
    if (count($clean) > 500) throw new \RuntimeException('A single batch may contain at most 500 timesheets.');
    return $clean;
}

/** Lock selected headers and return them in the same order as the request. */
function staffingTimesheetLockHeaders(array $ids): array {
    $pdo = getDB();
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT * FROM staffing_timesheets
          WHERE tenant_id = ? AND id IN ({$in})
          FOR UPDATE"
    );
    $stmt->execute(array_merge([currentTenantId()], $ids));
    $found = [];
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $found[(int) $row['id']] = $row;
    }
    $missing = array_values(array_diff($ids, array_keys($found)));
    if ($missing) {
        throw new \RuntimeException('Timesheet not found: #' . implode(', #', $missing));
    }
    return array_map(static fn (int $id): array => $found[$id], $ids);
}

/** Validate a submitted header and resolve every entry's approved rate. */
function staffingTimesheetApprovalPlan(?int $userId, array $header, ?int $tenantId = null): array {
    $headerId = (int) ($header['id'] ?? 0);
    $tenantId = $tenantId ?? currentTenantId();
    if (!$tenantId) throw new \RuntimeException('A tenant is required to approve a timesheet.');
    if (($header['status'] ?? '') !== 'submitted') {
        throw new \RuntimeException("Timesheet #{$headerId} is {$header['status']}; only submitted timesheets can be approved.");
    }
    if ($userId !== null && $userId > 0 && (int) ($header['worker_user_id'] ?? 0) === $userId) {
        throw new \RuntimeException("Timesheet #{$headerId} requires a different approver.");
    }
    staffingTimesheetRequirePositiveEntries($headerId, 'approve', $tenantId);

    $entryStmt = getDB()->prepare(
        'SELECT id, placement_id, work_date, hours, status, created_by_user_id
           FROM time_entries
          WHERE tenant_id = :tenant_id
            AND timesheet_id = :tid
            AND status != "superseded"
          ORDER BY work_date, id
          FOR UPDATE'
    );
    $entryStmt->execute(['tenant_id' => $tenantId, 'tid' => $headerId]);
    $entries = $entryStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    if (!$entries) {
        throw new \RuntimeException("Timesheet #{$headerId} has no time entries.");
    }

    $snapshots = [];
    $accountingTenantId = effectiveTenantIdForModule('accounting', $tenantId) ?? $tenantId;
    $entityStmt = getDB()->prepare(
        'SELECT id FROM accounting_entities
          WHERE tenant_id = :tenant_id AND active = 1
          ORDER BY id LIMIT 1'
    );
    $entityStmt->execute(['tenant_id' => $accountingTenantId]);
    $defaultEntityId = (int) ($entityStmt->fetchColumn() ?: 0);
    if ($defaultEntityId <= 0) {
        throw new \RuntimeException('Set up an active legal entity before approving time.');
    }
    $dimensionReadiness = [];
    foreach ($entries as $entry) {
        $entryId = (int) $entry['id'];
        if ((float) $entry['hours'] <= 0) {
            throw new \RuntimeException("Timesheet #{$headerId} contains a zero-hour entry (#{$entryId}).");
        }
        if (($entry['status'] ?? '') !== 'pending_review') {
            throw new \RuntimeException(
                "Timesheet #{$headerId} contains entry #{$entryId} in {$entry['status']} status. Refresh the week before approving it."
            );
        }
        if ($userId !== null && $userId > 0 && (int) ($entry['created_by_user_id'] ?? 0) === $userId) {
            throw new \RuntimeException("Timesheet #{$headerId} requires a different approver because you entered or imported its time.");
        }
        $snap = timeResolveRateSnapshot((int) $entry['placement_id'], (string) $entry['work_date'], $tenantId);
        if (!$snap || empty($snap['id'])) {
            throw new \RuntimeException(
                "Timesheet #{$headerId}, entry #{$entryId} has no approved rate covering {$entry['work_date']} for placement #{$entry['placement_id']}."
            );
        }
        $placementId = (int) $entry['placement_id'];
        $readinessKey = $placementId . ':' . (string) $entry['work_date'];
        if (!isset($dimensionReadiness[$readinessKey])) {
            $dimensionContext = staffingAssignmentDimensionContext(
                $tenantId,
                $placementId,
                $defaultEntityId,
                (string) $entry['work_date']
            );
            $missing = staffingDimensionBlockingMissing($dimensionContext, 'time');
            if ($missing) {
                throw new \RuntimeException(
                    "Complete placement #{$placementId} before approving time. Missing: "
                    . implode(', ', staffingDimensionMissingLabels($missing))
                    . '. You can update these fields in the placement or by CSV import.'
                );
            }
            $dimensionReadiness[$readinessKey] = true;
        }
        $snapshots[$entryId] = (int) $snap['id'];
    }
    return $snapshots;
}

/** Apply a prevalidated approval plan inside the caller's transaction. */
function staffingTimesheetApplyApproval(?int $userId, int $headerId, array $snapshots, array $options = []): void {
    $pdo = getDB();
    $tenantId = (int) ($options['tenant_id'] ?? currentTenantId());
    if ($tenantId <= 0) throw new \RuntimeException('A tenant is required to approve a timesheet.');
    $headerVia = (string) ($options['header_approved_via'] ?? 'internal_app');
    $entryVia = (string) ($options['entry_approved_via'] ?? 'manual');
    if (!in_array($headerVia, ['internal_app', 'external_email'], true)) {
        throw new \InvalidArgumentException('Unsupported timesheet approval channel.');
    }
    if (!in_array($entryVia, ['manual', 'tokenized_client_email', 'bulk_pre_approved'], true)) {
        throw new \InvalidArgumentException('Unsupported time-entry approval channel.');
    }
    $headerUpdate = $pdo->prepare(
        "UPDATE staffing_timesheets
            SET status = 'approved',
                approved_at = NOW(),
                approved_by_user_id = :user_id,
                approved_via = :approved_via,
                external_approver_email = :external_email,
                approval_note = :approval_note,
                rejected_at = NULL,
                rejected_by_user_id = NULL,
                rejection_reason = NULL
          WHERE tenant_id = :tenant_id
            AND id = :id
            AND status = 'submitted'"
    );
    $headerUpdate->execute([
        'user_id'       => $userId,
        'approved_via'  => $headerVia,
        'external_email'=> $options['external_approver_email'] ?? null,
        'approval_note' => $options['approval_note'] ?? null,
        'tenant_id'     => $tenantId,
        'id'            => $headerId,
    ]);
    if ($headerUpdate->rowCount() !== 1) {
        throw new \RuntimeException("Timesheet #{$headerId} changed while it was being approved. Refresh and try again.");
    }
    $upd = $pdo->prepare(
        "UPDATE time_entries
            SET status = 'approved',
                rate_snapshot_id = :rid,
                approved_at = NOW(),
                approved_by_user_id = :u,
                approved_via = :approved_via,
                rejected_reason = NULL
          WHERE tenant_id = :t
            AND timesheet_id = :tid
            AND id = :id
            AND status = 'pending_review'"
    );
    foreach ($snapshots as $entryId => $rateId) {
        $upd->execute([
            't' => $tenantId,
            'tid' => $headerId,
            'id' => $entryId,
            'rid' => $rateId,
            'u' => $userId,
            'approved_via' => $entryVia,
        ]);
        if ($upd->rowCount() !== 1) {
            throw new \RuntimeException("Entry #{$entryId} changed while the timesheet was being approved. Refresh and try again.");
        }
    }
    staffingTimesheetRecordArtifactEvent($headerId, 'timesheet.approved', $userId, [
        'approved_via' => $headerVia,
        'entry_count' => count($snapshots),
        'external_approver_email' => $options['external_approver_email'] ?? null,
        'approval_token_id' => $options['approval_token_id'] ?? null,
    ], $tenantId);
}

/** Approve the whole week — cascade to rows. Two-eye control. */
function staffingTimesheetApprove(int $userId, int $personId, string $periodStart, string $periodEnd): array {
    staffingTimesheetValidateWeek($personId, $periodStart, $periodEnd);
    $header = staffingTimesheetFind($personId, $periodStart);
    if (!$header) throw new \RuntimeException('Timesheet not found');
    $headerId = (int) $header['id'];

    $pdo = getDB();
    $ownsTxn = cf_tx_begin($pdo);
    try {
        $locked = staffingTimesheetLockHeaders([$headerId])[0];
        $snapshots = staffingTimesheetApprovalPlan($userId, $locked);
        staffingTimesheetApplyApproval($userId, $headerId, $snapshots);
        cf_tx_commit($pdo, $ownsTxn);
    } catch (\Throwable $e) {
        cf_tx_rollback($pdo, $ownsTxn);
        throw $e;
    }

    // Best-effort: emit the accounting event so the GL gets the staffing
    // labor revenue / cost / GP journal. Failures don't roll back approval.
    staffingEmitWorkerHoursApprovedEvent(currentTenantId(), $headerId);

    return staffingTimesheetWeek($personId, $periodStart, $periodEnd);
}

/** Approve selected submitted weeks atomically, then post their accounting events. */
function staffingTimesheetBulkApprove(int $userId, array $ids): array {
    $ids = staffingTimesheetBulkIds($ids);
    $pdo = getDB();
    $ownsTxn = cf_tx_begin($pdo);
    try {
        $headers = staffingTimesheetLockHeaders($ids);
        $plans = [];
        foreach ($headers as $header) {
            $plans[(int) $header['id']] = staffingTimesheetApprovalPlan($userId, $header);
        }
        foreach ($plans as $headerId => $snapshots) {
            staffingTimesheetApplyApproval($userId, $headerId, $snapshots);
        }
        cf_tx_commit($pdo, $ownsTxn);
    } catch (\Throwable $e) {
        cf_tx_rollback($pdo, $ownsTxn);
        throw $e;
    }

    $postingWarnings = [];
    foreach ($ids as $headerId) {
        $warning = staffingEmitWorkerHoursApprovedEvent(currentTenantId(), $headerId);
        if ($warning !== null) $postingWarnings[] = ['id' => $headerId, 'warning' => $warning];
    }
    return ['approved' => count($ids), 'ids' => $ids, 'posting_warnings' => $postingWarnings];
}

/** Reject selected submitted weeks atomically with one clear reason. */
function staffingTimesheetBulkReject(int $userId, array $ids, string $reason): array {
    $ids = staffingTimesheetBulkIds($ids);
    $reason = trim($reason);
    if ($reason === '') throw new \RuntimeException('A rejection reason is required.');
    $reasonLength = function_exists('mb_strlen') ? mb_strlen($reason) : strlen($reason);
    if ($reasonLength > 500) throw new \RuntimeException('The rejection reason must be 500 characters or fewer.');

    $pdo = getDB();
    $ownsTxn = cf_tx_begin($pdo);
    try {
        $headers = staffingTimesheetLockHeaders($ids);
        foreach ($headers as $header) {
            if (($header['status'] ?? '') !== 'submitted') {
                throw new \RuntimeException(
                    "Timesheet #{$header['id']} is {$header['status']}; only submitted timesheets can be rejected."
                );
            }
        }

        $rowUpdate = $pdo->prepare(
            "UPDATE time_entries
                SET status = 'rejected', rejected_reason = :reason
              WHERE tenant_id = :tenant_id
                AND timesheet_id = :timesheet_id
                AND status = 'pending_review'"
        );
        foreach ($ids as $headerId) {
            scopedUpdate('staffing_timesheets', $headerId, [
                'status'              => 'rejected',
                'rejected_at'         => date('Y-m-d H:i:s'),
                'rejected_by_user_id' => $userId,
                'rejection_reason'    => $reason,
            ]);
            $rowUpdate->execute([
                'tenant_id'   => currentTenantId(),
                'timesheet_id'=> $headerId,
                'reason'      => $reason,
            ]);
            if ($rowUpdate->rowCount() < 1) {
                throw new \RuntimeException("Timesheet #{$headerId} has no submitted entries to reject.");
            }
            staffingTimesheetRecordArtifactEvent($headerId, 'timesheet.rejected', $userId, [
                'reason' => $reason,
                'bulk' => true,
            ]);
        }
        cf_tx_commit($pdo, $ownsTxn);
    } catch (\Throwable $e) {
        cf_tx_rollback($pdo, $ownsTxn);
        throw $e;
    }
    return ['rejected' => count($ids), 'ids' => $ids, 'reason' => $reason];
}

/** Empty headers are useful while a worker opens a week, but they must not
 * enter review or downstream settlement. */
function staffingTimesheetRequirePositiveEntries(int $headerId, string $action, ?int $tenantId = null): void {
    $tenantId = $tenantId ?? currentTenantId();
    if (!$tenantId) throw new \RuntimeException('A tenant is required to validate a timesheet.');
    $stmt = getDB()->prepare(
        'SELECT COUNT(*) AS entry_count, COALESCE(SUM(hours), 0) AS total_hours
           FROM time_entries
          WHERE tenant_id = :tenant_id
            AND timesheet_id = :tid
            AND status != "superseded"
            AND hours > 0'
    );
    $stmt->execute(['tenant_id' => $tenantId, 'tid' => $headerId]);
    $totals = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    if ((int) ($totals['entry_count'] ?? 0) < 1 || (float) ($totals['total_hours'] ?? 0) <= 0) {
        $verb = $action === 'approve' ? 'approving' : 'submitting';
        throw new \RuntimeException("Add at least one positive-hour entry before {$verb} this timesheet.");
    }
}

/** Emit `staffing.worker_hours.approved` events to the accounting posting
 *  engine. One event PER (timesheet x placement) keeps every revenue and
 *  direct-cost posting on the assignment that generated it. Posting rules
 *  still route the event by engagement type. Best-effort: failures don't
 *  roll back the approval. */
function staffingEmitWorkerHoursApprovedEvent(int $tenantId, int $headerId): ?string {
    try {
        require_once __DIR__ . '/../../../core/posting_engine/process.php';
        $pdo = getDB();
        $placementsTenantId = staffingPlacementsTenantId($tenantId) ?? $tenantId;
        $accountingTenantId = effectiveTenantIdForModule('accounting', $tenantId) ?? $tenantId;

        // The approved rate snapshot is the immutable economic source for
        // each entry. Revenue only follows billable hours; direct cost only
        // follows payable hours and includes the recurring employer/vendor
        // loads used by placement margin reporting. Referral assignments use
        // their dated vendor payout rather than a fabricated worker pay rate.
        $stmt = $pdo->prepare(
            "SELECT t.id, t.person_id, t.period_start, t.period_end,
                    te.placement_id,
                    COALESCE(pl.engagement_type, 'w2') AS engagement_type,
                    SUM(te.hours) AS hours,
                    SUM(CASE WHEN te.billable = 1 THEN
                        te.hours
                        * COALESCE(
                            pr.adjusted_bill_rate,
                            GREATEST(0,
                                COALESCE(pr.bill_rate, 0)
                                * (1 + COALESCE(pr.bill_adder_pct, 0) - COALESCE(pr.bill_discount_pct, 0))
                                + COALESCE(pr.bill_adder_flat, 0)
                                - COALESCE(pr.bill_discount_flat, 0)
                            )
                        )
                        * CASE te.hour_type
                            WHEN 'overtime' THEN COALESCE(pr.ot_multiplier, 1.50)
                            WHEN 'doubletime' THEN COALESCE(pr.dt_multiplier, 2.00)
                            ELSE 1.00
                          END
                        ELSE 0 END) AS revenue,
                    SUM(CASE
                        WHEN te.payable <> 1 THEN 0
                        WHEN pl.engagement_type = 'referral' THEN te.hours * COALESCE((
                            SELECT SUM(ref.fee_flat)
                              FROM placement_referrals ref
                             WHERE ref.tenant_id = pl.tenant_id
                               AND ref.placement_id = pl.id
                               AND ref.fee_basis = 'per_hour'
                               AND ref.start_date <= te.work_date
                               AND (ref.end_date IS NULL OR ref.end_date >= te.work_date)
                        ), 0)
                        WHEN pl.engagement_type IN ('w2','temp_to_perm','internal') THEN
                            te.hours * (
                                COALESCE(pr.pay_rate, 0)
                                * CASE te.hour_type
                                    WHEN 'overtime' THEN COALESCE(pr.ot_multiplier, 1.50)
                                    WHEN 'doubletime' THEN COALESCE(pr.dt_multiplier, 2.00)
                                    ELSE 1.00
                                  END
                                * (1 + COALESCE(pr.adder_pct, 0)
                                     + COALESCE(pr.workers_comp_pct, 0)
                                     + COALESCE(pr.benefits_load_pct, 0))
                                + COALESCE(pr.other_cost_per_hour, 0)
                            )
                        WHEN pl.engagement_type = 'c2c' THEN
                            te.hours * (
                                COALESCE(pr.pay_rate, 0)
                                * CASE te.hour_type
                                    WHEN 'overtime' THEN COALESCE(pr.ot_multiplier, 1.50)
                                    WHEN 'doubletime' THEN COALESCE(pr.dt_multiplier, 2.00)
                                    ELSE 1.00
                                  END
                                * (1 + COALESCE(pr.c2c_overhead_pct, 0))
                                + COALESCE(pr.other_cost_per_hour, 0)
                            )
                        ELSE
                            te.hours * (
                                COALESCE(pr.pay_rate, 0)
                                * CASE te.hour_type
                                    WHEN 'overtime' THEN COALESCE(pr.ot_multiplier, 1.50)
                                    WHEN 'doubletime' THEN COALESCE(pr.dt_multiplier, 2.00)
                                    ELSE 1.00
                                  END
                                + COALESCE(pr.other_cost_per_hour, 0)
                            )
                    END) AS cost,
                    SUM(CASE WHEN te.payable = 1
                                  AND pl.engagement_type IN ('w2','temp_to_perm','internal')
                             THEN te.hours * COALESCE(pr.pay_rate, 0)
                                * CASE te.hour_type
                                    WHEN 'overtime' THEN COALESCE(pr.ot_multiplier, 1.50)
                                    WHEN 'doubletime' THEN COALESCE(pr.dt_multiplier, 2.00)
                                    ELSE 1.00
                                  END
                             ELSE 0 END) AS wage_cost,
                    SUM(CASE WHEN te.payable = 1
                                  AND pl.engagement_type IN ('w2','temp_to_perm','internal')
                             THEN te.hours * COALESCE(pr.pay_rate, 0)
                                * CASE te.hour_type
                                    WHEN 'overtime' THEN COALESCE(pr.ot_multiplier, 1.50)
                                    WHEN 'doubletime' THEN COALESCE(pr.dt_multiplier, 2.00)
                                    ELSE 1.00
                                  END * COALESCE(pr.adder_pct, 0)
                             ELSE 0 END) AS employer_load_cost,
                    SUM(CASE WHEN te.payable = 1
                                  AND pl.engagement_type IN ('w2','temp_to_perm','internal')
                             THEN te.hours * COALESCE(pr.pay_rate, 0)
                                * CASE te.hour_type
                                    WHEN 'overtime' THEN COALESCE(pr.ot_multiplier, 1.50)
                                    WHEN 'doubletime' THEN COALESCE(pr.dt_multiplier, 2.00)
                                    ELSE 1.00
                                  END * COALESCE(pr.workers_comp_pct, 0)
                             ELSE 0 END) AS workers_comp_cost,
                    SUM(CASE WHEN te.payable = 1
                                  AND pl.engagement_type IN ('w2','temp_to_perm','internal')
                             THEN te.hours * COALESCE(pr.pay_rate, 0)
                                * CASE te.hour_type
                                    WHEN 'overtime' THEN COALESCE(pr.ot_multiplier, 1.50)
                                    WHEN 'doubletime' THEN COALESCE(pr.dt_multiplier, 2.00)
                                    ELSE 1.00
                                  END * COALESCE(pr.benefits_load_pct, 0)
                             ELSE 0 END) AS benefits_cost,
                    SUM(CASE WHEN te.payable = 1
                                  AND pl.engagement_type IN ('w2','temp_to_perm','internal')
                             THEN te.hours * COALESCE(pr.other_cost_per_hour, 0)
                             ELSE 0 END) AS other_direct_cost
               FROM staffing_timesheets t
               JOIN time_entries te ON te.timesheet_id = t.id AND te.tenant_id = t.tenant_id AND te.status != 'superseded'
               LEFT JOIN placements pl     ON pl.id = te.placement_id AND pl.tenant_id = :placements_tid
               LEFT JOIN placement_rates pr ON pr.id = te.rate_snapshot_id AND pr.tenant_id = :rates_tid
              WHERE t.tenant_id = :t AND t.id = :id
              GROUP BY t.id, t.person_id, t.period_start, t.period_end,
                       te.placement_id, engagement_type"
        );
        $stmt->execute([
            't' => $tenantId,
            'id' => $headerId,
            'placements_tid' => $placementsTenantId,
            'rates_tid' => $placementsTenantId,
        ]);
        $groups = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (!$groups) return null;

        $ent = $pdo->prepare(
            'SELECT id FROM accounting_entities
              WHERE tenant_id = :t AND active = 1
              ORDER BY id LIMIT 1'
        );
        $ent->execute(['t' => $accountingTenantId]);
        $entityId = (int) ($ent->fetchColumn() ?: 0);
        if (!$entityId) {
            throw new \RuntimeException('No active accounting entity is available for approved hours.');
        }

        // Before assignment-level posting, the legacy emitter created one
        // event for an entire timesheet/engagement bucket. If that event was
        // already posted, the new assignment events must not double-book it.
        $legacyPosted = $pdo->prepare(
            "SELECT 1 FROM accounting_events
              WHERE tenant_id = :tenant_id
                AND source_module = 'staffing'
                AND source_record_id = :source_record_id
                AND event_type = 'staffing.worker_hours.approved'
                AND status = 'posted'
              LIMIT 1"
        );

        foreach ($groups as $g) {
            $placementId = (int) ($g['placement_id'] ?? 0);
            if ($placementId <= 0) {
                throw new \RuntimeException("Timesheet #{$headerId} has approved hours without a placement.");
            }
            $engagementType = (string) $g['engagement_type'];
            $legacyPosted->execute([
                'tenant_id' => $accountingTenantId,
                'source_record_id' => (string) $g['id'] . ':' . $engagementType,
            ]);
            if ($legacyPosted->fetchColumn()) continue;

            $dimensionContext = staffingAssignmentDimensionContext(
                $tenantId,
                $placementId,
                $entityId,
                (string) $g['period_end']
            );
            $eventEntityId = (int) ($dimensionContext['event_entity_id'] ?? 0);
            if ($eventEntityId <= 0) {
                throw new \RuntimeException("Placement #{$placementId} has no valid accounting entity.");
            }
            $blockingMissing = staffingDimensionBlockingMissing($dimensionContext, 'time');
            if ($blockingMissing) {
                throw new \RuntimeException(
                    "Placement #{$placementId} is missing dimensions required for time accounting: "
                    . implode(', ', staffingDimensionMissingLabels($blockingMissing))
                );
            }
            if (in_array($engagementType, ['1099','c2c','referral'], true)
                && empty($dimensionContext['vendor_ap_id'])) {
                throw new \RuntimeException(
                    "Placement #{$placementId} has no AP vendor linked to its primary payable party."
                );
            }
            $rev  = round((float) $g['revenue'], 2);
            $cost = round((float) $g['cost'], 2);
            $wageCost = round((float) ($g['wage_cost'] ?? 0), 2);
            $employerLoadCost = round((float) ($g['employer_load_cost'] ?? 0), 2);
            $workersCompCost = round((float) ($g['workers_comp_cost'] ?? 0), 2);
            $benefitsCost = round((float) ($g['benefits_cost'] ?? 0), 2);
            $otherDirectCost = round((float) ($g['other_direct_cost'] ?? 0), 2);
            if (in_array($engagementType, ['w2','temp_to_perm','internal'], true)) {
                $cost = round(
                    $wageCost + $employerLoadCost + $workersCompCost + $benefitsCost + $otherDirectCost,
                    2
                );
            }
            $result = accountingProcessEvent($accountingTenantId, [
                'entity_id'        => $eventEntityId,
                'event_type'       => 'staffing.worker_hours.approved',
                'source_module'    => 'staffing',
                'source_record_id' => 'timesheet:' . (string) $g['id']
                    . ':placement:' . $placementId . ':' . $engagementType,
                'event_date'       => (string) $g['period_end'],
                'payload'          => [
                    'timesheet_id'    => (int) $g['id'],
                    'person_id'       => (int) $g['person_id'],
                    'placement_id'    => $placementId,
                    'period_start'    => $g['period_start'],
                    'period_end'      => $g['period_end'],
                    'engagement_type' => $engagementType,
                    'hours'           => (float) $g['hours'],
                    'revenue'         => $rev,
                    'cost'            => $cost,
                    'wage_cost'       => $wageCost,
                    'employer_load_cost' => $employerLoadCost,
                    'workers_comp_cost' => $workersCompCost,
                    'benefits_cost'   => $benefitsCost,
                    'other_direct_cost' => $otherDirectCost,
                    'gross_profit'    => $rev - $cost,
                    'dimensions'      => $dimensionContext['dimensions'],
                    'vendor_dimension'=> $dimensionContext['vendor_dimension'],
                    'vendor_economic_party_id'=> $dimensionContext['vendor_economic_party_id'],
                    'vendor_company_id'=> $dimensionContext['vendor_company_id'],
                    'vendor_ap_id'     => $dimensionContext['vendor_ap_id'],
                    'dimension_missing'=> $dimensionContext['missing'],
                    // Convenience flags for posting-rule `conditions` matching.
                    'is_w2'           => in_array($engagementType, ['w2','temp_to_perm'], true) ? 1 : 0,
                    'is_1099_or_c2c'  => in_array($engagementType, ['1099','c2c'], true) ? 1 : 0,
                    'is_internal'     => $engagementType === 'internal' ? 1 : 0,
                    'is_referral'     => $engagementType === 'referral' ? 1 : 0,
                ],
            ], null);
            if (($result['status'] ?? '') !== 'posted') {
                throw new \RuntimeException((string) ($result['error'] ?? 'Approved hours were not posted to accounting.'));
            }
        }
        return null;
    } catch (\Throwable $e) {
        error_log("[staffing] accounting event emit failed for ts #{$headerId}: " . $e->getMessage());
        return $e->getMessage();
    }
}

// ─── Per-entry edit lifecycle (2026-02 — Batch 5) ──────────────────────────
//
// Operators told us the weekly-grid-only edit flow was too coarse: they
// want to click into ONE timesheet row from People / Placement views and
// fix a single entry, even after submission or approval, without
// rebuilding the whole week.  These helpers add row-level CRUD with an
// automatic "reopen" semantic — the parent header flips back to `draft`
// the moment its first entry is touched, so the downstream
// billing/payroll/journal pipeline re-evaluates on the next submission.
//
// Anyone with `staffing.timesheets.write` (enforced at the API layer)
// may edit any timesheet — original worker OR a manager fixing it
// in-place, per product direction (2026-02).

/**
 * Re-open a timesheet header so its entries can be edited.  Cascades to
 * a flat re-stage of every non-superseded entry back to `draft` and
 * records an audit row so we can prove the manager touched it.
 */
function staffingTimesheetReopen(int $userId, int $timesheetId, string $reason = ''): array {
    $header = scopedFind(
        'SELECT * FROM staffing_timesheets WHERE tenant_id = :tenant_id AND id = :id LIMIT 1',
        ['id' => $timesheetId]
    );
    if (!$header) throw new \RuntimeException("timesheet #{$timesheetId} not found");
    if ($header['status'] === 'draft') return $header; // already editable, no-op
    if (in_array($header['status'], ['approved','payroll_ready','billing_ready','locked'], true)) {
        throw new \RuntimeException(
            "This timesheet is {$header['status']} and may already affect payroll, billing, or accounting. "
            . 'Reverse or correct the downstream records before reopening it.'
        );
    }

    $settled = scopedQueryOne(
        'SELECT id, bill_extracted_at, bill_extracted_ref,
                ap_extracted_at, ap_extracted_ref,
                payroll_extracted_at, payroll_extracted_ref
           FROM time_entries
          WHERE tenant_id = :tenant_id
            AND timesheet_id = :tid
            AND status != "superseded"
            AND (bill_extracted_at IS NOT NULL OR bill_extracted_ref IS NOT NULL
              OR ap_extracted_at IS NOT NULL OR ap_extracted_ref IS NOT NULL
              OR payroll_extracted_at IS NOT NULL OR payroll_extracted_ref IS NOT NULL)
          LIMIT 1',
        ['tid' => $timesheetId]
    );
    if ($settled) staffingTimesheetAssertEntryNotSettled($settled);

    // `locked`, `payroll_ready`, `billing_ready` and `approved` all
    // reopen back to `draft` so the entry update path can land cleanly.
    // We DON'T reopen entries that already flowed downstream into
    // posted journal lines — those carry status `locked` per the
    // existing settlement code and the journal_entry_lines reference
    // them by id.  Operators must reverse the JE first.
    $pdo = getDB();
    $ownsTxn = cf_tx_begin($pdo);
    try {
        scopedUpdate('staffing_timesheets', $timesheetId, [
            'status'           => 'draft',
            'rejection_reason' => $reason !== '' ? $reason : null,
            'rejected_at'      => $reason !== '' ? date('Y-m-d H:i:s') : null,
            'rejected_by_user_id' => $reason !== '' ? $userId : null,
        ]);
        $upd = $pdo->prepare(
            "UPDATE time_entries
                SET status = 'draft', rejected_reason = NULL
              WHERE tenant_id = :t AND timesheet_id = :tid
                AND status IN ('pending_review','approved','rejected','payroll_ready','billing_ready')"
        );
        $upd->execute(['t' => currentTenantId(), 'tid' => $timesheetId]);
        staffingTimesheetRecordArtifactEvent($timesheetId, 'timesheet.reopened', $userId, [
            'reason' => $reason !== '' ? $reason : null,
        ]);
        cf_tx_commit($pdo, $ownsTxn);
    } catch (\Throwable $e) {
        cf_tx_rollback($pdo, $ownsTxn);
        throw $e;
    }
    return scopedFind(
        'SELECT * FROM staffing_timesheets WHERE tenant_id = :tenant_id AND id = :id LIMIT 1',
        ['id' => $timesheetId]
    );
}

/**
 * Save (insert or update) a single `time_entries` row.  Auto-reopens the
 * parent timesheet if it's not already draft — operators get a single
 * "edit and save" flow even on previously-submitted rows.
 *
 * @return array The updated/inserted row + the parent header.
 */
function staffingTimeEntrySave(int $userId, array $payload): array {
    $entryId   = (int) ($payload['id'] ?? 0);
    $tsId      = (int) ($payload['timesheet_id'] ?? 0);
    if ($entryId <= 0 && $tsId <= 0) {
        throw new \RuntimeException('Either id or timesheet_id is required');
    }

    // Resolve the parent timesheet so we can auto-reopen.
    $existing = null;
    if ($entryId > 0) {
        $existing = scopedFind(
            'SELECT * FROM time_entries WHERE tenant_id = :tenant_id AND id = :id LIMIT 1',
            ['id' => $entryId]
        );
        if (!$existing) throw new \RuntimeException("time_entry #{$entryId} not found");
        $tsId = (int) $existing['timesheet_id'];
    }
    if ($tsId <= 0) throw new \RuntimeException('timesheet_id could not be resolved');

    $header = scopedFind(
        'SELECT * FROM staffing_timesheets WHERE tenant_id = :tenant_id AND id = :id LIMIT 1',
        ['id' => $tsId]
    );
    if (!$header) throw new \RuntimeException("timesheet #{$tsId} not found");

    if ($existing) staffingTimesheetAssertEntryNotSettled($existing);

    // Per product direction (2026-02): anyone with timesheets.write can
    // edit any timesheet, including ones already submitted/approved.
    // We auto-reopen so the existing draft-only constraints below stay
    // intact without forcing the operator to click twice.
    if ($header['status'] !== 'draft') {
        $header = staffingTimesheetReopen($userId, $tsId, 'edited inline');
    }

    $hourType = $payload['hour_type'] ?? ($existing['hour_type'] ?? 'regular');
    if (!in_array($hourType, STAFFING_HOUR_TYPES, true)) {
        throw new \RuntimeException("Invalid hour_type: {$hourType}");
    }
    if (array_key_exists('hours', $payload) && !is_numeric($payload['hours'])) {
        throw new \RuntimeException('hours must be numeric');
    }
    $hours = isset($payload['hours']) ? (float) $payload['hours'] : (float) ($existing['hours'] ?? 0);

    $placementId = (int) ($payload['placement_id'] ?? ($existing['placement_id'] ?? 0));
    $workDate    = (string) ($payload['work_date']    ?? ($existing['work_date']    ?? ''));
    $personId    = (int) ($header['person_id']);
    if ($placementId <= 0) throw new \RuntimeException('placement_id required');
    if ($workDate === '')  throw new \RuntimeException('work_date required');
    staffingTimesheetValidateWeek($personId, (string) $header['period_start'], (string) $header['period_end']);
    staffingTimesheetAssertWorkDateInWeek($workDate, (string) $header['period_start'], (string) $header['period_end']);
    staffingTimesheetValidatePlacement($placementId, $personId, $workDate);
    staffingTimesheetAssertDailyHours($personId, $workDate, $hours, $entryId);

    // Period resolution (same logic as bulk_save).
    $period = scopedFind(
        "SELECT id FROM time_periods
          WHERE tenant_id = :tenant_id AND start_date <= :wd_lo AND end_date >= :wd_hi AND status != 'closed'
          ORDER BY start_date DESC LIMIT 1",
        ['wd_lo' => $workDate, 'wd_hi' => $workDate]
    );
    $periodId = $period ? (int) $period['id'] : (int) scopedInsert('time_periods', [
        'period_type' => 'weekly',
        'start_date'  => (string) $header['period_start'],
        'end_date'    => (string) $header['period_end'],
        'label'       => 'Week of ' . $header['period_start'],
        'status'      => 'open',
    ]);

    $category = STAFFING_HOUR_TYPE_TO_CATEGORY[$hourType] ?? 'regular_billable';
    $billable = in_array($hourType, ['regular','overtime','doubletime'], true) ? 1 : 0;
    $payable  = 1;

    $row = [
        'placement_id' => $placementId,
        'person_id'    => $personId,
        'period_id'    => $periodId,
        'timesheet_id' => $tsId,
        'work_date'    => $workDate,
        'hour_type'    => $hourType,
        'category'     => $category,
        'hours'        => $hours,
        'billable'     => $billable,
        'payable'      => $payable,
        'description'  => array_key_exists('description', $payload)
                            ? $payload['description']
                            : ($existing['description'] ?? null),
        'source'       => 'manual_entry',
        'status'       => 'draft',
    ];

    if ($entryId > 0) {
        scopedUpdate('time_entries', $entryId, $row);
        $finalId = $entryId;
    } else {
        $row['created_by_user_id'] = $userId;
        $finalId = (int) scopedInsert('time_entries', $row);
    }

    // Recompute header total_hours.
    $sum = scopedFind(
        "SELECT COALESCE(SUM(hours), 0) AS h FROM time_entries
          WHERE tenant_id = :tenant_id AND timesheet_id = :tid AND status != 'superseded'",
        ['tid' => $tsId]
    );
    scopedUpdate('staffing_timesheets', $tsId, ['total_hours' => (float) ($sum['h'] ?? 0)]);
    $updatedHeader = staffingTimesheetRecordArtifactEvent($tsId, 'timesheet.entry_saved', $userId, [
        'entry_id' => $finalId,
        'source' => 'manual_entry',
    ]);

    $saved = scopedFind(
        'SELECT * FROM time_entries WHERE tenant_id = :tenant_id AND id = :id LIMIT 1',
        ['id' => $finalId]
    );
    return ['entry' => $saved, 'timesheet' => $updatedHeader];
}

/** Delete a single time entry.  Auto-reopens the parent if needed. */
function staffingTimeEntryDelete(int $userId, int $entryId): array {
    $row = scopedFind(
        'SELECT * FROM time_entries WHERE tenant_id = :tenant_id AND id = :id LIMIT 1',
        ['id' => $entryId]
    );
    if (!$row) throw new \RuntimeException("time_entry #{$entryId} not found");
    staffingTimesheetAssertEntryNotSettled($row);
    $tsId = (int) $row['timesheet_id'];
    $header = scopedFind(
        'SELECT * FROM staffing_timesheets WHERE tenant_id = :tenant_id AND id = :id LIMIT 1',
        ['id' => $tsId]
    );
    if ($header && $header['status'] !== 'draft') {
        staffingTimesheetReopen($userId, $tsId, 'entry deleted inline');
    }
    scopedDelete('time_entries', $entryId);

    // Recompute header total_hours.
    $sum = scopedFind(
        "SELECT COALESCE(SUM(hours), 0) AS h FROM time_entries
          WHERE tenant_id = :tenant_id AND timesheet_id = :tid AND status != 'superseded'",
        ['tid' => $tsId]
    );
    scopedUpdate('staffing_timesheets', $tsId, ['total_hours' => (float) ($sum['h'] ?? 0)]);
    $updatedHeader = staffingTimesheetRecordArtifactEvent($tsId, 'timesheet.entry_deleted', $userId, [
        'entry_id' => $entryId,
    ]);
    return ['deleted' => $entryId, 'timesheet_id' => $tsId, 'timesheet' => $updatedHeader];
}
