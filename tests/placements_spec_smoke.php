<?php
/**
 * Placements Phase A smoke test — static contract validation
 * (manifest, migration tables/enums, API parse, UI files, lib contract).
 * DB-level verification happens on Cloudways after deploy.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/ModuleRegistry.php';
require_once __DIR__ . '/../modules/placements/lib/placements.php';

$pass = 0; $fail = 0;
$assert = function (string $name, $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ {$name}\n"; $pass++; }
    else       { echo "  ✗ {$name}\n"; $fail++; }
};

echo "Manifest\n";
$reg = ModuleRegistry::reset(__DIR__ . '/../modules');
$pl = $reg->getModule('placements');
$assert('module registered',                        $pl !== null);
$assert('depends_on people',                        in_array('people', $pl['depends_on'] ?? [], true));
$actionRoutes = array_column($pl['actions'] ?? [], 'route');
$assert('action route: custom_fields',              in_array('custom_fields', $actionRoutes, true));

$expectedPerms = [
    'placements.view','placements.manage','placements.financials.view',
    'placements.financials.manage','placements.financials.approve',
    'placements.commissions.view','placements.commissions.manage',
    'placements.referrals.manage','placements.docs.view','placements.docs.manage',
    'placements.terminate','placements.corp.view','placements.corp.manage',
    'placements.custom_fields.manage',
];
foreach ($expectedPerms as $p) {
    $assert("permission: {$p}", in_array($p, array_keys($pl['permissions'] ?? []), true));
}

$expectedEvents = [
    'placement.created','placement.updated','placement.status_changed','placement.ended',
    'placement.chain.updated','placement.rate.drafted','placement.rate.approved',
    'placement.rate.superseded','placement.commission.added','placement.commission.updated',
    'placement.commission.removed','placement.referral.added','placement.referral.updated',
    'placement.financials.viewed','placement.corp.viewed','placement.corp.updated',
    'placement.document.uploaded','placement.document.deleted','placement.approval_contact.updated',
    'placement.csv_imported',
];
foreach ($expectedEvents as $ev) {
    $assert("audit event: {$ev}", in_array($ev, $pl['audit_events'] ?? [], true));
}

echo "\nMigration SQL\n";
$sql = (string) file_get_contents(__DIR__ . '/../modules/placements/migrations/001_init.sql');
$assert('migration file exists',                    strlen($sql) > 0);
$assert('uses utf8mb4_unicode_ci (Cloudways compat)', strpos($sql, 'utf8mb4_unicode_ci') !== false);
$assert('does NOT use utf8mb4_0900_ai_ci',          strpos($sql, 'utf8mb4_0900_ai_ci') === false);
$expectedTables = [
    'tenant_vendor_portals','tenant_end_clients','placements','placement_client_chain',
    'placement_rates','placement_commissions','placement_referrals','placement_corp_details',
    'placement_documents',
];
foreach ($expectedTables as $t) {
    $assert("CREATE TABLE: {$t}",   strpos($sql, "CREATE TABLE IF NOT EXISTS {$t}") !== false);
}
$assert('status ENUM 6 values',
    strpos($sql, "ENUM('draft','pending_start','active','on_hold','ended','cancelled')") !== false);
$assert('engagement_type ENUM 5 values',
    strpos($sql, "ENUM('w2','1099','c2c','temp_to_perm','direct_hire')") !== false);
$assert('chain party_role ENUM 5 values',
    strpos($sql, "ENUM('end_client','msp','prime_vendor','sub_vendor','direct')") !== false);

echo "\nAPI files exist + parse\n";
$apiFiles = ['placements.php','chain.php','rates.php','commissions.php','referrals.php',
             'corp.php','documents.php','approval_contact.php','reports.php','csv_import.php'];
foreach ($apiFiles as $f) {
    $path = __DIR__ . "/../modules/placements/api/{$f}";
    $assert("api/{$f} exists", is_file($path));
    if (is_file($path)) {
        $output = []; $rc = 0;
        @exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $rc);
        $assert("api/{$f} parses",  $rc === 0);
    }
}

echo "\nUI components exist\n";
$uiFiles = ['PlacementsModule.jsx','List.jsx','Expiring.jsx','PlacementCreate.jsx',
            'PlacementDetail.jsx','Reports.jsx','CsvImport.jsx','CustomFields.jsx'];
foreach ($uiFiles as $f) $assert("ui/{$f}",  is_file(__DIR__ . "/../modules/placements/ui/{$f}"));

echo "\nLib contract + Margin formula (SPEC §4)\n";
$libFns = ['placementsSafeFields','placementGet','placementsList','placementChain',
           'placementRates','placementCurrentRate','placementCommissions',
           'placementReferrals','placementDocuments','placementsComputeMargin',
           'placementsAudit'];
foreach ($libFns as $fn) $assert("lib fn: {$fn}",  function_exists($fn));

// Margin formula determinism (SPEC §4 example)
$rate = ['bill_rate' => 100.00, 'pay_rate' => 60.00];
// 2-tier chain: end_client (no fee) + MSP at 2%
$chain = [
    ['portal_fee_pct' => null,    'portal_fee_flat' => null],
    ['portal_fee_pct' => 0.0200,  'portal_fee_flat' => null],
];
$m = placementsComputeMargin($rate, $chain);
$assert('total_portal_fee_pct = 0.02',                         abs($m['total_portal_fee_pct']  - 0.02) < 1e-6);
$assert('adjusted_bill_rate = 100 * 0.98 = 98.00',             abs($m['adjusted_bill_rate']    - 98.00) < 1e-4);
$assert('net_to_vendor = 98 - 60 = 38.00',                     abs($m['net_to_vendor']         - 38.00) < 1e-4);

// Additive stacking (SPEC §6 decision: portal fees stack additively)
$chain2 = [['portal_fee_pct' => 0.03], ['portal_fee_pct' => 0.02], ['portal_fee_pct' => 0.01]];
$m2 = placementsComputeMargin(['bill_rate' => 100, 'pay_rate' => 50], $chain2);
$assert('additive stacking: 6% total',                         abs($m2['total_portal_fee_pct'] - 0.06) < 1e-6);
$assert('adjusted = 100 * 0.94 = 94.00',                       abs($m2['adjusted_bill_rate']   - 94.00) < 1e-4);
$assert('net = 94 - 50 = 44.00',                               abs($m2['net_to_vendor']        - 44.00) < 1e-4);

echo "\nLegacy preserved\n";
$leg = glob(__DIR__ . '/../legacy/placements_pre_spec_*');
$assert('legacy copy exists',                                  is_array($leg) && count($leg) >= 1);

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
