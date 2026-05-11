<?php
/**
 * Smoke: Migration runner — failed migrations must NOT be silently marked
 * as applied. The v1 runner blindly REPLACE INTOed `_migrations` after
 * every loop, so a half-failing file was permanently skipped on subsequent
 * runs, leaving silent schema drift. This test pins the v2 behavior:
 * non-safe errors write a `FAIL:` sentinel hash so the next run retries.
 *
 * Also pins the /api/admin/retry_migration endpoint contract.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "core/migrate.php — failed migrations retried, not silently applied\n";
$src = $read(__DIR__ . '/../core/migrate.php');
$a('records FAIL: sentinel hash for non-safe errors',     str_contains($src, "'FAIL:' . substr(hash('sha256'"));
$a('only adds to applied_files when errBlob is null',     str_contains($src, "if (\$errBlob !== null)") && str_contains($src, "applied_files'][] = \$name"));
$a('records retry-pending row instead of skipping save',  str_contains($src, "marked retry (not applied)"));
$a('skip cache uses content hash equality (re-runs on hash change)', str_contains($src, '$prev === $hash'));
$a('safe pattern list still tolerates Duplicate column',  str_contains($src, "'Duplicate column name'"));
$a('safe pattern list still tolerates already exists',    str_contains($src, "'already exists'"));

echo "\n/api/admin/retry_migration.php — admin retry endpoint contract\n";
$ep = $read(__DIR__ . '/../api/admin/retry_migration.php');
$a('endpoint file exists',                                $ep !== '');
$a('requires master_admin role',                          str_contains($ep, "api_require_role(['master_admin'])"));
$a('accepts {file} or {all_failed} body',                 str_contains($ep, "\$body['file']") && str_contains($ep, "\$body['all_failed']"));
$a('deletes ledger row for explicit file',                str_contains($ep, "DELETE FROM _migrations WHERE filename = :f"));
$a('all_failed mode targets FAIL: sentinel rows',         str_contains($ep, "sha256 LIKE 'FAIL:%'"));
$a('force-reruns migrations after clearing',              str_contains($ep, 'coreflux_run_migrations(true)'));
$a('responds with cleared_ledger + migration_status',     str_contains($ep, "'cleared_ledger'") && str_contains($ep, "'migration_status'"));
$a('audit-logged best effort',                            str_contains($ep, "audit_log") || str_contains($ep, "audit_log"));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
