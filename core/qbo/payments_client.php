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
 *      row, then atomically create the receipt, allocate it, and post
 *      DR processor clearing / CR accounts receivable in CoreFlux.
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
 *   qboPaymentsRecaptchaConfigured(): bool
 *   qboVerifyPaymentsRecaptcha(string $responseToken, ?string $remoteIp=null): array
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
    if ((string) ($row['environment'] ?? '') !== qboEnvironment()) return false;
    $scopes = preg_split('/\s+/', trim((string) ($row['scope'] ?? '')));
    return in_array(QBO_PAYMENTS_SCOPE, (array) $scopes, true);
}

/** Payment collection stays disabled until both halves of reCAPTCHA are installed. */
function qboPaymentsRecaptchaConfigured(): bool
{
    return trim(qboCfg('QBO_RECAPTCHA_SITE_KEY')) !== ''
        && trim(qboCfg('QBO_RECAPTCHA_SECRET_KEY')) !== '';
}

/**
 * Verify a reCAPTCHA v2 response before creating any card or e-check charge.
 * The response token is deliberately never logged or persisted.
 *
 * @return array{success:bool,hostname:string,challenge_ts:string}
 */
function qboVerifyPaymentsRecaptcha(string $responseToken, ?string $remoteIp = null): array
{
    $secret = trim(qboCfg('QBO_RECAPTCHA_SECRET_KEY'));
    $responseToken = trim($responseToken);
    if ($secret === '' || trim(qboCfg('QBO_RECAPTCHA_SITE_KEY')) === '') {
        throw new \RuntimeException('Payment verification is not configured.');
    }
    if ($responseToken === '') {
        throw new \InvalidArgumentException('Complete the reCAPTCHA challenge before submitting payment.');
    }

    $form = ['secret' => $secret, 'response' => $responseToken];
    if (is_string($remoteIp) && filter_var($remoteIp, FILTER_VALIDATE_IP)) {
        $form['remoteip'] = $remoteIp;
    }
    $verification = qboRawRequest(
        'POST',
        'https://www.google.com/recaptcha/api/siteverify',
        http_build_query($form, '', '&', PHP_QUERY_RFC3986),
        ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded']
    );
    if ((int) ($verification['status'] ?? 0) !== 200 || !is_array($verification['body'] ?? null)) {
        throw new \RuntimeException('Payment verification is temporarily unavailable. Try again.');
    }

    $body = $verification['body'];
    if (($body['success'] ?? false) !== true) {
        throw new \InvalidArgumentException('reCAPTCHA verification failed. Complete a new challenge and try again.');
    }

    $hostname = strtolower(rtrim(trim((string) ($body['hostname'] ?? '')), '.'));
    $allowed = preg_split(
        '/\s*,\s*/',
        strtolower(trim(qboCfg('QBO_RECAPTCHA_ALLOWED_HOSTS') ?: 'corefluxapp.com,www.corefluxapp.com')),
        -1,
        PREG_SPLIT_NO_EMPTY
    ) ?: [];
    if ($hostname === '' || !in_array($hostname, $allowed, true)) {
        throw new \InvalidArgumentException('reCAPTCHA verification was issued for an unexpected host.');
    }

    return [
        'success' => true,
        'hostname' => $hostname,
        'challenge_ts' => (string) ($body['challenge_ts'] ?? ''),
    ];
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
        $intuitTid = trim((string) ($resp['headers']['intuit_tid'] ?? $resp['headers']['intuit-tid'] ?? ''));
        if ($intuitTid !== '') {
            $ex->raw['intuit_tid'] = substr($intuitTid, 0, 200);
        }

        qboAudit($tenantId, 'payments_http_error', [
            'direction' => 'outbound',
            'ok'        => false,
            'detail'    => [
                'method' => $method, 'path' => $path,
                'status' => $resp['status'], 'error_code' => $errCode,
                'error_message' => substr($errMsg, 0, 240),
                'request_id' => $idem,
                'intuit_tid' => $intuitTid,
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

    // Serialize status progression for one charge, including overlapping
    // poll and operator-refresh requests.
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
    // Upsert by (tenant_id, qbo_charge_id).
    $sel = $pdo->prepare(
        'SELECT id, status, amount_cents, currency, coreflux_invoice_id, context_token
           FROM qbo_payment_charges
          WHERE tenant_id = :t AND qbo_charge_id = :c LIMIT 1 FOR UPDATE'
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

    $updateExisting = static function (array $prior) use ($pdo, $params): int {
        $id = (int) $prior['id'];
        if (!empty($prior['coreflux_invoice_id']) && !empty($params['coreflux_invoice_id'])
            && (int) $prior['coreflux_invoice_id'] !== (int) $params['coreflux_invoice_id']) {
            throw new \RuntimeException('Processor charge is already linked to another invoice.');
        }
        if (!empty($prior['context_token']) && !empty($params['context_token'])
            && (string) $prior['context_token'] !== (string) $params['context_token']) {
            throw new \RuntimeException('Processor charge Request-Id changed on replay.');
        }
        if ((int) $prior['amount_cents'] > 0
            && ((int) $prior['amount_cents'] !== (int) $params['amount_cents']
                || strcasecmp((string) $prior['currency'], (string) $params['currency']) !== 0)) {
            throw new \RuntimeException('Processor charge amount or currency changed on replay.');
        }

        $rank = ['ISSUED' => 1, 'PENDING' => 1, 'AUTHORIZED' => 1,
            'CAPTURED' => 2, 'SETTLED' => 3,
            'REFUNDED' => 4, 'VOIDED' => 4, 'DECLINED' => 4, 'FAILED' => 4];
        $oldStatus = strtoupper((string) $prior['status']);
        $newStatus = strtoupper((string) $params['status']);
        if (isset($rank[$oldStatus], $rank[$newStatus]) && $rank[$newStatus] < $rank[$oldStatus]) {
            return $id;
        }
        if (($rank[$oldStatus] ?? 0) === 4 && ($rank[$newStatus] ?? 0) === 4
            && $oldStatus !== $newStatus) {
            throw new \RuntimeException('Processor charge terminal status changed unexpectedly.');
        }
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
        $id = $updateExisting($existing);
        if ($ownsTransaction) $pdo->commit();
        return $id;
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
        $id = $updateExisting($raced);
        if ($ownsTransaction) $pdo->commit();
        return $id;
    }
    $id = (int) $pdo->lastInsertId();
    if ($ownsTransaction) $pdo->commit();
    return $id;
    } catch (\Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Turn a captured Intuit transaction into a posted CoreFlux AR receipt.
 *
 * This is deliberately shared by the initial POST, the operator refresh
 * endpoint, and the polling cron.  Previously only an immediately
 * CAPTURED card was allocated; an ACH transaction that became captured
 * later updated the shadow row but left the invoice open forever.
 *
 * The shadow-row lock serializes retries for one charge. The payment's
 * external-id unique key and JE idempotency key provide a second defense.
 * A capture never changes invoice balances without its matching ledger entry.
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

    if (!function_exists('billingAllocatePayment')) {
        require_once __DIR__ . '/../../modules/billing/lib/billing.php';
    }
    if (!function_exists('accountingPostJe')) {
        require_once __DIR__ . '/../../modules/accounting/lib/accounting.php';
    }
    $pdo = getDB();
    if ($pdo->inTransaction()) throw new \RuntimeException('Captured-payment application requires its own transaction.');
    $pdo->beginTransaction();
    try {
        $shadowStmt = $pdo->prepare(
            'SELECT * FROM qbo_payment_charges
              WHERE tenant_id = :t AND qbo_charge_id = :c LIMIT 1 FOR UPDATE'
        );
        $shadowStmt->execute(['t' => $tenantId, 'c' => $chargeId]);
        $shadow = $shadowStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$shadow) throw new \RuntimeException('Captured charge has no persisted shadow record.');
        if (!in_array(strtoupper((string) $shadow['status']), ['CAPTURED', 'SETTLED'], true)) {
            throw new \RuntimeException('Saved charge is no longer captured; review its processor status.');
        }

        $invoiceId = (int) $shadow['coreflux_invoice_id'];
        if ($invoiceId <= 0 || (!empty($context['coreflux_invoice_id'])
            && (int) $context['coreflux_invoice_id'] !== $invoiceId)) {
            throw new \RuntimeException('Captured charge is not linked to this invoice.');
        }
        $amountCents = (int) $shadow['amount_cents'];
        $reportedCents = (int) round(((float) ($charge['amount'] ?? 0)) * 100);
        $currency = strtoupper((string) ($charge['currency'] ?? $shadow['currency']));
        if ($amountCents <= 0 || ($reportedCents > 0 && $reportedCents !== $amountCents)
            || $currency !== strtoupper((string) $shadow['currency'])) {
            throw new \RuntimeException('Captured charge amount or currency differs from its saved payment intent.');
        }
        $amount = $amountCents / 100;

        $paymentStmt = $pdo->prepare(
            "SELECT * FROM billing_payments WHERE tenant_id = :t
                AND source_system = 'qbo' AND external_id = :c LIMIT 1 FOR UPDATE"
        );
        $paymentStmt->execute(['t' => $tenantId, 'c' => $chargeId]);
        $payment = $paymentStmt->fetch(\PDO::FETCH_ASSOC) ?: null;

        $invoiceStmt = $pdo->prepare(
            'SELECT i.*, je.status AS invoice_je_status, je.entity_id AS invoice_je_entity_id
               FROM billing_invoices i
          LEFT JOIN accounting_journal_entries je
                 ON je.tenant_id = i.tenant_id AND je.id = i.journal_entry_id
              WHERE i.tenant_id = :t AND i.id = :id LIMIT 1 FOR UPDATE'
        );
        $invoiceStmt->execute(['t' => $tenantId, 'id' => $invoiceId]);
        $invoice = $invoiceStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$invoice || $invoice['invoice_je_status'] !== 'posted') {
            throw new \RuntimeException('The linked invoice must be posted before a captured payment can be applied.');
        }
        if ($currency !== strtoupper((string) $invoice['currency'])) {
            throw new \RuntimeException('Captured payment and invoice currencies differ.');
        }
        $entityId = (int) ($invoice['invoice_je_entity_id'] ?? 0);
        if ($entityId <= 0 || (!empty($invoice['entity_id']) && (int) $invoice['entity_id'] !== $entityId)
            || in_array((string) $invoice['status'], ['void', 'cancelled'], true)) {
            throw new \RuntimeException('Invoice and posted journal legal entity or status is inconsistent.');
        }
        $receiptDate = $payment ? (string) $payment['received_at'] : date('Y-m-d');
        if ($receiptDate < (string) $invoice['issue_date']) {
            throw new \RuntimeException('Payment date cannot precede the invoice date.');
        }

        $created = false;
        $allocation = null;
        if ($payment) {
            if ($payment['voided_at'] !== null
                || abs((float) $payment['amount'] - $amount) > 0.005
                || strcasecmp((string) $payment['currency'], $currency) !== 0
                || strcasecmp(trim((string) $payment['client_name']), trim((string) $invoice['client_name'])) !== 0) {
                throw new \RuntimeException('An existing processor receipt does not match the captured charge.');
            }
        } else {
            if (!in_array((string) $invoice['status'], ['approved', 'sent', 'partially_paid'], true)
                || (float) $invoice['amount_due'] + 0.005 < $amount) {
                throw new \RuntimeException('The invoice cannot absorb this captured charge. Review the excess or closed balance before applying it.');
            }
            $chargeType = (string) $shadow['charge_type'];
            $requestId = trim((string) ($shadow['context_token'] ?? ''));
            $pdo->prepare(
                "INSERT INTO billing_payments
                    (tenant_id, client_name, received_at, method, reference,
                     external_id, source_system, amount, currency, unallocated_amount,
                     notes, created_by_user_id, created_at)
                 VALUES (:t, :cn, :rd, :method, :ref, :ext, 'qbo',
                         :amt, :cur, :amt2, :nt, :u, CURRENT_TIMESTAMP)"
            )->execute([
                't' => $tenantId,
                'cn' => (string) $invoice['client_name'],
                'rd' => $receiptDate,
                'method' => $chargeType === 'echeck' ? 'ach' : 'card',
                'ref' => ($chargeType === 'echeck' ? 'QBO E-check ' : 'QBO Charge ') . $chargeId,
                'ext' => $chargeId,
                'amt' => $amount,
                'amt2' => $amount,
                'cur' => $currency,
                'nt' => 'Captured through QuickBooks Payments'
                    . ($requestId !== '' ? ' (Request-Id: ' . $requestId . ')' : '') . '.',
                'u' => $actorUserId,
            ]);
            $payment = ['id' => (int) $pdo->lastInsertId(), 'unallocated_amount' => $amount,
                'journal_entry_id' => null];
            $created = true;
        }

        $paymentId = (int) $payment['id'];
        if (!empty($shadow['coreflux_payment_id']) && (int) $shadow['coreflux_payment_id'] !== $paymentId) {
            throw new \RuntimeException('Charge shadow is linked to a different CoreFlux payment.');
        }
        $allocatedStmt = $pdo->prepare(
            'SELECT invoice_id, ROUND(SUM(amount_applied), 2) AS applied
               FROM billing_payment_allocations
              WHERE payment_id = :p AND reversed_at IS NULL GROUP BY invoice_id'
        );
        $allocatedStmt->execute(['p' => $paymentId]);
        $priorAllocations = $allocatedStmt->fetchAll(\PDO::FETCH_ASSOC);
        if (!$priorAllocations) {
            if (!empty($payment['journal_entry_id'])) {
                throw new \RuntimeException('Posted processor receipt has no invoice allocation.');
            }
            if (!$created && abs((float) $payment['unallocated_amount'] - $amount) > 0.005) {
                throw new \RuntimeException('Existing processor receipt has an inconsistent unallocated balance.');
            }
            if (!in_array((string) $invoice['status'], ['approved', 'sent', 'partially_paid'], true)
                || (float) $invoice['amount_due'] + 0.005 < $amount) {
                throw new \RuntimeException('The invoice cannot absorb this captured charge.');
            }
            $allocation = billingAllocatePayment($paymentId, [
                'allocations' => [['invoice_id' => $invoiceId, 'amount' => $amount]],
                'defer_pwp' => true,
            ], $actorUserId);
            if (abs((float) ($allocation['unallocated_remaining'] ?? $amount)) > 0.005) {
                throw new \RuntimeException('The captured charge was not fully applied.');
            }
        } elseif (count($priorAllocations) !== 1
            || (int) $priorAllocations[0]['invoice_id'] !== $invoiceId
            || abs((float) $priorAllocations[0]['applied'] - $amount) > 0.005
            || abs((float) $payment['unallocated_amount']) > 0.005) {
            throw new \RuntimeException('Existing processor receipt allocations do not match its charge.');
        }

        if (!empty($payment['journal_entry_id'])) {
            $jeStmt = $pdo->prepare(
                'SELECT status, source_module, source_ref_type, source_ref_id
                   FROM accounting_journal_entries WHERE tenant_id = :t AND id = :id LIMIT 1'
            );
            $jeStmt->execute(['t' => $tenantId, 'id' => (int) $payment['journal_entry_id']]);
            $priorJe = $jeStmt->fetch(\PDO::FETCH_ASSOC);
            if (!$priorJe || $priorJe['status'] !== 'posted'
                || $priorJe['source_module'] !== 'billing'
                || $priorJe['source_ref_type'] !== 'billing_payment'
                || (int) $priorJe['source_ref_id'] !== $paymentId) {
                throw new \RuntimeException('Processor receipt has an invalid ledger link.');
            }
            $pdo->prepare('UPDATE qbo_payment_charges SET coreflux_payment_id = :p WHERE tenant_id = :t AND id = :id')
                ->execute(['p' => $paymentId, 't' => $tenantId, 'id' => (int) $shadow['id']]);
            $pdo->commit();
            return ['applied' => true, 'reused' => true, 'payment_id' => $paymentId,
                'journal_entry_id' => (int) $payment['journal_entry_id'],
                'allocation' => null, 'reason' => 'already_posted'];
        }

        $clientDimension = !empty($invoice['client_company_id'])
            ? (int) $invoice['client_company_id']
            : 'name:' . strtolower(trim((string) $invoice['client_name']));
        $journal = accountingPostJe($tenantId, [
            'entity_id' => $entityId,
            'posting_date' => $receiptDate,
            'currency' => $currency,
            'source_module' => 'billing',
            'source_ref_type' => 'billing_payment',
            'source_ref_id' => $paymentId,
            'idempotency_key' => 'billing:qbo-capture:' . $chargeId,
            'memo' => 'Processor receipt / ' . $invoice['invoice_number'],
            'lines' => [
                ['account_code' => '1010', 'debit' => $amount, 'credit' => 0,
                    'memo' => 'QuickBooks Payments clearing ' . $chargeId,
                    'dims' => ['legal_entity' => $entityId]],
                ['account_code' => '1100', 'debit' => 0, 'credit' => $amount,
                    'memo' => 'Apply processor receipt to ' . $invoice['invoice_number'],
                    'counterparty_company_id' => $invoice['client_company_id'] ?? null,
                    'dims' => ['legal_entity' => $entityId, 'client' => $clientDimension]],
            ],
        ], $actorUserId, true);
        if (!empty($journal['idempotent_replay'])) {
            throw new \RuntimeException('Processor receipt journal already exists without a valid payment link.');
        }
        $savePayment = $pdo->prepare(
            'UPDATE billing_payments SET journal_entry_id = :je, posted_at = NOW(), unallocated_amount = 0
              WHERE tenant_id = :t AND id = :p AND journal_entry_id IS NULL AND voided_at IS NULL'
        );
        $savePayment->execute(['je' => (int) $journal['je_id'], 't' => $tenantId, 'p' => $paymentId]);
        if ($savePayment->rowCount() !== 1) {
            throw new \RuntimeException('Processor receipt changed while posting.');
        }
        $pdo->prepare(
            'INSERT INTO accounting_subledger_links
                (tenant_id, source_module, source_record_id, journal_entry_id, link_kind)
             VALUES (:t, "billing", :ref, :je, "primary")'
        )->execute(['t' => $tenantId, 'ref' => 'payment:' . $paymentId, 'je' => (int) $journal['je_id']]);
        $pdo->prepare(
            'UPDATE qbo_payment_charges
                SET coreflux_payment_id = :p, error_code = NULL, error_message = NULL
              WHERE tenant_id = :t AND id = :id'
        )->execute(['p' => $paymentId, 't' => $tenantId, 'id' => (int) $shadow['id']]);
        $pdo->commit();

        $pwp = $allocation
            ? billingReleasePayWhenPaidForAllocations($tenantId, $allocation['applied'], $actorUserId)
            : [];
        billingAudit('billing.qbo_payments.captured', [
            'invoice_id' => $invoiceId, 'amount' => $amount, 'charge_id' => $chargeId,
            'payment_id' => $paymentId, 'journal_entry_id' => (int) $journal['je_id'],
        ], $paymentId);
        return ['applied' => true, 'reused' => !$created, 'payment_id' => $paymentId,
            'journal_entry_id' => (int) $journal['je_id'],
            'allocation' => $allocation, 'reason' => null, 'pwp' => $pwp];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        try {
            $pdo->prepare(
                'UPDATE qbo_payment_charges SET error_message = :error WHERE tenant_id = :t AND qbo_charge_id = :c'
            )->execute(['error' => substr('posting_error: ' . $e->getMessage(), 0, 500),
                't' => $tenantId, 'c' => $chargeId]);
        } catch (\Throwable $_) {}
        throw $e;
    }
}
