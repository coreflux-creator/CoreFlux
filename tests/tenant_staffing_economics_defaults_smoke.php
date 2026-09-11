<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
$assert = static function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '  OK ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$migration = file_get_contents($root . '/core/migrations/135_tenant_staffing_economics_defaults.sql')
    . file_get_contents($root . '/core/migrations/136_c2c_overhead_load.sql')
    . file_get_contents($root . '/core/migrations/139_c2c_source_no_waiver.sql');
$helper = file_get_contents($root . '/core/staffing_economics.php');
$api = file_get_contents($root . '/api/staffing_economics_settings.php');
$economics = file_get_contents($root . '/modules/placements/lib/economics.php');
$placementUi = file_get_contents($root . '/modules/placements/ui/PlacementDetail.jsx');
$settingsUi = file_get_contents($root . '/dashboard/src/pages/StaffingEconomicsSettings.jsx');
$settingsIndex = file_get_contents($root . '/dashboard/src/pages/SettingsPage.jsx');
$app = file_get_contents($root . '/dashboard/src/App.jsx');

echo "Tenant staffing economics defaults\n";
$assert('migration stores three tenant W-2 percentages',
    str_contains($migration, 'w2_payroll_load_pct')
    && str_contains($migration, 'w2_workers_comp_pct')
    && str_contains($migration, 'w2_benefits_load_pct'));
$assert('tenant settings API is tenant-scoped and permission-gated',
    str_contains($api, "\$ctx['tenant_id']")
    && str_contains($api, "rbac_legacy_require(\$user, 'tenant.manage')"));
$assert('tenant settings API upserts one policy row per tenant',
    str_contains($api, 'ON DUPLICATE KEY UPDATE')
    && str_contains($api, 'tenant_staffing_economics_defaults'));
$assert('migration and settings keep C2C overhead separate from W-2 costs',
    str_contains($migration, 'c2c_overhead_pct')
    && str_contains($api, "'c2c_overhead_pct'")
    && str_contains($settingsUi, 'staffing-default-c2c-overhead'));
$assert('placement model resolves inherited employer costs',
    str_contains($economics, 'staffingEconomicsResolveW2Costs')
    && str_contains($economics, "'employer_cost_sources'"));
$assert('approved snapshots resolve classification defaults at approval time',
    str_contains($economics, 'placementEconomicsDefaultsForPlacement')
    && str_contains($economics, '$model = placementEconomicsModelForRate($tenantId, $placementId, $rate, $parties, $tenantDefaults, $engagementType)'));
$assert('placement editor explains inheritance and explicit zero override',
    str_contains($placementUi, 'Blank fields inherit tenant defaults')
    && str_contains($placementUi, 'Enter zero to override a default'));
$assert('settings page is routed and discoverable',
    str_contains($settingsUi, 'staffing-economics-form')
    && str_contains($settingsIndex, '/settings/staffing-economics')
    && str_contains($app, 'StaffingEconomicsSettings'));

require_once $root . '/core/staffing_economics.php';
$resolved = staffingEconomicsResolveW2Costs([
    'adder_pct' => null,
    'workers_comp_pct' => 0,
    'benefits_load_pct' => 0.06,
], [
    'payroll_load_pct' => 0.12,
    'workers_comp_pct' => 0.025,
    'benefits_load_pct' => 0.04,
]);
$assert('blank placement value inherits tenant default',
    abs($resolved['rates']['adder_pct'] - 0.12) < 0.000001
    && $resolved['sources']['adder_pct'] === 'tenant_default');
$assert('explicit placement zero overrides tenant default',
    abs($resolved['rates']['workers_comp_pct']) < 0.000001
    && $resolved['sources']['workers_comp_pct'] === 'placement_override');
$assert('nonzero placement value overrides tenant default',
    abs($resolved['rates']['benefits_load_pct'] - 0.06) < 0.000001
    && $resolved['sources']['benefits_load_pct'] === 'placement_override');

$c2cInherited = staffingEconomicsResolveC2COverhead(['c2c_overhead_pct' => null], ['c2c_overhead_pct' => 0.05]);
$c2cWaived = staffingEconomicsResolveC2COverhead(['c2c_overhead_pct' => 0], ['c2c_overhead_pct' => 0.05]);
$c2cSourceWaived = staffingEconomicsResolveC2COverhead(['c2c_overhead_pct' => null], ['c2c_overhead_pct' => 0.05], false);
$c2cSourceOverridden = staffingEconomicsResolveC2COverhead(['c2c_overhead_pct' => 0.03], ['c2c_overhead_pct' => 0.05], false);
$assert('blank C2C load inherits its independent tenant default',
    abs($c2cInherited['rate'] - 0.05) < 0.000001 && $c2cInherited['source'] === 'tenant_default');
$assert('explicit C2C zero is an intentional placement waiver',
    abs($c2cWaived['rate']) < 0.000001 && $c2cWaived['source'] === 'placement_override');
$assert('source C2C No waives the tenant default',
    abs($c2cSourceWaived['rate']) < 0.000001 && $c2cSourceWaived['source'] === 'source_waiver');
$assert('a placement value can override source C2C No',
    abs($c2cSourceOverridden['rate'] - 0.03) < 0.000001 && $c2cSourceOverridden['source'] === 'placement_override');
$assert('migration repairs only unapproved JobDiva drafts',
    str_contains($migration, 'approved_at IS NULL')
    && str_contains($migration, "'$.source_overheads.c2c'")
    && str_contains($migration, "IN ('false', '0', 'no', 'off', 'unchecked')"));

echo "Tenant staffing economics defaults: {$pass} OK / {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
