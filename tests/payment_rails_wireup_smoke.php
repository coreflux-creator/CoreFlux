<?php
/**
 * Payment Rails wire-up smoke test
 *
 * Validates that AP and Payroll both consume paymentRails::originate()
 * via the shared helpers, and that:
 *  - vendor routing schema is in place (mig 009)
 *  - originate handlers persist rail_external_ref / rail_status
 *  - audit events emitted, manifests updated
 *  - NACHA driver still works against synthetic items (no DB, no creds)
 */

declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$c = fn (string $h, string $n): bool => strpos($h, $n) !== false;

echo "Migration 009_vendor_routing.sql\n";
$mig = (string) file_get_contents(__DIR__ . '/../modules/ap/migrations/009_vendor_routing.sql');
$a('adds payment_routing_ct',                  $c($mig, 'payment_routing_ct VARBINARY(512)'));
$a('adds payment_routing_last4',               $c($mig, 'payment_routing_last4 CHAR(4)'));
$a('adds payment_account_type checking/savings', $c($mig, "ENUM(\"checking\",\"savings\")"));
$a('idempotent (information_schema guard)',    $c($mig, 'information_schema.COLUMNS'));
$a('utf8mb4_unicode_ci safe (no 0900_ai_ci)',  stripos($mig, 'utf8mb4_0900_ai_ci') === false);

echo "\ncore/payment_rails/originate_helpers.php\n";
$h = (string) file_get_contents(__DIR__ . '/../core/payment_rails/originate_helpers.php');
$a('paymentRailsDecryptBank declared',         $c($h, 'function paymentRailsDecryptBank'));
$a('paymentRailsBuildItem declared',           $c($h, 'function paymentRailsBuildItem'));
$a('paymentRailsDispatch declared',            $c($h, 'function paymentRailsDispatch'));
$a('Decrypt validates 9-digit routing',        $c($h, 'routing must be 9 digits'));
$a('Decrypt validates account length 4..17',   $c($h, 'account number length invalid'));
$a('BuildItem requires positive amount_cents', $c($h, 'amount_cents must be > 0'));
$a('Dispatch surfaces config error (no NACHA fall-back per 2026-02)',
    $c($h, 'Admin → Export Templates') && $c($h, 'isConfigured()'));

echo "\nAP — payments.php?action=originate\n";
$ap = (string) file_get_contents(__DIR__ . '/../modules/ap/api/payments.php');
$a('originate action handler present',         $c($ap, "\$action === 'originate'"));
$a('requires ap.payment.send permission',      $c($ap, "rbac_legacy_require(\$user, 'ap.payment.send')"));
$a('refuses unless status=sent',               $c($ap, 'Originate requires status=sent'));
$a('refuses non-ach/plaid methods',            $c($ap, 'Originate only supports ach/plaid methods'));
$a('idempotent (refuses if already originated)', $c($ap, 'Already originated on rail'));
$a('joins ap_vendors_index for banking',       $c($ap, 'FROM ap_vendors_index') && $c($ap, 'payment_routing_ct'));
$a('uses paymentRailsDecryptBank',             $c($ap, 'paymentRailsDecryptBank('));
$a('uses paymentRailsBuildItem',               $c($ap, 'paymentRailsBuildItem('));
$a('uses paymentRailsDispatch with module=ap', $c($ap, "paymentRailsDispatch('ap'"));
$a('1099 individuals dispatch as PPD',         $c($ap, "vendor_type'] === '1099_individual'") && $c($ap, "? 'ppd' : 'ccd'"));
$a('non-individuals dispatch as CCD',          $c($ap, "'ccd'"));
$a('persists rail_external_ref + status + at', $c($ap, 'rail_external_ref = :x') && $c($ap, 'rail_originated_at = NOW()'));
$a('audits ap.payment.originated',             $c($ap, "'ap.payment.originated'"));
$a('audits ap.payment.originate_failed',       $c($ap, "'ap.payment.originate_failed'"));
$a('AP — payments.php?action=originate, returns NACHA file b64 when rail=nacha',
    strpos($ap, 'nacha_file_b64') !== false);

echo "\nPayroll — runs?action=originate\n";
$pr = (string) file_get_contents(__DIR__ . '/../modules/payroll/api/runs.php');
$a('originate action handler present',         $c($pr, "\$action === 'originate'"));
$a('requires payroll.run.disburse',            $c($pr, "rbac_legacy_require(\$ctx['user'], 'payroll.run.disburse')"));
$a('refuses unless status=approved',           $c($pr, 'Originate requires status=approved'));
$a('idempotent (refuses if already originated)', $c($pr, 'Already originated on rail'));
$a('joins people_employees + people_bank_accounts',
    $c($pr, 'FROM payroll_line_items li') && $c($pr, 'JOIN people_employees') && $c($pr, 'people_bank_accounts'));
