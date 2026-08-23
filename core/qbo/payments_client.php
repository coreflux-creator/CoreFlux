<?php
/**
 * core/qbo/payments_client.php
 *
 * QBO Payments API client — distinct from QBO Accounting.
 *
 * Two different products share the OAuth grant:
 *   - QBO Accounting  (scope: com.intuit.quickbooks.accounting)
 *     → /v3/company/{realmId}/...
 *   - QBO Payments    (scope: com.intuit.quickbooks.payment)
 *     → /quickbooks/v4/payments/...
 *
 * Tenants must re-consent with the payment scope before any charge
 * endpoint becomes callable. The charge flow:
 *
 *   1. The browser sends payment details directly to Intuit's Payments
 *      token endpoint and receives an opaque `value` token. CoreFlux
 *      never receives the raw PAN or bank account details.
 *   2. CoreFlux backend POSTs /quickbooks/v4/payments/charges with the
 *      token and the desired capture flag (true = auth+capture).
 *   3. On `status=CAPTURED`, we INSERT a `qbo_payment_charges` shadow
 *      row, create a matching `billing_payments` entry, and allocate
 *      it against the originating invoice via `billingAllocatePayment`.
 *   4. Pending card/e-check transactions are polled through their
 *      respective retrieve endpoints. When one advances to CAPTURED,
 *      qboApplyCapturedPayment() idempotently creates and allocates the
 *      CoreFlux billing payment.
 *
 * Idempotency: every outbound request carries a `Request-Id` header.
 * QBO de-duplicates on this header for charges; we generate one per
 * call so retries on transient network failures don't double-charge.
 *
 * Public surface:
 *   qboPaymentsConfigured(int $tid): bool
 *   qboPaymentsBaseUrl(): string
 *   qboPaymentsCall(int $tid, string $method, string $path,
 *                   ?array $body=null, ?array $query=null,
 *                   ?string $idempotencyKey=null): array
 *   qboCreateCharge(int $tid, array $opts): array
 *   qboGetCharge(int $tid, string $chargeId): array
 *   qboCreateECheck(int $tid, array $opts): array
 *   qboGetECheck(int $tid, string $eCheckId): array
 *   qboRecordChargeShadow(int $tid, array $charge, array $context=[]): int
 *   qboFetchPaymentTransaction(int $tid, string $id, string $type): array
 *   qboApplyCapturedPayment(int $tid, array $charge, array $context=[], ?int $userId=null): array
 */
declare(strict_types=1);

require_once __DIR__ . '/client.php';

// QBO Payments scope — must be granted at OAuth consent time, in
// addition to com.intuit.quickbooks.accounting.
const QBO_PAYMENTS_SCOPE = 'com.intuit.quickbooks.payment';

// QBO Payments API base — sandbox vs production. Note these differ
// from the Accounting bases declared in client.php.
const QBO_PAYMENTS_API_SANDBOX    = 'https://sandbox.api.intuit.com';
const QBO_PAYMENTS_API_PRODUCTION = 'https://api.intuit.com';

/**
 * True when the tenant's active connection carries the payment scope.
 * The OAuth `scope` field is space-separated by Intuit.
 */
function qboPaymentsConfigured(int $tenantId): bool
{
    $row = qboConnection($tenantId);
    if (!$row || $row['status'] !== 'active') return false;
    $scopes = preg_split('/\s+/', trim((string) ($row['scope'] ?? '')));
    return in_array(QBO_PAYMENTS_SCOPE, (array) $scopes, true);
}

function qboPaymentsBaseUrl(): string
{
    return qboEnvironment() === 'production'
        ? QBO_PAYMENTS_API_PRODUCTION
        : QBO_PAYMENTS_API_SANDBOX;
}

/**
 * Authenticated QBO Payments call. Mirrors qboCall() but against the
 * payments base + always includes a Request-Id idempotency header.
 *
 * Refreshes the access token on 401 and retries once.
 */
