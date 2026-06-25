<?php
/**
 * Smoke — CSV import integer validator + person-lookup correctness
 * fixes (the "P-114 not an integer + email not found" double-error bug).
 *
 * Reproduces the operator-visible report from the screenshot:
 *   1. Validator rejected "P-114" because the strict regex demanded
 *      digits-only — but the UI displays the ID as "P-114" with a
 *      click-to-copy that writes the bare integer. Operators paste
 *      what they SEE, not what they CLICK.
 *   2. Even with a valid email, the lookup missed because hidden
 *      whitespace lived on the *DB side* (a prior import wrote NBSP /
 *      BOM bytes into email_primary). We were only defending the CSV
 *      side.
 *   3. When both id and email failed, the row showed TWO errors saying
 *      the same thing — operators had to read both before realising.
 *
 * Locks in the fixes:
 *   - integer type strips `^[A-Za-z]+-` prefix + commas + whitespace
 *     before validating digits-only
 *   - placements dry_run/commit normalise BOTH sides of the email
 *     equality (TRIM stored email_primary in the SQL)
 *   - validator-rejected person_id rows suppress the redundant email-
 *     miss error
 *   - email-miss error with no fuzzy suggestions points at the
 *     People importer explicitly
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
require_once $ROOT . '/core/CsvImportService.php';
require_once $ROOT . '/modules/placements/lib/csv_helpers.php';

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$svcPath    = $ROOT . '/modules/placements/api/csv_import.php';
$csvSvcPath = $ROOT . '/core/CsvImportService.php';
$svc        = (string) file_get_contents($svcPath);
$csvSvc     = (string) file_get_contents($csvSvcPath);

echo "\n1. Integer validator — accepts both bare and prefixed forms\n";
Core\CsvImportService::registerSchema('placements', [
    'fields' => [
        'person_id'       => ['label' => 'Person ID',       'type' => 'integer'],
        'title'           => ['label' => 'Title',           'required' => true],
        'engagement_type' => ['label' => 'Engagement type', 'required' => true,
                              'enum' => ['w2','1099','c2c','temp_to_perm','direct_hire']],
        'start_date'      => ['label' => 'Start date',      'required' => true, 'type' => 'date'],
    ],
]);

$csv = "person_id,title,engagement_type,start_date\n"
     . "P-1042,Bare prefix,1099,2026-05-01\n"
     . "PL-2317,Two-letter prefix,1099,2026-05-01\n"
     . "1042,Plain digits,1099,2026-05-01\n"
     . " 1042 ,Whitespace,1099,2026-05-01\n"
     . "\"1,042\",Quoted comma,1099,2026-05-01\n"
     . "P-foo,Garbage after prefix,1099,2026-05-01\n"
     . "1042.5,Decimal,1099,2026-05-01\n"
     . "abc,No digits,1099,2026-05-01\n";
$result = Core\CsvImportService::dryRun('placements', $csv, [
    'person_id' => 'person_id', 'title' => 'title',
    'engagement_type' => 'engagement_type', 'start_date' => 'start_date',
]);

$a('row 2 P-1042 parses to int 1042',
    isset($result['rows'][2]) && $result['rows'][2]['person_id'] === 1042
    && !isset($result['errors'][2]));
$a('row 3 PL-2317 parses to int 2317',
    isset($result['rows'][3]) && $result['rows'][3]['person_id'] === 2317
    && !isset($result['errors'][3]));
$a('row 4 plain 1042 still works (backwards-compat)',
    isset($result['rows'][4]) && $result['rows'][4]['person_id'] === 1042
    && !isset($result['errors'][4]));
$a('row 5 surrounding whitespace is stripped',
    isset($result['rows'][5]) && $result['rows'][5]['person_id'] === 1042
    && !isset($result['errors'][5]));
$a('row 6 "1,042" (quoted) is accepted, comma stripped',
    isset($result['rows'][6]) && $result['rows'][6]['person_id'] === 1042
    && !isset($result['errors'][6]));
$a('row 7 "P-foo" still rejected (garbage after prefix)',
    isset($result['errors'][7])
    && str_contains(implode(' ', $result['errors'][7]), 'not an integer')
    && str_contains(implode(' ', $result['errors'][7]), 'P-foo'));
$a('row 8 decimal "1042.5" rejected (no silent truncation)',
    isset($result['errors'][8])
    && str_contains(implode(' ', $result['errors'][8]), 'not an integer'));
$a('row 9 "abc" rejected',
    isset($result['errors'][9])
    && str_contains(implode(' ', $result['errors'][9]), 'not an integer'));
$a("error hint mentions both accepted forms",
    isset($result['errors'][7])
    && str_contains(implode(' ', $result['errors'][7]), '1042 or P-1042'));

echo "\n2. Source-level wiring — integer validator + DB-side defense\n";
$a('CsvImportService strips alphabetic prefix before digit-check',
    str_contains($csvSvc, "preg_replace(\n                        ['/^\\s+|\\s+\$/u', '/^[A-Za-z]+-/', '/,/']"));
$a('CsvImportService coerces stripped value to int on success',
    str_contains($csvSvc, '$row[$field] = (int) $stripped;'));
$a('CsvImportService error hint mentions both accepted forms',
    str_contains($csvSvc, '(accepted: 1042 or P-1042)'));

$a('Placements dry_run uses TRIM on stored email_primary',
    str_contains($svc, 'LOWER(TRIM(email_primary)) AS e')
    && str_contains($svc, 'LOWER(TRIM(email_primary)) IN'));
$a('Placements commit uses TRIM on stored email_primary',
    str_contains($svc, 'LOWER(TRIM(email_primary)) = :email'));
$a('Placements dry_run DB-side defense has explanatory comment',
    str_contains($svc, 'stored DB value may'));

echo "\n3. Dry-run handler — no double-error on invalid id row\n";
$a('detects pidInvalid (column present, not coerced to int)',
    str_contains($svc, '$pidInvalid = $hasPidCol && !is_int($row[\'person_id\']);'));
$a('skips email-miss reporting when pidInvalid',
    (bool) preg_match('/if\s*\(\$pidInvalid\)\s*\{\s*continue;\s*\}/', $svc));
$a('uses array_key_exists/is_int for pid detection',
    str_contains($svc, "array_key_exists('person_id', \$row)")
    && str_contains($svc, "is_int(\$row['person_id'])"));

echo "\n4. Dry-run handler — actionable error copy\n";
$a('person_id miss copy points at the People detail URL',
    str_contains($svc, 'open /modules/people/{$pid} to verify'));
$a('person_id miss copy mentions clicking the P-badge',
    str_contains($svc, 'click the P-badge on a real row to copy the right id'));
$a('email-miss WITHOUT suggestion points at the People importer',
    str_contains($svc, 'Import the person first via /modules/people/import'));

echo "\n5. Commit handler — same precedence + DB-side defense\n";
$a('commit re-uses hasPidCol/is_int detection',
    str_contains($svc, "\$hasPidCol  = array_key_exists('person_id', \$row)"));
$a('commit raises a specific error when person_id was rejected at dry-run',
    str_contains($svc, "throw new \\RuntimeException(\n                \"person_id: not an integer '"));
$a('commit also uses TRIM on stored email',
    substr_count($svc, 'LOWER(TRIM(email_primary)) = :email') >= 1);

echo "\n6. PHP syntax\n";
foreach ([
    $csvSvcPath,
    $svcPath,
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $a('php -l ' . str_replace($ROOT . '/', '', $f), $rc === 0, implode("\n", $out));
}

echo "\n=========================================\n";
echo "Placements CSV id-validator + lookup fix smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
