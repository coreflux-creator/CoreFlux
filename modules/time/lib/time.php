<?php
/**
 * Time Module — Phase A lib (cross-module reads + core helpers)
 *
 * SPEC: /app/modules/time/SPEC.md
 */

require_once __DIR__ . '/../../../core/tenant_scope.php';
require_once __DIR__ . '/../../../core/sub_tenants.php';
require_once __DIR__.'/../../staffing/lib/time_workspace.php';

const TIME_CATEGORIES = [
    'regular_billable','regular_nonbillable','OT_billable','OT_nonbillable',
    'holiday','vacation','sick','bereavement','unpaid_leave','custom',
];

/** Categories that always roll up to the billable bucket. */
const TIME_BILLABLE_CATS    = ['regular_billable','OT_billable'];
const TIME_NONBILLABLE_CATS = ['regular_nonbillable','OT_nonbillable'];
const TIME_PTO_CATS         = ['holiday','vacation','sick','bereavement'];
const TIME_UNPAID_CATS      = ['unpaid_leave'];

/** Translate the atomic time category into the weekly-timesheet columns. */
function timeCategoryRowMeta(string $category): array
{
    $hourType = match ($category) {
        'OT_billable', 'OT_nonbillable' => 'overtime',
        'holiday' => 'holiday',
        'vacation' => 'pto',
        'sick' => 'sick',
        'bereavement' => 'bereavement',
        'unpaid_leave' => 'unpaid',
        'regular_nonbillable', 'custom' => 'nonbillable',
        default => 'regular',
    };
    return [
        'hour_type' => $hourType,
        'billable' => in_array($category, TIME_BILLABLE_CATS, true) ? 1 : 0,
        'payable' => 1,
    ];
}

/** Resolve the tenant's configured seven-day week for a work date. */
function timeWeekBounds(string $workDate): array
{
    $settings=staffingTimeFind('SELECT week_starts_on FROM tenant_staffing_settings WHERE tenant_id=:tenant_id LIMIT 1');
    $startsOn=(int)($settings['week_starts_on']??1);
    if ($startsOn < 0 || $startsOn > 6) $startsOn = 1;
    $date = new \DateTimeImmutable($workDate);
    $offset = ((int) $date->format('w') - $startsOn + 7) % 7;
    $start = $date->modify("-{$offset} days");
    return [$start->format('Y-m-d'), $start->modify('+6 days')->format('Y-m-d')];
}

/** Find or create an open weekly period so entry does not require setup first. */
function timeOpenPeriodIdForDate(string $workDate): int
{
    $period = staffingTimeFind(
        'SELECT id, status FROM time_periods
         WHERE tenant_id = :tenant_id AND start_date <= :wd_lo AND end_date >= :wd_hi
         ORDER BY start_date DESC LIMIT 1',
        ['wd_lo' => $workDate, 'wd_hi' => $workDate]
    );
    if ($period) {
        if (($period['status'] ?? '') !== 'open') {
            throw new \RuntimeException("The time period covering {$workDate} is {$period['status']} and cannot accept changes", 409);
        }
        return (int) $period['id'];
    }

    [$startDate, $endDate] = timeWeekBounds($workDate);
    try {
        return (int) staffingTimeInsert('time_periods', [
            'period_type' => 'weekly',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'label' => "Week of {$startDate}",
            'status' => 'open',
        ]);
    } catch (\Throwable $e) {
        $existing = staffingTimeFind(
            'SELECT id, status FROM time_periods
             WHERE tenant_id = :tenant_id AND start_date = :sd AND end_date = :ed LIMIT 1',
            ['sd' => $startDate, 'ed' => $endDate]
        );
        if (!$existing || ($existing['status'] ?? '') !== 'open') throw $e;
        return (int) $existing['id'];
    }
}

function timePlacementGraphTenantId(?int $tenantId = null): ?int
{
    if (class_exists('StaffingNativeTimeEdit',false) && StaffingNativeTimeEdit::active() && ($tenantId===null || $tenantId===staffingTimeWorkspace())) return StaffingNativeTimeEdit::owner('placements');
    $tenantId = $tenantId ?? currentTenantId();
    if (!$tenantId) return null;
    try {
        return effectiveTenantIdForModule('placements', $tenantId) ?? $tenantId;
    } catch (\Throwable $_) {
        return $tenantId;
    }
}

/**
 * Resolve the tenant's bundle-correction grace window in days.
 * Spec re-audit reversal: post-consume bundle supersession is
 * allowed within this window. Beyond the window, supersession
 * SKIPS (does not silently overwrite) and emits an audit event so
 * operators can run an explicit override path.
 */
