<?php
/**
 * CsvImportService smoke test — verifies the platform CSV primitive
 * end-to-end without DB. Modules wire their own onRow callback at runtime.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/CsvImportService.php';

use Core\CsvImportService;

$pass = 0; $fail = 0;
$assert = function (string $name, $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ {$name}\n"; $pass++; }
    else       { echo "  ✗ {$name}\n"; $fail++; }
};

CsvImportService::registerSchema('people_test', [
    'fields' => [
        'first_name'        => ['label' => 'First name',     'required' => true],
        'last_name'         => ['label' => 'Last name',      'required' => true],
        'email_primary'     => ['label' => 'Primary email',  'required' => true, 'type' => 'email'],
        'classification'    => ['label' => 'Classification', 'required' => true,
                                'enum'  => ['w2','1099','candidate']],
        'work_auth_expiry'  => ['label' => 'Work auth expiry','type' => 'date'],
        'requires_sponsor'  => ['label' => 'Requires sponsorship','type' => 'boolean'],
    ],
    'unique_within_batch' => ['email_primary'],
]);

echo "Template generation\n";
$tpl = CsvImportService::buildTemplate('people_test');
$assert("template contains First name header",     strpos($tpl, 'First name') !== false);
$assert("template contains Classification header", strpos($tpl, 'Classification') !== false);
$assert("template ends with newline",              str_ends_with($tpl, "\n"));

echo "\nDry run — happy path\n";
$csv = "First name,Last name,Primary email,Classification,Work auth expiry,Requires sponsorship\n"
     . "Jane,Doe,jane@x.co,w2,2027-01-01,true\n"
     . "Sam,Lee,sam@x.co,candidate,,no\n";
$dry = CsvImportService::dryRun('people_test', $csv);
$assert("row_count=2",                              $dry['row_count'] === 2);
$assert("error_count=0",                            $dry['error_count'] === 0);
$assert("row 2 first_name=Jane",                    ($dry['rows'][2]['first_name'] ?? null) === 'Jane');
$assert("row 2 boolean coerced to 1",               ($dry['rows'][2]['requires_sponsor'] ?? null) === 1);
$assert("row 3 boolean coerced to 0",               ($dry['rows'][3]['requires_sponsor'] ?? null) === 0);

echo "\nValidation — required + enum + email + date\n";
$badCsv = "First name,Last name,Primary email,Classification,Work auth expiry,Requires sponsorship\n"
        . ",Doe,not-an-email,w99,not-a-date,maybe\n";
$bad = CsvImportService::dryRun('people_test', $badCsv);
$assert("invalid row flagged",                      isset($bad['errors'][2]));
$errs = $bad['errors'][2] ?? [];
$assert("flags missing first_name",                 (bool) array_filter($errs, fn($e) => str_contains($e, 'first_name')));
$assert("flags invalid email",                      (bool) array_filter($errs, fn($e) => str_contains($e, 'invalid email')));
$assert("flags invalid enum classification",        (bool) array_filter($errs, fn($e) => str_contains($e, 'classification')));
$assert("flags invalid date",                       (bool) array_filter($errs, fn($e) => str_contains($e, 'work_auth_expiry') || str_contains($e, 'invalid date')));
$assert("flags invalid boolean",                    (bool) array_filter($errs, fn($e) => str_contains($e, 'requires_sponsor') || str_contains($e, 'boolean')));

echo "\nDuplicate within batch\n";
$dup = CsvImportService::dryRun('people_test',
    "First name,Last name,Primary email,Classification\nA,B,same@x.co,w2\nC,D,same@x.co,1099\n");
$assert("dup row 3 flagged",                        isset($dup['errors'][3]));
$assert("dup error mentions row 2",                 (bool) array_filter($dup['errors'][3] ?? [], fn($e) => str_contains($e, 'row 2')));

echo "\nDate coercion (mm/dd/yyyy → ISO)\n";
$d = CsvImportService::dryRun('people_test',
    "First name,Last name,Primary email,Classification,Work auth expiry\nA,B,a@x.co,w2,01/15/2027\n");
$assert("mm/dd/yyyy accepted",                      $d['error_count'] === 0);
$assert("date normalized to ISO",                   ($d['rows'][2]['work_auth_expiry'] ?? null) === '2027-01-15');

echo "\nCommit — calls onRow for valid rows only (skip_invalid=true)\n";
$mixedCsv = "First name,Last name,Primary email,Classification\n"
          . "Jane,Doe,jane@x.co,w2\n"
          . ",Bad,bad,w99\n"
          . "Sam,Lee,sam@x.co,candidate\n";
$called = [];
$res = CsvImportService::commit('people_test', $mixedCsv,
    function (array $row) use (&$called) { $called[] = $row['email_primary']; return rand(1, 9999); },
    ['skip_invalid' => true]
);
$assert("imported_count=2",                         $res['imported_count'] === 2);
$assert("skipped_count >= 1",                       $res['skipped_count'] >= 1);
$assert("onRow called for jane only twice (valid)", count($called) === 2 && in_array('jane@x.co', $called, true));

echo "\nCommit — aborts when errors exist and skip_invalid=false\n";
$res2 = CsvImportService::commit('people_test', $mixedCsv,
    function () { throw new RuntimeException('should not be called'); },
    ['skip_invalid' => false]
);
$assert("imported_count=0 on abort",                $res2['imported_count'] === 0);
$assert("abort message present",                    !empty($res2['message']));

echo "\nUnknown module throws\n";
try {
    CsvImportService::dryRun('does-not-exist', 'a,b\n1,2');
    $assert("unknown module rejected", false);
} catch (\InvalidArgumentException $e) {
    $assert("unknown module rejected", true);
}

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
