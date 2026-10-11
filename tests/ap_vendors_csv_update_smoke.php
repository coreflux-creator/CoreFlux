<?php
/** Vendor CSV preview and commit must agree on duplicate handling. */
declare(strict_types=1);

$root = dirname(__DIR__);
$vendor = (string) file_get_contents($root . '/modules/ap/api/csv_import.php');
$single = (string) file_get_contents($root . '/dashboard/src/components/CsvImportPage.jsx');
$bulk = (string) file_get_contents($root . '/dashboard/src/pages/CsvBulkImport.jsx');
$checks = [
    'preview accepts existing vendors only in update mode' => str_contains($vendor, "\$result['rows'] && empty(\$_GET['update_existing'])"),
    'commit reads the same update flag' => str_contains($vendor, "\$updateExisting = !empty(\$_GET['update_existing']);"),
    'commit only uses upsert when update mode is enabled' => str_contains($vendor, "if (\$updateExisting) {\n            \$sql .= ' ON DUPLICATE KEY UPDATE"),
    'commit respects skip-invalid choice' => str_contains($vendor, "'skip_invalid' => \$skipInvalid"),
    'single-file preview includes update mode' => str_contains($single, "?action=dry_run\${updateMode ? '&update_existing=1' : ''}"),
    'single-file toggle refreshes preview' => str_contains($single, 'dryRun(next);'),
    'bulk vendor import supports update mode' => preg_match('/ap_vendors:\s*\{[^}]*supportsUpdate:\s*true/s', $bulk) === 1,
    'bulk preview includes update mode' => str_contains($bulk, "?action=dry_run\${shouldUpdate ? '&update_existing=1' : ''}"),
];

$failed = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n";
    if (!$ok) $failed++;
}
exit($failed === 0 ? 0 : 1);
