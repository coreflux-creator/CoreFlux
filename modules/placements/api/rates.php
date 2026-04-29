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
    RBAC::requirePermission($user, 'placements.financials.view');
    $pid = (int) api_query('placement_id', 0);
    if ($pid <= 0) api_error('placement_id required', 400);
    placementsAudit('placement.financials.viewed', ['placement_id' => $pid], $pid);
    api_ok(['rates' => placementRates($pid)]);
}

if ($method === 'POST' && $action === 'approve') {
    RBAC::requirePermission($user, 'placements.financials.approve');
    $id = (int) api_query('id', 0);
    if ($id <= 0) api_error('id required', 400);

    $rate = scopedFind('SELECT * FROM placement_rates WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$rate) api_error('Rate not found', 404);
    if ($rate['approved_at']) api_error('Already approved (snapshot is locked; create a correction)', 409);

    $body = api_json_body();
    $isCorrection    = !empty($body['is_correction']);
    $correctionReason= $body['correction_reason'] ?? null;
    if ($isCorrection && empty($correctionReason)) {
        api_error('correction_reason is required when is_correction=true', 422);
    }

    // Compute snapshot from current chain
    $chain = placementChain((int) $rate['placement_id']);
    $margin = placementsComputeMargin($rate, $chain);

    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        // Close prior approved row covering this effective_from
        $stmt = $pdo->prepare(
            "UPDATE placement_rates
             SET effective_to = DATE_SUB(:eff_from, INTERVAL 1 DAY),
                 superseded_by = :new_id
             WHERE tenant_id = :tenant_id AND placement_id = :pid
               AND id != :new_id
               AND approved_at IS NOT NULL
               AND effective_from <= :eff_from
               AND (effective_to IS NULL OR effective_to >= :eff_from)"
        );
        $stmt->execute([
            'eff_from' => $rate['effective_from'],
            'new_id'   => $id,
            'tenant_id'=> currentTenantId(),
            'pid'      => $rate['placement_id'],
        ]);
        $closed = $stmt->rowCount();

        // Stamp the new row
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
            'id'        => $id,
        ]);

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        api_error('Approve failed: ' . $e->getMessage(), 500);
    }

    placementsAudit('placement.rate.approved', [
        'placement_id'        => (int) $rate['placement_id'],
        'rate_id'             => $id,
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
            'placement_id' => (int) $rate['placement_id'], 'by_rate_id' => $id, 'count' => $closed,
        ], (int) $rate['placement_id']);
    }

    api_ok(['ok' => true, 'snapshot' => $margin]);
}

if ($method === 'POST') {
    RBAC::requirePermission($user, 'placements.financials.manage');
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
