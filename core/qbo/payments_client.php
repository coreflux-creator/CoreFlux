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
 *   1. Frontend tokenizer (Intuit's hosted PCI iframe) collects the
 *      card details and returns an opaque `value` token. CoreFlux
 *      never touches the raw PAN.
 *   2. CoreFlux backend POSTs /quickbooks/v4/payments/charges with the
 *      token and the desired capture flag (true = auth+capture).
 *   3. On `status=CAPTURED`, we INSERT a `qbo_payment_charges` shadow
 *      row, create a matching `billing_payments` entry, and allocate
 *      it against the originating invoice via `billingAllocatePayment`.
 *   4. On settlement (T+1 / T+3 days for ACH), QBO fires a webhook OR
 *      we poll GET /charges/{id} via a future cron; the shadow row's
 *      `status` and `settled_at` advance.
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
        'currency' => strtoupper((string) ($opts['currency'] ?? 'USD')),
        'token'    => (string) $opts['token'],
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
        'context_token'   => (string) ($context['context_token'] ?? ''),
        'error_code'      => $errCode !== '' ? $errCode : null,
        'error_message'   => $errMsg  !== '' ? substr($errMsg, 0, 500) : null,
        'raw_payload'     => json_encode($charge),
        'captured_at'     => $captured,
        'settled_at'      => $settled,
    ];

    if ($existing) {
        $id = (int) $existing['id'];
        $cols = 'amount_cents=:amount_cents, currency=:currency, status=:status,
                 card_brand=:card_brand, card_last4=:card_last4,
                 card_exp_month=:card_exp_month, card_exp_year=:card_exp_year,
                 bank_name=:bank_name, account_last4=:account_last4,
                 routing_last4=:routing_last4,
                 coreflux_invoice_id=COALESCE(:coreflux_invoice_id, coreflux_invoice_id),
                 coreflux_payment_id=COALESCE(:coreflux_payment_id, coreflux_payment_id),
                 context_token=:context_token,
                 error_code=:error_code, error_message=:error_message,
                 raw_payload=:raw_payload,
                 captured_at=COALESCE(:captured_at, captured_at),
                 settled_at =COALESCE(:settled_at,  settled_at)';
        $params['id'] = $id;
        // Drop the columns we don't bind on UPDATE (tenant_id, qbo_charge_id, charge_type).
        unset($params['tenant_id'], $params['qbo_charge_id'], $params['charge_type']);
        $params['id'] = $id;
        $pdo->prepare("UPDATE qbo_payment_charges SET {$cols} WHERE id = :id")->execute($params);
        return $id;
    }

    $colList = implode(', ', array_keys($params));
    $vals    = ':' . implode(', :', array_keys($params));
    $pdo->prepare("INSERT INTO qbo_payment_charges ({$colList}) VALUES ({$vals})")->execute($params);
    return (int) $pdo->lastInsertId();
}