function qboPaymentsCall(
    int $tenantId,
    string $method,
    string $path,
    ?array $body = null,
    ?array $query = null,
    ?string $idempotencyKey = null
): array {
    if (!qboPaymentsConfigured($tenantId)) {
        throw new \RuntimeException(
            'QBO Payments scope not granted for this tenant — re-connect QuickBooks with the payment scope enabled.'
        );
    }

    $token = qboAccessToken($tenantId);
    $url   = qboPaymentsBaseUrl() . $path;
    if ($query) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);

    $idem = $idempotencyKey ?: ('cf-' . bin2hex(random_bytes(8)));
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
        'Request-Id: ' . $idem,
    ];

    $payload = $body !== null ? json_encode($body) : null;
    $resp = qboRawRequest($method, $url, $payload, $headers);

    if ($resp['status'] === 401) {
        $token   = qboRefreshAccessToken($tenantId);
        $headers[2] = 'Authorization: Bearer ' . $token;
        $resp    = qboRawRequest($method, $url, $payload, $headers);
    }
    if ($resp['status'] >= 400) {
        $rawBody = is_string($resp['body']) ? $resp['body'] : json_encode($resp['body']);
        // QBO Payments error envelope is different from Accounting —
        // top-level "errors":[{"code":"PMT-1000","message":"..."}].
        $errCode = '';
        $errMsg  = '';
        if (is_array($resp['body'])) {
            $first = $resp['body']['errors'][0] ?? null;
            if (is_array($first)) {
                $errCode = (string) ($first['code']    ?? '');
                $errMsg  = (string) ($first['message'] ?? '');
            }
        }
        $ex = new QboApiException(
            'QBO Payments ' . $method . ' ' . $path . ' returned HTTP ' . $resp['status']
            . ($errCode !== '' ? " ({$errCode})" : '')
            . ': ' . substr($rawBody, 0, 300)
        );
        $ex->httpStatus = (int) $resp['status'];
        $ex->errorCode  = $errCode;
        $ex->raw        = ['body' => substr($rawBody, 0, 600), 'request_id' => $idem];

        qboAudit($tenantId, 'payments_http_error', [
            'direction' => 'outbound',
            'ok'        => false,
            'detail'    => [
                'method' => $method, 'path' => $path,
                'status' => $resp['status'], 'error_code' => $errCode,
                'error_message' => substr($errMsg, 0, 240),
                'request_id' => $idem,
            ],
        ]);
        throw $ex;
    }
    return is_array($resp['body']) ? $resp['body'] : ['raw' => $resp['body']];
}

// ─────────────────────────────────────────────────────────────────────
// Charge (card) — POST /quickbooks/v4/payments/charges
// ─────────────────────────────────────────────────────────────────────

/**
 * Create a card charge.
 *
 * @param array{amount:float,currency?:string,token:string,capture?:bool,
 *              context?:array,description?:string,
 *              card?:array{name?:string,address?:array}} $opts
 */
function qboCreateCharge(int $tenantId, array $opts): array
{
    if (empty($opts['token']))  throw new \InvalidArgumentException('token required');
    if (!isset($opts['amount']))throw new \InvalidArgumentException('amount required');

    $payload = [
        'amount'   => number_format((float) $opts['amount'], 2, '.', ''),
        'currency' => strtoupper((string) ($opts['currency'] ?? 'USD')),
        'token'    => (string) $opts['token'],
        'capture'  => (bool) ($opts['capture'] ?? true),
    ];
    if (!empty($opts['description'])) $payload['description'] = (string) $opts['description'];
    if (!empty($opts['context']))     $payload['context']     = (array)  $opts['context'];
    if (!empty($opts['card']))        $payload['card']        = (array)  $opts['card'];

    $resp = qboPaymentsCall(
        $tenantId, 'POST', '/quickbooks/v4/payments/charges',
        $payload, null, $opts['idempotency_key'] ?? null
    );
    qboAudit($tenantId, 'payments_charge_create', [
        'direction' => 'outbound', 'ok' => true,
        'detail' => [
            'charge_id' => $resp['id'] ?? null,
            'status'    => $resp['status'] ?? null,
            'amount'    => $payload['amount'],
        ],
    ]);
    return $resp;
}

function qboGetCharge(int $tenantId, string $chargeId): array
{
    if ($chargeId === '') throw new \InvalidArgumentException('chargeId required');
    return qboPaymentsCall(
        $tenantId, 'GET', '/quickbooks/v4/payments/charges/' . rawurlencode($chargeId)
    );
}

