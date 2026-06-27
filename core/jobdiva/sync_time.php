<?php
/**
 * JobDiva time-entry sync (Sprint 8a / Slice A4 follow-on).
 *
 * Honors the per-entity `time` row in `jobdiva_connections.sync_config`:
 *   source=jobdiva,  direction=pull | two_way  → pull JobDiva timesheets into time_entries
 *   source=coreflux, direction=push | two_way  → push approved time_entries into JobDiva
 *   direction=off (the default for `time`) → no-op
 *
 * Uses the agnostic `external_entity_mappings` pipeline so each JobDiva
 * timesheet binds to one time_entries row.
 *
 * IMPORTANT design note: pulling time from JobDiva ONLY ingests entries that
 * resolve to a placement we already know (via the placement mapping from A3).
 * If the placement isn't in CoreFlux, the time entry is gracefully skipped —
 * the user is NOT in the business of auto-creating placements from time data.
 */
declare(strict_types=1);

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/sync.php';
require_once __DIR__ . '/../integrations/entity_mappings.php';

function jobdivaTimeWeekBounds(int $tid, string $workDate): array
{
    $pdo = getDB();
    $weekStartsOn = 1;
    try {
        $st = $pdo->prepare('SELECT week_starts_on FROM tenant_staffing_settings WHERE tenant_id = :t LIMIT 1');
        $st->execute(['t' => $tid]);
        $raw = $st->fetchColumn();
        if ($raw !== false) $weekStartsOn = (int) $raw;
    } catch (\Throwable $_) {
        $weekStartsOn = 1;
    }
    $ts = strtotime($workDate . ' 12:00:00 UTC');
    if ($ts === false) throw new \InvalidArgumentException('invalid work_date');
    $day = (int) gmdate('w', $ts); // 0=Sun..6=Sat
    $delta = ($day - $weekStartsOn + 7) % 7;
    $startTs = strtotime("-{$delta} days", $ts);
    $endTs = strtotime('+6 days', $startTs);
    return [gmdate('Y-m-d', $startTs), gmdate('Y-m-d', $endTs)];
}

function jobdivaEnsureTimePeriod(int $tid, string $workDate): array
{
    $pdo = getDB();
    $st = $pdo->prepare(
        "SELECT id, start_date, end_date
           FROM time_periods
          WHERE tenant_id = :t
            AND start_date <= :wd1
            AND end_date >= :wd2
            AND status != 'closed'
          ORDER BY start_date DESC
          LIMIT 1"
    );
    $st->execute(['t' => $tid, 'wd1' => $workDate, 'wd2' => $workDate]);
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    if ($row) {
        return ['id' => (int) $row['id'], 'start_date' => (string) $row['start_date'], 'end_date' => (string) $row['end_date']];
    }

    [$periodStart, $periodEnd] = jobdivaTimeWeekBounds($tid, $workDate);
    $pdo->prepare(
        'INSERT INTO time_periods (tenant_id, period_type, start_date, end_date, label, status)
         VALUES (:t, "weekly", :ps, :pe, :label, "open")
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), status = IF(status = "closed", status, "open")'
    )->execute([
        't' => $tid,
        'ps' => $periodStart,
        'pe' => $periodEnd,
        'label' => 'Week of ' . $periodStart,
    ]);
    return ['id' => (int) $pdo->lastInsertId(), 'start_date' => $periodStart, 'end_date' => $periodEnd];
}

function jobdivaEnsureStaffingTimesheet(int $tid, int $personId, string $periodStart, string $periodEnd): int
{
    $pdo = getDB();
    $pdo->prepare(
        'INSERT INTO staffing_timesheets (tenant_id, person_id, period_start, period_end, status, total_hours)
         VALUES (:t, :p, :ps, :pe, "draft", 0)
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), period_end = VALUES(period_end)'
    )->execute(['t' => $tid, 'p' => $personId, 'ps' => $periodStart, 'pe' => $periodEnd]);
    return (int) $pdo->lastInsertId();
}