function timeBundleCorrectionGraceDays(?int $tid = null): int {
    static $cache = [];
    $tid = $tid ?? currentTenantId();
    if (isset($cache[$tid])) return $cache[$tid];
    try {
        $pdo = getDB();
        $st  = $pdo->prepare('SELECT time_bundle_correction_grace_days FROM tenants WHERE id = :t LIMIT 1');
        $st->execute(['t' => $tid]);
        $days = (int) ($st->fetchColumn() ?: 7);
    } catch (\Throwable $e) {
        $days = 7;
    }
    return $cache[$tid] = ($days > 0 ? $days : 7);
}

/**
 * True when a consumed bundle's `consumed_at` timestamp is still
 * within the grace window. Null timestamp = treat as within (never
 * actually consumed). Caller decides what to do when out-of-window.
 */
function timeBundleWithinGrace(?string $consumedAt, int $graceDays): bool {
    if ($consumedAt === null || $consumedAt === '' || $consumedAt === '0000-00-00 00:00:00') return true;
    try {
        $ts = strtotime($consumedAt);
        if ($ts === false) return true;
        $age = (time() - $ts) / 86400.0;
        return $age <= $graceDays;
    } catch (\Throwable $e) {
        return true;
    }
}

function timeEntryGet(int $id): ?array
{
    return staffingTimeFind(
        'SELECT * FROM time_entries WHERE tenant_id = :tenant_id AND id = :id',
        ['id' => $id]
    );
}

/**
 * Keep the weekly staffing header aligned with its atomic time rows.
 * The least-complete row wins: a week with any draft remains draft, a
 * fully submitted week is submitted, and only an all-approved week is
 * approved. Downstream-ready/locked headers are never moved backwards.
 */
function timeReconcileTimesheetHeader(?int $timesheetId, ?int $actorUserId = null): void
{
    $timesheetId = (int) $timesheetId;
    if ($timesheetId <= 0) return;

    require_once __DIR__ . '/../../staffing/lib/timesheets.php';
    $pdo = getDB();
    $tenantId = staffingTimeWorkspace();
    $ownsTxn = cf_tx_begin($pdo);
    $savepoint = 'time_reconcile_' . bin2hex(random_bytes(6));
    if (!$ownsTxn) $pdo->exec('SAVEPOINT ' . $savepoint);
    try {
        $header = staffingTimesheetRow($tenantId, $timesheetId, true);
        if (!$header) {
            if (!$ownsTxn) $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            cf_tx_commit($pdo, $ownsTxn);
            return;
        }

        // Locking reads see current rows even inside a caller's repeatable-read transaction.
        $stmt = $pdo->prepare(
            "SELECT status, hours, approved_by_user_id, approved_at FROM time_entries
              WHERE tenant_id = :tenant_id AND timesheet_id = :timesheet_id
                AND status != 'superseded' ORDER BY id FOR UPDATE"
        );
        $stmt->execute(['tenant_id' => $tenantId, 'timesheet_id' => $timesheetId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $counts = array_count_values(array_column($rows, 'status'));
        $reviewers = [];
        $missingReviewerCount = 0;
        $latestApproval = null;
        foreach ($rows as $row) {
            if ($row['status'] !== 'approved') continue;
            $reviewer = (int) ($row['approved_by_user_id'] ?? 0);
            if ($reviewer > 0) $reviewers[$reviewer] = $reviewer;
            else $missingReviewerCount++;
            if (!empty($row['approved_at']) && ($latestApproval === null || $row['approved_at'] > $latestApproval)) {
                $latestApproval = $row['approved_at'];
            }
        }
        sort($reviewers, SORT_NUMERIC);
        $update = ['total_hours' => round(array_sum(array_column($rows, 'hours')), 2)];
        $currentStatus = (string) $header['status'];
        if (!in_array($currentStatus, ['payroll_ready', 'billing_ready', 'locked'], true)) {
            if (!$rows || !empty($counts['draft'])) $nextStatus = 'draft';
            elseif (!empty($counts['rejected'])) $nextStatus = 'rejected';
            elseif (!empty($counts['pending_review'])) $nextStatus = 'submitted';
            elseif (($counts['approved'] ?? 0) === count($rows)) $nextStatus = 'approved';
            else $nextStatus = 'draft';
            $update['status'] = $nextStatus;
            if ($nextStatus === 'submitted' && $currentStatus !== 'submitted') {
                $update['submitted_at'] = date('Y-m-d H:i:s');
            }
            // A week approved by several reviewers has no single approving user.
            // Preserve each row's evidence; never invent an approver or approval date.
            $update['approved_by_user_id'] = $nextStatus === 'approved'
                && count($reviewers) === 1 && $missingReviewerCount === 0 ? $reviewers[0] : null;
            $update['approved_at'] = $nextStatus === 'approved' ? $latestApproval : null;
        }

        $changed = [];
        foreach ($update as $field => $value) {
            $prior = $header[$field] ?? null;
            if ($value === null ? $prior !== null : ($prior === null || (string) $prior !== (string) $value)) {
                if ($field === 'total_hours' && (float) $prior === $value) continue;
                $changed[$field] = $value;
            }
        }
        if ($changed) staffingTimeUpdate('staffing_timesheets', $timesheetId, $changed);
        $header = array_replace($header, $update);
        if ($header['status']==='approved' && StaffingNativeTimeEdit::active()) {
            require_once __DIR__.'/accounting_handoff.php';
            timeAccountingHandoffEnqueue($tenantId,$timesheetId);
        }
        $artifact = empty($header['artifact_id']) ? null : artifactGet($tenantId, (string) $header['artifact_id']);
        if ($changed || !$artifact
            || $artifact['status'] !== staffingTimesheetArtifactStatus($header['status'])
            || $artifact['title'] !== staffingTimesheetDisplayId($timesheetId) . ' - week of ' . $header['period_start']
            || artifactComparableJson($artifact['payload']) !== artifactComparableJson(staffingTimesheetArtifactPayload($header))) {
            staffingTimesheetRecordArtifactEvent($timesheetId, 'timesheet.entries_reconciled', $actorUserId, [
                'prior_status' => $currentStatus,
                'status' => $header['status'],
                'changed_fields' => array_keys($changed),
                'approver_user_ids' => $reviewers,
                'approved_entries_without_user' => $missingReviewerCount,
                'source' => 'atomic_time_entries',
            ], $tenantId);
        }
        if (!$ownsTxn) $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        cf_tx_commit($pdo, $ownsTxn);
    } catch (\Throwable $e) {
        if (!$ownsTxn && $pdo->inTransaction()) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        }
        cf_tx_rollback($pdo, $ownsTxn);
        throw $e;
    }
}

/** Resolve the talent-pool person linked to an authenticated user. */
function timePersonIdForUser(array $user): ?int
{
    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) return null;
    $protected = class_exists('StaffingNativeTimeEdit', false) && StaffingNativeTimeEdit::active();
    $owner = $protected ? StaffingNativeTimeEdit::owner('people')
        : (effectiveTenantIdForModule('people', (int)currentTenantId()) ?? currentTenantId());
    // Read the authenticated account again; a stale session email is not ownership.
    $q = getDB()->prepare('SELECT email FROM users WHERE id=?'.($protected ? ' FOR UPDATE' : ''));
    $q->execute([$userId]);
    $email = trim((string)$q->fetchColumn());
    if ($email === '') return null;
    $q = getDB()->prepare('SELECT id FROM people WHERE tenant_id=? AND deleted_at IS NULL AND LOWER(email_primary)=LOWER(?) ORDER BY id'.($protected ? ' FOR UPDATE' : ''));
    $q->execute([$owner, $email]);
    $ids = $q->fetchAll(PDO::FETCH_COLUMN);
    // Never pick an arbitrary person if legacy data has an ambiguous identity.
    return count($ids) === 1 ? (int)$ids[0] : null;
}

