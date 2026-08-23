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
 *           Pending card/e-check transactions are completed by the
 *           polling worker through the same idempotent apply helper.
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
    // Live refresh from QBO (best-effort). E-checks have a distinct
    // retrieve endpoint, so route by the persisted shadow type.
    $live = null;
    $application = null;
    try {
        $chargeType = (string) ($shadow['charge_type'] ?? 'card');
        $live = qboFetchPaymentTransaction($tenantId, $chargeId, $chargeType);
        if (is_array($live)) {
            qboRecordChargeShadow($tenantId, $live, [
                'charge_type' => $chargeType,
                'coreflux_invoice_id' => $shadow['coreflux_invoice_id'] ?? null,
                'context_token' => $shadow['context_token'] ?? null,
            ]);
            $application = qboApplyCapturedPayment($tenantId, $live, [
                'charge_type' => $chargeType,
                'coreflux_invoice_id' => $shadow['coreflux_invoice_id'] ?? null,
                'context_token' => $shadow['context_token'] ?? null,
            ], $userId ?: null);
            $shadowStmt->execute(['t' => $tenantId, 'c' => $chargeId]);
            $shadow = $shadowStmt->fetch(\PDO::FETCH_ASSOC) ?: $shadow;
        }
    } catch (\Throwable $e) {
        $live = ['error' => $e->getMessage()];
    }
    api_ok(['shadow' => $shadow, 'live' => $live, 'application' => $application]);
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
$providedIdempotencyKey = trim((string) ($body['idempotency_key'] ?? ''));

