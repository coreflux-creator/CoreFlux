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
        // Phase B will implement: per-item /transfer/authorization/create →
        // /transfer/create with idempotency_key and transfer_id, returning
        // the rail_external_ref (Plaid transfer.id) per item. Until then,
        // we throw cleanly so callers fall back to NACHA.
        throw new PaymentRailsNotConfiguredException(
            'Plaid Transfer originate() not yet wired. ' .
            'See /app/core/payment_rails/plaid_transfer_driver.php docblock for the Phase B checklist.'
        );
    }

    public function getStatus(string $railExternalRef): string
    {
        if (!$this->isConfigured()) return 'unknown';
        // Phase B: POST {host}/transfer/get { transfer_id: $railExternalRef }
        // and translate Plaid status (pending|posted|settled|cancelled|failed|returned) 1:1.
        return 'unknown';
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