// ─────────────────────────────────────────────────────────────────────
// E-Check (ACH) — POST /quickbooks/v4/payments/echecks
// ─────────────────────────────────────────────────────────────────────

function qboCreateECheck(int $tenantId, array $opts): array
{
    if (empty($opts['token']))   throw new \InvalidArgumentException('token required');
    if (!isset($opts['amount'])) throw new \InvalidArgumentException('amount required');

    $payload = [
        'amount'   => number_format((float) $opts['amount'], 2, '.', ''),
        'token'    => (string) $opts['token'],
        // Intuit models browser/online ACH debits with the WEB SEC code.
        // A stable synthetic check number makes an idempotent replay's
        // request body identical without collecting another bank detail.
        'paymentMode' => 'WEB',
        'checkNumber' => substr(str_pad(
            sprintf('%u', crc32((string) ($opts['idempotency_key'] ?? random_bytes(8)))),
            8,
            '0',
            STR_PAD_LEFT
        ), -8),
    ];
    if (!empty($opts['description'])) $payload['description'] = (string) $opts['description'];
    if (!empty($opts['bankAccount']))$payload['bankAccount']  = (array)  $opts['bankAccount'];

    $resp = qboPaymentsCall(
        $tenantId, 'POST', '/quickbooks/v4/payments/echecks',
        $payload, null, $opts['idempotency_key'] ?? null
    );
    qboAudit($tenantId, 'payments_echeck_create', [
        'direction' => 'outbound', 'ok' => true,
        'detail' => [
            'echeck_id' => $resp['id'] ?? null,
            'status'    => $resp['status'] ?? null,
            'amount'    => $payload['amount'],
        ],
    ]);
    return $resp;
}

function qboGetECheck(int $tenantId, string $eCheckId): array
{
    if ($eCheckId === '') throw new \InvalidArgumentException('eCheckId required');
    return qboPaymentsCall(
        $tenantId, 'GET', '/quickbooks/v4/payments/echecks/' . rawurlencode($eCheckId)
    );
}

/**
 * Retrieve the correct Intuit Payments resource for a shadow row.
 * Card charges and ACH e-checks live under different API paths.
 */
function qboFetchPaymentTransaction(int $tenantId, string $transactionId, string $chargeType): array
{
    return $chargeType === 'echeck'
        ? qboGetECheck($tenantId, $transactionId)
        : qboGetCharge($tenantId, $transactionId);
}

// ─────────────────────────────────────────────────────────────────────
// Shadow table writes
// ─────────────────────────────────────────────────────────────────────

/**
 * Idempotent shadow-row upsert for a charge/echeck response. Returns
 * the persisted row id.
 *
 * `$context` is an optional caller-provided hash:
 *   - charge_type     : 'card' | 'echeck'  (defaults to 'card')
 *   - coreflux_invoice_id : int  — the AR invoice we're collecting on
 *   - context_token   : string — our outbound Request-Id (for tracing)
 */
