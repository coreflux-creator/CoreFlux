<?php
/**
 * core/mercury_adapter.php — Mercury Bank REST adapter (PHP cURL, no SDK).
 *
 * Per MVP spec §13 (Mercury Adapter Contract). Slice 1 exports:
 *
 *   mercuryListAccounts(string $apiToken): array
 *   mercuryGetAccount(string $apiToken, string $accountId): array
 *   mercuryListTransactions(string $apiToken, string $accountId, array $opts = []): array
 *
 * All return Mercury's raw JSON shape (associative arrays). Callers normalize
 * into mercury_accounts / mercury_transactions via core/mercury_service.php.
 *
 * Auth: `Authorization: Bearer <api-token>`. Tokens are tenant-owned (MVP
 * Operational Assumption §24) so each call needs the tenant's token threaded
 * through — never reads a global env secret.
 *
 * Slices 2–4 will add createRecipient / createPayment / getPaymentStatus /
 * createFundingTransfer on top of this same adapter.
 */

declare(strict_types=1);

class MercuryApiException extends \RuntimeException
{
    public ?string $errorCode = null;
    public ?int    $httpStatus = null;
    public ?array  $raw        = null;
}

function mercuryApiBase(): string
{
    $env = strtolower((string) (getenv('MERCURY_ENV') ?: 'production'));
    // Mercury only ships production endpoints publicly; sandbox is private-beta.
    // We allow an explicit MERCURY_API_BASE override for local/staging proxies.
    $override = (string) (getenv('MERCURY_API_BASE') ?: '');
    if ($override !== '') return rtrim($override, '/');
    return $env === 'sandbox'
        ? 'https://api.sandbox.mercury.com/api/v1'
        : 'https://api.mercury.com/api/v1';
}

/**
 * Make a Mercury API call. All adapter functions go through here so the
 * tests can inject a transport via $GLOBALS['__mercury_transport'] (callable
 * (string $method, string $url, array $headers, ?string $body): array{status:int, body:string}).
 */
function mercuryCall(string $apiToken, string $method, string $path, array $body = [], int $timeoutSec = 25): array
{
    if ($apiToken === '') {
        throw new MercuryApiException('Mercury: api token required');
    }
    $url = mercuryApiBase() . $path;
    $headers = [
        'Authorization: Bearer ' . $apiToken,
        'Accept: application/json',
        'Content-Type: application/json',
        'User-Agent: CoreFlux/1.0 (+mercury_adapter.php)',
    ];
    $bodyJson = ($method === 'GET' || !$body) ? null : json_encode($body);

    // Test seam — let smoke tests stub HTTP without hitting the wire.
    if (isset($GLOBALS['__mercury_transport']) && is_callable($GLOBALS['__mercury_transport'])) {
        $resp = ($GLOBALS['__mercury_transport'])($method, $url, $headers, $bodyJson);
        $status = (int) ($resp['status'] ?? 0);
        $rawBody = (string) ($resp['body'] ?? '');
    } else {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => $timeoutSec,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($bodyJson !== null) $opts[CURLOPT_POSTFIELDS] = $bodyJson;
        curl_setopt_array($ch, $opts);
        $rawBody = curl_exec($ch);
        $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr    = curl_error($ch);
        curl_close($ch);
        if ($rawBody === false || $cerr) {
            $e = new MercuryApiException('Mercury cURL error: ' . ($cerr ?: 'unknown'));
            $e->httpStatus = $status;
            throw $e;
        }
        $rawBody = (string) $rawBody;
    }

    $data = json_decode($rawBody, true);
    if (!is_array($data)) {
        $e = new MercuryApiException("Mercury: invalid JSON response (HTTP {$status})");
        $e->httpStatus = $status;
        $e->raw        = ['body' => substr($rawBody, 0, 500)];
        throw $e;
    }
    if ($status >= 400 || isset($data['errors']) || isset($data['error'])) {
        $msg = $data['message']
             ?? $data['error']
             ?? (is_array($data['errors'] ?? null) ? json_encode($data['errors']) : '')
             ?: ("HTTP {$status}");
        $e  = new MercuryApiException('Mercury: ' . $msg);
        $e->httpStatus = $status;
        $e->errorCode  = (string) ($data['code'] ?? $data['error_code'] ?? '');
        $e->raw        = $data;
        throw $e;
    }
    return $data;
}

/**
 * GET /accounts — list all accounts the API token can access.
 * Mercury response shape: { accounts: [{ id, name, accountNumber, routingNumber, kind, status, availableBalance, currentBalance, ... }] }
 */
function mercuryListAccounts(string $apiToken): array
{
    return mercuryCall($apiToken, 'GET', '/accounts');
}

/** GET /account/{id} — full account detail (richer than list row). */
function mercuryGetAccount(string $apiToken, string $accountId): array
{
    if ($accountId === '') throw new MercuryApiException('mercuryGetAccount: accountId required');
    return mercuryCall($apiToken, 'GET', '/account/' . rawurlencode($accountId));
}

