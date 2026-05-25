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
require_once __DIR__ . '/../lib/rate_approve.php';

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
 * (Implementation moved to `modules/placements/lib/rate_approve.php`
 * so `api/placements.php` can also call it during draft → active
 * status promotions without re-loading this API file.)
 */

if ($method === 'POST' && $action === 'approve_all_for_placement') {
    rbac_legacy_require($user, 'placements.financials.approve');
    $pid = (int) api_query('placement_id', 0);
    if ($pid <= 0) api_error('placement_id required', 400);
    // Catch-up affordance for placements that were promoted from draft
    // BEFORE the auto-approve side effect shipped (or where the
    // operator lacked the financials.approve permission at promotion
    // time). Reuses the same shared helper as bulk_status / PATCH so
    // the audit trail and margin snapshots are identical.
    $count = placementsAutoApproveDraftRates($pid, $user);
    placementsAudit('placement.rates.approve_all_clicked', [
        'placement_id' => $pid,
        'approved'     => $count,
    ], $pid);
    api_ok(['ok' => true, 'approved' => $count]);
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
    // Auto-detect correction: if a prior approved rate row exists for
    // this placement, the new approval IS a supersede — flag it as
    // is_correction regardless of what the client sent. Removes the
    // confirm popup from the UI ("Is this a correction?") that
    // operators (rightly) thought was redundant — by definition,
    // approving a second rate after one is already locked is a
    // correction.
    //
    // Reason is OPTIONAL when auto-detected; we generate a default
    // breadcrumb so the audit row still has something useful. Operators
    // can override by passing an explicit `correction_reason` in the
    // request body.
    $rateRow = scopedFind('SELECT placement_id FROM placement_rates WHERE tenant_id = :tenant_id AND id = :id',
        ['id' => $id]);
    $autoCorrection = false;
    if ($rateRow) {
        $prior = scopedFind(
            'SELECT id FROM placement_rates
              WHERE tenant_id = :tenant_id AND placement_id = :pid
                AND id != :rid AND approved_at IS NOT NULL
              LIMIT 1',
            ['pid' => (int) $rateRow['placement_id'], 'rid' => $id]
        );
        $autoCorrection = (bool) $prior;
    }
    $isCorrection     = !empty($body['is_correction']) || $autoCorrection;
    $correctionReason = $body['correction_reason'] ?? null;
    if ($isCorrection && empty($correctionReason)) {
        $correctionReason = $autoCorrection
            ? 'Rate update (auto-detected supersede of prior approved row)'
            : 'Manual correction (no reason provided)';
    }

    try {
        $r = placementsRateApproveOne($id, $user, $isCorrection, $correctionReason);
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'not found'))    api_error('Rate not found', 404);
        if (str_contains($msg, 'already approved')) api_error('Already approved (snapshot is locked; create a correction)', 409);
        api_error('Approve failed: ' . $msg, 500);
    }
    api_ok(['ok' => true, 'snapshot' => $r['margin'], 'auto_correction' => $autoCorrection]);
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
