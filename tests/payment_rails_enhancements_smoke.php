<?php
/**
 * Payment Rails enhancements smoke — metadata badging + AP/Payroll settings UI.
 *
 *  - PaymentRailsDriver::metadata() returns the expected shape for both drivers
 *  - paymentRailsList() surfaces metadata in the public registry
 *  - /core/api/payment_rails.php endpoint exists, GET-only, requires auth
 *  - /modules/ap/api/settings.php exposes GET + PUT, validates rail value
 *  - /app/dashboard/src/components/RailPicker.jsx renders pills, badges, fallback chain
 *  - APModule wires Settings route + nav item
 *  - PayrollSettings.jsx imports RailPicker and renders disbursement-rail fieldset
 *  - Manifest declares ap.settings.updated audit event + Settings action route
 *  - Migration columns honoured by settings save (disbursement_rail, nacha_*)
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/payment_rails.php';
require_once __DIR__ . '/../core/payment_rails/nacha_driver.php';
require_once __DIR__ . '/../core/payment_rails/plaid_transfer_driver.php';

$pass = 0; $fail = 0;
$a = function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

echo "Driver metadata\n";
$nm = (new NachaDriver())->metadata();
$a('nacha cost_per_item_dollars = 0',          $nm['cost_per_item_dollars'] === 0.0);
$a('nacha cost_pct = 0',                       $nm['cost_pct'] === 0.0);
$a('nacha settlement_business_days min/max',   $nm['settlement_business_days']['min'] === 1 && $nm['settlement_business_days']['max'] === 2);
$a('nacha supports_same_day_ach = true',       $nm['supports_same_day_ach'] === true);
$a('nacha needs_pre_approval = false',         $nm['needs_pre_approval'] === false);
$a('nacha fallback_to = null',                 $nm['fallback_to'] === null);
$a('nacha pros / cons populated',              count($nm['pros']) >= 2 && count($nm['cons']) >= 2);

$pm = (new PlaidTransferDriver())->metadata();
$a('plaid cost_per_item_dollars > 0',          $pm['cost_per_item_dollars'] > 0);
$a('plaid cost_pct > 0',                       $pm['cost_pct'] > 0);
$a('plaid settlement min = 0 (same-day)',      $pm['settlement_business_days']['min'] === 0);
$a('plaid supports_rtp = true',                $pm['supports_rtp'] === true);
$a('plaid needs_pre_approval = true',          $pm['needs_pre_approval'] === true);
$a('plaid needs_funding_link = true',          $pm['needs_funding_link'] === true);
$a('plaid fallback_to = nacha',                $pm['fallback_to'] === 'nacha');

echo "\npaymentRailsList includes metadata\n";
$list = paymentRailsList();
$a('list returns 3 rails (nacha, plaid_transfer, mercury — Batch 2026-02)',
    count($list) === 3);
$a('every list entry has metadata key',
    !array_filter($list, fn($r) => !isset($r['metadata']) || !is_array($r['metadata'])));
$nachaRow = array_values(array_filter($list, fn($r) => $r['id'] === 'nacha'))[0];
$plaidRow = array_values(array_filter($list, fn($r) => $r['id'] === 'plaid_transfer'))[0];
$a('nacha row exposes settlement_business_days',  isset($nachaRow['metadata']['settlement_business_days']));
$a('plaid row exposes pros / cons arrays',
    is_array($plaidRow['metadata']['pros']) && is_array($plaidRow['metadata']['cons']));

echo "\nCore API endpoint /core/api/payment_rails.php\n";
$ep  = __DIR__ . '/../core/api/payment_rails.php';
$a('endpoint file exists',                     file_exists($ep));
$src = (string) file_get_contents($ep);
$a('endpoint requires auth',                   strpos($src, 'api_require_auth()') !== false);
$a('endpoint GET-only',                        strpos($src, "api_method() !== 'GET'") !== false &&
                                               strpos($src, "Method not allowed") !== false);
$a('endpoint returns paymentRailsList',        strpos($src, 'paymentRailsList()') !== false);
$a('endpoint requires payment_rails.php',      strpos($src, "require_once __DIR__ . '/../payment_rails.php'") !== false);

echo "\nAP settings API\n";
$apSet = __DIR__ . '/../modules/ap/api/settings.php';
$a('AP settings.php exists',                   file_exists($apSet));
$apSrc = (string) file_get_contents($apSet);
$a('AP settings handles GET',                  strpos($apSrc, "case 'GET'") !== false);
$a('AP settings handles PUT',                  strpos($apSrc, "case 'PUT'") !== false);
$a('AP settings validates rail value',
    strpos($apSrc, "array_map(fn(\$r) => \$r['id'], paymentRailsList())") !== false);
$a('AP settings persists nacha + plaid fields',
    strpos($apSrc, "'disbursement_rail'") !== false &&
    strpos($apSrc, "'nacha_company_id'") !== false &&
    strpos($apSrc, "'nacha_company_name'") !== false &&
    strpos($apSrc, "'nacha_origin_routing'") !== false &&
    strpos($apSrc, "'plaid_account_id'") !== false);
$a('AP settings audits ap.settings.updated',   strpos($apSrc, "'ap.settings.updated'") !== false);

echo "\nAP manifest + APModule\n";
$man = (string) file_get_contents(__DIR__ . '/../modules/ap/manifest.php');
$a("manifest declares 'ap.settings.updated'",  strpos($man, "'ap.settings.updated'") !== false);
$a('manifest declares Settings action',        strpos($man, "'route' => 'settings'") !== false);
$apMod = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/APModule.jsx');
$a("APModule routes 'settings'",               strpos($apMod, 'path="settings"') !== false);
$a('APModule navItems Settings',               strpos($apMod, "label: 'Settings'") !== false);

echo "\nAP Settings.jsx\n";
$apUi = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/Settings.jsx');
$a('imports RailPicker',                       strpos($apUi, "from '../../../dashboard/src/components/RailPicker'") !== false);
$a('uses /core/api/payment_rails.php',         strpos($apUi, '/core/api/payment_rails.php') !== false);
$a('renders RailPicker with ap-rail prefix',   strpos($apUi, 'testIdPrefix="ap-rail"') !== false);
$a('NACHA company-id input',                   strpos($apUi, 'ap-settings-nacha-company-id') !== false);
$a('NACHA origin-routing input strips non-digits',
    strpos($apUi, "replace(/\\D/g, '').slice(0, 9)") !== false);
$a('save button',                              strpos($apUi, 'ap-settings-save') !== false);

echo "\nPayrollSettings.jsx — rail picker added\n";
$prUi = (string) file_get_contents(__DIR__ . '/../modules/payroll/ui/PayrollSettings.jsx');
$a('imports RailPicker',                       strpos($prUi, "from '../../../dashboard/src/components/RailPicker'") !== false);
$a('renders payroll-rail picker',              strpos($prUi, 'testIdPrefix="payroll-rail"') !== false);
$a('Disbursement rail fieldset',               stripos($prUi, '<legend>Disbursement rail</legend>') !== false);
$a('NACHA company id input',                   strpos($prUi, 'payroll-settings-nacha-company-id') !== false);
$a('NACHA origin routing input',               strpos($prUi, 'payroll-settings-nacha-origin-routing') !== false);

echo "\nPayroll settings API persists rail fields\n";
$prApi = (string) file_get_contents(__DIR__ . '/../modules/payroll/api/settings.php');
$a('payroll settings allows disbursement_rail',     strpos($prApi, "'disbursement_rail'") !== false);
$a('payroll settings allows nacha_company_id',      strpos($prApi, "'nacha_company_id'") !== false);
$a('payroll settings allows nacha_origin_routing',  strpos($prApi, "'nacha_origin_routing'") !== false);

echo "\nRailPicker.jsx — UI surface\n";
$rp  = (string) file_get_contents(__DIR__ . '/../dashboard/src/components/RailPicker.jsx');
$a('renders Configured pill',                  strpos($rp, "Configured</Pill>") !== false);
$a('renders Selected pill',                    strpos($rp, "Selected</Pill>") !== false);
$a('renders cost badge testid',                strpos($rp, '${testIdPrefix}-cost-${rail.id}') !== false);
$a('renders settlement badge testid',          strpos($rp, '${testIdPrefix}-settlement-${rail.id}') !== false);
$a('renders same-day-ach badge',               strpos($rp, '${testIdPrefix}-sda-${rail.id}') !== false);
$a('renders RTP badge',                        strpos($rp, '${testIdPrefix}-rtp-${rail.id}') !== false);
$a('renders pre-approval badge',               strpos($rp, '${testIdPrefix}-pre-approval-${rail.id}') !== false);
$a('renders funding-link badge',               strpos($rp, '${testIdPrefix}-funding-${rail.id}') !== false);
$a('renders fallback chain',                   strpos($rp, '${testIdPrefix}-fallback-${rail.id}') !== false &&
                                                stripos($rp, 'falls back to') !== false);
$a('renders pros / cons lists',
    strpos($rp, '${testIdPrefix}-pros-${rail.id}') !== false &&
    strpos($rp, '${testIdPrefix}-cons-${rail.id}') !== false);
$a('default export is RailPicker',             strpos($rp, 'export default function RailPicker') !== false);
$a('formatCost handles zero-fee case',         strpos($rp, "'No per-transfer fee'") !== false);

echo PHP_EOL . "Total: $pass passed, $fail failed" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
