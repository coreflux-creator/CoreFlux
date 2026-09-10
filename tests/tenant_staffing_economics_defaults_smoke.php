<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
$assert = static function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '  OK ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$migration = file_get_contents($root . '/core/migrations/135_tenant_staffing_economics_defaults.sql');
$helper = file_get_contents($root . '/core/staffing_economics.php');
$api = file_get_contents($root . '/api/staffing_economics_settings.php');
$economics = file_get_contents($root . '/modules/placements/lib/economics.php');
$placementUi = file_get_contents($root . '/modules/placements/ui/PlacementDetail.jsx');
$settingsUi = file_get_contents($root . '/dashboard/src/pages/StaffingEconomicsSettings.jsx');
$settingsIndex = file_get_contents($root . '/dashboard/src/pages/SettingsPage.jsx');
$app = file_get_contents($root . '/dashboard/src/App.jsx');

echo "Tenant W-2 economics defaults\n";
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
$assert('placement model resolves inherited employer costs',
    str_contains($economics, 'staffingEconomicsResolveW2Costs')
    && str_contains($economics, "'employer_cost_sources'"));
$assert('approved snapshots resolve defaults at approval time',
    str_contains($economics, 'placementEconomicsW2DefaultsForPlacement')
    && str_contains($economics, '$model = placementEconomicsModelForRate($tenantId, $placementId, $rate, $parties, $tenantW2Defaults)'));
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

echo "Tenant W-2 economics defaults: {$pass} OK / {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);