if ($invoiceId <= 0)               api_error('invoice_id required', 400);
if ($amount <= 0)                  api_error('amount must be > 0', 422);
if ($token === '')                 api_error('token required (use the Intuit tokenizer to obtain it)', 400);
if (!in_array($type, ['card','echeck'], true)) {
    api_error("type must be 'card' or 'echeck'", 400);
}
if ($providedIdempotencyKey !== ''
    && !preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $providedIdempotencyKey)) {
    api_error('idempotency_key must be 8-64 URL-safe characters', 422);
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
$contextToken = $providedIdempotencyKey !== ''
    ? $providedIdempotencyKey
    : ('cf-inv-' . $invoiceId . '-' . bin2hex(random_bytes(6)));

// Durable retry: the browser reuses one key for the same payment intent.
// If a prior response was lost after QBO accepted it, never POST a second
// charge; refresh and return the already-recorded transaction instead.
$priorStmt = getDB()->prepare(
    'SELECT * FROM qbo_payment_charges
      WHERE tenant_id = :t AND context_token = :k LIMIT 1'
);
$priorStmt->execute(['t' => $tenantId, 'k' => $contextToken]);
$prior = $priorStmt->fetch(\PDO::FETCH_ASSOC) ?: null;
if ($prior) {
    if ((int) ($prior['coreflux_invoice_id'] ?? 0) !== $invoiceId
        || (string) ($prior['charge_type'] ?? '') !== $type
        || (int) ($prior['amount_cents'] ?? 0) !== (int) round($amount * 100)) {
        api_error('idempotency_key was already used for a different payment intent', 409);
    }
}

// Only a brand-new intent is subject to the invoice's current balance.
// A response may have been lost after the original request paid the
// invoice; the same Request-Id must still replay the original result.
if (!$prior) {
    if (!in_array((string) $inv['status'], ['approved', 'sent', 'partially_paid'], true)) {
        api_error("Invoice {$inv['invoice_number']} is {$inv['status']}; cannot collect", 409);
    }
    if ($amount - 0.005 > (float) $inv['amount_due']) {
        api_error('Charge amount exceeds invoice amount_due', 422);
    }
}

// 2. Fire the upstream charge.
try {
    if ($prior) {
        try {
            $charge = qboFetchPaymentTransaction(
                $tenantId,
                (string) $prior['qbo_charge_id'],
                (string) $prior['charge_type']
            );
        } catch (\Throwable $_) {
            $charge = json_decode((string) ($prior['raw_payload'] ?? ''), true) ?: [
                'id'       => (string) $prior['qbo_charge_id'],
                'amount'   => number_format(((int) $prior['amount_cents']) / 100, 2, '.', ''),
                'currency' => (string) $prior['currency'],
                'status'   => (string) $prior['status'],
            ];
        }
    } else {
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
    'reused'    => $prior !== null,
    'receipt'   => qboBuildPaymentReceipt($charge, $type, $amount, (string) ($inv['currency'] ?? 'USD')),
];

// 4. Apply against the invoice whenever the transaction is captured.
// The same helper is used by GET refresh and the polling cron, so ACH
// and delayed cards close the invoice at the moment QBO advances them.
$status = strtoupper((string) ($charge['status'] ?? ''));
if (in_array($status, ['CAPTURED', 'SETTLED'], true)) {
    try {
        $application = qboApplyCapturedPayment($tenantId, $charge, [
            'charge_type'         => $type,
            'coreflux_invoice_id' => $invoiceId,
            'context_token'       => $contextToken,
        ], $userId ?: null);
        $result['application'] = $application;
        $result['payment_id']  = $application['payment_id'];
        $result['allocation']  = $application['allocation'];
    } catch (\Throwable $e) {
        billingAudit('billing.qbo_payments.allocation_failed', [
            'invoice_id' => $invoiceId,
            'reason'     => substr($e->getMessage(), 0, 240),
            'shadow_id'  => $shadowId,
        ], $invoiceId);
        $result['allocation_error'] = $e->getMessage();
    }
} else {
    // ISSUED / PENDING / DECLINED etc. — the polling worker closes the
    // loop when Intuit reports CAPTURED.
    billingAudit('billing.qbo_payments.charge_pending', [
        'invoice_id' => $invoiceId,
        'status'     => $status,
        'charge_id'  => $charge['id'] ?? null,
        'shadow_id'  => $shadowId,
    ], $invoiceId);
}

api_ok($result);

/**
 * Build the customer-facing receipt without returning unmasked payment data.
 */
function qboBuildPaymentReceipt(array $charge, string $type, float $amount, string $currency): array
{
    $digits = static function (mixed $value): string {
        $numeric = preg_replace('/\D+/', '', (string) $value) ?? '';
        return $numeric === '' ? '' : substr($numeric, -4);
    };

    if ($type === 'echeck') {
        $bank = (array) ($charge['bankAccount'] ?? []);
        $last4 = $digits($bank['accountNumber'] ?? '');
        $label = trim((string) ($bank['bankName'] ?? $bank['name'] ?? 'ACH e-check'));
        $paymentMethod = ($label !== '' ? $label : 'ACH e-check') . ($last4 !== '' ? ' ending in ' . $last4 : '');
    } else {
        $card = (array) ($charge['card'] ?? []);
        $last4 = $digits($card['number'] ?? '');
        $brand = trim((string) ($card['type'] ?? 'Card'));
        $paymentMethod = ($brand !== '' ? $brand : 'Card') . ($last4 !== '' ? ' ending in ' . $last4 : '');
    }

    return [
        'payment_amount'  => number_format($amount, 2, '.', ''),
        'total_amount'    => number_format($amount, 2, '.', ''),
        'currency'        => strtoupper($currency !== '' ? $currency : 'USD'),
        'transaction_at'  => (string) ($charge['created'] ?? $charge['createdAt'] ?? date(DATE_ATOM)),
        'payment_method'  => $paymentMethod,
        'transaction_id'  => (string) ($charge['id'] ?? ''),
        'processor'       => 'Intuit Payments Inc.',
        'processor_address' => '2700 Coast Avenue, Mountain View, CA 94043',
        'processor_phone' => '1-888-536-4801',
        'processor_nmls'  => '1098819',
    ];
}
