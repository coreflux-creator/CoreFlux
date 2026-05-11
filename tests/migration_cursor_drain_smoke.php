<?php
/**
 * Smoke: PDO unbuffered-cursor hardening in migrate.php + DO-0 fallback
 * pattern in all conditional migrations.
 *
 * The user hit:
 *   "SQLSTATE[HY000]: 2014 Cannot execute queries while other unbuffered
 *    queries are active"
 * on every page. Root cause: conditional migrations used `SELECT '...'
 * AS note` as the no-op fallback, which emits a result set that
 * PDO::exec() didn't consume → cursor stayed open → next query died.
 *
 * Two defences pinned here:
 *   1. migrate.php drains every result set via query() + closeCursor() +
 *      nextRowset() loop.
 *   2. Every conditional migration uses `DO 0` (no result set) rather
 *      than `SELECT '...' AS note`.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "core/migrate.php — result-set draining\n";
$src = $read(__DIR__ . '/../core/migrate.php');
$a('uses query() not exec() to allow draining',  str_contains($src, '$rs = $pdo->query($clean)'));
$a('drains via closeCursor()',                   str_contains($src, '$rs->closeCursor()'));
$a('iterates nextRowset() in drain loop',        str_contains($src, '$rs->nextRowset()'));
$a('nextRowset wrapped in try/catch (driver compat)', str_contains($src, 'nextRowset is unsupported on some drivers'));

echo "\nConditional migrations use DO 0 (no result set)\n";
$migs = [
    'modules/time/migrations/007_backfill_person_id.sql',
    'modules/staffing/migrations/002_timesheet_id_on_entries.sql',
    'core/migrations/034_register_staffing_module.sql',
];
foreach ($migs as $rel) {
    $m = $read(__DIR__ . '/../' . $rel);
    $a("{$rel} uses 'DO 0' fallback",            str_contains($m, "'DO 0'"));
    $a("{$rel} no leaky bare SELECT '...' AS note fallback", preg_match("/^\s*'?SELECT\s+'[^']*'\s+AS\s+note'?,?\s*\)?\s*$/mi", $m) === 0 && stripos($m, ", 'SELECT \"") === false);
}

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
