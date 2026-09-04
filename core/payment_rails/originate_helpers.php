<?php
/**
 * Helpers shared by AP `payments?action=originate` and Payroll
 * `runs?action=originate` to dispatch a batch through paymentRails.
 *
 * Per AP SPEC §12.2 / Payroll SPEC §2.2, both modules use the same
 * PaymentRailsDriver interface — these helpers shape per-module rows
 * into the canonical RailItem shape, resolve the rail, and persist
 * rail_external_ref / rail_status / rail_originated_at on the source
 * row so the bank-rec / status webhook can match it back.
 */

declare(strict_types=1);

require_once __DIR__ . '/../payment_rails.php';
require_once __DIR__ . '/../encryption.php';

/**
 * Decrypt bank info, validate it, and return ['routing' => ..., 'account' => ..., 'last4_acct' => ...].
 * Throws PaymentRailsOriginateException with a clear, non-PII message on bad data.
 */
function paymentRailsDecryptBank(?string $routingCt, ?string $accountCt, string $context): array
{
    if (!$routingCt || !$accountCt) {
        throw new PaymentRailsOriginateException("$context: missing bank routing or account");
    }
    $routing = decryptField($routingCt);
    $account = decryptField($accountCt);
    if (!$routing || !$account) {
        throw new PaymentRailsOriginateException("$context: cannot decrypt bank info");
    }
    $routing = preg_replace('/\D+/', '', $routing) ?? '';
    $account = preg_replace('/\s+/', '', $account) ?? '';
    if (strlen($routing) !== 9) {
        throw new PaymentRailsOriginateException("$context: routing must be 9 digits");
    }
    if (strlen($account) < 4 || strlen($account) > 17) {
        throw new PaymentRailsOriginateException("$context: account number length invalid");
    }
    return ['routing' => $routing, 'account' => $account, 'last4_acct' => substr($account, -4)];
}

/**
 * Build a RailItem from a per-module dict.
 * Required keys in $row: external_ref, recipient_name, amount_cents,
 *                        sec_code, description. Direct-bank rails additionally
 *                        require routing/account; provider-vault rails do not.
 */
function paymentRailsBuildItem(array $row): array
{
    foreach (['external_ref','recipient_name','amount_cents','sec_code','description'] as $k) {
        if (!isset($row[$k]) || $row[$k] === '' || $row[$k] === null) {
            throw new PaymentRailsOriginateException("RailItem missing key: $k");
        }
    }
    if ((int) $row['amount_cents'] <= 0) {
        throw new PaymentRailsOriginateException('RailItem amount_cents must be > 0');
    }
    $item = [
        'external_ref'    => (string) $row['external_ref'],
        'recipient_name'  => substr((string) $row['recipient_name'], 0, 22),
        'recipient_full_name' => trim((string) ($row['recipient_full_name'] ?? $row['recipient_name'])),
        'amount_cents'    => (int) $row['amount_cents'],
        'sec_code'        => (string) $row['sec_code'],
        'description'     => substr((string) $row['description'], 0, 10),
        'addenda'         => $row['addenda'] ?? null,
        'recipient_ref'   => isset($row['recipient_ref']) ? (string) $row['recipient_ref'] : null,
        'recipient_email' => isset($row['recipient_email']) ? (string) $row['recipient_email'] : null,
        'invoice_number'  => isset($row['invoice_number']) ? (string) $row['invoice_number'] : null,
    ];
    if (isset($row['routing']) && $row['routing'] !== '') $item['account_routing'] = (string) $row['routing'];
    if (isset($row['account']) && $row['account'] !== '') $item['account_number'] = (string) $row['account'];
    if (isset($item['account_routing']) || isset($item['account_number'])) {
        $item['account_type'] = in_array($row['account_type'] ?? 'checking', ['checking','savings'], true)
            ? $row['account_type'] : 'checking';
    }
    return $item;
}

/**
 * Dispatch `$items` through the configured rail.
 *
 * @param string $module    'ap' | 'payroll'
 * @param array  $sourceRow source row carrying optional disbursement_rail override
 * @param array  $settings  tenant settings row (NACHA company_id / company_name / origin_routing / disbursement_rail)
 * @param array  $items     RailItem[]
 * @return array{rail:string, batch_id:string, status:string, items:array, payload?:array}
 */
function paymentRailsDispatch(string $module, array $sourceRow, array $settings, array $items): array
{
    $rail   = paymentRailsResolveRail($module, $sourceRow, $settings);
    $driver = paymentRailsGetDriver($rail);
    if (!$driver->isConfigured()) {
        // Plaid Transfer not configured for this tenant. Per product
        // direction (2026-02), we no longer auto-fall-back to NACHA — instead
        // surface a clean error so the UI can prompt the tenant to either
        // (a) link a Plaid funding source, or (b) export via a CSV template
        // that matches their bank/payroll provider.
        throw new PaymentRailsNotConfiguredException(
            "Rail '$rail' is not configured for this tenant. Either link a " .
            'funding source under Treasury → Plaid, or export your payments ' .
            'via Admin → Export Templates and upload to your bank manually.'
        );
    }
    $opts = [
        'company_name'    => (string) ($settings['nacha_company_name']    ?? ($module === 'payroll' ? 'PAYROLL'  : 'AP')),
        'company_id'      => (string) ($settings['nacha_company_id']      ?? '1234567890'),
        'origin_routing'  => (string) ($settings['nacha_origin_routing']  ?? ''),
        'service_class'   => 'credits_only',
        'effective_date'  => (string) ($sourceRow['effective_date'] ?? date('Y-m-d', strtotime('+1 day'))),
        'tenant_id'       => isset($sourceRow['tenant_id']) ? (int) $sourceRow['tenant_id']
                              : (isset($settings['tenant_id']) ? (int) $settings['tenant_id']
                              : (function_exists('currentTenantContext') ? (int) (currentTenantContext()['tenant_id'] ?? 0) : 0)),
    ];
    $res = $driver->originate($items, $opts);
    $res['rail'] = $rail;
    return $res;
}
