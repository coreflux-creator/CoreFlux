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
$assert('?action=extract calls aiExtract',                 strpos($api, "feature_key' => \$mode") !== false || strpos($api, "feature_key' => 'time.timesheet.from_upload") !== false);
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
$assert('Review table renders groups',                     strpos($ui, 'time-upload-review') !== false && strpos($ui, 'GroupCard') !== false);
$assert('Save-all button',                                 strpos($ui, 'data-testid="time-upload-save"') !== false);
$assert('Cancel button',                                   strpos($ui, 'data-testid="time-upload-cancel"') !== false);
$assert('uses uploadFileViaPresignedPost',                 strpos($ui, 'uploadFileViaPresignedPost') !== false);
$assert('posts to /modules/time/api/upload.php?action=extract', strpos($ui, '/modules/time/api/upload.php?action=extract') !== false);
$assert('saves entries via entries.php POST',              strpos($ui, "'/modules/time/api/entries.php'") !== false);
$assert('marks doc consumed on save success',              strpos($ui, "action=consume") !== false);
$assert('placement typeahead from active placements',      strpos($ui, "placements.php?status=active") !== false);
$assert('source: ai_inbox stamp on saved entries',         preg_match("/source:\\s+'ai_inbox'/", $ui) === 1);
$assert('source_ref_id stamps doc id',                     strpos($ui, "source_ref_id: docId") !== false);

// Bulk mode
$assert('UI bulk mode radio',                              strpos($ui, 'data-testid="time-upload-mode-bulk"') !== false);
$assert('UI single mode radio',                            strpos($ui, 'data-testid="time-upload-mode-single"') !== false);
$assert('UI sends mode to extract',                        strpos($ui, 'mode,') !== false);
$assert('UI builds groups from draft.people',              strpos($ui, 'draft.people') !== false);
$assert('UI renders GroupCard component',                  strpos($ui, 'function GroupCard') !== false);
$assert('UI renders PersonPicker component',               strpos($ui, 'function PersonPicker') !== false);
$assert('UI filters placements by selected person',        strpos($ui, 'placementsByPerson[personId]') !== false);
$assert('UI fetches people via /modules/people/api/people.php', strpos($ui, '/modules/people/api/people.php') !== false);

// Backend bulk
$assert('API accepts mode=bulk param',                     strpos($api, "['single', 'bulk']") !== false);
$assert('API uses bulk schema with people array',          strpos($api, '"people":[{"person_name"') !== false);
$assert('API uses bulk feature_key',                       strpos($api, "'time.timesheet.from_upload_bulk'") !== false);
$assert('API resolves people via timeUploadResolvePeople', strpos($api, 'timeUploadResolvePeople') !== false);
$assert('API queries people table for matches',            strpos($api, 'FROM people') !== false && strpos($api, 'first_name') !== false);
$assert('API attaches match_candidates to each person',    strpos($api, "'match_candidates'") !== false);
$assert('API audit includes mode + people_count',          strpos($api, "'mode'") !== false && strpos($api, "'people_count'") !== false);

$mt = file_get_contents(__DIR__ . '/../modules/time/ui/MyTime.jsx');
$assert('MyTime header link to /upload',                   strpos($mt, 'data-testid="time-my-time-upload-link"') !== false);
$assert('MyTime imports Link',                             strpos($mt, "import { Link }") !== false);

echo "\nPass: {$pass}\nFail: {$fail}\n";
exit($fail === 0 ? 0 : 1);
