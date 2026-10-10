<?php
/** Contract checks for honest CSV source-evidence status. */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

$api = $read('api/evidence_upload_url.php');
$a('CSV import run is an allowed evidence subject', str_contains($api, "'csv_import_run'"));
$a('CSV source has a storage namespace', str_contains($api, "'csv_import_run'          => 'csv_imports'"));
$helper = $read('dashboard/src/lib/csvAuditAttach.js');
$a('shared source-attachment helper exists', str_contains($helper, 'export async function attachCsvToImportRun'));
$a('helper requests an upload destination', str_contains($helper, '/api/evidence_upload_url.php'));
$a('helper accepts both local and S3 destinations', str_contains($helper,
    'presign.upload?.form_action || presign.upload?.url'));
$a('helper refuses a missing destination', str_contains($helper, 'Storage did not provide an upload destination'));
$a('helper uploads bytes before registering metadata', strpos($helper, 'await fetch(uploadUrl')
    < strpos($helper, "api.post('/api/accounting/evidence.php'"));
$a('helper distinguishes CSV from mapping evidence', str_contains($helper, "documentType:  'csv_source'")
    && str_contains($helper, "documentType:  'column_map'"));
$a('helper reports failed CSV upload', str_contains($helper, '[csv-audit-attach:csv]'));

$audit = $read('dashboard/src/lib/csvImportAudit.js');
$a('finalizer attempts source attachment', str_contains($audit, 'await attachCsvToImportRun({'));
$a('finalizer reports attachment outcome', str_contains($audit, 'sourceAttached: !!attachment?.csv?.id'));
$single = $read('dashboard/src/components/CsvImportPage.jsx');
$bulk = $read('dashboard/src/pages/CsvBulkImport.jsx');
$a('single-file screen warns on source failure', str_contains($single, 'audit-source-missing')
    && str_contains($single, 'Keep your original file'));
$a('bulk screen warns on source failure', str_contains($bulk, 'source-missing')
    && str_contains($bulk, 'keep the original file'));
$history = $read('dashboard/src/pages/CsvImportHistory.jsx');
$a('history retains CSV evidence download', str_contains($history, 'DownloadOriginalCsv')
    && str_contains($history, 'download-original'));
$a('history retains mapping evidence download', str_contains($history, 'DownloadColumnMap')
    && str_contains($history, 'download-mapping'));
$a('history download avoids an async popup', str_contains($history, 'window.location.assign(r.signed_url)')
    && !str_contains($history, "window.open(r.signed_url"));
$evidence = $read('api/accounting/evidence.php');
$a('download links force the evidence filename', str_contains($evidence, "'filename_for_download'"));

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
