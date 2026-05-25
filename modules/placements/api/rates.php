<?php
/**
 * Placements API — rates (effective-dated, draft → approve snapshot lock)
 *
 *   GET   /api/placements/rates?placement_id=N           → list
 *   POST  /api/placements/rates?placement_id=N           → draft new rate
 *   POST  /api/placements/rates?action=approve&id=N      → approve (snapshot lock)
 *
 * On approve:
 *   - require placements.financials.approve
 *   - compute adjusted_bill_rate + net_to_vendor from chain (SPEC §4)
 *   - close prior approved row's effective_to to (this.effective_from - 1 day)
 *   - record audit `placement.rate.approved` with is_correction flag
 *
 * SPEC §3.3, §4, §6.2.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/placements.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET') {
    rbac_legacy_require($user, 'placements.financials.view');

    // GET /api/placements/rates?action=drafts
    //
    // Lists every UNapproved placement_rates row in the current tenant,
    // joined with the parent placement + person so the queue page can
    // render rich rows without an N+1 fetch. Powers the "Draft Rates"
    // bulk-approval queue.
    if ($action === 'drafts') {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            "SELECT pr.id, pr.placement_id, pr.effective_from, pr.bill_rate, pr.bill_rate_unit,
                    pr.pay_rate, pr.pay_rate_unit, pr.currency, pr.created_at,
                    p.title AS placement_title, p.status AS placement_status,
                    p.external_id AS placement_external_id, p.start_date AS placement_start_date,
                    p.person_id, p.end_client_name,
                    pe.first_name, pe.last_name, pe.email_primary
             FROM placement_rates pr
             JOIN placements p ON p.id = pr.placement_id
             LEFT JOIN people pe ON pe.id = p.person_id
             WHERE pr.tenant_id = :tenant_id
               AND pr.approved_at IS NULL
               AND (p.deleted_at IS NULL)
             ORDER BY pr.created_at DESC, pr.id DESC
             LIMIT 500"
        );
        $stmt->execute(['tenant_id' => currentTenantId()]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        api_ok(['rates' => $rows, 'count' => count($rows)]);
    }

    $pid = (int) api_query('placement_id', 0);
    if ($pid <= 0) api_error('placement_id required', 400);
    placementsAudit('placement.financials.viewed', ['placement_id' => $pid], $pid);
    api_ok(['rates' => placementRates($pid)]);
}

/**
 * Approve a single placement_rates row inside a transaction.
 * Shared by `?action=approve` (one rate) and `?action=bulk_approve`
 * (many) so the bulk path uses the IDENTICAL approve semantics —
 * margin snapshot computed from current chain, prior approved row
 * closed via effective_to, audit emitted. Throws on failure.
 *
 * @return array{margin: array, superseded_count: int}
 */
function placementsRateApproveOne(int $rateId, array $user, bool $isCorrection, ?string $correctionReason): array
{
    $rate = scopedFind('SELECT * FROM placement_rates WHERE tenant_id = :tenant_id AND id = :id', ['id' => $rateId]);
    if (!$rate)              throw new \RuntimeException("Rate {$rateId} not found");
    if ($rate['approved_at']) throw new \RuntimeException("Rate {$rateId} already approved");

    $chain  = placementChain((int) $rate['placement_id']);
    $margin = placementsComputeMargin($rate, $chain);

    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "UPDATE placement_rates
             SET effective_to = DATE_SUB(:eff_set, INTERVAL 1 DAY),
                 superseded_by = :new_id_set
             WHERE tenant_id = :tenant_id AND placement_id = :pid
               AND id != :new_id_filter
               AND approved_at IS NOT NULL
               AND effective_from <= :eff_lt
               AND (effective_to IS NULL OR effective_to >= :eff_gt)"
        );
        $stmt->execute([
            'eff_set'        => $rate['effective_from'],
            'eff_lt'         => $rate['effective_from'],
            'eff_gt'         => $rate['effective_from'],
            'new_id_set'     => $rateId,
            'new_id_filter'  => $rateId,
            'tenant_id'      => currentTenantId(),
            'pid'            => $rate['placement_id'],
        ]);
        $closed = $stmt->rowCount();

        $stmt2 = $pdo->prepare(
            'UPDATE placement_rates SET
                approved_by_user_id = :uid,
                approved_at = NOW(),
                adjusted_bill_rate = :abr,
                net_to_vendor = :ntv,
                is_correction = :ic,
                correction_reason = :reason
             WHERE tenant_id = :tenant_id AND id = :id'
        );
        $stmt2->execute([
            'uid'       => $user['id'] ?? null,
            'abr'       => $margin['adjusted_bill_rate'],
            'ntv'       => $margin['net_to_vendor'],
            'ic'        => $isCorrection ? 1 : 0,
            'reason'    => $correctionReason,
            'tenant_id' => currentTenantId(),
            'id'        => $rateId,
        ]);
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    placementsAudit('placement.rate.approved', [
        'placement_id'        => (int) $rate['placement_id'],
        'rate_id'             => $rateId,
        'effective_from'      => $rate['effective_from'],
        'adjusted_bill_rate'  => $margin['adjusted_bill_rate'],
        'net_to_vendor'       => $margin['net_to_vendor'],
        'total_portal_fee_pct'=> $margin['total_portal_fee_pct'],
        'is_correction'       => $isCorrection,
        'correction_reason'   => $correctionReason,
        'superseded_count'    => $closed,
    ], (int) $rate['placement_id']);

    if ($closed > 0) {
        placementsAudit('placement.rate.superseded', [
            'placement_id' => (int) $rate['placement_id'], 'by_rate_id' => $rateId, 'count' => $closed,
        ], (int) $rate['placement_id']);
    }

    return ['margin' => $margin, 'superseded_count' => $closed];
}

