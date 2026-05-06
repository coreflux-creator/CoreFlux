<?php
/**
 * AP API — layered approval policies (C2).
 *
 *   GET  /api/ap/approval_policies                     → list
 *   POST /api/ap/approval_policies                     → upsert (id optional)
 *   DELETE /api/ap/approval_policies?id=N              → soft-deactivate
 *   POST /api/ap/approval_policies?action=route&bill_id=N → re-route a bill
 *   GET  /api/ap/approval_policies?action=evaluate&bill_id=N → preview without routing
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/approval_router.php';

$ctx       = api_require_auth();
$user      = $ctx['user'];
$tenantId  = (int) $ctx['tenant_id'];
$method    = api_method();
$action    = (string) (api_query('action') ?? '');

if ($method === 'GET' && $action === '') {
    RBAC::requirePermission($user, 'ap.bills.approve_admin');
    $rows = scopedQuery(
        "SELECT *
           FROM ap_approval_policies
          WHERE tenant_id = :tenant_id
          ORDER BY priority ASC, id ASC"
    );
    api_ok(['policies' => $rows]);
}

if ($method === 'POST' && $action === '') {
    RBAC::requirePermission($user, 'ap.bills.approve_admin');
    $body = api_json_body();
    api_require_fields($body, ['name', 'chain']);
    $chain = is_array($body['chain']) ? $body['chain'] : [];
    if (!$chain) api_error('chain must contain at least one step', 422);

    $payload = [
        'name'             => (string) $body['name'],
        'description'      => $body['description'] ?? null,
        'priority'         => (int) ($body['priority'] ?? 100),
        'entity_id'        => isset($body['entity_id'])       ? (int) $body['entity_id']      : null,
        'vendor_type'      => $body['vendor_type']            ?? null,
        'min_amount'       => isset($body['min_amount'])      ? (float) $body['min_amount']   : null,
        'max_amount'       => isset($body['max_amount'])      ? (float) $body['max_amount']   : null,
        'min_risk_level'   => $body['min_risk_level']         ?? null,
        'gl_account_code'  => $body['gl_account_code']        ?? null,
        'chain_json'       => json_encode($chain, JSON_UNESCAPED_SLASHES),
        'quorum'           => isset($body['quorum'])          ? (int) $body['quorum']         : null,
        'sla_hours'        => isset($body['sla_hours'])       ? (int) $body['sla_hours']      : null,
        'active'           => isset($body['active']) ? (int) (bool) $body['active'] : 1,
    ];
    if (!empty($body['id'])) {
        scopedUpdate('ap_approval_policies', (int) $body['id'], $payload);
        api_ok(['id' => (int) $body['id']]);
    } else {
        $id = scopedInsert('ap_approval_policies', $payload);
        api_ok(['id' => $id], 201);
    }
}

if ($method === 'DELETE') {
    RBAC::requirePermission($user, 'ap.bills.approve_admin');
    $id = (int) (api_query('id') ?? 0);
    if (!$id) api_error('id required', 422);
    scopedUpdate('ap_approval_policies', $id, ['active' => 0]);
    api_ok(['ok' => true]);
}

if ($method === 'GET' && $action === 'evaluate') {
    RBAC::requirePermission($user, 'ap.bills.approve_admin');
    $billId = (int) (api_query('bill_id') ?? 0);
    if (!$billId) api_error('bill_id required', 422);
    $bill = _apFetchBillForRouting($tenantId, $billId);
    if (!$bill) api_error('Bill not found', 404);
    $eval = apEvaluateApprovalPolicy($tenantId, $bill);
    api_ok(['bill_id' => $billId, 'evaluation' => $eval]);
}

if ($method === 'POST' && $action === 'route') {
    RBAC::requirePermission($user, 'ap.bills.approve_admin');
    $billId = (int) (api_query('bill_id') ?? 0);
    if (!$billId) api_error('bill_id required', 422);
    $bill = _apFetchBillForRouting($tenantId, $billId);
    if (!$bill) api_error('Bill not found', 404);
    $result = apRouteBillForApproval($tenantId, $bill, (int) ($user['id'] ?? 0));
    api_ok(['bill_id' => $billId, 'routing' => $result]);
}

api_error('Unknown method/action', 405);

/** @internal Defensive bill fetch — schema may vary across deployments. */
function _apFetchBillForRouting(int $tenantId, int $billId): ?array {
    $pdo = getDB();
    if (!$pdo) return null;
    try {
        $stmt = $pdo->prepare(
            "SELECT b.*, v.vendor_type
               FROM ap_bills b
               LEFT JOIN ap_vendors v ON v.id = b.vendor_id AND v.tenant_id = b.tenant_id
              WHERE b.tenant_id = :t AND b.id = :b LIMIT 1"
        );
        $stmt->execute(['t' => $tenantId, 'b' => $billId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) return null;
        return [
            'id'             => (int) $row['id'],
            'entity_id'      => isset($row['entity_id']) ? (int) $row['entity_id'] : null,
            'total_amount'   => (float) ($row['total_amount'] ?? 0),
            'vendor_id'      => isset($row['vendor_id']) ? (int) $row['vendor_id'] : null,
            'vendor_type'    => $row['vendor_type'] ?? null,
            'gl_account_code'=> $row['default_gl_code'] ?? null,
        ];
    } catch (\Throwable $_) { return null; }
}
