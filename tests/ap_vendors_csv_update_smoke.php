<?php
/** Vendor CSV preview and commit must agree on duplicate handling. */
declare(strict_types=1);

$root = dirname(__DIR__);
$vendor = (string) file_get_contents($root . '/modules/ap/api/csv_import.php');
$export = (string) file_get_contents($root . '/modules/ap/api/csv_export.php');
$datasets = (string) file_get_contents($root . '/core/export_datasets.php');
$single = (string) file_get_contents($root . '/dashboard/src/components/CsvImportPage.jsx');
$bulk = (string) file_get_contents($root . '/dashboard/src/pages/CsvBulkImport.jsx');
$checks = [
    'raw export includes the stable vendor ID' => str_contains($export, "'vendor_id'             => 'Vendor ID'")
        && str_contains($datasets, 'SELECT id AS vendor_id, vendor_name, external_id, source_system,'),
    'preview accepts existing vendors only in update mode' => str_contains($vendor, '!$updateExisting && ($vendorId > 0 || $nameId > 0 || $externalMatchId > 0)'),
    'blank vendor IDs remain valid for new rows' => str_contains($vendor, "(\$row['vendor_id'] ?? '') !== '' && \$vendorId <= 0"),
    'preview rejects mismatched identity keys' => str_contains($vendor, '$nameId !== $vendorId')
        && str_contains($vendor, '$externalMatchId !== $vendorId'),
    'commit reads the same update flag' => str_contains($vendor, "\$updateExisting = !empty(\$_GET['update_existing']);"),
    'commit only uses upsert when update mode is enabled' => str_contains($vendor, "if (\$updateExisting) {\n            \$assignments")
        && str_contains($vendor, "\$sql .= ' ON DUPLICATE KEY UPDATE '"),
    'vendor ID updates are tenant-scoped' => str_contains($vendor, 'WHERE tenant_id = :t AND id = :id'),
    'updates preserve fields absent from the CSV' => str_contains($vendor, 'if (!$provided($field)) continue;')
        && str_contains($vendor, 'if ($provided($field)) $assignments[]'),
    'repeated vendor IDs are rejected within one file' => str_contains($vendor, "'unique_within_batch' => ['vendor_id', 'external_id', 'vendor_name']"),
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