function qboRecordChargeShadow(int $tenantId, array $charge, array $context = []): int
{
    $chargeId = (string) ($charge['id'] ?? '');
    if ($chargeId === '') {
        throw new \InvalidArgumentException('charge.id required for shadow write');
    }
    $pdo  = getDB();
    $type = (string) ($context['charge_type'] ?? 'card');
    if (!in_array($type, ['card', 'echeck'], true)) $type = 'card';

    // QBO returns the amount as a string ("100.00") — convert to cents.
    $amountCents = (int) round(((float) ($charge['amount'] ?? 0)) * 100);
    $currency    = strtoupper((string) ($charge['currency'] ?? 'USD'));
    $status      = (string) ($charge['status'] ?? 'ISSUED');

    $cardBrand   = null; $cardLast4 = null; $expM = null; $expY = null;
    $bankName    = null; $acctLast4 = null; $rtgLast4 = null;
    if ($type === 'card') {
        $cd = $charge['card'] ?? [];
        $cardBrand = isset($cd['type']) ? (string) $cd['type'] : null;
        $cardLast4 = isset($cd['number']) ? substr((string) $cd['number'], -4) : null;
        $expM      = isset($cd['expMonth']) ? (int) $cd['expMonth'] : null;
        $expY      = isset($cd['expYear'])  ? (int) $cd['expYear']  : null;
    } else {
        $bk = $charge['bankAccount'] ?? [];
        $bankName  = isset($bk['name']) ? (string) $bk['name'] : null;
        $acctLast4 = isset($bk['accountNumber']) ? substr((string) $bk['accountNumber'], -4) : null;
        $rtgLast4  = isset($bk['routingNumber']) ? substr((string) $bk['routingNumber'], -4) : null;
    }
    $errFirst = $charge['errors'][0] ?? null;
    $errCode  = is_array($errFirst) ? (string) ($errFirst['code']    ?? '') : '';
    $errMsg   = is_array($errFirst) ? (string) ($errFirst['message'] ?? '') : '';

    $captured = $status === 'CAPTURED' ? date('Y-m-d H:i:s') : null;
    $settled  = $status === 'SETTLED'  ? date('Y-m-d H:i:s') : null;

    // Upsert by (tenant_id, qbo_charge_id).
    $sel = $pdo->prepare(
        'SELECT id FROM qbo_payment_charges
          WHERE tenant_id = :t AND qbo_charge_id = :c LIMIT 1'
    );
    $sel->execute(['t' => $tenantId, 'c' => $chargeId]);
    $existing = $sel->fetch(\PDO::FETCH_ASSOC);

    $params = [
        'tenant_id'       => $tenantId,
        'qbo_charge_id'   => $chargeId,
        'charge_type'     => $type,
        'amount_cents'    => $amountCents,
        'currency'        => $currency,
        'status'          => $status,
        'card_brand'      => $cardBrand,
        'card_last4'      => $cardLast4,
        'card_exp_month'  => $expM,
        'card_exp_year'   => $expY,
        'bank_name'       => $bankName,
        'account_last4'   => $acctLast4,
        'routing_last4'   => $rtgLast4,
        'coreflux_invoice_id' => isset($context['coreflux_invoice_id'])
            ? (int) $context['coreflux_invoice_id'] : null,
        'coreflux_payment_id' => isset($context['coreflux_payment_id'])
            ? (int) $context['coreflux_payment_id'] : null,
        'context_token'   => trim((string) ($context['context_token'] ?? '')) ?: null,
        'error_code'      => $errCode !== '' ? $errCode : null,
        'error_message'   => $errMsg  !== '' ? substr($errMsg, 0, 500) : null,
        'raw_payload'     => json_encode($charge),
        'captured_at'     => $captured,
        'settled_at'      => $settled,
    ];

    $updateExisting = static function (int $id) use ($pdo, $params): int {
        $cols = 'amount_cents=:amount_cents, currency=:currency, status=:status,
                 card_brand=:card_brand, card_last4=:card_last4,
                 card_exp_month=:card_exp_month, card_exp_year=:card_exp_year,
                 bank_name=:bank_name, account_last4=:account_last4,
                 routing_last4=:routing_last4,
                 coreflux_invoice_id=COALESCE(:coreflux_invoice_id, coreflux_invoice_id),
                 coreflux_payment_id=COALESCE(:coreflux_payment_id, coreflux_payment_id),
                 context_token=COALESCE(:context_token, context_token),
                 error_code=:error_code, error_message=:error_message,
                 raw_payload=:raw_payload,
                 captured_at=COALESCE(:captured_at, captured_at),
                 settled_at =COALESCE(:settled_at,  settled_at)';
        $updateParams = $params;
        // Drop the columns we don't bind on UPDATE (tenant_id, qbo_charge_id, charge_type).
        unset($updateParams['tenant_id'], $updateParams['qbo_charge_id'], $updateParams['charge_type']);
        $updateParams['id'] = $id;
        $pdo->prepare("UPDATE qbo_payment_charges SET {$cols} WHERE id = :id")->execute($updateParams);
        return $id;
    };

    if ($existing) {
        return $updateExisting((int) $existing['id']);
    }

    $colList = implode(', ', array_keys($params));
    $vals    = ':' . implode(', :', array_keys($params));
    try {
        $pdo->prepare("INSERT INTO qbo_payment_charges ({$colList}) VALUES ({$vals})")->execute($params);
    } catch (\Throwable $insertError) {
        // Concurrent replays can both miss the SELECT and then race on
        // the unique QBO charge id. The winner inserted the same upstream
        // transaction, so converge through the normal update path.
        $sel->execute(['t' => $tenantId, 'c' => $chargeId]);
        $raced = $sel->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$raced) throw $insertError;
        return $updateExisting((int) $raced['id']);
    }
    return (int) $pdo->lastInsertId();
}

