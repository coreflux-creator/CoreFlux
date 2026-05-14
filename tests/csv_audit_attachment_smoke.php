<?php
/**
 * tests/csv_audit_attachment_smoke.php
 *
 * Auditor follow-up: every csv_import_history row carries its source CSV
 * bytes as a polymorphic evidence_attachments record so auditors can
 * download the EXACT input that produced a batch of records, not just the
 * metadata about it.
 *
 * Validates:
 *   - csvImportHistoryRecord() returns the inserted id (was void)
 *   - api/admin/csv_import_history.php POST returns {recorded, id}
 *   - api/evidence_upload_url.php allowlist includes 'csv_import_run'
 *   - module bucket map routes csv_import_run → 'csv_imports'
 *   - dashboard/src/lib/csvAuditAttach.js helper exists + contract
 *   - CsvImportPage + CsvBulkImport both auto-attach the CSV after commit
 *   - CsvImportHistory page renders a lazy "Download original CSV" link
 *
 * Lane: ui (CSV chrome). Static contract checks only.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail) {
    if ($ok) { $pass++; echo "  ok    {$name}\n"; }
    else     { $fail++; echo "  FAIL  {$name}\n"; }
};

$ROOT = realpath(__DIR__ . '/..');

// ── Backend: history record now returns id ──────────────────────────
echo "core/csv_import_history.php — returns inserted id\n";
$cih = (string) file_get_contents("{$ROOT}/core/csv_import_history.php");
$a('csv_import_history.php exists',                  is_file("{$ROOT}/core/csv_import_history.php"));
$a('csv_import_history.php parses',
   (int) shell_exec('php -l ' . escapeshellarg("{$ROOT}/core/csv_import_history.php") . ' >/dev/null 2>&1; echo $?') === 0);
$a('csvImportHistoryRecord returns ?int',            str_contains($cih, 'function csvImportHistoryRecord(array $args): ?int'));
$a('returns lastInsertId on success',                str_contains($cih, '(int) $pdo->lastInsertId() ?: null'));
$a('returns null on missing DB',                     str_contains($cih, 'if (!$pdo) return null'));
$a('returns null on exception',                      str_contains($cih, "return null;\n    }"));

echo "\napi/admin/csv_import_history.php — POST returns id\n";
$apiCih = (string) file_get_contents("{$ROOT}/api/admin/csv_import_history.php");
$a('endpoint POST captures returned id',             str_contains($apiCih, '$newId = csvImportHistoryRecord('));
$a('endpoint POST returns id field',                 str_contains($apiCih, "'id' => \$newId"));

// ── Backend: evidence allowlist + bucket map ────────────────────────
echo "\napi/evidence_upload_url.php — csv_import_run wiring\n";
$eup = (string) file_get_contents("{$ROOT}/api/evidence_upload_url.php");
$a('csv_import_run in ALLOWED_SUBJECTS',             str_contains($eup, "'csv_import_run'"));
$a('csv_import_run routes to csv_imports bucket',    str_contains($eup, "'csv_import_run'          => 'csv_imports'"));

// ── Frontend: shared helper ─────────────────────────────────────────
echo "\ndashboard/src/lib/csvAuditAttach.js helper\n";
$helperPath = "{$ROOT}/dashboard/src/lib/csvAuditAttach.js";
$h = (string) file_get_contents($helperPath);
$a('csvAuditAttach.js exists',                       is_file($helperPath));
$a('exports attachCsvToImportRun()',                 str_contains($h, 'export async function attachCsvToImportRun'));
$a('bails when importRunId or csvText missing',      str_contains($h, 'if (!importRunId || !csvText) return null'));
$a('POSTs /api/evidence_upload_url.php with subject_type=csv_import_run',
    str_contains($h, "subject_type: 'csv_import_run'"));
$a('uses content_type text/csv',                     str_contains($h, "content_type: 'text/csv'"));
$a('creates Blob from csv text',                     str_contains($h, "new Blob([csvText], { type: 'text/csv' })"));
$a('handles LocalDriver path (no upload.url)',       str_contains($h, 'if (presign.upload?.url)'));
$a('registers metadata document_type=csv_source',    str_contains($h, "document_type: 'csv_source'"));
$a('uses source=csv_import_auto_attach',             str_contains($h, "source:        'csv_import_auto_attach'"));
$a('never throws (try/catch around all)',            str_contains($h, 'try {') && str_contains($h, '} catch (e) {'));
$a('returns null on error',                          str_contains($h, '} catch (e) {') && str_contains($h, 'return null;'));

// ── Frontend: CsvImportPage auto-attach ─────────────────────────────
echo "\nCsvImportPage.jsx auto-attaches source CSV\n";
$cip = (string) file_get_contents("{$ROOT}/dashboard/src/components/CsvImportPage.jsx");
$a('CsvImportPage imports attachCsvToImportRun',
    str_contains($cip, "import { attachCsvToImportRun } from '../../../dashboard/src/lib/csvAuditAttach'"));
$a('captures history POST response as `hist`',       str_contains($cip, 'const hist = await api.post('));
$a('calls attachCsvToImportRun when hist.id set',
    preg_match('/if\s*\(\s*hist\?\.id\s*\)\s*\{\s*await attachCsvToImportRun\(/', $cip) === 1);
$a('passes csvText, fileName, entity, columnMap to helper',
    str_contains($cip, 'csvText,') && str_contains($cip, 'fileName,') &&
    str_contains($cip, 'entity: presetEntity') && str_contains($cip, 'columnMap: columnMap || null'));

// ── Frontend: CsvBulkImport auto-attach ─────────────────────────────
echo "\nCsvBulkImport.jsx auto-attaches source CSV\n";
$cbi = (string) file_get_contents("{$ROOT}/dashboard/src/pages/CsvBulkImport.jsx");
$a('CsvBulkImport imports attachCsvToImportRun',
    str_contains($cbi, "import { attachCsvToImportRun } from '../lib/csvAuditAttach'"));
$a('captures history POST response as `hist`',       str_contains($cbi, 'const hist = await api.post(\'/api/admin/csv_import_history.php\''));
$a('calls attachCsvToImportRun for each file',
    preg_match('/if\s*\(\s*hist\?\.id\s*\)\s*\{\s*await attachCsvToImportRun\(/', $cbi) === 1);
$a('passes f.csv, f.fileName, f.entity, f.columnMap to helper',
    str_contains($cbi, 'csvText:     f.csv') && str_contains($cbi, 'fileName:    f.fileName') &&
    str_contains($cbi, 'entity:      f.entity') && str_contains($cbi, 'columnMap:   f.columnMap || null'));

// ── Frontend: CsvImportHistory download link ────────────────────────
echo "\nCsvImportHistory.jsx — Download original CSV + Mapping JSON buttons\n";
$ch = (string) file_get_contents("{$ROOT}/dashboard/src/pages/CsvImportHistory.jsx");
$a('CsvImportHistory imports Download icon',         str_contains($ch, "import { Download } from 'lucide-react'"));
$a('renders <DownloadOriginalCsv> for each row',
    str_contains($ch, '<DownloadOriginalCsv importRunId={r.id} fallbackName={r.file_name}/>'));
$a('renders <DownloadColumnMap> for each row',
    str_contains($ch, '<DownloadColumnMap   importRunId={r.id}/>'));
$a('DownloadOriginalCsv defined',                    str_contains($ch, 'function DownloadOriginalCsv({ importRunId, fallbackName })'));
$a('DownloadColumnMap defined',                      str_contains($ch, 'function DownloadColumnMap({ importRunId })'));
$a('shared DownloadEvidenceByType generic',          str_contains($ch, 'function DownloadEvidenceByType('));
$a('CSV button filters document_type=csv_source',    str_contains($ch, 'documentType="csv_source"'));
$a('JSON button filters document_type=column_map',   str_contains($ch, 'documentType="column_map"'));
$a('CSV fallback extension regex',                   str_contains($ch, 'extensionFallback={/\\.csv$/i}'));
$a('JSON fallback extension regex .mapping.json',    str_contains($ch, 'extensionFallback={/\\.mapping\\.json$/i}'));
$a('generic component fetches list on mount',
    str_contains($ch, "/api/accounting/evidence.php?subject_type=csv_import_run&subject_id="));
$a('generic component renders nothing on miss',      str_contains($ch, 'if (loading || !attachment) return null'));
$a('fresh signed_url per click',
    str_contains($ch, '/api/accounting/evidence.php?action=signed_url&id='));
$a('opens in new tab with noopener',                 str_contains($ch, "window.open(r.signed_url, '_blank', 'noopener')"));
$a('CSV button testid',                              str_contains($ch, 'csv-history-row-${importRunId}-download-original'));
$a('JSON button testid',                             str_contains($ch, 'csv-history-row-${importRunId}-download-mapping'));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
