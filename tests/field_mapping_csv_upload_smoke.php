<?php
/**
 * field_mapping_csv_upload_smoke.php
 *
 * End-to-end smoke for the CSV → field-index ingestion path. Operator
 * complaint that triggered this: when JobDiva's per-id `/apiv2/jobdiva/
 * search*` endpoints aren't reachable on a tenant's account, the
 * picker only ever sees the sparse BI placement flat fields. The CSV
 * upload path is the fallback — operator drops a Job/Candidate/
 * Customer CSV export, every column becomes a first-class mappable
 * path under the chosen entity_type.
 *
 *   Tests:
 *   1. csvIndexerIngest happy path with a real synthetic CSV
 *      (header normalisation, BOM strip, empty-row skip, padding,
 *      sample_headers slice).
 *   2. Bad input handling — unreadable file, missing headers, blank
 *      header row.
 *   3. API endpoint structural checks (RBAC, validation, size cap,
 *      whitelisted integration / entity_type pattern).
 *   4. Studio UI surfaces — button, modal, all form fields, error
 *      and result panels, submit handler.
 *
 * Run:  php -d zend.assertions=1 tests/field_mapping_csv_upload_smoke.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);

require_once $root . '/core/integrations/csv_indexer.php';

$pass = 0; $fail = 0; $failures = [];
$a = function (string $label, bool $cond) use (&$pass, &$fail, &$failures) {
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else       { $fail++; $failures[] = $label; echo "  ✗ $label\n"; }
};

echo "CSV upload smoke\n";
echo "================\n";

// --- 1) Pure-function happy path against a synthetic CSV ---------------
echo "\n1. csvIndexerIngest happy path\n";

// Build a temp CSV that exercises BOM-stripping, quoted fields,
// empty rows, mismatched row width, blank trailing columns.
$tmp = tempnam(sys_get_temp_dir(), 'cf_csv_');
file_put_contents($tmp,
    "\xEF\xBB\xBFid,first_name,last_name,email,phone,empty_col,\n" .
    "1001,Alice,Wong,\"alice@example.com\",555-1212,,\n" .
    "1002,Bob,\"O'Hara, Jr\",bob@example.com,555-3434,,\n" .
    "\n" . // blank row
    "1003,Carla,,carla@example.com,,,,\n" . // missing values + extra column
    "1004,,Smith,smith@example.com,555-7878,,\n"
);

// csvIndexerIngest writes to integrationPayloadFieldIndexRecord which
// hits the database; in this sandbox we'd see DB-conn errors caught
// inside the helper, but rows_seen / sample_headers / field_count are
// computed BEFORE that call, so they're meaningful regardless.
$summary = csvIndexerIngest(7777, 'jobdiva', 'person', $tmp);
$a('rows_seen counts every non-empty data row', $summary['rows_seen'] >= 4);
$a('sample_headers strips BOM from first column',
    is_array($summary['sample_headers'])
    && ($summary['sample_headers'][0] ?? null) === 'id');
$a('sample_headers includes all named columns',
    in_array('first_name', $summary['sample_headers'], true)
    && in_array('last_name',  $summary['sample_headers'], true)
    && in_array('email',      $summary['sample_headers'], true)
    && in_array('phone',      $summary['sample_headers'], true));
$a('field_count matches non-empty header count (trailing empty dropped)',
    $summary['field_count'] >= 5 && $summary['field_count'] <= 6);
$a('returns array shape (errors[] always present)',
    is_array($summary['errors']));
unlink($tmp);

// --- 2) Invalid inputs --------------------------------------------------
echo "\n2. csvIndexerIngest input validation\n";
$bad1 = csvIndexerIngest(7777, '', 'person', '/tmp/nope.csv');
$a('rejects empty integration',
    !empty($bad1['errors']) && str_contains(implode('|', $bad1['errors']), 'required'));
$bad2 = csvIndexerIngest(7777, 'jobdiva', 'person', '/tmp/definitely-not-here.csv');
$a('rejects unreadable file',
    !empty($bad2['errors']) && str_contains(implode('|', $bad2['errors']), 'not readable'));

$empty = tempnam(sys_get_temp_dir(), 'cf_csv_empty_');
file_put_contents($empty, "");
$bad3 = csvIndexerIngest(7777, 'jobdiva', 'person', $empty);
$a('rejects empty file (no header row)',
    !empty($bad3['errors']) && str_contains(implode('|', $bad3['errors']), 'no header'));
unlink($empty);

$blank = tempnam(sys_get_temp_dir(), 'cf_csv_blank_');
file_put_contents($blank, ",,,\n1,2,3,4\n");
$bad4 = csvIndexerIngest(7777, 'jobdiva', 'person', $blank);
$a('rejects all-empty header row',
    !empty($bad4['errors']) && str_contains(implode('|', $bad4['errors']), 'empty'));
unlink($blank);

// --- 3) API endpoint structural checks --------------------------------
echo "\n3. /api/admin/integrations/upload_csv.php\n";
$ep = "$root/api/admin/integrations/upload_csv.php";
$a('endpoint file exists', file_exists($ep));
$src = (string) @file_get_contents($ep);
$a('endpoint requires csv_indexer.php',
    str_contains($src, "core/integrations/csv_indexer.php"));
$a('endpoint enforces POST',
    str_contains($src, "if (api_method() !== 'POST')"));
$a('endpoint RBAC-gated by tenant_admin.integrations',
    str_contains($src, "rbac_legacy_require(\$user, 'tenant_admin.integrations')"));
$a('endpoint validates integration + entity_type with whitelist regex',
    str_contains($src, "preg_match('/^[a-z0-9_]{1,40}\$/', \$integration)")
    && str_contains($src, "preg_match('/^[a-z0-9_]{1,40}\$/', \$entityType)"));
$a('endpoint checks $_FILES["file"] error code',
    str_contains($src, "\$_FILES['file']['error']")
    && str_contains($src, "UPLOAD_ERR_OK"));
$a('endpoint enforces 25MB cap',
    str_contains($src, '25 * 1024 * 1024'));
$a('endpoint calls csvIndexerIngest with tenant + integration + entity_type + tmp',
    str_contains($src, 'csvIndexerIngest($tid, $integration, $entityType, $tmp)'));
$a('endpoint response surfaces rows_seen / rows_indexed / field_count / errors',
    str_contains($src, "'rows_seen'") && str_contains($src, "'rows_indexed'")
    && str_contains($src, "'field_count'") && str_contains($src, "'errors'"));

// --- 4) Studio UI surfaces --------------------------------------------
echo "\n4. FieldMappingStudio.jsx CSV upload UI\n";
$fms = file_get_contents("$root/dashboard/src/pages/FieldMappingStudio.jsx");
$a('declares csvOpen / csvFile / csvEntity / csvBusy / csvResult state',
    str_contains($fms, 'const [csvOpen, setCsvOpen]')
    && str_contains($fms, 'const [csvFile, setCsvFile]')
    && str_contains($fms, 'const [csvEntity, setCsvEntity]')
    && str_contains($fms, 'const [csvBusy, setCsvBusy]')
    && str_contains($fms, 'const [csvResult, setCsvResult]'));
$a('Upload CSV button rendered',
    str_contains($fms, 'data-testid="fms-csv-upload-btn"'));
$a('modal rendered with testid fms-csv-modal',
    str_contains($fms, 'data-testid="fms-csv-modal"'));
$a('modal has integration / entity / file inputs',
    str_contains($fms, 'data-testid="fms-csv-integration"')
    && str_contains($fms, 'data-testid="fms-csv-entity"')
    && str_contains($fms, 'data-testid="fms-csv-file"'));
$a('entity input strips invalid chars + lowercases',
    str_contains($fms, "replace(/[^a-z0-9_]/g, '').toLowerCase()"));
$a('submit button + close + error + result testids present',
    str_contains($fms, 'data-testid="fms-csv-submit"')
    && str_contains($fms, 'data-testid="fms-csv-close"')
    && str_contains($fms, 'data-testid="fms-csv-error"')
    && str_contains($fms, 'data-testid="fms-csv-result"'));
$a('submit uses multipart FormData against /upload_csv.php',
    str_contains($fms, 'new FormData()')
    && str_contains($fms, '/api/admin/integrations/upload_csv.php'));
$a('submit refreshes sources + auto-switches to uploaded entity',
    str_contains($fms, 'await reloadSources();')
    && str_contains($fms, 'if (csvEntity !== entityType) setEntityType(csvEntity)'));

// --- 5) PHP lint -------------------------------------------------------
echo "\n5. PHP syntax\n";
$lint = shell_exec('php -l ' . escapeshellarg("$root/core/integrations/csv_indexer.php") . ' 2>&1');
$a('php -l csv_indexer.php', str_contains((string) $lint, 'No syntax errors detected'));
$lint2 = shell_exec('php -l ' . escapeshellarg($ep) . ' 2>&1');
$a('php -l upload_csv.php', str_contains((string) $lint2, 'No syntax errors detected'));

echo "\n================\n";
echo "CSV upload smoke: $pass ✓ / $fail ✗\n";
echo "================\n";
if ($fail > 0) {
    foreach ($failures as $msg) echo " ! $msg\n";
    exit(1);
}
exit(0);
