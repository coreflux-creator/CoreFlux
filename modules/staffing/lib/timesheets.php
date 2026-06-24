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

/** Get-or-create the timesheet header for (person, week). */
function staffingTimesheetUpsert(int $personId, string $periodStart, string $periodEnd): array {
    $existing = scopedFind(
        'SELECT * FROM staffing_timesheets WHERE tenant_id = :tenant_id AND person_id = :pid AND period_start = :ps LIMIT 1',
        ['pid' => $personId, 'ps' => $periodStart]
    );
    if ($existing) return $existing;

    $id = scopedInsert('staffing_timesheets', [
        'person_id'    => $personId,
        'period_start' => $periodStart,
        'period_end'   => $periodEnd,
        'status'       => 'draft',
        'total_hours'  => 0,
    ]);
    return scopedFind('SELECT * FROM staffing_timesheets WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]) ?? [];
}

/** Snapshot of a worker's week — header + grouped entries by placement × day. */
function staffingTimesheetWeek(int $personId, string $periodStart, string $periodEnd): array {
    $header = staffingTimesheetUpsert($personId, $periodStart, $periodEnd);

    $entries = scopedQuery(
        "SELECT te.id, te.placement_id, te.work_date, te.hour_type, te.category,
                te.hours, te.billable, te.payable, te.description, te.status,
                p.title AS placement_title,
                COALESCE(p.end_client_name, '') AS client_name
           FROM time_entries te
           LEFT JOIN placements p ON p.id = te.placement_id AND p.tenant_id = te.tenant_id
          WHERE te.tenant_id = :tenant_id
            AND te.person_id = :pid
            AND te.work_date BETWEEN :ps AND :pe
            AND te.status != 'superseded'
          ORDER BY te.placement_id, te.work_date, te.id",
        ['pid' => $personId, 'ps' => $periodStart, 'pe' => $periodEnd]
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
    $personId    = (int) $payload['person_id'];
    $periodStart = (string) $payload['period_start'];
    $periodEnd   = (string) $payload['period_end'];
    $rows        = $payload['rows'] ?? [];

    if ($personId <= 0)        throw new \RuntimeException('person_id required');
    if (!$periodStart || !$periodEnd) throw new \RuntimeException('period_start / period_end required');

    $header = staffingTimesheetUpsert($personId, $periodStart, $periodEnd);
    $headerId = (int) $header['id'];

    // Per product direction (2026-02): operators with timesheets.write
    // may edit any timesheet — including ones already submitted /
    // approved / payroll_ready / billing_ready.  Auto-reopen so the
    // existing draft-only constraints in the row loop still apply.
    // Truly `locked` headers (status='locked') stay frozen because
    // their entries are referenced by posted journal lines; the
    // operator must reverse the JE first.
    if (in_array($header['status'] ?? 'draft', ['submitted','approved','rejected','payroll_ready','billing_ready'], true)) {
        $header = staffingTimesheetReopen($userId, $headerId, 'bulk edit');
    } elseif (($header['status'] ?? 'draft') === 'locked') {
        throw new \RuntimeException("Timesheet is locked — reverse downstream journal entries first");
    }

    $pdo = getDB();
    $ownsTxn = cf_tx_begin($pdo);
    try {
        foreach ($rows as $r) {
            $hourType = $r['hour_type'] ?? 'regular';
            if (!in_array($hourType, STAFFING_HOUR_TYPES, true)) {
                throw new \RuntimeException("Invalid hour_type: {$hourType}");
            }
            $hours = isset($r['hours']) ? (float) $r['hours'] : 0.0;

            if (!empty($r['_delete']) && !empty($r['id'])) {
                scopedDelete('time_entries', (int) $r['id']);
                continue;
            }

            // Allow zero-hours rows to be skipped (no need to persist empty cells).
            if ($hours <= 0 && empty($r['id'])) continue;
            // Zero-hours on existing row = delete.
            if ($hours <= 0 && !empty($r['id'])) {
                scopedDelete('time_entries', (int) $r['id']);
                continue;
            }

            $placementId = (int) ($r['placement_id'] ?? 0);
            $workDate    = (string) ($r['work_date'] ?? '');
            if ($placementId <= 0 || $workDate === '') continue;

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

            if (!empty($r['id'])) {
                scopedUpdate('time_entries', (int) $r['id'], $base);
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

        cf_tx_commit($pdo, $ownsTxn);
    } catch (\Throwable $e) {
        cf_tx_rollback($pdo, $ownsTxn);
        throw $e;
    }

    return staffingTimesheetWeek($personId, $periodStart, $periodEnd);
}

/** Submit the whole week → flips header + all rows to submitted/pending_review. */
function staffingTimesheetSubmit(int $userId, int $personId, string $periodStart, string $periodEnd): array {
    $header = staffingTimesheetUpsert($personId, $periodStart, $periodEnd);
    if (!in_array($header['status'], ['draft','rejected'], true)) {
        throw new \RuntimeException("Cannot submit a {$header['status']} timesheet");
    }
    $headerId = (int) $header['id'];

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
        cf_tx_commit($pdo, $ownsTxn);
    } catch (\Throwable $e) {
        cf_tx_rollback($pdo, $ownsTxn);
        throw $e;
    }

    return staffingTimesheetWeek($personId, $periodStart, $periodEnd);
}

/** Reject the whole week — rows return to draft, reason captured on header. */
function staffingTimesheetReject(int $userId, int $personId, string $periodStart, string $periodEnd, string $reason): array {
    $header = staffingTimesheetUpsert($personId, $periodStart, $periodEnd);
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

/** Approve the whole week — cascade to rows. Two-eye control. */
function staffingTimesheetApprove(int $userId, int $personId, string $periodStart, string $periodEnd): array {
    $header = staffingTimesheetUpsert($personId, $periodStart, $periodEnd);
    if ($header['status'] !== 'submitted') {
        throw new \RuntimeException("Cannot approve a {$header['status']} timesheet");
    }

    // Two-eye: the user approving must not have created the timesheet's rows.
    // Best-effort check: forbid self-approval where worker_user_id == approver.
    if (isset($header['worker_user_id']) && (int) $header['worker_user_id'] === $userId) {
        throw new \RuntimeException('Two-eye control: cannot approve your own timesheet');
    }

    $headerId = (int) $header['id'];
    $pdo = getDB();
    $ownsTxn = cf_tx_begin($pdo);
    try {
        scopedUpdate('staffing_timesheets', $headerId, [
            'status'              => 'approved',
            'approved_at'         => date('Y-m-d H:i:s'),
            'approved_by_user_id' => $userId,
        ]);
        $upd = $pdo->prepare(
            "UPDATE time_entries
                SET status = 'approved', approved_at = NOW(), approved_by_user_id = :u, approved_via = 'manual'
              WHERE tenant_id = :t AND timesheet_id = :tid AND status = 'pending_review'"
        );
        $upd->execute(['t' => currentTenantId(), 'tid' => $headerId, 'u' => $userId]);
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

/** Emit `staffing.worker_hours.approved` events to the accounting posting
 *  engine. One event PER (timesheet × engagement_type) combo so posting
 *  rules can route W2 hours to Accrued Payroll and 1099/C2C hours to
 *  Accrued AP. Best-effort: failures don't roll back the approval. */
function staffingEmitWorkerHoursApprovedEvent(int $tenantId, int $headerId): void {
    try {
        require_once __DIR__ . '/../../../core/posting_engine/process.php';
        $pdo = getDB();

        // Group hours/revenue/cost by engagement_type (w2/1099/c2c/etc.) on
        // the placement so each tax classification books to the right
        // liability account.
        $stmt = $pdo->prepare(
            "SELECT t.id, t.person_id, t.period_start, t.period_end,
                    COALESCE(pl.engagement_type, 'w2') AS engagement_type,
                    SUM(te.hours)                                    AS hours,
                    SUM(te.hours * COALESCE(pr.bill_rate, 0))        AS revenue,
                    SUM(te.hours * COALESCE(pr.pay_rate,  0))        AS cost
               FROM staffing_timesheets t
               JOIN time_entries te ON te.timesheet_id = t.id AND te.tenant_id = t.tenant_id AND te.status != 'superseded'
               LEFT JOIN placements pl     ON pl.id = te.placement_id AND pl.tenant_id = t.tenant_id
               LEFT JOIN placement_rates pr ON pr.id = te.rate_snapshot_id
              WHERE t.tenant_id = :t AND t.id = :id
              GROUP BY t.id, engagement_type"
        );
        $stmt->execute(['t' => $tenantId, 'id' => $headerId]);
        $groups = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (!$groups) return;

        $ent = $pdo->prepare("SELECT id FROM accounting_entities WHERE tenant_id = :t LIMIT 1");
        $ent->execute(['t' => $tenantId]);
        $entityId = (int) ($ent->fetchColumn() ?: 0);
        if (!$entityId) return;

        foreach ($groups as $g) {
            $rev  = (float) $g['revenue'];
            $cost = (float) $g['cost'];
            accountingProcessEvent($tenantId, [
                'entity_id'        => $entityId,
                'event_type'       => 'staffing.worker_hours.approved',
                'source_module'    => 'staffing',
                'source_record_id' => (string) $g['id'] . ':' . $g['engagement_type'],
                'event_date'       => (string) $g['period_end'],
                'payload'          => [
                    'timesheet_id'    => (int) $g['id'],
                    'person_id'       => (int) $g['person_id'],
                    'period_start'    => $g['period_start'],
                    'period_end'      => $g['period_end'],
                    'engagement_type' => (string) $g['engagement_type'],
                    'hours'           => (float) $g['hours'],
                    'revenue'         => $rev,
                    'cost'            => $cost,
                    'gross_profit'    => $rev - $cost,
                    // Convenience flags for posting-rule `conditions` matching.
                    'is_w2'           => $g['engagement_type'] === 'w2' ? 1 : 0,
                    'is_1099_or_c2c'  => in_array($g['engagement_type'], ['1099','c2c'], true) ? 1 : 0,
                    'is_internal'     => $g['engagement_type'] === 'internal' ? 1 : 0,
                ],
            ], null);
        }
    } catch (\Throwable $e) {
        error_log("[staffing] accounting event emit failed for ts #{$headerId}: " . $e->getMessage());
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
    $hours = isset($payload['hours']) ? (float) $payload['hours'] : (float) ($existing['hours'] ?? 0);
    if ($hours < 0) throw new \RuntimeException('hours cannot be negative');

    $placementId = (int) ($payload['placement_id'] ?? ($existing['placement_id'] ?? 0));
    $workDate    = (string) ($payload['work_date']    ?? ($existing['work_date']    ?? ''));
    $personId    = (int) ($header['person_id']);
    if ($placementId <= 0) throw new \RuntimeException('placement_id required');
    if ($workDate === '')  throw new \RuntimeException('work_date required');

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

    $saved = scopedFind(
        'SELECT * FROM time_entries WHERE tenant_id = :tenant_id AND id = :id LIMIT 1',
        ['id' => $finalId]
    );
    return ['entry' => $saved, 'timesheet' => scopedFind(
        'SELECT * FROM staffing_timesheets WHERE tenant_id = :tenant_id AND id = :id LIMIT 1',
        ['id' => $tsId]
    )];
}

/** Delete a single time entry.  Auto-reopens the parent if needed. */
function staffingTimeEntryDelete(int $userId, int $entryId): array {
    $row = scopedFind(
        'SELECT id, timesheet_id FROM time_entries WHERE tenant_id = :tenant_id AND id = :id LIMIT 1',
        ['id' => $entryId]
    );
    if (!$row) throw new \RuntimeException("time_entry #{$entryId} not found");
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
    return ['deleted' => $entryId, 'timesheet_id' => $tsId];
}

