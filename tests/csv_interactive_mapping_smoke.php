<?php
/**
 * CSV interactive column mapping smoke (2026-02-XX).
 *
 * Validates:
 *   1. Core\CsvImportService::inspect() reports headers + auto_map + schema fields.
 *   2. Core\CsvImportService::dryRun() accepts both header-name-keyed and
 *      index-keyed column_map overrides, and the override takes precedence
 *      over auto-detection.
 *   3. Core\CsvImportService::readRequestColumnMap() exists for endpoints.
 *   4. Every csv_import endpoint exposes ?action=inspect.
 *   5. dry_run and commit handlers forward column_map through to the service.
 *   6. The shared CsvImportPage component renders a mapping table after
 *      file pick and flags missing required fields.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

require_once __DIR__ . '/../core/CsvImportService.php';

echo "Core service\n";
\Core\CsvImportService::registerSchema('__map_test', [
    'fields' => [
        'first_name' => ['label' => 'First name', 'required' => true],
        'last_name'  => ['label' => 'Last name',  'required' => true],
        'email'      => ['label' => 'Primary email', 'type' => 'email'],
    ],
]);

// 1. inspect() reports headers + auto_map + fields
$csv = "FName,LName,Mail,Extra\nAlice,Smith,alice@ex.com,ignored\n";
$ins = \Core\CsvImportService::inspect('__map_test', $csv);
$a('inspect returns headers array',          isset($ins['headers']) && count($ins['headers']) === 4);
$a('inspect returns auto_map',               isset($ins['auto_map']) && count($ins['auto_map']) === 4);
$a('inspect returns fields with metadata',
    isset($ins['fields']) && count($ins['fields']) === 3 &&
    !empty($ins['fields'][0]['key']) && isset($ins['fields'][0]['required']));
$a('inspect auto_map is null for unmatched', $ins['auto_map'][0] === null && $ins['auto_map'][3] === null);

// auto_map should match labels too
$csvLabels = "First name,Last name,Primary email\nA,B,a@b.c\n";
$ins2 = \Core\CsvImportService::inspect('__map_test', $csvLabels);
$a('inspect auto_map matches by label',
    $ins2['auto_map'][0] === 'first_name' &&
    $ins2['auto_map'][1] === 'last_name' &&
    $ins2['auto_map'][2] === 'email');

// 2. dryRun with header-name-keyed column_map (the UI shape)
$mapByHeader = ['FName' => 'first_name', 'LName' => 'last_name', 'Mail' => 'email'];
$dry = \Core\CsvImportService::dryRun('__map_test', $csv, $mapByHeader);
$a('dryRun with header-keyed map parses row', isset($dry['rows'][2]) && $dry['rows'][2]['first_name'] === 'Alice');
$a('dryRun with header-keyed map no errors', empty($dry['errors']));

// 3. dryRun with index-keyed column_map
$mapByIdx = [0 => 'first_name', 1 => 'last_name', 2 => 'email', 3 => null];
$dry2 = \Core\CsvImportService::dryRun('__map_test', $csv, $mapByIdx);
$a('dryRun with index-keyed map parses row', isset($dry2['rows'][2]) && $dry2['rows'][2]['last_name'] === 'Smith');

// 4. Without column_map, weird headers fail validation
$dry3 = \Core\CsvImportService::dryRun('__map_test', $csv);
$a('dryRun without map flags missing required', !empty($dry3['errors'][2]));

// 5. column_map override beats auto-detection (intentional remap)
$csvSwap = "first_name,last_name,email\nAlice,Smith,a@b.c\n";
$dry4 = \Core\CsvImportService::dryRun('__map_test', $csvSwap, ['first_name' => 'last_name', 'last_name' => 'first_name', 'email' => 'email']);
$a('column_map overrides auto-detection',
    $dry4['rows'][2]['last_name'] === 'Alice' &&
    $dry4['rows'][2]['first_name'] === 'Smith');

// 6. Invalid field_keys in column_map are silently ignored
$dry5 = \Core\CsvImportService::dryRun('__map_test', $csv, ['FName' => 'first_name', 'LName' => 'last_name', 'Mail' => 'NOPE', 'Extra' => 'also_nope']);
$a('column_map ignores unknown field_keys',  !isset($dry5['rows'][2]['NOPE']) && !isset($dry5['rows'][2]['also_nope']));

echo "\nService source code\n";
$svc = $read(__DIR__ . '/../core/CsvImportService.php');
$a('CsvImportService::inspect declared',     str_contains($svc, 'public static function inspect'));
$a('dryRun accepts columnMap argument',      str_contains($svc, 'public static function dryRun(string $module, string $rawCsv, ?array $columnMap = null)'));
$a('commit forwards column_map opt',         str_contains($svc, "\$columnMap = \$opts['column_map'] ?? null") && str_contains($svc, 'self::dryRun($module, $rawCsv, $columnMap)'));
$a('resolveHeaderMap supports index-keyed',  str_contains($svc, '$isIndexKeyed'));
$a('resolveHeaderMap supports header-keyed', str_contains($svc, '$headerIndex'));
$a('readRequestColumnMap exists',            str_contains($svc, 'public static function readRequestColumnMap'));

echo "\nEndpoints expose ?action=inspect and forward column_map\n";
$endpoints = [
    'people'           => '/../modules/people/api/csv_import.php',
    'placements'       => '/../modules/placements/api/csv_import.php',
    'time'             => '/../modules/time/api/csv_import.php',
    'ap_vendors'       => '/../modules/ap/api/csv_import.php',
    'staffing_clients' => '/../modules/staffing/api/csv_import.php',
    'ap_bills'         => '/../modules/ap/api/bills_csv_import.php',
    'billing_invoices' => '/../modules/billing/api/csv_import.php',
];
foreach ($endpoints as $name => $rel) {
    $body = $read(__DIR__ . $rel);
    $a("{$name} exposes ?action=inspect",    str_contains($body, "action === 'inspect'") && str_contains($body, 'CsvImportService::inspect'));
    $a("{$name} reads column_map in dry_run", substr_count($body, 'readRequestColumnMap') >= 1);
    $a("{$name} forwards column_map to service",
        str_contains($body, 'column_map') || str_contains($body, 'dryRun(\'' . preg_replace('/^_+/', '', explode('_', $name)[0]) . ''));
}

echo "\nUI mapping table (CsvImportPage)\n";
$cmp = $read(__DIR__ . '/../dashboard/src/components/CsvImportPage.jsx');
$a('UI auto-inspects on file pick',          str_contains($cmp, "?action=inspect"));
$a('UI keeps inspect result in state',       str_contains($cmp, 'setInspectResult'));
$a('UI tracks per-header columnMap',         str_contains($cmp, 'setColumnMap'));
$a('UI seeds map from auto_map',             str_contains($cmp, 'res.auto_map'));
$a('UI sends column_map on dry_run',         str_contains($cmp, 'body.column_map = columnMap') || str_contains($cmp, 'column_map: columnMap'));
$a('UI renders mapping table',               str_contains($cmp, 'mapping-table') && str_contains($cmp, 'Source column'));
$a('UI has skip-column option',              str_contains($cmp, 'skip this column'));
$a('UI flags missing required fields',       str_contains($cmp, 'missing-required'));
$a('UI testids per mapping row',             str_contains($cmp, 'mapping-row-${i}') || str_contains($cmp, 'mapping-row-`'));
$a('UI testids per mapping select',          str_contains($cmp, 'mapping-select-${i}') || str_contains($cmp, 'mapping-select-`'));

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