function jobdivaRefreshStaffingTimesheetTotal(int $tid, int $timesheetId): void
{
    if ($timesheetId <= 0) return;
    getDB()->prepare(
        "UPDATE staffing_timesheets
            SET total_hours = (
                SELECT COALESCE(SUM(hours), 0)
                  FROM time_entries
                 WHERE tenant_id = :t_sum
                   AND timesheet_id = :id_sum
                   AND status != 'superseded'
            )
          WHERE tenant_id = :t AND id = :id"
    )->execute(['t_sum' => $tid, 'id_sum' => $timesheetId, 't' => $tid, 'id' => $timesheetId]);
}

function jobdivaHourTypeFromCategory(string $category): array
{
    return match ($category) {
        'OT_billable' => ['hour_type' => 'overtime', 'billable' => 1, 'payable' => 1],
        'OT_nonbillable' => ['hour_type' => 'overtime', 'billable' => 0, 'payable' => 1],
        'holiday' => ['hour_type' => 'holiday', 'billable' => 0, 'payable' => 1],
        'vacation' => ['hour_type' => 'pto', 'billable' => 0, 'payable' => 1],
        'sick' => ['hour_type' => 'sick', 'billable' => 0, 'payable' => 1],
        'bereavement' => ['hour_type' => 'bereavement', 'billable' => 0, 'payable' => 1],
        'unpaid_leave' => ['hour_type' => 'unpaid', 'billable' => 0, 'payable' => 0],
        'regular_nonbillable' => ['hour_type' => 'nonbillable', 'billable' => 0, 'payable' => 1],
        default => ['hour_type' => 'regular', 'billable' => 1, 'payable' => 1],
    };
}

function jobdivaSyncTimePull(int $tid, ?int $userId, array $opts = []): array
{
    $items = isset($opts['items_override']) && is_array($opts['items_override'])
        ? $opts['items_override']
        : jobdivaSyncFetchWithRetry($tid, JOBDIVA_PATH_TIMESHEETS_DELTA, $opts);
    $processed = 0; $skipped = 0; $failed = 0; $errors = [];
    $skipReasons = ['missing_fields' => 0, 'placement_unmapped' => 0, 'placement_missing_person' => 0];
    $itemsFetched = count($items);

    $pdo = getDB();
    foreach ($items as $jd) {
        try {
            $extId = jobdivaPluckField($jd, [
                'id', 'timesheetId', 'timesheet_id', 'timesheet id', 'timecardId', 'timecard_id',
            ]);
            $placementExtId = jobdivaPluckField($jd, [
                'placementId', 'placement_id', 'placement id', 'startId', 'start_id', 'start id',
            ]);
            $workDate = jobdivaNormaliseDate(jobdivaPluckField($jd, [
                'date', 'workDate', 'work_date', 'work date', 'entryDate', 'entry_date',
            ])) ?? '';
            $hoursVal = jobdivaPluckField($jd, ['hours', 'totalHours', 'total_hours', 'regularHours', 'regular_hours']);
            $hoursRaw = $hoursVal !== '' ? $hoursVal : null;
            if ($extId === '' || $placementExtId === '' || $workDate === '' || $hoursRaw === null) {
                $skipped++; $skipReasons['missing_fields']++; continue;
            }

            $placementMapping = mappingFindInternal($tid, 'jobdiva', 'placement', $placementExtId);
            if (!$placementMapping) { $skipped++; $skipReasons['placement_unmapped']++; continue; }
            $placementId = (int) $placementMapping['internal_entity_id'];

            $period = jobdivaEnsureTimePeriod($tid, $workDate);

            $row = $pdo->prepare(
                'SELECT p.person_id
                   FROM placements p
                  WHERE p.id = :pid AND p.tenant_id = :t
                  LIMIT 1'
            );
            $row->execute(['pid' => $placementId, 't' => $tid]);
            $meta = $row->fetch(\PDO::FETCH_ASSOC);
            if (!$meta || (int) ($meta['person_id'] ?? 0) <= 0) {
                $skipped++; $skipReasons['placement_missing_person']++; continue;
            }
            $personId = (int) $meta['person_id'];
            $timesheetId = jobdivaEnsureStaffingTimesheet($tid, $personId, $period['start_date'], $period['end_date']);
            $category = jobdivaMapTimeCategory((string) jobdivaPluckField($jd, ['category', 'hourType', 'hour_type', 'type']));
            $hourMeta = jobdivaHourTypeFromCategory($category);

            $internalId = jobdivaSyncUpsertTimeEntry($tid, [
                'placement_id' => $placementId,
                'person_id'    => $personId,
                'period_id'    => (int) $period['id'],
                'timesheet_id' => $timesheetId,
                'work_date'    => $workDate,
                'hours'        => (float) $hoursRaw,
                'category'     => $category,
                'hour_type'    => $hourMeta['hour_type'],
                'billable'     => $hourMeta['billable'],
                'payable'      => $hourMeta['payable'],
                'description'  => $jd['description'] ?? null,
                'source'       => 'bulk_upload', // tagged so the source enum stays unchanged
                'created_by_user_id' => $userId,
            ], $extId);
            jobdivaRefreshStaffingTimesheetTotal($tid, $timesheetId);

            mappingUpsert($tid, 'jobdiva', 'time_entry', $extId, $internalId, $jd, 'pull');
            $processed++;
        } catch (\Throwable $e) {
            $failed++;
            $errors[] = ['entity' => 'time_entry', 'external_id' => $extId ?? '?', 'error' => $e->getMessage()];
            if (count($errors) >= 50) break;
        }
    }

    jobdivaAudit($tid, 'sync', [
        'entity_type'     => 'time',
        'direction'       => 'pull',
        'ok'              => $failed === 0,
        'items_processed' => $processed,
        'items_skipped'   => $skipped,
        'items_failed'    => $failed,
        'actor_user_id'   => $userId,
        'detail'          => [
            'items_fetched' => $itemsFetched,
            'skip_reasons'  => $skipReasons,
            'errors'        => array_slice($errors, 0, 5),
        ],
    ]);
    return [
        'processed'     => $processed,
        'skipped'       => $skipped,
        'failed'        => $failed,
        'errors'        => $errors,
        'items_fetched' => $itemsFetched,
        'skip_reasons'  => $skipReasons,
    ];
}

