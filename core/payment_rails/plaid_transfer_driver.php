<?php
/**
 * Plaid Transfer driver — scaffolded, env-gated.
 *
 * Per playbook: Plaid Transfer needs (1) a Plaid Transfer pre-approval
 * (1-2 week manual review by Plaid), (2) PLAID_CLIENT_ID + PLAID_SECRET_*
 * env vars, (3) the tenant linking a funding source through Plaid Link
 * and the resulting access_token persisted somewhere we can fetch.
 *
 * This driver:
 *   - reports isConfigured()=true only when env keys are present AND a
 *     Plaid Transfer agreement / authorization is on file for the tenant
 *   - throws PaymentRailsNotConfiguredException from originate() until the
 *     full Phase B Plaid wiring (Link → public_token exchange → /transfer/
 *     authorization/create → /transfer/create + webhooks) is shipped
 *   - exposes the exact endpoint constants from the Plaid playbook so the
 *     Phase B agent can drop them in without rediscovery
 *
 * Extending this scaffold requires:
 *   1. tenant_payment_rails table to persist Plaid access_token + funding
 *      account_id per tenant (see /app/core/migrations/005_payment_rails.sql §3)
 *   2. /api/core/webhooks/plaid endpoint with JWT (ES256) signature
 *      verification per playbook §"Webhook Handler Implementation"
 *   3. POST /api/core/payment_rails/plaid/link_token   → /link/token/create
 *   4. POST /api/core/payment_rails/plaid/exchange     → /item/public_token/exchange
 *   5. originate() → /transfer/authorization/create + /transfer/create per item,
 *      with idempotency_key and transfer_id for safe retry
 */

declare(strict_types=1);

require_once __DIR__ . '/../payment_rails.php';

class PlaidTransferDriver implements PaymentRailsDriver
{
    public const HOST_SANDBOX    = 'https://sandbox.plaid.com';
    public const HOST_PRODUCTION = 'https://production.plaid.com';

    public const ENDPOINT_LINK_TOKEN_CREATE      = '/link/token/create';
    public const ENDPOINT_PUBLIC_TOKEN_EXCHANGE  = '/item/public_token/exchange';
    public const ENDPOINT_AUTHORIZATION_CREATE   = '/transfer/authorization/create';
    public const ENDPOINT_TRANSFER_CREATE        = '/transfer/create';
    public const ENDPOINT_TRANSFER_GET           = '/transfer/get';
    public const ENDPOINT_TRANSFER_EVENT_SYNC    = '/transfer/event/sync';
    public const ENDPOINT_WEBHOOK_VERIFICATION   = '/webhook_verification_key/get';
    public const ENDPOINT_SANDBOX_SIMULATE       = '/sandbox/transfer/simulate';

    public function name(): string { return 'plaid_transfer'; }

    public function isConfigured(): bool
    {
        $clientId = getenv('PLAID_CLIENT_ID');
        $env      = strtolower((string) (getenv('PLAID_ENV') ?: 'sandbox'));
        $secret   = $env === 'production'
            ? getenv('PLAID_SECRET_PRODUCTION')
            : getenv('PLAID_SECRET_SANDBOX');
        return is_string($clientId) && $clientId !== ''
            && is_string($secret)   && $secret   !== ''
            && in_array($env, ['sandbox','production'], true);
    }

    /** Active host for the configured environment. */
    public function host(): string
    {
        $env = strtolower((string) (getenv('PLAID_ENV') ?: 'sandbox'));
        return $env === 'production' ? self::HOST_PRODUCTION : self::HOST_SANDBOX;
    }

