<?php
/**
 * CSV sample pack smoke (2026-02-XX).
 *
 * Verifies:
 *   - core/csv_samples.php exposes one block per primary entity, with
 *     enough rows to be useful (>= 2 each, >= 5 for the root entities).
 *   - Samples are FK-coherent: every placement.person_email exists in
 *     people[], every placement.end_client_name exists in clients[],
 *     every time.placement_external_id exists in placements[], every
 *     bill.vendor_name exists in vendors[], every invoice.client_name
 *     exists in clients[].
 *   - CsvImportService::buildSample() emits a header row + N data rows.
 *   - Every csv_import endpoint exposes `?action=sample`.
 *   - The shared CsvImportPage UI exposes a "Download sample" link.
 *   - The bulk-import wizard surfaces a sample-pack disclosure with one
 *     link per entity.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "Sample library\n";
$samples = require __DIR__ . '/../core/csv_samples.php';
$a('csv_samples.php returns array',          is_array($samples));
foreach (['people','ap_vendors','staffing_clients','placements','time','ap_bills','billing_invoices'] as $entity) {
    $a("samples include {$entity}",          isset($samples[$entity]) && count($samples[$entity]) > 0);
}
$a('people has 5 rows',                      count($samples['people']) >= 5);
$a('vendors has 5 rows',                     count($samples['ap_vendors']) >= 5);
$a('clients has 5 rows',                     count($samples['staffing_clients']) >= 5);
$a('placements has 5 rows',                  count($samples['placements']) >= 5);
$a('time has 5 rows',                        count($samples['time']) >= 5);
$a('ap_bills has at least 2 rows',           count($samples['ap_bills']) >= 2);
$a('invoices has at least 2 rows',           count($samples['billing_invoices']) >= 2);

echo "\nFK coherence\n";
$peopleEmails = array_column($samples['people'], 'email_primary');
$placementPersonOk = true;
foreach ($samples['placements'] as $p) {
    if (!in_array($p['person_email'] ?? '', $peopleEmails, true)) { $placementPersonOk = false; break; }
}
$a('placements.person_email ⊆ people',      $placementPersonOk);

$clientNames = array_column($samples['staffing_clients'], 'name');
$placementClientOk = true;
foreach ($samples['placements'] as $p) {
    if (!in_array($p['end_client_name'] ?? '', $clientNames, true)) { $placementClientOk = false; break; }
}
$a('placements.end_client_name ⊆ clients', $placementClientOk);

$placementExt = array_column($samples['placements'], 'external_id');
$timeOk = true;
foreach ($samples['time'] as $t) {
    if (!in_array($t['placement_external_id'] ?? '', $placementExt, true)) { $timeOk = false; break; }
}
$a('time.placement_external_id ⊆ placements', $timeOk);

$vendorNames = array_column($samples['ap_vendors'], 'vendor_name');
$billOk = true;
foreach ($samples['ap_bills'] as $b) {
    $vn = $b['vendor_name'] ?? '';
    if ($vn === '') continue; // continuation row (no header field)
    if (!in_array($vn, $vendorNames, true)) { $billOk = false; break; }
}
$a('bills.vendor_name ⊆ vendors',           $billOk);

$invoiceOk = true;
foreach ($samples['billing_invoices'] as $inv) {
    $cn = $inv['client_name'] ?? '';
    if ($cn === '') continue;
    if (!in_array($cn, $clientNames, true)) { $invoiceOk = false; break; }
}
$a('invoices.client_name ⊆ clients',        $invoiceOk);

echo "\nCsvImportService::buildSample\n";
require_once __DIR__ . '/../core/CsvImportService.php';
\Core\CsvImportService::registerSchema('__test', [
    'fields' => [
        'a' => ['label' => 'Alpha'],
        'b' => ['label' => 'Beta'],
    ],
]);
$out = \Core\CsvImportService::buildSample('__test', [
    ['a' => 'one', 'b' => 'two'],
    ['a' => 'three'],
]);
$lines = array_values(array_filter(preg_split('/\r?\n/', $out)));
$a('buildSample emits 3 lines (1 hdr + 2)',  count($lines) === 3);
$a('header uses field labels',               $lines[0] === 'Alpha,Beta');
$a('first sample row maps by field key',     $lines[1] === 'one,two');
$a('missing keys serialise to empty cells',  $lines[2] === 'three,');

echo "\n?action=sample wired into endpoints\n";
$paths = [
    'people'           => '/../modules/people/api/csv_import.php',
    'placements'       => '/../modules/placements/api/csv_import.php',
    'time'             => '/../modules/time/api/csv_import.php',
    'ap_vendors'       => '/../modules/ap/api/csv_import.php',
    'staffing_clients' => '/../modules/staffing/api/csv_import.php',
    'ap_bills'         => '/../modules/ap/api/bills_csv_import.php',
    'billing_invoices' => '/../modules/billing/api/csv_import.php',
];
foreach ($paths as $entity => $rel) {
    $body = $read(__DIR__ . $rel);
    $a("{$entity} exposes ?action=sample",   str_contains($body, "action === 'sample'"));
    $a("{$entity} uses buildSample helper",  str_contains($body, "buildSample"));
    $a("{$entity} downloads as _sample.csv", str_contains($body, "_sample.csv"));
}

echo "\nUI surfaces sample downloads\n";
$cmp = $read(__DIR__ . '/../dashboard/src/components/CsvImportPage.jsx');
$a('per-module page has sample link',        str_contains($cmp, '?action=sample') && str_contains($cmp, 'download-sample'));

$bulk = $read(__DIR__ . '/../dashboard/src/pages/CsvBulkImport.jsx');
$a('bulk wizard surfaces sample pack',       str_contains($bulk, 'csv-bulk-sample-pack'));
$a('bulk wizard has per-entity sample link', str_contains($bulk, 'csv-bulk-sample-${k}') || str_contains($bulk, 'csv-bulk-sample-`'));
$a('bulk wizard links to ?action=sample',    str_contains($bulk, '?action=sample'));

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
