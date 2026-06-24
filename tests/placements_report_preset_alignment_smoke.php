<?php
declare(strict_types=1);

$root = dirname(__DIR__);

$pass = 0;
$fail = 0;
$assert = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok  {$name}\n"; }
    else     { $fail++; echo "  no  {$name}\n"; }
};

echo "Placements report preset alignment\n";

$api = (string) file_get_contents($root . '/modules/placements/api/reports.php');
$datasets = (string) file_get_contents($root . '/core/export_datasets.php');
$builder = (string) file_get_contents($root . '/core/report_builder.php');
$docs = (string) file_get_contents($root . '/docs/REPORT_BUILDER.md');
$alignment = (string) file_get_contents($root . '/docs/PRODUCT_ARCHITECTURE_ALIGNMENT.md');

$assert('placements reports API parses', _php_lint($root . '/modules/placements/api/reports.php'));
$assert('placements report keeps module permission', str_contains($api, "rbac_legacy_require(\$user, 'placements.view')"));
$assert('placements report loads shared report builder', str_contains($api, "/../../../core/report_builder.php"));
$assert('expiring report resolves shared placement preset', str_contains($api, "placementsPresetDefinition('placements.expiring_soon'"));
$assert('expiring report executes through report builder', str_contains($api, 'reportBuilderRunDefinition($definition, $tenantId)'));
$assert('expiring report audits through report builder', str_contains($api, 'reportBuilderAudit($tenantId') && str_contains($api, "'source' => 'module_preset'"));
$assert('expiring response exposes shared-service source', str_contains($api, "'source' => 'report_builder'"));
$assert('active-by-client resolves shared aggregate preset', str_contains($api, "placementsPresetDefinition('placements.active_by_client'") && str_contains($api, 'placementsActiveClientRowsFromReportBuilder'));
$assert('active-by-client no longer owns aggregate SQL', !str_contains($api, 'GROUP BY end_client_name'));

$assert('placements dataset exposes split person names', str_contains($datasets, "'person_first_name'") && str_contains($datasets, "'person_last_name'"));
$assert('placements dataset exposes normalized expiring date', str_contains($datasets, "'expiring_date'") && str_contains($datasets, 'END AS expiring_date'));
$assert('placements dataset exposes count measure', str_contains($datasets, "'placement_count'") && str_contains($datasets, '1 AS placement_count'));
$assert('report builder has placement expiring preset', str_contains($builder, "'placements.expiring_soon'"));
$assert('report builder has placement active-by-client preset', str_contains($builder, "'placements.active_by_client'"));
$assert('placement preset uses placements dataset', str_contains($builder, "'dataset' => 'placements_directory'"));
$assert('placement preset supports status list filter', str_contains($builder, "'operator' => 'in'") && str_contains($builder, "'pending_start'"));
$assert('report builder supports inclusive date filters', str_contains($builder, "'less_than_or_equal'"));
$assert('report builder supports grouped measure execution', str_contains($builder, 'reportBuilderAggregateRows') && str_contains($builder, 'source_row_count'));

$assert('report builder docs explain module preset adapters', str_contains($docs, 'placements.expiring_soon') && str_contains($docs, 'placements.active_by_client'));
$assert('alignment docs mark aggregate report shared', str_contains($alignment, 'Placement Active by Client now resolves `placements.active_by_client`'));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

function _php_lint(string $path): bool
{
    $output = [];
    $rc = 0;
    @exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $rc);
    return $rc === 0;
}
