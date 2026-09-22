<?php
/**
 * Static regression coverage for referral-only placements.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = static function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    {$name}\n"; }
    else { $fail++; echo "  FAIL  {$name}\n"; }
};
$read = static fn(string $path): string => (string) file_get_contents(__DIR__ . '/../' . $path);

echo "Referral engagement schema and APIs\n";
$migration = $read('modules/placements/migrations/005_referral_engagement_type.sql');
$assert('placement enum includes referral', str_contains($migration, "'internal','referral'"));
$assert('placement enum preserves the W-2 default', str_contains($migration, "DEFAULT 'w2'"));

$placementsApi = $read('modules/placements/api/placements.php');
$assert('placements API accepts referral', str_contains($placementsApi, "'internal','referral'"));
$assert('referral person remains a candidate', str_contains($placementsApi, "'referral' => 'candidate'"));

$referralsApi = $read('modules/placements/api/referrals.php');
$assert('hourly payout must be positive', str_contains($referralsApi, 'Hourly referral payout must be greater than 0'));
$assert('referral fee basis is allow-listed', str_contains($referralsApi, "['per_hour', 'per_invoice', 'one_time', 'pct_bill', 'pct_margin']"));

echo "\nEconomics and settlement\n";
$economics = $read('modules/placements/lib/economics.php');
$assert('no worker payable is fabricated', str_contains($economics, "!in_array(\$engagement, ['c2c', 'referral'], true)"));
$assert('activation requires one valid referral payout',
    str_contains($economics, "'missing_referral_payee'")
    && str_contains($economics, "'multiple_referral_payees'")
    && str_contains($economics, "\$r['fee_basis'] === 'per_hour'")
    && str_contains($economics, "(float) (\$r['fee_flat'] ?? 0) > 0"));

$settlement = $read('modules/time/lib/settlement_create.php');
$assert('billing requires a positive rate independently', str_contains($settlement, "\$target === 'billing' && \$unitPrice <= 0"));

$ap = $read('modules/ap/lib/ap.php');
$assert('zero placement pay rate is valid without a primary AP party', str_contains($ap, 'if ($party && $base <= 0)'));

$timesheets = $read('modules/staffing/lib/timesheets.php');
$assert('approved referral hours use the hourly referral payout', str_contains($timesheets, 'placement_referrals') && str_contains($timesheets, "ref.fee_basis = 'per_hour'"));
$assert('event marks referral hours explicitly', str_contains($timesheets, "'is_referral'"));

$rules = $read('modules/staffing/lib/posting_rules_seed.php');
$assert('referral posting template exists', str_contains($rules, 'staffing.referral_hours_approved'));
$assert('referral posting rule uses is_referral', str_contains($rules, 'payload.is_referral'));
$seedAll = $read('core/seeds/posting_rules_seed_all.php');
$assert('production seed pass installs referral accounting', str_contains($seedAll, 'staffingSeedPostingRules'));

echo "\nBulk import and operator UI\n";
$csv = $read('modules/placements/api/csv_import.php');
foreach (['referral_client_rate', 'referral_vendor_name', 'referral_payout_rate', 'referral_payment_terms'] as $field) {
    $assert("CSV field {$field}", str_contains($csv, "'{$field}'"));
}
$assert('CSV creates hourly referral economics', str_contains($csv, "'fee_basis' => 'per_hour'"));
$assert('CSV requires the client fee', str_contains($csv, 'Referral-only placements require a positive hourly referral fee paid by client.'));
$assert('CSV requires the payout', str_contains($csv, 'Referral-only placements require a positive vendor referral payout.'));
$assert('CSV requires the payout vendor', str_contains($csv, 'Referral-only placements require referral_vendor_name or referral_vendor_company_id.'));

$create = $read('modules/placements/ui/PlacementCreate.jsx');
$assert('create form exposes Referral placement', str_contains($create, "referral: 'Referral placement'"));
$assert('create form asks for client fee', str_contains($create, 'Client referral fee / hour'));
$assert('create form asks for payout vendor', str_contains($create, 'Referral vendor *'));
$assert('create form asks for payout rate', str_contains($create, 'Vendor referral payout / hour *'));

echo "\nSource-system normalization\n";
$sync = $read('core/jobdiva/sync.php');
$assert('Ref normalizes to referral', str_contains($sync, "\$s === 'ref' || str_contains(\$s, 'referral')"));
$assert('JobDiva referral payouts are hourly', str_contains($sync, "\$isReferralEngagement") && str_contains($sync, "? 'per_hour'"));
$assert('JobDiva referral payout is not duplicated as worker pay',
    str_contains($sync, 'same vendor obligation twice') && str_contains($sync, '$payRate = 0.0;'));

$projection = $read('core/jobdiva/contract_projection.php');
$assert('contract readiness recognizes referral', str_contains($projection, "'internal', 'referral'"));
$assert('contract preview names referral payout', str_contains($projection, 'Referral payout rate'));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
