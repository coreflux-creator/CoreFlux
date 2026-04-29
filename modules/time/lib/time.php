<?php
/**
 * Time Module — Phase A lib (cross-module reads + core helpers)
 *
 * SPEC: /app/modules/time/SPEC.md
 */

require_once __DIR__ . '/../../../core/tenant_scope.php';

const TIME_CATEGORIES = [
    'regular_billable','regular_nonbillable','OT_billable','OT_nonbillable',
    'holiday','vacation','sick','bereavement','unpaid_leave','custom',
];

/** Categories that always roll up to the billable bucket. */
const TIME_BILLABLE_CATS    = ['regular_billable','OT_billable'];
const TIME_NONBILLABLE_CATS = ['regular_nonbillable','OT_nonbillable'];
const TIME_PTO_CATS         = ['holiday','vacation','sick','bereavement'];
const TIME_UNPAID_CATS      = ['unpaid_leave'];

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
    $rows  = scopedQuery(
        "SELECT te.*, pe.first_name, pe.last_name, pe.email_primary,
                pl.title AS placement_title, pl.end_client_name
         FROM time_entries te
         LEFT JOIN people pe     ON pe.id = te.person_id    AND pe.tenant_id = te.tenant_id
         LEFT JOIN placements pl ON pl.id = te.placement_id AND pl.tenant_id = te.tenant_id
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
                'SELECT id, status FROM time_downstream_feed
                 WHERE tenant_id = :tenant_id AND period_id = :pid AND placement_id = :plid AND bundle_type = :bt',
                ['pid' => $periodId, 'plid' => $placementId, 'bt' => $bundleType]
            );
            if ($existing && $existing['status'] === 'consumed') {
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
    return $built;
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
