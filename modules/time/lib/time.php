<?php
/**
 * Time Module — Phase A lib (cross-module reads + core helpers)
 *
 * SPEC: /app/modules/time/SPEC.md
 */

require_once __DIR__ . '/../../../core/tenant_scope.php';
require_once __DIR__ . '/../../../core/sub_tenants.php';

const TIME_CATEGORIES = [
    'regular_billable','regular_nonbillable','OT_billable','OT_nonbillable',
    'holiday','vacation','sick','bereavement','unpaid_leave','custom',
];

/** Categories that always roll up to the billable bucket. */
const TIME_BILLABLE_CATS    = ['regular_billable','OT_billable'];
const TIME_NONBILLABLE_CATS = ['regular_nonbillable','OT_nonbillable'];
const TIME_PTO_CATS         = ['holiday','vacation','sick','bereavement'];
const TIME_UNPAID_CATS      = ['unpaid_leave'];

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
    return scopedFind(
        'SELECT * FROM time_entries WHERE tenant_id = :tenant_id AND id = :id',
        ['id' => $id]
    );
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

    $total = (int) (scopedFind("SELECT COUNT(*) AS c FROM time_entries te WHERE {$whereSql}", $params)['c'] ?? 0);
    // FK joins are bound to the *target module's* effective tenant —
    // not te.tenant_id. People and placements default to `'shared'`
    // sub-tenant scope, so for a sub-tenant the time_entries row lives
    // under the sub but the people/placements rows live under the
    // parent. Joining on te.tenant_id silently misses every row →
    // operator sees "name + placement: —" in the time entries list.
    $params['people_tid']     = effectiveTenantIdForModule('people')     ?? currentTenantId();
    $params['placements_tid'] = effectiveTenantIdForModule('placements') ?? currentTenantId();
    $rows  = scopedQuery(
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
function timeResolveRateSnapshot(int $placementId, string $workDate): ?array
{
    return scopedFind(
        'SELECT id, bill_rate, pay_rate, bill_rate_unit, pay_rate_unit, currency, ot_multiplier, dt_multiplier
         FROM placement_rates
         WHERE tenant_id = :tenant_id AND placement_id = :pid
           AND approved_at IS NOT NULL
           AND effective_from <= :wd
           AND (effective_to IS NULL OR effective_to >= :wd)
         ORDER BY effective_from DESC LIMIT 1',
        ['pid' => $placementId, 'wd' => $workDate]
    );
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
    $period = scopedFind('SELECT * FROM time_periods WHERE tenant_id = :tenant_id AND id = :id', ['id' => $periodId]);
    if (!$period) throw new \RuntimeException("Period {$periodId} not found");

    $entries = scopedQuery(
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
    foreach ($byPlacement as $placementId => $group) {
        // Resolve a representative rate_snapshot_id (should be stable across entries in period)
        $rateIds = array_unique(array_filter(array_column($group, 'rate_snapshot_id')));
        $rateId = $rateIds ? (int) reset($rateIds) : null;
        $rate = $rateId ? scopedFind('SELECT bill_rate, pay_rate FROM placement_rates WHERE tenant_id = :tenant_id AND id = :id', ['id' => $rateId]) : null;
        $bill = $rate ? (float) $rate['bill_rate'] : 0.0;
        $pay  = $rate ? (float) $rate['pay_rate'] : 0.0;

        $totals = ['billable' => 0.0, 'nonbillable' => 0.0, 'pto' => 0.0, 'unpaid' => 0.0];
        $entryIds = [];
        foreach ($group as $e) {
            $totals[timeBucket($e['category'])] += (float) $e['hours'];
            $entryIds[] = (int) $e['id'];
        }

        foreach (['ar','ap','payroll','revrec'] as $bundleType) {
            $payload = [
                'entries_json'           => json_encode(['entry_ids' => $entryIds, 'totals' => $totals]),
                'rate_snapshot_id'       => $rateId,
                'total_hours_billable'   => round($totals['billable'], 2),
                'total_hours_nonbillable'=> round($totals['nonbillable'], 2),
                'total_hours_pto'        => round($totals['pto'], 2),
                'total_amount_bill'      => round($bill * $totals['billable'], 2),
                'total_amount_pay'       => round($pay  * $totals['billable'], 2),
            ];

            // Find existing bundle
            $existing = scopedFind(
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
                    'period_id' => $periodId, 'placement_id' => $placementId,
                    'bundle_type' => $bundleType, 'status' => 'ready',
                ], $payload));
                $built[] = ['id' => $newId, 'placement_id' => $placementId, 'bundle_type' => $bundleType, 'status' => 'ready', 'superseded_prior' => $existing['id']];
            } else if ($existing) {
                scopedUpdate('time_downstream_feed', (int) $existing['id'], array_merge(['status' => 'ready'], $payload));
                $built[] = ['id' => (int) $existing['id'], 'placement_id' => $placementId, 'bundle_type' => $bundleType, 'status' => 'ready'];
            } else {
                $newId = scopedInsert('time_downstream_feed', array_merge([
                    'period_id' => $periodId, 'placement_id' => $placementId,
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
    $period = scopedFind('SELECT * FROM time_periods WHERE tenant_id = :tenant_id AND id = :id', ['id' => $periodId]);
    if (!$period) throw new \RuntimeException("Period {$periodId} not found");

    // Blockers + informational counts
    $statusCounts = scopedQuery(
        'SELECT status, COUNT(*) AS c FROM time_entries
         WHERE tenant_id = :tenant_id AND period_id = :pid GROUP BY status',
        ['pid' => $periodId]
    );
    $byStatus = ['draft' => 0, 'pending_review' => 0, 'approved' => 0, 'rejected' => 0, 'superseded' => 0];
    foreach ($statusCounts as $r) { $byStatus[$r['status']] = (int) $r['c']; }

    // Approved entries (the only thing that bundles)
    $entries = scopedQuery(
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

    foreach ($byPlacement as $placementId => $group) {
        $sum['placements']++;
        $rateIds = array_unique(array_filter(array_column($group, 'rate_snapshot_id')));
        $rateId  = $rateIds ? (int) reset($rateIds) : null;
        $rate    = $rateId ? scopedFind('SELECT bill_rate, pay_rate FROM placement_rates WHERE tenant_id = :tenant_id AND id = :id', ['id' => $rateId]) : null;
        $bill    = $rate ? (float) $rate['bill_rate'] : 0.0;
        $pay     = $rate ? (float) $rate['pay_rate'] : 0.0;

        $totals = ['billable' => 0.0, 'nonbillable' => 0.0, 'pto' => 0.0, 'unpaid' => 0.0];
        foreach ($group as $e) { $totals[timeBucket($e['category'])] += (float) $e['hours']; }

        $title  = $group[0]['placement_title']  ?? null;
        $client = $group[0]['end_client_name']  ?? null;

        foreach (['ar','ap','payroll','revrec'] as $bundleType) {
            $existing = scopedFind(
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
                'total_amount_bill'             => round($bill * $totals['billable'], 2),
                'total_amount_pay'              => round($pay  * $totals['billable'], 2),
                'supersedes_existing_bundle_id' => $supersedes,
            ];
            $sum['bundles']++;
        }
        $sum['hours_billable'] += $totals['billable'];
        $sum['hours_pto']      += $totals['pto'];
        $sum['amount_bill']    += $bill * $totals['billable'];
        $sum['amount_pay']     += $pay  * $totals['billable'];
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
    $pdo = getDB();
    if (!$pdo) { error_log("[time.audit] {$event} " . json_encode($meta)); return; }
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO audit_log (tenant_id, actor_user_id, event, target_id, meta_json, ip_address, request_id, created_at)
             VALUES (:tenant_id, :actor_user_id, :event, :target_id, :meta_json, :ip_address, :request_id, NOW())'
        );
        $stmt->execute([
            'tenant_id'     => currentTenantId(),
            'actor_user_id' => $_SESSION['user']['id'] ?? null,
            'event'         => $event,
            'target_id'     => $targetId,
            'meta_json'     => $meta ? json_encode($meta, JSON_UNESCAPED_SLASHES) : null,
            'ip_address'    => $_SERVER['REMOTE_ADDR'] ?? null,
            'request_id'    => $_SERVER['HTTP_X_REQUEST_ID'] ?? null,
        ]);
    } catch (\Throwable $e) {
        error_log("[time.audit] db-write-failed: " . $e->getMessage());
    }
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
    timeAudit('time.entry.approved', $meta, $entryId);
}
