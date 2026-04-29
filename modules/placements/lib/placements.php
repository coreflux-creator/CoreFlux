<?php
/**
 * Placements Module — cross-module read library
 *
 * Other modules (time, accounting, billing, payroll) use these helpers to
 * read placement data without selecting from `placement_*` tables directly.
 *
 * SPEC: /app/modules/placements/SPEC.md §6
 */

require_once __DIR__ . '/../../../core/tenant_scope.php';

function placementsSafeFields(string $alias = 'p'): string
{
    $cols = ['id','tenant_id','person_id','external_id','status','start_date','end_date',
             'actual_end_date','due_date','engagement_type','worksite_state','worksite_country',
             'remote_policy','title','end_client_name','notes',
             'client_approver_name','client_approver_email','tokenized_email_approval_enabled',
             'bulk_uploads_can_be_pre_approved',
             'created_by_user_id','created_at','updated_at','deleted_at'];
    return implode(', ', array_map(fn($c) => "{$alias}.{$c}", $cols));
}

function placementGet(int $id): ?array
{
    $sql = 'SELECT ' . placementsSafeFields() . '
            FROM placements p
            WHERE p.tenant_id = :tenant_id AND p.id = :id AND p.deleted_at IS NULL';
    return scopedFind($sql, ['id' => $id]);
}

function placementsList(array $filters = []): array
{
    $where  = ['p.tenant_id = :tenant_id', 'p.deleted_at IS NULL'];
    $params = [];
    if (!empty($filters['q'])) {
        $where[] = '(p.title LIKE :q OR p.end_client_name LIKE :q OR p.external_id = :qexact)';
        $params['q']      = '%' . $filters['q'] . '%';
        $params['qexact'] = $filters['q'];
    }
    if (!empty($filters['status'])) {
        $where[] = 'p.status = :status';
        $params['status'] = $filters['status'];
    }
    if (!empty($filters['person_id'])) {
        $where[] = 'p.person_id = :person_id';
        $params['person_id'] = (int) $filters['person_id'];
    }
    if (!empty($filters['end_client'])) {
        $where[] = 'p.end_client_name = :end_client';
        $params['end_client'] = $filters['end_client'];
    }
    if (!empty($filters['engagement_type'])) {
        $where[] = 'p.engagement_type = :etype';
        $params['etype'] = $filters['engagement_type'];
    }
    if (!empty($filters['start_after'])) {
        $where[] = 'p.start_date >= :sa';
        $params['sa'] = $filters['start_after'];
    }
    if (!empty($filters['end_before'])) {
        $where[] = 'p.end_date IS NOT NULL AND p.end_date <= :eb';
        $params['eb'] = $filters['end_before'];
    }
    if (!empty($filters['due_before'])) {
        $where[] = 'p.due_date IS NOT NULL AND p.due_date <= :db';
        $params['db'] = $filters['due_before'];
    }

    $page    = max(1, (int) ($filters['page'] ?? 1));
    $perPage = min(200, max(1, (int) ($filters['per_page'] ?? 25)));
    $offset  = ($page - 1) * $perPage;
    $whereSql = implode(' AND ', $where);

    $total = (int) (scopedFind("SELECT COUNT(*) AS c FROM placements p WHERE {$whereSql}", $params)['c'] ?? 0);
    $rows  = scopedQuery(
        'SELECT ' . placementsSafeFields() . ', pe.first_name, pe.last_name, pe.email_primary
         FROM placements p
         LEFT JOIN people pe ON pe.id = p.person_id AND pe.tenant_id = p.tenant_id
         WHERE ' . $whereSql . '
         ORDER BY p.start_date DESC
         LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
        $params
    );
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
}

function placementChain(int $placementId): array
{
    return scopedQuery(
        'SELECT * FROM placement_client_chain
         WHERE tenant_id = :tenant_id AND placement_id = :pid
         ORDER BY position',
        ['pid' => $placementId]
    );
}

function placementRates(int $placementId): array
{
    return scopedQuery(
        'SELECT * FROM placement_rates
         WHERE tenant_id = :tenant_id AND placement_id = :pid
         ORDER BY effective_from DESC, id DESC',
        ['pid' => $placementId]
    );
}

function placementCurrentRate(int $placementId, ?string $asOf = null): ?array
{
    $asOf = $asOf ?: date('Y-m-d');
    return scopedFind(
        'SELECT * FROM placement_rates
         WHERE tenant_id = :tenant_id AND placement_id = :pid
           AND approved_at IS NOT NULL
           AND effective_from <= :asof
           AND (effective_to IS NULL OR effective_to >= :asof)
         ORDER BY effective_from DESC LIMIT 1',
        ['pid' => $placementId, 'asof' => $asOf]
    );
}

function placementCommissions(int $placementId): array
{
    return scopedQuery(
        'SELECT * FROM placement_commissions
         WHERE tenant_id = :tenant_id AND placement_id = :pid
         ORDER BY role, effective_from DESC',
        ['pid' => $placementId]
    );
}

function placementReferrals(int $placementId): array
{
    return scopedQuery(
        'SELECT * FROM placement_referrals
         WHERE tenant_id = :tenant_id AND placement_id = :pid
         ORDER BY start_date DESC',
        ['pid' => $placementId]
    );
}

function placementDocuments(int $placementId): array
{
    return scopedQuery(
        'SELECT * FROM placement_documents
         WHERE tenant_id = :tenant_id AND placement_id = :pid AND deleted_at IS NULL
         ORDER BY created_at DESC',
        ['pid' => $placementId]
    );
}

/**
 * Compute net margin per SPEC §4 from a rate row + chain.
 * Returns ['adjusted_bill_rate', 'net_to_vendor', 'gross_margin_per_hour',
 *          'total_portal_fee_pct'].
 */
function placementsComputeMargin(array $rate, array $chain): array
{
    $totalPct  = 0.0;
    $totalFlat = 0.0;
    foreach ($chain as $c) {
        if (!empty($c['portal_fee_pct']))  $totalPct  += (float) $c['portal_fee_pct'];
        if (!empty($c['portal_fee_flat'])) $totalFlat += (float) $c['portal_fee_flat'];
    }
    $bill = (float) $rate['bill_rate'];
    $pay  = (float) $rate['pay_rate'];
    // Flat fee per hour amortization assumed at 160 hrs/month ≈ 173.33/mo equivalent;
    // for stored snapshot we treat flat as $/hour input directly (UI converts),
    // OR caller divides by their billable_hours_in_period as needed.
    $adjusted = $bill * (1 - $totalPct) - $totalFlat;
    $net      = $adjusted - $pay;
    return [
        'adjusted_bill_rate'    => round($adjusted, 4),
        'net_to_vendor'         => round($net, 4),
        'gross_margin_per_hour' => round($adjusted - $pay, 4),
        'total_portal_fee_pct'  => round($totalPct, 6),
    ];
}

/**
 * Audit logger for placements.* events. Writes to audit_log;
 * never blocks calling request on failure.
 */
function placementsAudit(string $event, array $meta = [], ?int $targetId = null): void
{
    $pdo = getDB();
    if (!$pdo) {
        error_log("[placements.audit] {$event} target={$targetId} meta=" . json_encode($meta));
        return;
    }
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO audit_log
             (tenant_id, actor_user_id, event, target_id, meta_json, ip_address, request_id, created_at)
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
        error_log("[placements.audit] db-write-failed: " . $e->getMessage() . " event={$event}");
    }
}