/** Atomically move one or more draft entries into review. */
function timeSubmitEntries(array $entryIds, array $user, bool $canManageOthers = false): array
{
    require_once __DIR__.'/native_submission.php';
    if (!$entryIds) throw new RuntimeException('At least one entry is required', 422);
    foreach ($entryIds as $id) {
        if ((!is_int($id) && !is_string($id)) || filter_var($id, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) === false) {
            throw new RuntimeException('Choose valid time entry IDs', 422);
        }
    }
    $entryIds = array_values(array_unique(array_map('intval', $entryIds)));
    sort($entryIds, SORT_NUMERIC);
    if (count($entryIds) > 500) throw new RuntimeException('Too many entries (max 500 per submission)', 422);
    return StaffingNativeTimeEdit::run(static function() use ($entryIds, $user, $canManageOthers): array {
        $q = getDB()->prepare('SELECT * FROM time_entries WHERE tenant_id=? AND id IN ('.implode(',', array_fill(0, count($entryIds), '?')).') ORDER BY id FOR UPDATE');
        $q->execute([staffingTimeWorkspace(), ...$entryIds]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== count($entryIds)) throw new RuntimeException('Time entries not found in this workspace', 404);
        foreach ($rows as $row) {
            if ($row['status'] !== 'draft') throw new RuntimeException('Only draft entries can be submitted: #'.$row['id'].' is '.$row['status'], 409);
        }
        if (!$canManageOthers) {
            $personId = timePersonIdForUser($user);
            if (!$personId) throw new RuntimeException('Your login is not linked to one current People record. Ask an administrator to review your work email.', 403);
            foreach ($rows as $row) {
                if ((int)$row['person_id'] !== $personId) throw new RuntimeException('You can only submit your own time entries', 403);
            }
        }
        return timeNativeApplySubmission($rows, (int)($user['id'] ?? 0));
    });
}

/**
 * Approve one pending entry using the same controls for single-row, bulk,
 * and future workflow callers. Throws a RuntimeException whose code maps to
 * the intended HTTP status; callers decide whether to fail the request or
 * collect a per-row bulk result.
 */