if ($method === 'POST' && $action === 'bulk_approve') {
    rbac_legacy_require($user, 'placements.financials.approve');
    $body = api_json_body();
    $ids = is_array($body['ids'] ?? null) ? array_values(array_unique(array_map('intval', $body['ids']))) : [];
    $ids = array_values(array_filter($ids, static fn ($n) => $n > 0));
    if (!$ids)             api_error('ids[] required', 422);
    if (count($ids) > 200) api_error('Too many ids (max 200 per call)', 422);

    // Bulk-approve never accepts an "is_correction=true" flag — by the
    // shape of the workflow a CSV-imported draft is always a fresh
    // rate, never a correction (corrections are explicit single-row).
    // Forces operators to use the per-row Approve flow for corrections.
    $approved = 0; $failed = 0; $results = [];
    foreach ($ids as $rid) {
        try {
            $r = placementsRateApproveOne($rid, $user, false, null);
            $approved++;
            $results[] = ['id' => $rid, 'ok' => true, 'adjusted_bill_rate' => $r['margin']['adjusted_bill_rate']];
        } catch (\Throwable $e) {
            $failed++;
            $results[] = ['id' => $rid, 'ok' => false, 'reason' => $e->getMessage()];
        }
    }
    api_ok(['ok' => true, 'approved' => $approved, 'failed' => $failed, 'results' => $results]);
}

if ($method === 'POST' && $action === 'approve') {
    rbac_legacy_require($user, 'placements.financials.approve');
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);

    $body = api_json_body();
    $isCorrection    = !empty($body['is_correction']);
    $correctionReason= $body['correction_reason'] ?? null;
    if ($isCorrection && empty($correctionReason)) {
        api_error('correction_reason is required when is_correction=true', 422);
    }

    try {
        $r = placementsRateApproveOne($id, $user, $isCorrection, $correctionReason);
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'not found'))    api_error('Rate not found', 404);
        if (str_contains($msg, 'already approved')) api_error('Already approved (snapshot is locked; create a correction)', 409);
        api_error('Approve failed: ' . $msg, 500);
    }
    api_ok(['ok' => true, 'snapshot' => $r['margin']]);
}

if ($method === 'POST') {
    rbac_legacy_require($user, 'placements.financials.manage');
    $pid = (int) api_query('placement_id', 0);
    if ($pid <= 0) api_error('placement_id required', 400);
    $body = api_json_body();
    api_require_fields($body, ['effective_from', 'bill_rate', 'pay_rate']);

    $id = scopedInsert('placement_rates', [
        'placement_id'    => $pid,
        'effective_from'  => $body['effective_from'],
        'effective_to'    => $body['effective_to']    ?? null,
        'bill_rate'       => (float) $body['bill_rate'],
        'bill_rate_unit'  => $body['bill_rate_unit']  ?? 'hour',
        'pay_rate'        => (float) $body['pay_rate'],
        'pay_rate_unit'   => $body['pay_rate_unit']   ?? 'hour',
        'currency'        => $body['currency']        ?? 'USD',
        'ot_multiplier'   => $body['ot_multiplier']   ?? 1.5,
        'dt_multiplier'   => $body['dt_multiplier']   ?? 2.0,
        'adder_pct'       => $body['adder_pct']       ?? null,
        'background_fee_total' => $body['background_fee_total'] ?? null,
        'created_by_user_id'   => $user['id'] ?? null,
    ]);
    placementsAudit('placement.rate.drafted', ['placement_id' => $pid, 'rate_id' => $id], $pid);
    api_ok(['id' => $id], 201);
}

api_error('Method not allowed', 405);