/**
 * Push CoreFlux approved time entries into JobDiva. Idempotent — entries
 * already mapped (i.e. previously pushed) are skipped unless their content
 * hash changed since last push.
 */
function jobdivaSyncTimePush(int $tid, ?int $userId, array $opts = []): array
{
    $pdo = getDB();
    // Push window: approved entries from the last 60 days only, unless caller
    // overrides. Past that, prefer human review of the whole period.
    $sinceDate = $opts['since_date'] ?? date('Y-m-d', strtotime('-60 days'));

    $stmt = $pdo->prepare(
        "SELECT te.id, te.placement_id, te.person_id, te.work_date, te.hours,
                te.category, te.description
           FROM time_entries te
          WHERE te.tenant_id = :t
            AND te.status    = 'approved'
            AND te.work_date >= :s
            AND te.superseded_by_id IS NULL"
    );
    $stmt->execute(['t' => $tid, 's' => $sinceDate]);

    $processed = 0; $skipped = 0; $failed = 0; $errors = [];

    while ($te = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        $internalId = (int) $te['id'];
        try {
            $placementMapping = mappingFindExternal($tid, 'jobdiva', 'placement', (int) $te['placement_id']);
            if (!$placementMapping) { $skipped++; continue; }

            // Skip if already pushed and nothing changed (content_hash check).
            $payload = [
                'placement_id' => $placementMapping['external_id'],
                'date'         => $te['work_date'],
                'hours'        => (float) $te['hours'],
                'category'     => jobdivaUnmapTimeCategory((string) $te['category']),
                'description'  => $te['description'],
            ];
            $newHash = mappingHash($payload);
            $existing = mappingFindExternal($tid, 'jobdiva', 'time_entry', $internalId);
            if ($existing && $existing['content_hash'] === $newHash) { $skipped++; continue; }

            // Push to JobDiva V2.
            //   POST /apiv2/jobdiva/uploadTimesheet
            // Verified 2026-02 — V2 has no "update timesheet by id" endpoint
            // (only updateTimesheetStatus / updateTimesheetExternalID /
            // updateTimesheetUDFs, none of which replace the timesheet body).
            // We therefore guard against re-pushes via the content_hash check
            // above and skip when a mapping already exists.
            if ($existing) { $skipped++; continue; }
            $resp = isset($opts['transport']) && is_callable($opts['transport'])
                ? ($opts['transport'])($payload, null)
                : jobdivaCall($tid, 'POST', '/apiv2/jobdiva/uploadTimesheet', $payload);
            $extId = (string) ($resp['id']
                ?? $resp['timesheetId']
                ?? $resp['timesheet_id']
                ?? '');
            if ($extId === '') { $skipped++; continue; }

            mappingUpsert($tid, 'jobdiva', 'time_entry', $extId, $internalId, $payload, 'push');
            $processed++;
        } catch (\Throwable $e) {
            $failed++;
            $errors[] = ['entity' => 'time_entry', 'internal_id' => $internalId, 'error' => $e->getMessage()];
            if (count($errors) >= 50) break;
        }
    }

    jobdivaAudit($tid, 'sync', [
        'entity_type'     => 'time',
        'direction'       => 'push',
        'ok'              => $failed === 0,
        'items_processed' => $processed,
        'items_skipped'   => $skipped,
        'items_failed'    => $failed,
        'actor_user_id'   => $userId,
        'detail'          => ['errors' => array_slice($errors, 0, 5), 'since_date' => $sinceDate],
    ]);
    return ['processed' => $processed, 'skipped' => $skipped, 'failed' => $failed, 'errors' => $errors];
}

