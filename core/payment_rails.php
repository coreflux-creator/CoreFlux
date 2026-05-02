<?php
/**
 * CoreFlux Payment Rails — outbound disbursement abstraction.
 *
 * One contract, multiple drivers (NACHA file, Plaid Transfer, future Stripe
 * Treasury / Modern Treasury / Dwolla). Both AP outbound payments and
 * Payroll direct-deposit funding use the same interface, so the rail is
 * swappable per-tenant (per-module) without touching consumer code.
 *
 * Decision matrix:
 *   - tenant has Plaid configured + opted in       → plaid_transfer
 *   - tenant explicitly chose nacha                → nacha
 *   - tenant has Plaid configured but rail=nacha   → nacha (override wins)
 *   - default / nothing chosen                     → nacha (zero-key fallback)
 *
 * Per AP SPEC §12.2 and Payroll SPEC §2.2, this is the PaymentRailsDriver
 * interface called for from the start. Future drivers slot in without UI
 * or workflow changes.
 */

declare(strict_types=1);

/**
 * Single item in an originate() batch. Same shape for AP + Payroll.
 *
 * @phpstan-type RailItem array{
 *   external_ref:        string,        // our id, e.g. "ap_payment:42" or "payroll_line:715"
 *   recipient_name:      string,        // legal name of payee
 *   account_routing:     string,        // 9-digit ABA
 *   account_number:      string,        // up to 17 chars
 *   account_type:        'checking'|'savings',
 *   amount_cents:        int,           // > 0, always credit
 *   sec_code:            'ppd'|'ccd',   // ppd=consumer (W-2 employees), ccd=business (vendors)
 *   description:         string,        // 10 chars max, will be truncated
 *   addenda?:            string|null,   // 80-char addenda (CCD+ entries)
 * }
 */

interface PaymentRailsDriver
{
    /** Stable rail identifier, e.g. "nacha", "plaid_transfer". */
    public function name(): string;

    /**
     * True when the driver has everything it needs to originate (creds,
     * funding-account access tokens, etc.). Drivers that always work
     * (e.g. file-based NACHA) return true unconditionally.
     */
    public function isConfigured(): bool;

    /**
     * Originate a batch of items.
     *
     * @param array<int, array<string, mixed>> $items  list of RailItem dicts
     * @param array<string, mixed>             $opts   { effective_date: 'YYYY-MM-DD',
     *                                                  company_name: string,
     *                                                  company_id: string,         // 10-char originator ID
     *                                                  origin_routing: string,     // ODFI ABA
     *                                                  service_class: 'credits_only'|'mixed' }
     * @return array{
     *     batch_id: string,                    // driver-generated, idempotent
     *     status:   'queued'|'submitted'|'failed',
     *     items:    array<int, array{external_ref: string, status: string, rail_external_ref?: string|null, error?: string|null}>,
     *     payload?: array<string, mixed>,      // driver-specific extras (e.g. NACHA file content, Plaid transfer IDs)
     * }
     */
    public function originate(array $items, array $opts): array;

    /**
     * Look up the latest status for a single rail-side reference.
     * Returns one of: pending|submitted|posted|settled|returned|cancelled|failed|unknown.
     */
    public function getStatus(string $railExternalRef): string;

    /**
     * Static descriptive metadata about the rail — cost, settlement window,
     * supported features, fallback chain. Used to populate the rail-card
     * UI on AP / Payroll settings pages so tenants can pick rails on real
     * numbers, not gut feel.
     *
     * @return array{
     *   cost_per_item_dollars: float,        // e.g. 0.50 for plaid_transfer
     *   cost_pct:              float,        // e.g. 0.005 (0.5%) for plaid_transfer
     *   settlement_business_days: array{min:int, max:int},  // e.g. {0, 1} for same-day-capable rails
     *   supports_same_day_ach: bool,
     *   supports_rtp:          bool,
     *   needs_pre_approval:    bool,         // true for plaid_transfer (1-2 wk Plaid review)
     *   needs_funding_link:    bool,         // true if tenant must link an account via Plaid Link
     *   fallback_to:           string|null,  // rail id to fall back to on origination failure
     *   pros:                  array<int, string>,
     *   cons:                  array<int, string>,
     * }
     */
    public function metadata(): array;
}

/**
 * Thrown when a driver was asked to originate but is not yet configured
 * (e.g. Plaid scaffold called without keys). Callers should fall back to
 * the configured default rail (nacha) and log it.
 */
class PaymentRailsNotConfiguredException extends \RuntimeException {}

/**
 * Thrown for validation or driver-side originate failures.
 */
class PaymentRailsOriginateException extends \RuntimeException {}

/**
 * Resolve a driver instance by name. Throws InvalidArgumentException for
 * unknown rails so misconfigurations fail loudly.
 */
function paymentRailsGetDriver(string $rail): PaymentRailsDriver
{
    switch ($rail) {
        case 'nacha':
            require_once __DIR__ . '/payment_rails/nacha_driver.php';
            return new NachaDriver();
        case 'plaid_transfer':
            require_once __DIR__ . '/payment_rails/plaid_transfer_driver.php';
            return new PlaidTransferDriver();
        default:
            throw new \InvalidArgumentException("Unknown payment rail: {$rail}");
    }
}

/**
 * Available rails (registry). Exposed so admin UIs can render a dropdown.
 * Now also surfaces rail metadata (cost / settlement / fallback) so the
 * rail-card UI can show real numbers.
 *
 * @return array<int, array{id: string, name: string, configured: bool, description: string, metadata: array<string, mixed>}>
 */
function paymentRailsList(): array
{
    $nacha = paymentRailsGetDriver('nacha');
    $plaid = paymentRailsGetDriver('plaid_transfer');
    return [
        [
            'id'          => 'nacha',
            'name'        => 'NACHA file',
            'configured'  => $nacha->isConfigured(),
            'description' => 'Generate a NACHA-format ACH file. Tenant uploads it to their bank\'s cash-management portal. Zero external dependency.',
            'metadata'    => $nacha->metadata(),
        ],
        [
            'id'          => 'plaid_transfer',
            'name'        => 'Plaid Transfer (ACH API)',
            'configured'  => $plaid->isConfigured(),
            'description' => 'Programmatic ACH origination via Plaid. Requires Plaid Transfer pre-approval (1-2 weeks) and PLAID_CLIENT_ID + PLAID_SECRET_* env vars. Tenant must link a funding account through Plaid Link first.',
            'metadata'    => $plaid->metadata(),
        ],
    ];
}

/**
 * Resolve the disbursement rail for a given module + tenant.
 * Order of precedence:
 *   1. per-row override (e.g. payroll_runs.disbursement_rail / ap_payments.disbursement_rail)
 *   2. per-module tenant setting (payroll_settings.disbursement_rail / ap_settings.disbursement_rail)
 *   3. global fallback ('nacha')
 *
 * @param string                $module    'ap' or 'payroll'
 * @param array<string, mixed>  $row       the payroll_run / ap_payment row (may carry a per-row override)
 * @param array<string, mixed>  $settings  the matching tenant settings row (may carry a default)
 */
function paymentRailsResolveRail(string $module, array $row, array $settings): string
{
    $override = trim((string) ($row['disbursement_rail'] ?? ''));
    if ($override !== '') return $override;
    $tenant   = trim((string) ($settings['disbursement_rail'] ?? ''));
    if ($tenant !== '') return $tenant;
    return 'nacha';
}
