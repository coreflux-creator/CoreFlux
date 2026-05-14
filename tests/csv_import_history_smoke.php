<?php
/**
 * CSV Import History smoke (2026-02-XX) — closes out the CSV epic.
 *
 * Verifies:
 *   1. Migration 042 creates csv_import_history with the expected schema.
 *   2. core/csv_import_history.php helper exists and is non-throwing.
 *   3. /api/admin/csv_import_history endpoint supports GET (list) + POST (record).
 *   4. CsvImportPage.jsx + CsvBulkImport.jsx POST a history row after a
 *      successful commit (so the UI is the chokepoint, not 9 PHP files).
 *   5. New /data/import-history page is routed + linked from the dashboard.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "Migration 042 — csv_import_history\n";
$mig = $read(__DIR__ . '/../core/migrations/042_csv_import_history.sql');
$a('migration file exists',                 $mig !== '');
$a('table csv_import_history',              str_contains($mig, 'CREATE TABLE IF NOT EXISTS csv_import_history'));
foreach ([
    'tenant_id','entity','file_name','bytes_processed',
    'rows_total','rows_imported','rows_skipped','errors_count',
    'skip_invalid','update_existing','ai_used','preset_id',
    'column_map','error_summary','status','duration_ms',
    'created_by_user_id','created_at',
] as $col) {
    $a("column: {$col}",                    str_contains($mig, " {$col} ") || str_contains($mig, "{$col} "));
}
$a('status ENUM success/partial/failed',    str_contains($mig, "ENUM('success','partial','failed')"));
$a('index on (tenant, created_at)',         str_contains($mig, 'ix_tenant_created'));
$a('index on (tenant, entity, created_at)', str_contains($mig, 'ix_tenant_entity_created'));
$a('index on (tenant, status, created_at)', str_contains($mig, 'ix_tenant_status_created'));

echo "\nRecorder helper (core/csv_import_history.php)\n";
$rec = $read(__DIR__ . '/../core/csv_import_history.php');
$a('helper file exists',                    $rec !== '');
$a('exports csvImportHistoryRecord()',      str_contains($rec, 'function csvImportHistoryRecord'));
$a('INSERT into csv_import_history',        str_contains($rec, 'INSERT INTO csv_import_history'));
$a('classifies status (success/partial/failed)',
    str_contains($rec, "\$status = 'success'") && str_contains($rec, "\$status = 'failed'") && str_contains($rec, "\$status = 'partial'"));
$a('never throws (swallow + error_log)',    str_contains($rec, 'csvImportHistoryRecord failed') && str_contains($rec, 'Throwable'));
$a('truncates error_summary to 50 rows',    str_contains($rec, 'array_slice($errors, 0, 50, true)'));

echo "\nEndpoint /api/admin/csv_import_history.php\n";
$ep = $read(__DIR__ . '/../api/admin/csv_import_history.php');
$a('endpoint exists',                       $ep !== '');
$a('requires auth',                         str_contains($ep, 'api_require_auth'));
$a('GET list supports ?entity=',            str_contains($ep, "_GET['entity']"));
$a('GET list supports ?status=',            str_contains($ep, "_GET['status']"));
$a('GET list supports ?from=/?to=',         str_contains($ep, "_GET['from']") && str_contains($ep, "_GET['to']"));
$a('GET joins users + presets',             str_contains($ep, 'LEFT JOIN users') && str_contains($ep, 'LEFT JOIN csv_mapping_presets'));
$a('GET decodes JSON columns',              str_contains($ep, 'json_decode($r[\'column_map\']') && str_contains($ep, 'json_decode($r[\'error_summary\']'));
$a('GET returns migration_pending on err',  str_contains($ep, "'migration_pending' => true"));
$a('POST records via helper',               str_contains($ep, 'csvImportHistoryRecord(['));
$a('POST requires entity',                  str_contains($ep, 'entity is required'));
$a('POST returns recorded:true',            str_contains($ep, "'recorded' => true"));

echo "\nShared CsvImportPage records history after commit\n";
$cmp = $read(__DIR__ . '/../dashboard/src/components/CsvImportPage.jsx');
$a('CsvImportPage posts to history endpoint',
    str_contains($cmp, "api.post('/api/admin/csv_import_history.php'"));
$a('CsvImportPage gates on presetEntity',   preg_match('/if \(presetEntity\)\s*\{\s*try \{\s*await api\.post\(\'\/api\/admin\/csv_import_history\.php\'/', $cmp) === 1);
$a('CsvImportPage forwards file_name',      str_contains($cmp, 'file_name:'));
$a('CsvImportPage forwards rows_imported',  str_contains($cmp, 'rows_imported:'));
$a('CsvImportPage forwards rows_skipped',   str_contains($cmp, 'rows_skipped:'));
$a('CsvImportPage forwards errors',         str_contains($cmp, 'errors:'));
$a('CsvImportPage forwards skip_invalid',   str_contains($cmp, 'skip_invalid:'));
$a('CsvImportPage forwards update_existing', str_contains($cmp, 'update_existing:'));
$a('CsvImportPage forwards ai_used',        str_contains($cmp, 'ai_used:'));
$a('CsvImportPage forwards preset_id',      str_contains($cmp, 'preset_id:'));
$a('CsvImportPage forwards column_map',     str_contains($cmp, 'column_map:'));
$a('CsvImportPage forwards duration_ms',    str_contains($cmp, 'duration_ms:'));
$a('CsvImportPage history POST never breaks UI',
    str_contains($cmp, '/* non-fatal */'));