function jobdivaSyncUpsertTimeEntry(int $tid, array $entry, string $extId): int
{
    $pdo = getDB();
    // Look up by mapping first.
    $existingMapping = mappingFindInternal($tid, 'jobdiva', 'time_entry', $extId);
    if ($existingMapping) {
        $existingId = (int) $existingMapping['internal_entity_id'];
        $pdo->prepare(
            'UPDATE time_entries
                SET period_id = :prd, timesheet_id = :ts, hours = :h,
                    category = :c, hour_type = :ht, billable = :b, payable = :pay,
                    description = :d
              WHERE id = :id AND tenant_id = :t AND status IN ("draft","pending_review")'
        )->execute([
            'prd' => $entry['period_id'],
            'ts' => $entry['timesheet_id'],
            'h'  => $entry['hours'],
            'c'  => $entry['category'],
            'ht' => $entry['hour_type'],
            'b' => $entry['billable'],
            'pay' => $entry['payable'],
            'd'  => $entry['description'],
            'id' => $existingId,
            't'  => $tid,
        ]);
        return $existingId;
    }
    $pdo->prepare(
        'INSERT INTO time_entries
            (tenant_id, placement_id, person_id, period_id, timesheet_id, work_date, category,
             hour_type, billable, payable, hours, description, source, status, created_by_user_id)
         VALUES
            (:t, :pl, :p, :prd, :ts, :wd, :c, :ht, :b, :pay, :h, :d, :s, "draft", :u)'
    )->execute([
        't'   => $tid,
        'pl'  => $entry['placement_id'],
        'p'   => $entry['person_id'],
        'prd' => $entry['period_id'],
        'ts'  => $entry['timesheet_id'],
        'wd'  => $entry['work_date'],
        'c'   => $entry['category'],
        'ht'  => $entry['hour_type'],
        'b'   => $entry['billable'],
        'pay' => $entry['payable'],
        'h'   => $entry['hours'],
        'd'   => $entry['description'],
        's'   => $entry['source'],
        'u'   => $entry['created_by_user_id'],
    ]);
    return (int) $pdo->lastInsertId();
}

function jobdivaMapTimeCategory(string $jd): string
{
    $map = [
        'regular'    => 'regular_billable',
        'overtime'   => 'OT_billable',
        'ot'         => 'OT_billable',
        'holiday'    => 'holiday',
        'vacation'   => 'vacation',
        'sick'       => 'sick',
        'pto'        => 'vacation',
    ];
    $k = strtolower(trim($jd));
    return $map[$k] ?? 'regular_billable';
}

function jobdivaUnmapTimeCategory(string $cf): string
{
    $map = [
        'regular_billable'    => 'regular',
        'regular_nonbillable' => 'regular',
        'OT_billable'         => 'overtime',
        'OT_nonbillable'      => 'overtime',
        'holiday'             => 'holiday',
        'vacation'            => 'vacation',
        'sick'                => 'sick',
    ];
    return $map[$cf] ?? 'regular';
}