function timeApproveEntry(int $id, array $user, string $approvedVia = 'manual'): array
{
    require_once __DIR__.'/native_review.php';
    require_once __DIR__.'/../../../core/quanta/time_current.php';
    $reviewChannel=$approvedVia;
    if ($approvedVia==='bulk_review') $approvedVia='manual';
    if (!in_array($approvedVia,['manual','bulk_pre_approved'],true)) throw new RuntimeException('Unsupported time approval method',422);
    return StaffingNativeTimeEdit::run(static function()use($id,$user,$approvedVia,$reviewChannel):array {
        $entry=timeEntryGet($id);
        if (!$entry) throw new RuntimeException("Entry {$id} not found",404);
        $actor=(int)($user['id']??0);
        $snapshot=timeNativeApprovalSnapshot($entry,$actor);
        $sourceIds=timeNativeApprovalSourceIds([$entry],$actor);
        $sourceCheck=quantaTimeAssertCurrent(getDB(),staffingTimeWorkspace(),$sourceIds);
        if (timeEntryGet($id)!==$entry) throw new RuntimeException('The time entry changed during approval. Refresh and review it again.',409);
        $approved=timeNativeWriteApproval($entry,$snapshot,$actor,$approvedVia,[
            'review_channel'=>$reviewChannel,'quanta_source_check'=>$sourceCheck?:null,
        ]);
        timeReconcileTimesheetHeader((int)($entry['timesheet_id']??0),$actor);
        return $approved;
    });
}

function timeRejectEntry(int $id, array $user, string $reason): array
{
    require_once __DIR__.'/native_decisions.php';
    return StaffingNativeTimeEdit::run(static function()use($id,$user,$reason):array {
        $entry=timeEntryGet($id);
        if (!$entry) throw new RuntimeException("Entry {$id} not found",404);
        return timeNativeRejectRows([$entry],(int)($user['id']??0),$reason)[0];
    });
}

function timeEntriesList(array $filters = []): array
{
    $where  = ['te.tenant_id = :tenant_id'];
    $params = [];
    if (!empty($filters['period_id']))    { $where[] = 'te.period_id = :period_id';       $params['period_id'] = (int) $filters['period_id']; }
    if (!empty($filters['placement_id'])) { $where[] = 'te.placement_id = :placement_id'; $params['placement_id'] = (int) $filters['placement_id']; }
    if (!empty($filters['person_id']))    { $where[] = 'te.person_id = :person_id';       $params['person_id'] = (int) $filters['person_id']; }
    if (!empty($filters['status']))       { $where[] = 'te.status = :status';              $params['status'] = $filters['status']; }
    if (!empty($filters['source']))       { $where[] = 'te.source = :source';              $params['source'] = $filters['source']; }
    if (!empty($filters['category']))     { $where[] = 'te.category = :category';          $params['category'] = $filters['category']; }
    if (!empty($filters['work_date']))    { $where[] = 'te.work_date = :wd';               $params['wd'] = $filters['work_date']; }
    if (!empty($filters['work_date_from'])) { $where[] = 'te.work_date >= :wdf';           $params['wdf'] = $filters['work_date_from']; }
    if (!empty($filters['work_date_to']))   { $where[] = 'te.work_date <= :wdt';           $params['wdt'] = $filters['work_date_to']; }

    $page    = max(1, (int) ($filters['page'] ?? 1));
    $perPage = min(500, max(1, (int) ($filters['per_page'] ?? 50)));
    $offset  = ($page - 1) * $perPage;
    $whereSql = implode(' AND ', $where);

    $total = (int) (staffingTimeFind("SELECT COUNT(*) AS c FROM time_entries te WHERE {$whereSql}", $params)['c'] ?? 0);
    // FK joins are bound to the *target module's* effective tenant —
    // not te.tenant_id. People and placements default to `'shared'`
    // sub-tenant scope, so for a sub-tenant the time_entries row lives
    // under the sub but the people/placements rows live under the
    // parent. Joining on te.tenant_id silently misses every row →
    // operator sees "name + placement: —" in the time entries list.
    $params['people_tid']     = effectiveTenantIdForModule('people')     ?? currentTenantId();
    $params['placements_tid'] = effectiveTenantIdForModule('placements') ?? currentTenantId();
    $rows  = staffingTimeQuery(
        "SELECT te.*, pe.first_name, pe.last_name, pe.email_primary,
                pl.title AS placement_title, pl.end_client_name
         FROM time_entries te
         LEFT JOIN people pe     ON pe.id = te.person_id    AND pe.tenant_id = :people_tid
         LEFT JOIN placements pl ON pl.id = te.placement_id AND pl.tenant_id = :placements_tid
         WHERE {$whereSql}
         ORDER BY te.work_date DESC, te.id DESC
         LIMIT " . (int) $perPage . " OFFSET " . (int) $offset,
        $params
    );
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
}