echo "\nBulk wizard records per-file history\n";
$bulk = $read(__DIR__ . '/../dashboard/src/pages/CsvBulkImport.jsx');
$a('Bulk wizard posts to history endpoint',
    str_contains($bulk, "api.post('/api/admin/csv_import_history.php'"));
$a('Bulk wizard records per successful file',
    preg_match('/next\[idx\] = \{ \.\.\.f, committed: res.*?api\.post\(\'\/api\/admin\/csv_import_history\.php\'/s', $bulk) === 1);
$a('Bulk wizard forwards entity',           str_contains($bulk, 'entity:          f.entity'));
$a('Bulk wizard forwards file_name',        str_contains($bulk, 'file_name:       f.fileName || null'));
$a('Bulk wizard forwards column_map',       str_contains($bulk, 'column_map:      f.columnMap || null'));
$a('Bulk wizard header has Import History link',
    str_contains($bulk, 'data-testid="csv-bulk-history-link"')
    && str_contains($bulk, 'to="/data/import-history"'));
$a('Bulk wizard summary has View History CTA',
    str_contains($bulk, 'data-testid="csv-bulk-summary-view-history"')
    && str_contains($bulk, 'audit trail (who, when, file, rows, errors)'));

echo "\nLegacy Time CSV (one-off, not on shared component) also wired\n";
$time = $read(__DIR__ . '/../modules/time/ui/CsvImport.jsx');
$a('Time CSV header has Bulk Import link',  str_contains($time, 'data-testid="time-csv-bulk-link"') && str_contains($time, 'to="/data/bulk-import"'));
$a('Time CSV header has Import History link', str_contains($time, 'data-testid="time-csv-history-link"') && str_contains($time, 'to="/data/import-history"'));
$a('Time CSV records to history on commit', str_contains($time, "api.post('/api/admin/csv_import_history.php'") && str_contains($time, "entity:          'time'"));
$a('Time CSV history POST non-fatal',       str_contains($time, '/* non-fatal */'));
$a('Time CSV success has View History CTA', str_contains($time, 'data-testid="time-csv-view-history"'));

echo "\nNew CsvImportHistory page + routing\n";
$hp = $read(__DIR__ . '/../dashboard/src/pages/CsvImportHistory.jsx');
$a('CsvImportHistory page exists',          $hp !== '');
$a('page fetches from history endpoint',    str_contains($hp, '/api/admin/csv_import_history.php'));
$a('page filters by entity/status/date',    str_contains($hp, 'csv-history-filter-entity') && str_contains($hp, 'csv-history-filter-status') && str_contains($hp, 'csv-history-filter-from') && str_contains($hp, 'csv-history-filter-to'));
$a('page surfaces migration_pending state', str_contains($hp, 'migration_pending') && str_contains($hp, 'csv-history-migration-pending'));
$a('page renders status pill per row',      str_contains($hp, 'StatusPill') && str_contains($hp, 'csv-history-status-'));
$a('page exposes KPI strip',                str_contains($hp, 'csv-history-kpi-strip'));
$a('page expands row to show map + errs',   str_contains($hp, 'csv-history-row-${r.id}-toggle') || str_contains($hp, 'csv-history-row-`'));

$app = $read(__DIR__ . '/../dashboard/src/App.jsx');
$a('SPA imports CsvImportHistory',          str_contains($app, "import CsvImportHistory from './pages/CsvImportHistory'"));
$a('SPA routes /data/import-history',       str_contains($app, 'path="/data/import-history"') && str_contains($app, '<CsvImportHistory />'));

$dash = $read(__DIR__ . '/../dashboard/src/pages/DashboardOverview.jsx');
$a('Dashboard surfaces CSV history card',   str_contains($dash, 'data-testid="dashboard-csv-import-history"') && str_contains($dash, '/data/import-history'));
$a('Dashboard imports History icon',        str_contains($dash, 'History }') || str_contains($dash, ', History,') || str_contains($dash, 'History,'));

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
outes /data/import-history',       str_contains($app, 'path="/data/import-history"') && str_contains($app, '<CsvImportHistory />'));

$dash = $read(__DIR__ . '/../dashboard/src/pages/DashboardOverview.jsx');
$a('Dashboard surfaces CSV history card',   str_contains($dash, 'data-testid="dashboard-csv-import-history"') && str_contains($dash, '/data/import-history'));
$a('Dashboard imports History icon',        str_contains($dash, 'History }') || str_contains($dash, ', History,') || str_contains($dash, 'History,'));

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
