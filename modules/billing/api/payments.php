<?php
/**
 * Billing API — payments + allocations.
 *
 *   GET  /api/billing/payments                    → list with filters
 *   POST /api/billing/payments                    → record new payment
 *   POST /api/billing/payments?action=allocate&id=N
 *        body: {allocations: [{invoice_id, amount}]} OR {auto: 'fifo'}
 *
 * SPEC: /app/modules/billing/SPEC.md §5.4.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/billing.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET') {
    RBAC::requirePermission($user, 'billing.view');
    $where  = ['tenant_id = :tenant_id'];
    $params = [];
    if (!empty($_GET['client_name'])) { $where[] = 'client_name = :cn';   $params['cn'] = $_GET['client_name']; }
    if (!empty($_GET['from']))        { $where[] = 'received_at >= :df'; $params['df'] = $_GET['from']; }
    if (!empty($_GET['to']))          { $where[] = 'received_at <= :dt'; $params['dt'] = $_GET['to']; }
    $rows = scopedQuery(
        'SELECT * FROM billing_payments WHERE ' . implode(' AND ', $where) . ' ORDER BY received_at DESC, id DESC LIMIT 200',
        $params
    );
    api_ok(['rows' => $rows]);
}

if ($method === 'POST' && $action === '') {
    RBAC::requirePermission($user, 'billing.payments.record');
    $body = api_json_body();
    api_require_fields($body, ['client_name', 'received_at', 'method', 'amount']);
    $amount = round((float) $body['amount'], 2);
    if ($amount <= 0) api_error('amount must be > 0', 422);

    $id = scopedInsert('billing_payments', [
        'tenant_id'          => $tid,
        'client_name'        => (string) $body['client_name'],
        'received_at'        => (string) $body['received_at'],
        'method'             => (string) $body['method'],
        'reference'          => $body['reference'] ?? null,
        'amount'             => $amount,
        'currency'           => (string) ($body['currency'] ?? 'USD'),
        'unallocated_amount' => $amount,
        'notes'              => $body['notes'] ?? null,
        'created_by_user_id' => $user['id'] ?? null,
    ]);
    billingAudit('billing.payment.recorded', [
        'payment_id' => $id, 'client_name' => $body['client_name'], 'amount' => $amount,
    ], $id);

    // Auto-allocate FIFO if requested
    if (!empty($body['auto_allocate'])) {
        try {
            $alloc = billingAllocatePayment($id, ['auto' => 'fifo'], $user['id'] ?? null);
            billingAudit('billing.payment.allocated', ['payment_id' => $id, 'auto' => 'fifo', 'applied' => $alloc['applied']], $id);
            return api_ok(['id' => $id, 'auto_allocation' => $alloc], 201);
        } catch (\Throwable $e) {
            // Allocation failed but the payment is recorded — return success with a warning.
            return api_ok(['id' => $id, 'auto_allocation_error' => $e->getMessage()], 201);
        }
    }

    api_ok(['id' => $id], 201);
}

if ($method === 'POST' && $action === 'allocate') {
    RBAC::requirePermission($user, 'billing.payments.record');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $row = scopedFind('SELECT id FROM billing_payments WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);

    $body = api_json_body();
    $alloc = billingAllocatePayment($id, $body, $user['id'] ?? null);
    billingAudit('billing.payment.allocated', ['payment_id' => $id, 'request' => $body, 'applied' => $alloc['applied']], $id);
    api_ok($alloc);
}

api_error('Method not allowed', 405);
