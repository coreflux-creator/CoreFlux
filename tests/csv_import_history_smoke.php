<?php
/** Source contracts for reserved, recoverable CSV import history. */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

$migration = $read('core/migrations/042_csv_import_history.sql');
$a('history migration creates table', str_contains($migration, 'CREATE TABLE IF NOT EXISTS csv_import_history'));
foreach (['tenant_id', 'entity', 'file_name', 'rows_total', 'rows_imported',
          'rows_skipped', 'errors_count', 'column_map', 'error_summary',
          'created_by_user_id', 'created_at'] as $column) {
    $a("history column $column", str_contains($migration, $column));
}
$a('history indexes tenant and time', str_contains($migration, 'ix_tenant_created'));
$record = $read('core/csv_import_history.php');
$a('recorder returns inserted ID', str_contains($record, 'function csvImportHistoryRecord(array $args): ?int')
    && str_contains($record, '$pdo->lastInsertId()'));
$a('recorder logs write failures', str_contains($record, 'csvImportHistoryRecord failed'));

$api = $read('api/admin/csv_import_history.php');
$a('history API requires authentication', str_contains($api, 'api_require_auth()'));
$a('reserve inserts before source commit', str_contains($api, "\$action === 'reserve'")
    && str_contains($api, 'CSV_IMPORT_UNCONFIRMED_KEY =>'));
$a('reserve refuses missing history write', str_contains($api, 'if (!$newId) api_error('));
$a('finalization locks same actor and tenant', str_contains($api, 'created_by_user_id = :user_id')
    && str_contains($api, 'tenant_id = :tenant_id') && str_contains($api, 'FOR UPDATE'));
$a('finalization replaces unconfirmed marker', str_contains($api, 'error_summary = :errors')
    && str_contains($api, "already_finalized' => true"));
$a('history list exposes needs review', str_contains($api, "\$r['needs_review']"));
$a('history read does not hide general failures', str_contains($api, 'errorInfo[1] ?? 0')
    && str_contains($api, 'Import history could not be loaded'));
$a('finance-only scope remains', str_contains($api, 'coreAccountingCsvEntityAllowed')
    && str_contains($api, 'coreAccountingCsvEntities()'));

$audit = $read('dashboard/src/lib/csvImportAudit.js');
$a('client reserves before commit', str_contains($audit, '?action=reserve'));
$a('client finalizes result only', str_contains($audit, '?action=finalize'));
$a('source archive has a blocking receipt', str_contains($audit, 'export async function archiveCsvImportAudit')
    && str_contains($audit, "if (!attachment?.csv?.id)"));
$a('client checks server receipt', str_contains($audit, 'if (!finalized?.recorded'));
foreach (['file_name:', 'rows_total:', 'rows_imported:', 'rows_skipped:', 'errors:',
          'skip_invalid:', 'update_existing:', 'ai_used:', 'preset_id:',
          'column_map:', 'duration_ms:'] as $field) {
    $a("client captures $field", str_contains($audit, $field));
}
$single = $read('dashboard/src/components/CsvImportPage.jsx');
$bulk = $read('dashboard/src/pages/CsvBulkImport.jsx');
$a('single importer reserves before source commit', strpos($single, 'await reserveCsvImportAudit(')
    < strpos($single, 'await api.post(path, body)'));
$a('single importer archives before source commit', strpos($single, 'await archiveCsvImportAudit(')
    < strpos($single, 'await api.post(path, body)'));
$a('single importer finalizes after source commit', strpos($single, 'await finalizeCsvImportAudit(')
    > strpos($single, 'await api.post(path, body)'));
$a('single importer blocks duplicate commit', str_contains($single, '!!auditRun?.id'));
$a('single importer retries history without import', str_contains($single, 'const retryAudit = async () =>')
    && str_contains($single, 'Retry history only'));
$a('bulk importer reserves before source commit', strpos($bulk, 'await reserveCsvImportAudit(')
    < strpos($bulk, 'await api.post(path, body)'));
$a('bulk importer archives before source commit', strpos($bulk, 'await archiveCsvImportAudit(')
    < strpos($bulk, 'await api.post(path, body)'));
$a('bulk importer finalizes after source commit', strpos($bulk, 'await finalizeCsvImportAudit(')
    > strpos($bulk, 'await api.post(path, body)'));
$a('bulk importer excludes uncertain runs from re-import', str_contains($bulk, '!f.audit?.id'));
$a('bulk importer retries history without import', str_contains($bulk, 'const retryAudit = async (idx) =>')
    && str_contains($bulk, 'Retry history only'));
$a('time importer retains shared component', str_contains($read('modules/time/ui/CsvImport.jsx'),
    'presetEntity="time"'));
$history = $read('dashboard/src/pages/CsvImportHistory.jsx');
$a('history page distinguishes uncertain outcome', str_contains($history, 'csv-history-kpi-needs-review')
    && str_contains($history, 'needsReview={r.needs_review}'));
$a('history page retains source download', str_contains($history, 'DownloadOriginalCsv'));

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