/** Return the approved placement_rates row that covers $workDate, or null. */
function timeResolveRateSnapshot(int $placementId, string $workDate, ?int $tenantId = null): ?array
{
    $tenantId = timePlacementGraphTenantId($tenantId);
    $pdo = getDB();
    if (!$pdo || !$tenantId) return null;
    $stmt = $pdo->prepare(
        'SELECT *
         FROM placement_rates
         WHERE tenant_id = :tenant_id AND placement_id = :pid
           AND approved_at IS NOT NULL
           AND effective_from <= :wd_from
           AND (effective_to IS NULL OR effective_to >= :wd_to)
         ORDER BY effective_from DESC, id DESC LIMIT 1'.(class_exists('StaffingNativeTimeEdit',false) && StaffingNativeTimeEdit::active()?' FOR UPDATE':'')
    );
    $stmt->execute([
        'tenant_id' => $tenantId,
        'pid'       => $placementId,
        'wd_from'   => $workDate,
        'wd_to'     => $workDate,
    ]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row ?: null;
}

function timeRateCategoryMultiplier(array $rate, string $category): float
{
    $category = strtolower(trim($category));
    if ($category === 'doubletime' || $category === 'dt' || str_contains($category, 'double')) {
        return (float) ($rate['dt_multiplier'] ?? 2.0);
    }
    if ($category === 'overtime' || $category === 'ot' || str_contains($category, 'ot_') || str_contains($category, 'overtime')) {
        return (float) ($rate['ot_multiplier'] ?? 1.5);
    }
    return 1.0;
}

/**
 * Calculate bundle totals from each entry's locked rate snapshot.
 *
 * @return array{totals:array<string,float>,amount_bill:float,amount_pay:float,rate_ids:int[],primary_rate_id:?int,missing_rate_entry_ids:int[]}
 */
function timeEntrySnapshotFinancialTotals(array $entries, array $ratesByEntry): array
{
    $totals = ['billable' => 0.0, 'nonbillable' => 0.0, 'pto' => 0.0, 'unpaid' => 0.0, 'custom' => 0.0];
    $amountBill = 0.0;
    $amountPay = 0.0;
    $rateIds = [];
    $missing = [];

    foreach ($entries as $entry) {
        $hours = (float) ($entry['hours'] ?? 0);
        $category = (string) ($entry['category'] ?? $entry['hour_type'] ?? 'regular_billable');
        $bucket = timeBucket($category);
        $totals[$bucket] = ($totals[$bucket] ?? 0.0) + $hours;

        $rateId = (int) ($entry['rate_snapshot_id'] ?? 0);
        $rate = $ratesByEntry[(int)($entry['id'] ?? 0)] ?? null;
        if (!$rate) {
            $missing[] = (int) ($entry['id'] ?? 0);
            continue;
        }
        $rateIds[] = $rateId;
        $multiplier = timeRateCategoryMultiplier($rate, (string)($entry['hour_type'] ?? $category));
        $billBase = (float) ($rate['adjusted_bill_rate'] ?? $rate['bill_rate'] ?? 0);
        if ((int)($entry['billable'] ?? 0) === 1) $amountBill += round(round($billBase * $multiplier, 4) * $hours, 2);

        if ((int)($entry['payable'] ?? 0) !== 1) continue;
        $dimensions = json_decode((string)($entry['dimension_snapshot_json'] ?? ''), true);
        if (($dimensions['engagement_type'] ?? null) === 'referral') {
            if (!isset($dimensions['referral_fee_per_hour']) || !is_numeric($dimensions['referral_fee_per_hour'])) {
                $missing[] = (int) $entry['id'];
                continue;
            }
            $amountPay += round((float) $dimensions['referral_fee_per_hour'] * $hours, 2);
            continue;
        }

        $payBase = (float) ($rate['pay_rate'] ?? 0);
        $amountPay += round(round($payBase * $multiplier, 4) * $hours, 2);
    }

    $rateIds = array_values(array_unique(array_filter($rateIds)));
    return [
        'totals' => $totals,
        'amount_bill' => $amountBill,
        'amount_pay' => $amountPay,
        'rate_ids' => $rateIds,
        'primary_rate_id' => $rateIds[0] ?? null,
        'missing_rate_entry_ids' => array_values(array_unique(array_filter($missing))),
    ];
}

/** Bucket for totals aggregation. */
function timeBucket(string $category): string
{
    if (in_array($category, TIME_BILLABLE_CATS,    true)) return 'billable';
    if (in_array($category, TIME_NONBILLABLE_CATS, true)) return 'nonbillable';
    if (in_array($category, TIME_PTO_CATS,         true)) return 'pto';
    if (in_array($category, TIME_UNPAID_CATS,      true)) return 'unpaid';
    return 'custom';
}

/**
 * Build (or rebuild) downstream feed bundles for all approved entries in
 * a period. Returns array of built bundle rows (keyed by placement_id,
 * bundle_type). Idempotent: re-running replaces contents of existing bundles
 * in status='ready'; bundles with status='consumed' are never overwritten —
 * a superseded bundle is created instead with status='superseded'.
 */
function timeBuildBundlesForPeriod(int $periodId): array
{
    $period = staffingTimeFind('SELECT * FROM time_periods WHERE tenant_id = :tenant_id AND id = :id', ['id' => $periodId]);
    if (!$period) throw new \RuntimeException("Period {$periodId} not found");

    require_once __DIR__ . '/approval_economics.php';

    $entries = staffingTimeQuery(
        'SELECT * FROM time_entries
         WHERE tenant_id = :tenant_id AND period_id = :pid AND status = "approved"
         ORDER BY placement_id, work_date',
        ['pid' => $periodId]
    );

    // Group by placement_id
    $byPlacement = [];
    foreach ($entries as $e) {
        $byPlacement[$e['placement_id']][] = $e;
    }

    $built = [];
    $pdo = getDB();
    $ratesByEntry = timeApprovedRatesByEntry($entries, staffingTimeWorkspace());
    foreach ($byPlacement as $placementId => $group) {
        $calc = timeEntrySnapshotFinancialTotals($group, $ratesByEntry);
        if ($calc['missing_rate_entry_ids']) {
            throw new \RuntimeException(
                'Approved entries are missing locked rate snapshots: ' . implode(', ', $calc['missing_rate_entry_ids'])
            );
        }
        $totals = $calc['totals'];
        $entryIds = [];
        foreach ($group as $e) {
            $entryIds[] = (int) $e['id'];
        }

        foreach (['ar','ap','payroll','revrec'] as $bundleType) {
            $payload = [
                'entries_json'           => json_encode(['entry_ids' => $entryIds, 'totals' => $totals, 'rate_ids' => $calc['rate_ids']]),
                'rate_snapshot_id'       => $calc['primary_rate_id'],
                'total_hours_billable'   => round($totals['billable'], 2),
                'total_hours_nonbillable'=> round($totals['nonbillable'], 2),
                'total_hours_pto'        => round($totals['pto'], 2),
                'total_amount_bill'      => round($calc['amount_bill'], 2),
                'total_amount_pay'       => round($calc['amount_pay'], 2),
            ];

            // Find existing bundle
            $existing = staffingTimeFind(
                'SELECT id, status, consumed_at FROM time_downstream_feed
                 WHERE tenant_id = :tenant_id AND period_id = :pid AND placement_id = :plid AND bundle_type = :bt',
                ['pid' => $periodId, 'plid' => $placementId, 'bt' => $bundleType]
            );
            if ($existing && $existing['status'] === 'consumed') {
                // P1.9 — Bundle-correction grace window. Spec re-audit
                // reversed the earlier "no grace period on consumed
                // bundles" rule. Within window: supersede as before.
                // Beyond window: SKIP and audit so an operator can run
                // the explicit override flow (admin endpoint, not
                // automatic) instead of accruing a silent overwrite
                // past the close-window.
                $graceDays = timeBundleCorrectionGraceDays();
                if (!timeBundleWithinGrace($existing['consumed_at'] ?? null, $graceDays)) {
                    timeAudit('time.bundle.grace_exceeded_skipped', [
                        'period_id'    => $periodId,
                        'placement_id' => $placementId,
                        'bundle_type'  => $bundleType,
                        'prior_bundle_id' => (int) $existing['id'],
                        'consumed_at'  => $existing['consumed_at'] ?? null,
                        'grace_days'   => $graceDays,
                    ], (int) $existing['id']);
                    continue;
                }
                // Consumed bundles are immutable — produce a superseded-status row alongside.
                $stmt = $pdo->prepare(
                    'UPDATE time_downstream_feed SET status = "superseded"
                     WHERE tenant_id = :tenant_id AND id = :id'
                );
                $stmt->execute(['tenant_id' => currentTenantId(), 'id' => $existing['id']]);
                $newId = scopedInsert('time_downstream_feed', array_merge([
                    'tenant_id' => staffingTimeWorkspace(), 'period_id' => $periodId, 'placement_id' => $placementId,
                    'bundle_type' => $bundleType, 'status' => 'ready',
                ], $payload));
                $built[] = ['id' => $newId, 'placement_id' => $placementId, 'bundle_type' => $bundleType, 'status' => 'ready', 'superseded_prior' => $existing['id']];
            } else if ($existing) {
                $sets = implode(',', array_map(static fn(string $key): string => '`'.$key.'`=?', array_keys($payload)));
                $pdo->prepare('UPDATE time_downstream_feed SET status="ready",'.$sets.' WHERE tenant_id=? AND id=?')
                    ->execute([...array_values($payload), staffingTimeWorkspace(), (int)$existing['id']]);
                $built[] = ['id' => (int) $existing['id'], 'placement_id' => $placementId, 'bundle_type' => $bundleType, 'status' => 'ready'];
            } else {
                $newId = scopedInsert('time_downstream_feed', array_merge([
                    'tenant_id' => staffingTimeWorkspace(), 'period_id' => $periodId, 'placement_id' => $placementId,
                    'bundle_type' => $bundleType, 'status' => 'ready',
                ], $payload));
                $built[] = ['id' => $newId, 'placement_id' => $placementId, 'bundle_type' => $bundleType, 'status' => 'ready'];
            }
        }
    }

    // Accrual-at-approval hook (2026-02). Per the corrected accounting
    // model: timesheet approval IS the recognition event. For every ar/ap
    // bundle that just landed in status='ready', post per-accounting-period
    // accrual JEs (Dr AR Unbilled / Cr Revenue  +  Dr Expense / Cr AP Accrued)
    // when the tenant has multi_period_split_enabled=1. Failures are
    // logged-and-swallowed so a single misconfigured GL period doesn't
    // block the entire bundle build — operators can re-trigger via the
    // bundle's idempotent accrual key once the period is seeded.
    require_once __DIR__ . '/../../accounting/lib/multi_period.php';
    $tidNow = currentTenantId();
    $settings = accountingSettingsGet((int) $tidNow);
    if (!empty($settings['multi_period_split_enabled'])) {
        foreach ($built as $b) {
            if (!in_array($b['bundle_type'], ['ar', 'ap'], true)) continue;
            try {
                accountingPostBundleAccrual(
                    (int) $tidNow,
                    (int) $b['id'],
                    (string) $b['bundle_type']
                );
            } catch (\Throwable $e) {
                error_log(sprintf(
                    '[time.bundle.accrual] bundle_id=%d type=%s failed: %s',
                    (int) $b['id'], $b['bundle_type'], $e->getMessage()
                ));
            }
        }
    }
    return $built;
}

/**
 * Read-only "what would happen if I closed this period" preview.
 * Returns bundle rollups + blockers WITHOUT writing to the DB.
 *
 * Shape:
 *   [
 *     'period'                 => row,
 *     'blockers'               => ['pending_review_count' => N],
 *     'informational'          => ['draft_count' => N, 'rejected_count' => N],
 *     'approved_entries_count' => N,
 *     'bundles'                => [
 *        {placement_id, placement_title, end_client_name, bundle_type,
 *         entry_count, total_hours_billable, total_hours_nonbillable,
 *         total_hours_pto, total_amount_bill, total_amount_pay,
 *         supersedes_existing_bundle_id (?int)},
 *        ...
 *     ],
 *     'totals' => {placements, bundles, hours_billable, hours_pto,
 *                  amount_bill, amount_pay},
 *   ]
 */
function timePreviewBundlesForPeriod(int $periodId): array
{
    $period = staffingTimeFind('SELECT * FROM time_periods WHERE tenant_id = :tenant_id AND id = :id', ['id' => $periodId]);
    if (!$period) throw new \RuntimeException("Period {$periodId} not found");

    require_once __DIR__ . '/approval_economics.php';

    // Blockers + informational counts
    $statusCounts = staffingTimeQuery(
        'SELECT status, COUNT(*) AS c FROM time_entries
         WHERE tenant_id = :tenant_id AND period_id = :pid GROUP BY status',
        ['pid' => $periodId]
    );
    $byStatus = ['draft' => 0, 'pending_review' => 0, 'approved' => 0, 'rejected' => 0, 'superseded' => 0];
    foreach ($statusCounts as $r) { $byStatus[$r['status']] = (int) $r['c']; }

    // Approved entries (the only thing that bundles)
    $entries = staffingTimeQuery(
        'SELECT te.*, pl.title AS placement_title, pl.end_client_name
         FROM time_entries te
         LEFT JOIN placements pl ON pl.id = te.placement_id AND pl.tenant_id = :placements_tid
         WHERE te.tenant_id = :tenant_id AND te.period_id = :pid AND te.status = "approved"
         ORDER BY te.placement_id, te.work_date',
        ['pid' => $periodId, 'placements_tid' => effectiveTenantIdForModule('placements') ?? currentTenantId()]
    );

    $byPlacement = [];
    foreach ($entries as $e) { $byPlacement[$e['placement_id']][] = $e; }

    $bundles = [];
    $sum = ['placements' => 0, 'bundles' => 0, 'hours_billable' => 0.0, 'hours_pto' => 0.0, 'amount_bill' => 0.0, 'amount_pay' => 0.0];

    $ratesByEntry = timeApprovedRatesByEntry($entries, staffingTimeWorkspace());
    foreach ($byPlacement as $placementId => $group) {
        $sum['placements']++;
        $calc = timeEntrySnapshotFinancialTotals($group, $ratesByEntry);
        $totals = $calc['totals'];

        $title  = $group[0]['placement_title']  ?? null;
        $client = $group[0]['end_client_name']  ?? null;

        foreach (['ar','ap','payroll','revrec'] as $bundleType) {
            $existing = staffingTimeFind(
                'SELECT id, status FROM time_downstream_feed
                 WHERE tenant_id = :tenant_id AND period_id = :pid AND placement_id = :plid AND bundle_type = :bt',
                ['pid' => $periodId, 'plid' => $placementId, 'bt' => $bundleType]
            );
            $supersedes = ($existing && $existing['status'] === 'consumed') ? (int) $existing['id'] : null;

            $bundles[] = [
                'placement_id'                  => (int) $placementId,
                'placement_title'               => $title,
                'end_client_name'               => $client,
                'bundle_type'                   => $bundleType,
                'entry_count'                   => count($group),
                'total_hours_billable'          => round($totals['billable'], 2),
                'total_hours_nonbillable'       => round($totals['nonbillable'], 2),
                'total_hours_pto'               => round($totals['pto'], 2),
                'total_amount_bill'             => round($calc['amount_bill'], 2),
                'total_amount_pay'              => round($calc['amount_pay'], 2),
                'missing_rate_entry_ids'        => $calc['missing_rate_entry_ids'],
                'supersedes_existing_bundle_id' => $supersedes,
            ];
            $sum['bundles']++;
        }
        $sum['hours_billable'] += $totals['billable'];
        $sum['hours_pto']      += $totals['pto'];
        $sum['amount_bill']    += $calc['amount_bill'];
        $sum['amount_pay']     += $calc['amount_pay'];
    }

    return [
        'period' => $period,
        'blockers' => ['pending_review_count' => $byStatus['pending_review']],
        'informational' => ['draft_count' => $byStatus['draft'], 'rejected_count' => $byStatus['rejected']],
        'approved_entries_count' => $byStatus['approved'],
        'bundles' => $bundles,
        'totals' => [
            'placements'     => $sum['placements'],
            'bundles'        => $sum['bundles'],
            'hours_billable' => round($sum['hours_billable'], 2),
            'hours_pto'      => round($sum['hours_pto'], 2),
            'amount_bill'    => round($sum['amount_bill'], 2),
            'amount_pay'     => round($sum['amount_pay'], 2),
        ],
    ];
}

function timeAudit(string $event, array $meta = [], ?int $targetId = null): void
{
    require_once __DIR__ . '/../../../core/audit.php';
    platformAuditLogWrite(currentTenantId(), $_SESSION['user']['id'] ?? null, $event, $targetId, $meta);
}

/**
 * Per-entry approval audit emitter (2026-02 — accrual-at-approval P1.a).
 *
 * Bundle-level approval drives GL recognition (see
 * `accountingPostBundleAccrual`). Entry-level approval is audit-only:
 * every individual entry transition into status='approved' lands a
 * `time.entry.approved` row in `audit_log` with the per-entry context
 * (work_date, placement_id, hours, approved_via, approver). No GL
 * write — recognition is owned by the bundle path.
 *
 * Centralised so every approve site emits the same shape — keeps
 * downstream dashboards (audit_log queries, future event subscribers)
 * able to trust a stable payload regardless of which approve path
 * fired (manual / tokenized email / bulk pre-approved CSV).
 *
 * @param int    $entryId         time_entries.id that just landed approved
 * @param array  $entry           the time_entries row (for work_date/hours/etc.)
 * @param string $approvedVia     'manual'|'tokenized_client_email'|'bulk_pre_approved'
 * @param array  $approverContext extra metadata (approver_id, email, token_id, etc.)
 */
function timeEntryApprovedEmit(int $entryId, array $entry, string $approvedVia, array $approverContext = []): void
{
    $meta = array_merge([
        'entry_id'         => $entryId,
        'placement_id'     => isset($entry['placement_id']) ? (int) $entry['placement_id'] : null,
        'person_id'        => isset($entry['person_id'])    ? (int) $entry['person_id']    : null,
        'period_id'        => isset($entry['period_id'])    ? (int) $entry['period_id']    : null,
        'work_date'        => $entry['work_date'] ?? null,
        'category'         => $entry['category']  ?? null,
        'hours'            => isset($entry['hours']) ? (float) $entry['hours'] : null,
        'rate_snapshot_id' => isset($entry['rate_snapshot_id']) ? (int) $entry['rate_snapshot_id'] : null,
        'approved_via'     => $approvedVia,
    ], $approverContext);
    if (class_exists('StaffingNativeTimeEdit',false) && StaffingNativeTimeEdit::active()) {
        timeNativeRequiredAudit('time.entry.approved',$entryId,$meta,$approverContext['approver_user_id']??null);
    } else timeAudit('time.entry.approved', $meta, $entryId);
}
