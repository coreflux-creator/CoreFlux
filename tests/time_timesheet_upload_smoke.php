<?php
/**
 * Time — Manual timesheet upload + AI extraction smoke.
 *
 * User can drop a paper sign-in / scanned PDF / phone photo of a timesheet
 * → AI extracts (date, project, hours) rows → user maps each to a
 * placement → entries land as drafts via /api/time/entries.php POST.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $name, $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ {$name}\n"; $pass++; } else { echo "  ✗ {$name}\n"; $fail++; }
};
$lint = function (string $path): bool {
    $rc = 0; @exec('php -l ' . escapeshellarg($path) . ' 2>&1', $_, $rc); return $rc === 0;
};

// ─── Migration 004 ───
echo "Time upload — migration 004 schema\n";
$mig = file_get_contents(__DIR__ . '/../modules/time/migrations/004_uploaded_documents.sql');
$assert('creates time_uploaded_documents',                strpos($mig, 'CREATE TABLE IF NOT EXISTS time_uploaded_documents') !== false);
$assert('extraction_status enum',                          strpos($mig, "ENUM('pending','extracted','failed','consumed')") !== false);
$assert('ai_extracted_json column',                        strpos($mig, 'ai_extracted_json TEXT') !== false);
$assert('ai_confidence column',                            strpos($mig, 'ai_confidence     DECIMAL(4,3)') !== false);
$assert('week_ending_hint column',                         strpos($mig, 'week_ending_hint  DATE') !== false);
$assert('consumed_entry_count column',                     strpos($mig, 'consumed_entry_count') !== false);

// ─── API ───
echo "Time upload — /api/time/upload.php\n";
$api = file_get_contents(__DIR__ . '/../modules/time/api/upload.php');
$assert('?action=upload_url presigned POST',               strpos($api, "action === 'upload_url'") !== false);
$assert('uses Core\\StorageService::getInstance()',        strpos($api, '\\Core\\StorageService::getInstance()') !== false);
$assert('?action=extract calls aiExtract',                 strpos($api, "feature_key' => 'time.timesheet.from_upload'") !== false);
$assert('schema includes work_date/hours/category',        strpos($api, '"work_date":string') !== false && strpos($api, '"hours":number') !== false);
$assert('records doc up-front (audit even on AI fail)',    strpos($api, 'INSERT INTO time_uploaded_documents') !== false);
$assert('updates extraction_status=extracted on success',  strpos($api, '"extracted"') !== false);
$assert('updates extraction_status=failed on error',       strpos($api, '"failed"') !== false);
$assert('?id=N&action=consume marks consumed',             strpos($api, "action === 'consume'") !== false && strpos($api, '"consumed"') !== false);
$assert('GET ?id=N returns document + draft',              strpos($api, "ai_extracted_json'") !== false);
$assert('audit time.upload.extracted',                     strpos($api, 'time.upload.extracted') !== false);
$assert('audit time.upload.extract_failed',                strpos($api, 'time.upload.extract_failed') !== false);
$assert('audit time.upload.consumed',                      strpos($api, 'time.upload.consumed') !== false);
$assert('timeUploadConfidence() helper',                   strpos($api, 'function timeUploadConfidence') !== false);
$assert('PHP parses cleanly',                              $lint(__DIR__ . '/../modules/time/api/upload.php'));

// ─── Manifest ───
echo "Time upload — manifest declarations\n";
$man = file_get_contents(__DIR__ . '/../modules/time/manifest.php');
$assert('action Upload Timesheet route',                   strpos($man, "'route' => 'upload'") !== false);
$assert('audit time.upload.extracted declared',            strpos($man, 'time.upload.extracted') !== false);
$assert('audit time.upload.extract_failed declared',       strpos($man, 'time.upload.extract_failed') !== false);
$assert('audit time.upload.consumed declared',             strpos($man, 'time.upload.consumed') !== false);
$assert('time.entry.create permission declared',           strpos($man, 'time.entry.create') !== false);

// ─── UI ───
echo "Time upload — UI components\n";
$mod = file_get_contents(__DIR__ . '/../modules/time/ui/TimeModule.jsx');
$assert('TimeModule imports TimesheetUpload',              strpos($mod, 'import TimesheetUpload') !== false);
$assert('TimeModule routes /upload',                       strpos($mod, 'path="upload"') !== false);

$ui = file_get_contents(__DIR__ . '/../modules/time/ui/TimesheetUpload.jsx');
$assert('Upload page testid',                              strpos($ui, 'data-testid="time-upload"') !== false);
$assert('File picker testid',                              strpos($ui, 'data-testid="time-upload-file"') !== false);
$assert('Week-ending hint input',                          strpos($ui, 'data-testid="time-upload-week-ending"') !== false);
$assert('Extract button',                                  strpos($ui, 'data-testid="time-upload-extract"') !== false);
$assert('Review table',                                    strpos($ui, 'data-testid="time-upload-lines-table"') !== false);
$assert('Save-all button',                                 strpos($ui, 'data-testid="time-upload-save"') !== false);
$assert('Cancel button',                                   strpos($ui, 'data-testid="time-upload-cancel"') !== false);
$assert('uses uploadFileViaPresignedPost',                 strpos($ui, 'uploadFileViaPresignedPost') !== false);
$assert('posts to /modules/time/api/upload.php?action=extract', strpos($ui, '/modules/time/api/upload.php?action=extract') !== false);
$assert('saves entries via entries.php POST',              strpos($ui, "'/modules/time/api/entries.php'") !== false);
$assert('marks doc consumed on save success',              strpos($ui, "action=consume") !== false);
$assert('placement typeahead from active placements',      strpos($ui, "placements.php?status=active") !== false);
$assert('source: ai_inbox stamp on saved entries',         strpos($ui, "source:       'ai_inbox'") !== false);
$assert('source_ref_id stamps doc id',                     strpos($ui, "source_ref_id: docId") !== false);

$mt = file_get_contents(__DIR__ . '/../modules/time/ui/MyTime.jsx');
$assert('MyTime header link to /upload',                   strpos($mt, 'data-testid="time-my-time-upload-link"') !== false);
$assert('MyTime imports Link',                             strpos($mt, "import { Link }") !== false);

echo "\nPass: {$pass}\nFail: {$fail}\n";
exit($fail === 0 ? 0 : 1);
