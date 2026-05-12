<?php
/**
 * Smoke: defensive `time_entries` column repair + expanded self-heal.
 *
 * Pins:
 *   1. Migration 008 adds every canonical column of time_entries via
 *      information_schema-guarded conditional DDL, one statement per line.
 *   2. cf_self_heal_known_column() knows about every column in the canonical
 *      schema so a missing-column error at runtime can be repaired in-flight.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "Migration 008_ensure_columns.sql\n";
$mig = $read(__DIR__ . '/../modules/time/migrations/008_ensure_columns.sql');
$essential = ['placement_id','person_id','period_id','work_date','category','hours','status','source','approved_at','approved_via','rejected_reason','rate_snapshot_id'];
foreach ($essential as $c) {
    $a("guards ADD COLUMN $c via information_schema", str_contains($mig, "column_name = '$c'") && str_contains($mig, "ADD COLUMN $c "));
}
$a('uses DO 0 fallback (no result-set leak)', substr_count($mig, "'DO 0'") >= 12);
$a('PREPARE / EXECUTE / DEALLOCATE on own lines', preg_match('/PREPARE s FROM @sql[^\n]*;[^\n]*EXECUTE/', $mig) === 0);

echo "\nSelf-heal recipe expansion (core/api_bootstrap.php)\n";
$boot = $read(__DIR__ . '/../core/api_bootstrap.php');
foreach (['placement_id','person_id','period_id','work_date','hours','category','status','source','timesheet_id','hour_type','billable','payable'] as $c) {
    $a("recipe knows time_entries.$c",  preg_match("/'$c'\s*=>\s*['\"]ADD COLUMN/", $boot) === 1);
}

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
