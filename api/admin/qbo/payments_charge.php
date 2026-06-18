<?php
/**
 * POST /api/admin/qbo/payments_charge.php
 *
 *      Body (card):
 *        { invoice_id, amount, token, type: 'card', card?: {…}, description? }
 *      Body (echeck):
 *        { invoice_id, amount, token, type: 'echeck', bankAccount?: {…}, description? }
 *
 *      Flow:
 *        1. Validate the AR invoice belongs to the caller's tenant + has
 *           an open balance ≥ requested amount.
 *        2. Hit qboCreateCharge / qboCreateECheck via the QBO Payments
 *           client.
 *        3. Idempotently record the charge into qbo_payment_charges.
 *        4. On `status=CAPTURED` (card immediate-capture path), create a
 *           billing_payments row (source_system='qbo', external_id=chargeId)
 *           and allocate against the invoice via billingAllocatePayment.
 *           ACH e-checks come back ISSUED first → real allocation waits
 *           for the settlement webhook (Phase 2).
 *
 * GET  /api/admin/qbo/payments_charge.php?charge_id=…
 *      Returns the persisted shadow row + the live QBO status. Used by
 *      the operator UI to refresh a pending charge.
 *
 * RBAC: master_admin / tenant_admin / wildcard.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/rbac/legacy_map.php';
require_once __DIR__ . '/../../../core/qbo/client.php';
require_once __DIR__ . '/../../../core/qbo/payments_client.php';
require_once __DIR__ . '/../../../modules/billing/lib/billing.php';

$ctx = api_require_auth();
rbac_legacy_require_any($currentUser ?? $ctx, ['master_admin', 'tenant_admin', '*']);

$tenantId = (int) ($ctx['tenant_id'] ?? 0);
$userId   = (int) ($ctx['user']['id'] ?? $ctx['user_id'] ?? 0);
if ($tenantId <= 0) { http_response_code(400); api_error('tenant required', 400); }

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $chargeId = (string) ($_GET['charge_id'] ?? '');
    if ($chargeId === '') { http_response_code(400); api_error('charge_id required', 400); }
    try {
        $shadowStmt = getDB()->prepare(
            'SELECT * FROM qbo_payment_charges
              WHERE tenant_id = :t AND qbo_charge_id = :c LIMIT 1'
        );
        $shadowStmt->execute(['t' => $tenantId, 'c' => $chargeId]);
        $shadow = $shadowStmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) {
        $shadow = null;
    }
    // Live refresh from QBO (best-effort).
    $live = null;
    try {
        $live = qboGetCharge($tenantId, $chargeId);
        if (is_array($live)) {
            qboRecordChargeShadow($tenantId, $live, [
                'charge_type' => $shadow['charge_type'] ?? 'card',
                'coreflux_invoice_id' => $shadow['coreflux_invoice_id'] ?? null,
                'context_token' => $shadow['context_token'] ?? null,
            ]);
        }
    } catch (\Throwable $e) {
        $live = ['error' => $e->getMessage()];
    }
    api_ok(['shadow' => $shadow, 'live' => $live]);
}

if ($method !== 'POST') {
    http_response_code(405);
    api_error('GET or POST only', 405);
}

$body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];

$invoiceId = (int)    ($body['invoice_id'] ?? 0);
$amount    = round((float) ($body['amount'] ?? 0), 2);
$token     = (string) ($body['token'] ?? '');
$type      = (string) ($body['type']  ?? 'card');
$desc      = (string) ($body['description'] ?? '');

if ($invoiceId <= 0)               api_error('invoice_id required', 400);
if ($amount <= 0)                  api_error('amount must be > 0', 422);
if ($token === '')                 api_error('token required (use the Intuit tokenizer to obtain it)', 400);
if (!in_array($type, ['card','echeck'], true)) {
    api_error("type must be 'card' or 'echeck'", 400);
}

if (!qboPaymentsConfigured($tenantId)) {
    api_error('QBO Payments scope not granted — re-connect QuickBooks with the payment scope.', 412);
}

// 1. Resolve + validate the invoice within tenant scope.
$inv = scopedFind(
    'SELECT id, invoice_number, client_name, currency, status,
            total, amount_paid, amount_due
       FROM billing_invoices WHERE tenant_id = :tenant_id AND id = :id LIMIT 1',
    ['id' => $invoiceId]
);
if (!$inv)                             api_error('Invoice not found', 404);
if (in_array($inv['status'], ['paid','void','cancelled'], true)) {
    api_error("Invoice {$inv['invoice_number']} is {$inv['status']}; cannot collect", 409);
}
if ($amount - 0.005 > (float) $inv['amount_due']) {
    api_error('Charge amount exceeds invoice amount_due', 422);
}

$contextToken = 'cf-inv-' . $invoiceId . '-' . bin2hex(random_bytes(6));

// 2. Fire the upstream charge.
try {
    $payload = [
        'amount'          => $amount,
        'currency'        => (string) ($inv['currency'] ?? 'USD'),
        'token'           => $token,
        'capture'         => true,
        'description'     => $desc !== '' ? $desc : ('Invoice ' . $inv['invoice_number']),
        'idempotency_key' => $contextToken,
    ];
    if ($type === 'card') {
        if (!empty($body['card'])) $payload['card'] = (array) $body['card'];
        $charge = qboCreateCharge($tenantId, $payload);
    } else {
        if (!empty($body['bankAccount'])) $payload['bankAccount'] = (array) $body['bankAccount'];
        $charge = qboCreateECheck($tenantId, $payload);
    }
} catch (\QboApiException $e) {
    billingAudit('billing.qbo_payments.charge_failed', [
        'invoice_id'   => $invoiceId,
        'amount'       => $amount,
        'type'         => $type,
        'http_status'  => $e->httpStatus,
        'error_code'   => $e->errorCode,
        'request_id'   => $contextToken,
    ], $invoiceId);
    api_error($e->getMessage(), $e->httpStatus ?: 502);
} catch (\Throwable $e) {
    billingAudit('billing.qbo_payments.charge_failed', [
        'invoice_id' => $invoiceId, 'amount' => $amount, 'type' => $type,
        'reason'     => substr($e->getMessage(), 0, 240),
        'request_id' => $contextToken,
    ], $invoiceId);
    api_error($e->getMessage(), 502);
}

// 3. Persist shadow row.
$shadowId = qboRecordChargeShadow($tenantId, $charge, [
    'charge_type'         => $type,
    'coreflux_invoice_id' => $invoiceId,
    'context_token'       => $contextToken,
]);

$result = [
    'charge'    => $charge,
    'shadow_id' => $shadowId,
    'invoice_id'=> $invoiceId,
];

// 4. Apply against the invoice when QBO immediately captured the funds.
$status = strtoupper((string) ($charge['status'] ?? ''));
if ($status === 'CAPTURED') {
    try {
        $chargeId   = (string) ($charge['id'] ?? '');
        $chargeAmt  = round(((float) ($charge['amount'] ?? $amount)), 2);

        // Idempotent INSERT — UNIQUE KEY (tenant_id, source_system, external_id).
        $pdo = getDB();
        $pdo->prepare(
            "INSERT INTO billing_payments
                (tenant_id, client_name, received_at, method, reference,
                 external_id, source_system, amount, currency, unallocated_amount,
                 notes, created_by_user_id, created_at)
             VALUES
                (:t, :cn, :rd, 'card', :ref, :ext, 'qbo',
                 :amt, :cur, :amt2, :nt, :u, CURRENT_TIMESTAMP)"
        )->execute([
            't'   => $tenantId,
            'cn'  => $inv['client_name'],
            'rd'  => date('Y-m-d'),
            'ref' => 'QBO Charge ' . $chargeId,
            'ext' => $chargeId,
            'amt' => $chargeAmt,
            'amt2'=> $chargeAmt,
            'cur' => (string) ($inv['currency'] ?? 'USD'),
            'nt'  => 'QBO Payments charge captured (Request-Id: ' . $contextToken . ').',
            'u'   => $userId,
        ]);
        $paymentId = (int) $pdo->lastInsertId();

        // Link shadow back to the billing_payments row.
        $pdo->prepare(
            'UPDATE qbo_payment_charges
                SET coreflux_payment_id = :p
              WHERE id = :id AND tenant_id = :t'
        )->execute(['p' => $paymentId, 'id' => $shadowId, 't' => $tenantId]);

        // Allocate against the invoice — re-uses the canonical engine.
        $alloc = billingAllocatePayment(
            $paymentId,
            ['allocations' => [['invoice_id' => $invoiceId, 'amount' => $chargeAmt]]],
            $userId
        );
        billingAudit('billing.qbo_payments.captured', [
            'invoice_id'      => $invoiceId,
            'amount'          => $chargeAmt,
            'charge_id'       => $chargeId,
            'payment_id'      => $paymentId,
            'request_id'      => $contextToken,
            'allocated'       => $alloc['applied'] ?? [],
        ], $paymentId);

        $result['payment_id']  = $paymentId;
        $result['allocation'] = $alloc;
    } catch (\Throwable $e) {
        billingAudit('billing.qbo_payments.allocation_failed', [
            'invoice_id' => $invoiceId,
            'reason'     => substr($e->getMessage(), 0, 240),
            'shadow_id'  => $shadowId,
        ], $invoiceId);
        $result['allocation_error'] = $e->getMessage();
    }
} else {
    // ISSUED / PENDING / DECLINED etc. — operator polls or the future
    // settlement webhook closes the loop.
    billingAudit('billing.qbo_payments.charge_pending', [
        'invoice_id' => $invoiceId,
        'status'     => $status,
        'charge_id'  => $charge['id'] ?? null,
        'shadow_id'  => $shadowId,
    ], $invoiceId);
}

api_ok($result);