/**
 * Turn a captured Intuit transaction into a CoreFlux AR payment.
 *
 * This is deliberately shared by the initial POST, the operator refresh
 * endpoint, and the polling cron.  Previously only an immediately
 * CAPTURED card was allocated; an ACH transaction that became captured
 * later updated the shadow row but left the invoice open forever.
 *
 * The billing_payments (tenant_id, source_system, external_id) unique key
 * and the payment row lock inside billingAllocatePayment() make retries
 * safe across HTTP retries, cron overlap, and concurrent operator refreshes.
 *
 * @return array{applied:bool,reused:bool,payment_id:?int,allocation:?array,reason:?string}
 */
function qboApplyCapturedPayment(
    int $tenantId,
    array $charge,
    array $context = [],
    ?int $actorUserId = null
): array {
    $status = strtoupper(trim((string) ($charge['status'] ?? '')));
    if (!in_array($status, ['CAPTURED', 'SETTLED'], true)) {
        return [
            'applied' => false, 'reused' => false, 'payment_id' => null,
            'allocation' => null, 'reason' => 'not_captured',
        ];
    }

    $chargeId = trim((string) ($charge['id'] ?? ''));
    if ($chargeId === '') {
        throw new \InvalidArgumentException('charge.id required for captured-payment application');
    }

    if (!function_exists('billingAllocatePayment') || !function_exists('billingAudit')) {
        require_once __DIR__ . '/../../modules/billing/lib/billing.php';
    }
    $pdo = getDB();

    $shadowStmt = $pdo->prepare(
        'SELECT id, charge_type, amount_cents, coreflux_invoice_id,
                coreflux_payment_id, context_token
           FROM qbo_payment_charges
          WHERE tenant_id = :t AND qbo_charge_id = :c LIMIT 1'
    );
    $shadowStmt->execute(['t' => $tenantId, 'c' => $chargeId]);
    $shadow = $shadowStmt->fetch(\PDO::FETCH_ASSOC) ?: null;

    $invoiceId = (int) ($context['coreflux_invoice_id'] ?? ($shadow['coreflux_invoice_id'] ?? 0));
    if ($invoiceId <= 0) {
        return [
            'applied' => false, 'reused' => false, 'payment_id' => null,
            'allocation' => null, 'reason' => 'invoice_not_linked',
        ];
    }

    $invoiceStmt = $pdo->prepare(
        'SELECT id, invoice_number, client_name, currency, status, amount_due
           FROM billing_invoices
          WHERE tenant_id = :t AND id = :id LIMIT 1'
    );
    $invoiceStmt->execute(['t' => $tenantId, 'id' => $invoiceId]);
    $invoice = $invoiceStmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    if (!$invoice) {
        return [
            'applied' => false, 'reused' => false, 'payment_id' => null,
            'allocation' => null, 'reason' => 'invoice_not_found',
        ];
    }

    $amount = round((float) ($charge['amount'] ?? 0), 2);
    if ($amount <= 0 && $shadow) {
        $amount = round(((int) ($shadow['amount_cents'] ?? 0)) / 100, 2);
    }
    if ($amount <= 0) {
        throw new \InvalidArgumentException('captured charge amount must be greater than zero');
    }

    $paymentStmt = $pdo->prepare(
        "SELECT id, unallocated_amount
           FROM billing_payments
          WHERE tenant_id = :t AND source_system = 'qbo' AND external_id = :e
          LIMIT 1"
    );
    $paymentStmt->execute(['t' => $tenantId, 'e' => $chargeId]);
    $payment = $paymentStmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    $created = false;

    if (!$payment) {
        $chargeType = (string) ($context['charge_type'] ?? ($shadow['charge_type'] ?? 'card'));
        $method = $chargeType === 'echeck' ? 'ach' : 'card';
        $requestId = trim((string) ($context['context_token'] ?? ($shadow['context_token'] ?? '')));
        try {
            $pdo->prepare(
                "INSERT INTO billing_payments
                    (tenant_id, client_name, received_at, method, reference,
                     external_id, source_system, amount, currency, unallocated_amount,
                     notes, created_by_user_id, created_at)
                 VALUES
                    (:t, :cn, :rd, :method, :ref, :ext, 'qbo',
                     :amt, :cur, :amt2, :nt, :u, CURRENT_TIMESTAMP)"
            )->execute([
                't'      => $tenantId,
                'cn'     => (string) $invoice['client_name'],
                'rd'     => date('Y-m-d'),
                'method' => $method,
                'ref'    => ($chargeType === 'echeck' ? 'QBO E-check ' : 'QBO Charge ') . $chargeId,
                'ext'    => $chargeId,
                'amt'    => $amount,
                'amt2'   => $amount,
                'cur'    => strtoupper((string) ($charge['currency'] ?? $invoice['currency'] ?? 'USD')),
                'nt'     => 'Captured through QuickBooks Payments'
                    . ($requestId !== '' ? ' (Request-Id: ' . $requestId . ')' : '') . '.',
                'u'      => $actorUserId,
            ]);
            $payment = ['id' => (int) $pdo->lastInsertId(), 'unallocated_amount' => $amount];
            $created = true;
        } catch (\Throwable $insertError) {
            // A concurrent retry may have won the unique external-id
            // insert. Re-read it; rethrow only when this was a real DB
            // failure rather than the expected idempotency race.
            $paymentStmt->execute(['t' => $tenantId, 'e' => $chargeId]);
            $payment = $paymentStmt->fetch(\PDO::FETCH_ASSOC) ?: null;
            if (!$payment) throw $insertError;
        }
    }

    $paymentId = (int) $payment['id'];
    if ($shadow) {
        $pdo->prepare(
            'UPDATE qbo_payment_charges
                SET coreflux_payment_id = :p
              WHERE id = :id AND tenant_id = :t'
        )->execute(['p' => $paymentId, 'id' => (int) $shadow['id'], 't' => $tenantId]);
    }

    $allocatedStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(amount_applied), 0)
           FROM billing_payment_allocations
          WHERE payment_id = :p AND invoice_id = :i'
    );
    $allocatedStmt->execute(['p' => $paymentId, 'i' => $invoiceId]);
    $alreadyAllocated = round((float) $allocatedStmt->fetchColumn(), 2);
    if ($alreadyAllocated > 0 || in_array((string) $invoice['status'], ['paid', 'void', 'cancelled'], true)) {
        return [
            'applied' => $alreadyAllocated > 0,
            'reused' => true,
            'payment_id' => $paymentId,
            'allocation' => null,
            'reason' => $alreadyAllocated > 0 ? 'already_allocated' : 'invoice_closed',
        ];
    }

    $applyAmount = min(
        $amount,
        round((float) ($payment['unallocated_amount'] ?? 0), 2),
        round((float) ($invoice['amount_due'] ?? 0), 2)
    );
    if ($applyAmount <= 0) {
        return [
            'applied' => false, 'reused' => !$created, 'payment_id' => $paymentId,
            'allocation' => null, 'reason' => 'nothing_to_allocate',
        ];
    }

    try {
        $allocation = billingAllocatePayment(
            $paymentId,
            ['allocations' => [['invoice_id' => $invoiceId, 'amount' => $applyAmount]]],
            $actorUserId
        );
    } catch (\Throwable $allocationError) {
        // Resolve an overlap where another worker allocated after our
        // read but before billingAllocatePayment acquired its row lock.
        $allocatedStmt->execute(['p' => $paymentId, 'i' => $invoiceId]);
        if ((float) $allocatedStmt->fetchColumn() <= 0) throw $allocationError;
        return [
            'applied' => true, 'reused' => true, 'payment_id' => $paymentId,
            'allocation' => null, 'reason' => 'already_allocated',
        ];
    }

    billingAudit('billing.qbo_payments.captured', [
        'invoice_id' => $invoiceId,
        'amount'     => $applyAmount,
        'charge_id'  => $chargeId,
        'payment_id' => $paymentId,
        'request_id' => $context['context_token'] ?? ($shadow['context_token'] ?? null),
        'allocated'  => $allocation['applied'] ?? [],
    ], $paymentId);

    return [
        'applied' => true,
        'reused' => !$created,
        'payment_id' => $paymentId,
        'allocation' => $allocation,
        'reason' => null,
    ];
}
