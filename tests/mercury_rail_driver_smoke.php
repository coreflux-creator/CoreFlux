<?php
/**
 * Smoke — Mercury payment rail driver (2026-02).
 *
 * Locks:
 *   - core/payment_rails/mercury_driver.php (new MercuryRailDriver)
 *   - core/payment_rails.php → driver registered in getDriver() + list()
 *
 * Source-level + behavioural assertions. Bridges the canonical
 * PaymentRailsDriver contract to the bespoke Mercury payment engine
 * without rewriting either.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};

// ──────────────────────────────────────────────────────────────────────
// 1) Driver file structure
// ──────────────────────────────────────────────────────────────────────
echo "\n── Driver source ──\n";
$d = file_get_contents('/app/core/payment_rails/mercury_driver.php');
$a('MercuryRailDriver class defined',
    str_contains($d, 'class MercuryRailDriver implements PaymentRailsDriver'));
$a('name() returns "mercury"',
    str_contains($d, "public function name(): string { return 'mercury'; }"));
$a('isConfigured() returns true globally (per-tenant gate in originate)',
    str_contains($d, 'public function isConfigured(): bool { return true; }'));
$a('isConfiguredForTenant() checks mercury_connections.status=active',
    str_contains($d, "mercury_connections WHERE tenant_id = :t")
    && str_contains($d, "(\$row['status'] ?? '') === 'active'"));

// ──────────────────────────────────────────────────────────────────────
// 2) originate() contract
// ──────────────────────────────────────────────────────────────────────
echo "\n── originate() ──\n";
$a('requires tenant_id in opts',
    str_contains($d, "Mercury rail requires tenant_id in opts"));
$a('throws PaymentRailsNotConfiguredException for un-connected tenants',
    str_contains($d, 'PaymentRailsNotConfiguredException')
    && str_contains($d, 'Mercury is not configured for tenant'));
$a('upserts recipient before mpCreate()',
    str_contains($d, 'private function upsertRecipient(int $tenantId'));
$a('upsertRecipient matches by tenant+name+account_last4',
    str_contains($d, 'r.tenant_id = :t AND r.kind = "vendor"')
    && str_contains($d, 'bm.account_number_last4 = :l4'));
$a('upsertRecipient delegates to mercuryRecipientCreate (encryption-safe)',
    str_contains($d, 'mercuryRecipientCreate($tenantId, ['));
$a('calls mpCreate() with idempotency key derived from tenant+external_ref+batch',
    str_contains($d, "'idempotency_key' => 'rail_' . hash('sha256'"));
$a('submits each instruction for approval immediately',
    str_contains($d, 'mpSubmitForApproval($tenantId, (int) $created[\'id\']'));
$a('returns rail_external_ref in the mercury:instruction:N format',
    str_contains($d, "'rail_external_ref' => 'mercury:instruction:' . (int) \$created['id']"));
$a('failed item still appears in result with status=failed',
    str_contains($d, "'status'            => 'failed'")
    && str_contains($d, "'error'             => \$e->getMessage()"));
$a('validates routing must be 9 digits',
    str_contains($d, 'routing must be 9 digits'));

// ──────────────────────────────────────────────────────────────────────
// 3) getStatus() mapping
// ──────────────────────────────────────────────────────────────────────
echo "\n── getStatus() mapping ──\n";
$a('parses mercury:instruction:N format',
    str_contains($d, "preg_match('/^mercury:instruction:(\\d+)$/', \$railExternalRef, \$m)"));
$a('maps Draft/PendingApproval/Approved/Funding → pending',
    str_contains($d, "'Draft', 'PendingApproval', 'Approved', 'Funding' => 'pending'"));
$a('maps Submitted → submitted',
    str_contains($d, "'Submitted'                                       => 'submitted'"));
$a('maps Settled/Reconciled → settled',
    str_contains($d, "'Settled', 'Reconciled'                           => 'settled'"));
$a('maps Returned/Failed/Cancelled correctly',
    str_contains($d, "'Returned'                                        => 'returned'")
    && str_contains($d, "'Failed'                                          => 'failed'")
    && str_contains($d, "'Cancelled'                                       => 'cancelled'"));

// ──────────────────────────────────────────────────────────────────────
// 4) metadata() rail-card surface
// ──────────────────────────────────────────────────────────────────────
echo "\n── metadata() ──\n";
$a('metadata exposes cost + settlement window',
    str_contains($d, "'cost_per_item_dollars'    => 0.00")
    && str_contains($d, "'settlement_business_days' => ['min' => 1, 'max' => 3]"));
$a('metadata falls back to nacha on origination failure',
    str_contains($d, "'fallback_to'              => 'nacha'"));
$a('metadata needs_funding_link is true',
    str_contains($d, "'needs_funding_link'       => true"));

// ──────────────────────────────────────────────────────────────────────
// 5) Registry wiring
// ──────────────────────────────────────────────────────────────────────
echo "\n── Registry wiring ──\n";
$reg = file_get_contents('/app/core/payment_rails.php');
$a('paymentRailsGetDriver handles "mercury"',
    str_contains($reg, "case 'mercury':")
    && str_contains($reg, "return new MercuryRailDriver();"));
$a('paymentRailsList surfaces Mercury rail card',
    str_contains($reg, "'id'          => 'mercury'")
    && str_contains($reg, "'name'        => 'Mercury (ACH)'"));

// ──────────────────────────────────────────────────────────────────────
// 6) Behavioural — driver instantiates + answers via require
// ──────────────────────────────────────────────────────────────────────
echo "\n── Behavioural ──\n";
require_once __DIR__ . '/../core/payment_rails.php';
$drv = paymentRailsGetDriver('mercury');
$a('Driver instantiates without error',
    is_object($drv));
$a('Driver name() returns "mercury"',
    method_exists($drv, 'name') && $drv->name() === 'mercury');
$a('Driver isConfigured() returns true (env-level)',
    method_exists($drv, 'isConfigured') && $drv->isConfigured() === true);
$meta = $drv->metadata();
$a('metadata() returns array with expected keys',
    is_array($meta)
    && isset($meta['cost_per_item_dollars'])
    && isset($meta['settlement_business_days'])
    && isset($meta['fallback_to'])
    && isset($meta['pros']) && isset($meta['cons']));
$a('Driver getStatus() returns "unknown" for unparseable ref',
    $drv->getStatus('garbage-ref') === 'unknown');
$a('Registry list includes mercury alongside nacha/plaid_transfer',
    in_array('mercury', array_column(paymentRailsList(), 'id'), true)
    && in_array('nacha', array_column(paymentRailsList(), 'id'), true)
    && in_array('plaid_transfer', array_column(paymentRailsList(), 'id'), true));

echo "\n=========================================\n";
echo "Mercury rail driver smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