    public function originate(array $items, array $opts): array
    {
        if (!$this->isConfigured()) {
            throw new PaymentRailsNotConfiguredException(
                'Plaid Transfer driver: PLAID_CLIENT_ID + PLAID_SECRET_* env vars not set. ' .
                'Configure them on the pod, complete the Plaid Transfer Application from the ' .
                'Plaid Dashboard, and re-attempt. Falling back to NACHA in the meantime.'
            );
        }

        // Resolve the funding source for this tenant. Required for Plaid Transfer
        // — every /transfer/authorization/create needs an `access_token` +
        // `account_id` of the originator's funding bank account, persisted in
        // tenant_payment_rails after the tenant completes the Plaid Link flow.
        $tenantId = $opts['tenant_id'] ?? null;
        if (!$tenantId) {
            throw new \RuntimeException('Plaid Transfer originate(): opts[tenant_id] required');
        }
        $pdo = function_exists('getDB') ? getDB() : null;
        if (!$pdo) {
            throw new \RuntimeException('Plaid Transfer originate(): no DB connection');
        }
        $stmt = $pdo->prepare(
            "SELECT access_token_ct, account_id, item_id, status
               FROM tenant_payment_rails
              WHERE tenant_id = :t AND rail = 'plaid_transfer' LIMIT 1"
        );
        $stmt->execute(['t' => (int) $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || $row['status'] !== 'linked' || empty($row['access_token_ct']) || empty($row['account_id'])) {
            throw new PaymentRailsNotConfiguredException(
                'Plaid Transfer: tenant has not linked a funding source yet. ' .
                'Complete /modules/treasury → Connect Bank → Plaid Link first.'
            );
        }
        require_once __DIR__ . '/../plaid_service.php';
        $accessToken = plaidDecryptAccessToken($row['access_token_ct']);
        if (!$accessToken) throw new \RuntimeException('Plaid Transfer: could not decrypt access_token');

        $accountId = (string) $row['account_id'];
        $batchId   = 'plaid-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $effective = (string) ($opts['effective_date'] ?? date('Y-m-d'));
        $companyName = substr((string) ($opts['company_name'] ?? 'CoreFlux'), 0, 50);

        $resultItems = [];
        $fail = 0;

        foreach ($items as $i => $it) {
            $externalRef  = (string) $it['external_ref'];
            $amountCents  = (int)    ($it['amount_cents'] ?? 0);
            $amountStr    = sprintf('%.2f', $amountCents / 100);
            $secCode      = strtolower((string) ($it['sec_code'] ?? 'ppd'));
            $accType      = (string) ($it['account_type'] ?? 'checking');
            $idem         = "ic:rail:{$externalRef}";
            $userType     = $secCode === 'ccd' ? 'business' : 'individual';

            try {
                // Step 1 — authorization (Plaid risk-checks the transfer).
                $authPayload = [
                    'access_token'   => $accessToken,
                    'account_id'     => $accountId,
                    'type'           => 'credit',
                    'network'        => 'ach',
                    'amount'         => $amountStr,
                    'ach_class'      => $secCode,
                    'user' => [
                        'legal_name'   => substr((string) ($it['recipient_name'] ?? ''), 0, 100),
                    ],
                    'idempotency_key' => $idem . ':auth',
                ];
                $auth = $this->callPlaid(self::ENDPOINT_AUTHORIZATION_CREATE, $authPayload);
                $authId = $auth['authorization']['id'] ?? null;
                $decision = $auth['authorization']['decision'] ?? 'declined';
                if (!$authId || $decision !== 'approved') {
                    throw new \RuntimeException("authorization {$decision}: " .
                        ($auth['authorization']['decision_rationale']['description'] ?? 'declined'));
                }

                // Step 2 — actual transfer create.
                $transferPayload = [
                    'access_token'     => $accessToken,
                    'account_id'       => $accountId,
                    'authorization_id' => $authId,
                    'description'      => substr((string) ($it['description'] ?? 'payment'), 0, 15),
                    'amount'           => $amountStr,
                    'idempotency_key'  => $idem . ':transfer',
                ];
                $tr = $this->callPlaid(self::ENDPOINT_TRANSFER_CREATE, $transferPayload);
                $transferId = $tr['transfer']['id'] ?? null;
                $status     = $tr['transfer']['status'] ?? 'pending';

                $resultItems[] = [
                    'external_ref'      => $externalRef,
                    'status'            => $status,
                    'rail_external_ref' => $transferId,
                    'error'             => null,
                ];
            } catch (\Throwable $e) {
                $fail++;
                $resultItems[] = [
                    'external_ref'      => $externalRef,
                    'status'            => 'failed',
                    'rail_external_ref' => null,
                    'error'             => $e->getMessage(),
                ];
            }
        }

        return [
            'batch_id' => $batchId,
            'status'   => $fail === 0 ? 'submitted' : ($fail === count($items) ? 'failed' : 'submitted'),
            'items'    => $resultItems,
            'payload'  => [
                'effective_date' => $effective,
                'company_name'   => $companyName,
                'tenant_id'      => (int) $tenantId,
            ],
        ];
    }

    public function getStatus(string $railExternalRef): string
    {
        if (!$this->isConfigured() || $railExternalRef === '') return 'unknown';
        try {
            $resp = $this->callPlaid(self::ENDPOINT_TRANSFER_GET, ['transfer_id' => $railExternalRef]);
            $s = (string) ($resp['transfer']['status'] ?? 'unknown');
            // Plaid statuses: pending, posted, settled, cancelled, failed, returned.
            return $s === '' ? 'unknown' : $s;
        } catch (\Throwable $e) {
            return 'unknown';
        }
    }

    public function metadata(): array
    {
        return [
            'cost_per_item_dollars'    => 0.50,    // ballpark Plaid Transfer per-item
            'cost_pct'                 => 0.005,   // 0.5% fee
            'settlement_business_days' => ['min' => 0, 'max' => 1],
            'supports_same_day_ach'    => true,
            'supports_rtp'             => true,    // Plaid supports RTP rail
            'needs_pre_approval'       => true,    // 1-2 wk Plaid Transfer Application review
            'needs_funding_link'       => true,    // tenant must link funding source via Plaid Link
            'fallback_to'              => 'nacha', // if Plaid declines, fall back to NACHA file
            'pros'                     => [
                'Programmatic origination — no manual bank-portal step',
                'Status webhooks (pending → posted → settled → returned)',
                'Same-day ACH and RTP available',
                'Built-in risk / Signal Payment Risk checks',
            ],
            'cons'                     => [
                'Per-transfer fee (~$0.50 + 0.5%)',
                'Requires Plaid Transfer Application approval (1-2 week review)',
                'Tenant must link a funding account via Plaid Link',
            ],
        ];
    }

    /**
     * Helper Phase B will use to make signed Plaid API calls.
     * Kept here so the contract is visible from the scaffold.
     *
     * @internal
     */
    public function callPlaid(string $endpoint, array $body): array
    {
        if (!$this->isConfigured()) {
            throw new PaymentRailsNotConfiguredException('Plaid not configured');
        }
        $env    = strtolower((string) (getenv('PLAID_ENV') ?: 'sandbox'));
        $secret = $env === 'production'
            ? (string) getenv('PLAID_SECRET_PRODUCTION')
            : (string) getenv('PLAID_SECRET_SANDBOX');
        $payload = array_merge([
            'client_id' => (string) getenv('PLAID_CLIENT_ID'),
            'secret'    => $secret,
        ], $body);
        $ch = curl_init($this->host() . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($err) throw new \RuntimeException("Plaid cURL error: $err");
        $data = is_string($resp) ? json_decode($resp, true) : null;
        if (!is_array($data)) throw new \RuntimeException('Plaid: invalid JSON response');
        if ($code >= 400 || isset($data['error_code'])) {
            throw new \RuntimeException('Plaid: ' . ($data['error_message'] ?? "HTTP {$code}"));
        }
        return $data;
    }
}