$a('skips check (non-DD) line items',          $c($pr, "'reason' => 'check'"));
$a('skips line items with no active bank',     $c($pr, "'no_active_bank_account'"));
$a('skips zero/negative net',                  $c($pr, "'zero_or_negative_net'"));
$a('uses paymentRailsDecryptBank',             $c($pr, 'paymentRailsDecryptBank('));
$a('uses paymentRailsDispatch with module=payroll', $c($pr, "paymentRailsDispatch('payroll'"));
$a('uses sec_code=ppd for W-2 employees',      $c($pr, "'sec_code'       => 'ppd'"));
$a('persists rail_external_ref on payroll_runs',
    $c($pr, "'rail_external_ref'  => \$res['batch_id']") && $c($pr, "'rail_originated_at'"));
$a('audits payroll.run.originated',            $c($pr, "'payroll.run.originated'"));
$a('audits payroll.run.originate_failed',      $c($pr, "'payroll.run.originate_failed'"));
$a('returns NACHA file b64 when rail=nacha',   $c($pr, 'nacha_file_b64'));

echo "\nAP — vendors.php accepts encrypted routing\n";
$v = (string) file_get_contents(__DIR__ . '/../modules/ap/api/vendors.php');
$a('insert column list includes payment_routing_ct',  $c($v, 'payment_routing_ct, payment_routing_last4, payment_account_type'));
$a('encrypts payment_routing_full',                   $c($v, 'encryptField($payRoutFull)'));
$a('captures payment_routing_last4',                  $c($v, 'last4($payRoutFull)'));
$a('upsert preserves on duplicate',                   $c($v, 'payment_routing_ct      = COALESCE'));

echo "\nManifests — new audit events\n";
$apMan = (string) file_get_contents(__DIR__ . '/../modules/ap/manifest.php');
$a('AP manifest has ap.payment.originated',            $c($apMan, "'ap.payment.originated'"));
$a('AP manifest has ap.payment.originate_failed',      $c($apMan, "'ap.payment.originate_failed'"));
$prMan = (string) file_get_contents(__DIR__ . '/../modules/payroll/manifest.php');
$a('Payroll manifest has payroll.run.originated',      $c($prMan, "'payroll.run.originated'"));
$a('Payroll manifest has payroll.run.originate_failed', $c($prMan, "'payroll.run.originate_failed'"));

echo "\nFunctional — NACHA driver round-trip via helpers\n";
require_once __DIR__ . '/../core/payment_rails.php';
require_once __DIR__ . '/../core/payment_rails/originate_helpers.php';

// Build a synthetic item by hand (no DB, no decryptField needed).
$item = paymentRailsBuildItem([
    'external_ref'   => 'ap_payment:42',
    'recipient_name' => 'Acme Vendor LLC',
    'routing'        => '021000021',
    'account'        => '12345678',
    'account_type'   => 'checking',
    'amount_cents'   => 12345,
    'sec_code'       => 'ccd',
    'description'    => 'AP-PAY',
]);
$a('BuildItem returns expected keys',
    isset($item['account_routing'], $item['account_number'], $item['amount_cents'], $item['sec_code']));
$a('BuildItem trims recipient to 22 chars',  strlen($item['recipient_name']) <= 22);
$a('BuildItem trims description to 10 chars', strlen($item['description']) <= 10);

$res = paymentRailsDispatch('ap',
    ['tenant_id' => 1], // no DB row needed, but dispatch is tenant-scoped
    ['nacha_company_id' => '1234567890', 'nacha_company_name' => 'CORE FLUX', 'nacha_origin_routing' => '021000021', 'disbursement_rail' => 'nacha'],
    [$item]
);
$a('Dispatch resolves rail=nacha',           ($res['rail'] ?? '') === 'nacha');
$a('Dispatch returns batch_id',               !empty($res['batch_id']));
$a('Dispatch returns submitted-status batch', in_array($res['status'] ?? '', ['queued','submitted'], true));
$a('Dispatch surfaces NACHA file content',    !empty($res['payload']['content']));
$a('Dispatch echoes per-item result',         count($res['items']) === 1 && ($res['items'][0]['external_ref'] ?? '') === 'ap_payment:42');

// Validation paths
try { paymentRailsBuildItem(['external_ref'=>'x']); $a('BuildItem rejects missing keys', false); }
catch (PaymentRailsOriginateException $e) { $a('BuildItem rejects missing keys', true); }

try {
    paymentRailsBuildItem([
        'external_ref'=>'x','recipient_name'=>'A','routing'=>'021000021','account'=>'1','account_type'=>'checking',
        'amount_cents'=>0,'sec_code'=>'ppd','description'=>'X',
    ]);
    $a('BuildItem rejects amount_cents<=0', false);
} catch (PaymentRailsOriginateException $e) {
    $a('BuildItem rejects amount_cents<=0', true);
}

echo PHP_EOL . "Total: $pass passed, $fail failed" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
