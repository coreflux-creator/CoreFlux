<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/core/rbac/legacy_map.php';

$books = (string) file_get_contents($root . '/api/books_health.php');
$dashboardApi = (string) file_get_contents($root . '/api/exec_dashboard.php');
$placementLib = (string) file_get_contents($root . '/modules/placements/lib/placements.php');
$placementUi = (string) file_get_contents($root . '/modules/placements/ui/List.jsx');
$staffingMetrics = (string) file_get_contents($root . '/modules/reports/lib/staffing_metrics.php');
$staffingUi = (string) file_get_contents($root . '/modules/reports/ui/StaffingOverview.jsx');
$snapshotUi = (string) file_get_contents($root . '/modules/reports/ui/ExecutiveSnapshot.jsx');
$executiveUi = (string) file_get_contents($root . '/dashboard/src/pages/ExecutiveDashboard.jsx');

$checks = [
    'books health binds the ready-to-close date parameter' =>
        str_contains($books, "\$readyParams = ['t' => \$tid, 'd' => \$asOf]")
        && str_contains($books, '$readyStmt->execute($readyParams)'),
    'payroll list permissions resolve to read access' =>
        RbacLegacyMap::resolve('payroll.view') === ['payroll', 'read']
        && RbacLegacyMap::resolve('payroll.runs.view') === ['payroll', 'read'],
    'dashboard placement totals use the Placements catalog scope' =>
        str_contains($dashboardApi, "require_once __DIR__ . '/../core/sub_tenants.php'")
        &&
        str_contains($dashboardApi, "effectiveTenantIdForModule('placements', \$tenantId) ?? \$tenantId")
        && !str_contains($dashboardApi, "effectiveTenantIdForModule('staffing'"),
    'dashboard headcount is distinct people on active placements' =>
        str_contains($dashboardApi, 'COUNT(DISTINCT p.person_id) AS c')
        && str_contains($dashboardApi, "p.status = 'active'"),
    'report headcount uses the same active-placement population' =>
        str_contains($staffingMetrics, 'COUNT(DISTINCT person_id) AS c')
        && str_contains($staffingMetrics, "status = 'active'")
        && str_contains($staffingMetrics, "require_once __DIR__ . '/../../../core/sub_tenants.php'")
        && str_contains($staffingMetrics, "effectiveTenantIdForModule('placements', \$tenantId) ?? \$tenantId")
        && substr_count($staffingMetrics, "'t'=>\$placementsTenantId") === 3,
    'placement type summaries cover all filtered rows' =>
        str_contains($placementLib, "'summary' => \$summary")
        && str_contains($placementUi, 'W-2 placements')
        && str_contains($placementUi, 'C2C placements'),
    'report labels distinguish people from placement events' =>
        str_contains($staffingUi, 'Active people')
        && str_contains($staffingUi, 'Placement starts')
        && str_contains($snapshotUi, 'Placement endings')
        && str_contains($executiveUi, 'Net placement change'),
    'executive dashboard does not duplicate placement starts' =>
        str_contains($executiveUi, 'Upcoming starts')
        && !str_contains($executiveUi, 'title="New placements"'),
];

$failures = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS: ' : 'FAIL: ') . $label . PHP_EOL;
    if (!$passed) $failures++;
}

exit($failures === 0 ? 0 : 1);
