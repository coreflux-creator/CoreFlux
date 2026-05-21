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

function jobdivaSyncTimePull(int $tid, ?int $userId, array $opts = []): array
{
    $items = jobdivaSyncFetchItems($tid, JOBDIVA_PATH_TIMESHEETS_DELTA, $opts);
    $processed = 0; $skipped = 0; $failed = 0; $errors = [];

    $pdo = getDB();
    foreach ($items as $jd) {
        try {
            $extId           = (string) ($jd['id']           ?? $jd['timesheetId'] ?? $jd['timesheet_id'] ?? '');
            $placementExtId  = (string) ($jd['placementId']  ?? $jd['placement_id'] ?? '');
            $workDate        = (string) ($jd['date']         ?? $jd['workDate'] ?? $jd['work_date'] ?? '');
            $hoursRaw        = $jd['hours'] ?? null;
            if ($extId === '' || $placementExtId === '' || $workDate === '' || $hoursRaw === null) {
                $skipped++; continue;
            }

            $placementMapping = mappingFindInternal($tid, 'jobdiva', 'placement', $placementExtId);
            if (!$placementMapping) { $skipped++; continue; }
            $placementId = (int) $placementMapping['internal_entity_id'];

            // Fetch person_id + period_id by joining placements + the active
            // time_period for this work_date.
            $row = $pdo->prepare(
                'SELECT p.person_id, tp.id AS period_id
                   FROM placements p
                   JOIN time_periods tp
                     ON tp.tenant_id = p.tenant_id
                    AND tp.start_date <= :wd1
                    AND tp.end_date   >= :wd2
                  WHERE p.id = :pid AND p.tenant_id = :t
                  LIMIT 1'
            );
            $row->execute(['pid' => $placementId, 't' => $tid, 'wd1' => $workDate, 'wd2' => $workDate]);
            $meta = $row->fetch(\PDO::FETCH_ASSOC);
            if (!$meta) { $skipped++; continue; }

            $internalId = jobdivaSyncUpsertTimeEntry($tid, [
                'placement_id' => $placementId,
                'person_id'    => (int) $meta['person_id'],
                'period_id'    => (int) $meta['period_id'],
                'work_date'    => $workDate,
                'hours'        => (float) $hoursRaw,
                'category'     => jobdivaMapTimeCategory((string) ($jd['category'] ?? 'regular_billable')),
                'description'  => $jd['description'] ?? null,
                'source'       => 'bulk_upload', // tagged so the source enum stays unchanged
                'created_by_user_id' => $userId,
            ], $extId);

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
        'detail'          => ['errors' => array_slice($errors, 0, 5)],
    ]);
    return ['processed' => $processed, 'skipped' => $skipped, 'failed' => $failed, 'errors' => $errors];
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
                SET hours = :h, category = :c, description = :d
              WHERE id = :id AND tenant_id = :t AND status IN ("draft","pending_review")'
        )->execute([
            'h'  => $entry['hours'],
            'c'  => $entry['category'],
            'd'  => $entry['description'],
            'id' => $existingId,
            't'  => $tid,
        ]);
        return $existingId;
    }
    $pdo->prepare(
        'INSERT INTO time_entries
            (tenant_id, placement_id, person_id, period_id, work_date, category,
             hours, description, source, status, created_by_user_id)
         VALUES
            (:t, :pl, :p, :prd, :wd, :c, :h, :d, :s, "draft", :u)'
    )->execute([
        't'   => $tid,
        'pl'  => $entry['placement_id'],
        'p'   => $entry['person_id'],
        'prd' => $entry['period_id'],
        'wd'  => $entry['work_date'],
        'c'   => $entry['category'],
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