/**
 * GET /account/{id}/transactions — paginated history. Slice 1 supports the
 * cursor/`offset`+`limit` knob. Mercury's actual API uses 'start' + 'end' +
 * 'limit' + 'offset' (see Mercury API reference). We pass through whatever
 * the caller specifies in $opts.
 */
function mercuryListTransactions(string $apiToken, string $accountId, array $opts = []): array
{
    if ($accountId === '') throw new MercuryApiException('mercuryListTransactions: accountId required');
    $qs = [];
    foreach (['limit', 'offset', 'start', 'end', 'order', 'status'] as $k) {
        if (isset($opts[$k]) && $opts[$k] !== '') $qs[$k] = (string) $opts[$k];
    }
    $path = '/account/' . rawurlencode($accountId) . '/transactions';
    if ($qs) $path .= '?' . http_build_query($qs);
    return mercuryCall($apiToken, 'GET', $path);
}

// ============================================================================
// Slice 2: Recipients (counterparties) + External funding accounts
// ============================================================================

/**
 * POST /recipients — create a Mercury counterparty (vendor) the operating
 * account can pay via ACH/wire/check.
 *
 * Mercury's `/recipients` endpoint expects:
 *   { name, emails: [], paymentMethod: 'ach', defaultPaymentMethod: 'ach',
 *     electronicRoutingInfo: { electronicAccountType, routingNumber, accountNumber, ... } }
 *
 * Callers shape $payload to match. Returns raw Mercury body.
 */
function mercuryCreateCounterparty(string $apiToken, array $payload): array
{
    if (!is_array($payload) || empty($payload['name'])) {
        throw new MercuryApiException('mercuryCreateCounterparty: payload.name required');
    }
    return mercuryCall($apiToken, 'POST', '/recipients', $payload);
}

/** GET /recipients — list Mercury counterparties. */
function mercuryListCounterparties(string $apiToken, array $opts = []): array
{
    $qs = [];
    foreach (['limit', 'offset', 'search'] as $k) {
        if (isset($opts[$k]) && $opts[$k] !== '') $qs[$k] = (string) $opts[$k];
    }
    $path = '/recipients';
    if ($qs) $path .= '?' . http_build_query($qs);
    return mercuryCall($apiToken, 'GET', $path);
}

/**
 * Slice 2 NOTE on external funding accounts:
 *
 * Mercury's API does not expose a public endpoint to programmatically
 * register a tenant's external bank account as a fundable source — that
 * still happens manually inside the Mercury web UI (per current Mercury
 * docs). The mercury_recipient_mappings row for a `kind=funding_source`
 * therefore stores the EXISTING external_account id the operator copies
 * out of Mercury, rather than one CoreFlux creates over the wire.
 *
 * Slice 3 will originate the funding pull via the same `/account/{id}/
 * transactions` POST that originates outbound ACH — the recipient id
 * passed will be the external_account id of the tenant's funding source,
 * not a vendor counterparty.
 */

// ============================================================================
// Slice 3: Payment origination + status polling
// ============================================================================

/**
 * POST /account/{accountId}/transactions — originate a transaction.
 *
 * Used for BOTH directions:
 *   - Funding pull: $payload.recipientId = external_account id (funding_source mapping)
 *   - Vendor payout: $payload.recipientId = counterparty id (vendor mapping)
 *
 * Mercury's response shape includes `id` (transaction id) + `status`
 * (typically 'pending' on creation). Caller persists both into the
 * appropriate payment_instructions columns.
 *
 * Idempotency: caller passes a unique `idempotencyKey` in the payload;
 * Mercury short-circuits duplicate POSTs by returning the existing txn.
 */
function mercuryCreatePayment(string $apiToken, string $accountId, array $payload): array
{
    if ($accountId === '') {
        throw new MercuryApiException('mercuryCreatePayment: accountId required');
    }
    foreach (['recipientId', 'amount', 'paymentMethod', 'idempotencyKey'] as $k) {
        if (empty($payload[$k])) {
            throw new MercuryApiException("mercuryCreatePayment: payload.{$k} required");
        }
    }
    return mercuryCall($apiToken, 'POST', '/account/' . rawurlencode($accountId) . '/transactions', $payload);
}

/**
 * GET /account/{accountId}/transaction/{txnId} — poll a single transaction.
 * Returns Mercury's body; the `status` field drives the state machine.
 */
function mercuryGetPaymentStatus(string $apiToken, string $accountId, string $txnId): array
{
    if ($accountId === '' || $txnId === '') {
        throw new MercuryApiException('mercuryGetPaymentStatus: accountId + txnId required');
    }
    return mercuryCall($apiToken, 'GET',
        '/account/' . rawurlencode($accountId) . '/transaction/' . rawurlencode($txnId));
}
