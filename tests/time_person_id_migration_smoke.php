<?php
/**
 * Smoke: Time module person_id schema drift fix.
 *
 * The previous migration 007 was effectively idempotent only because the
 * runner caught "Duplicate column name". Tenants whose `time_entries` was
 * created AFTER the migration was first recorded never had `person_id`
 * added and stayed broken on the My Time page.
 *
 * Fix: migration rewritten using information_schema guards so the SQL
 * itself is idempotent, and the file's hash change forces every tenant
 * to re-execute it on the next /api/* request.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

$mig = $read(__DIR__ . '/../modules/time/migrations/007_backfill_person_id.sql');

echo "Migration 007 — information_schema guards\n";
$a('uses information_schema.columns to detect person_id',  str_contains($mig, "FROM information_schema.columns") && str_contains($mig, "column_name  = 'person_id'"));
$a('checks time_entries table exists before ADD COLUMN',   str_contains($mig, "table_name = 'time_entries'") && str_contains($mig, '@table_exists'));
$a('ADD COLUMN guarded by both table+col checks',          str_contains($mig, '@table_exists = 1 AND @col_exists = 0'));
$a('uses PREPARE/EXECUTE for conditional DDL',             substr_count($mig, 'PREPARE stmt FROM @sql') >= 3);
$a('checks placements.worker_id before backfill UPDATE',   str_contains($mig, "column_name  = 'worker_id'"));
$a('backfill UPDATE wrapped in conditional',               str_contains($mig, '@worker_id_exists = 1'));
$a('index creation guarded by information_schema',         str_contains($mig, "FROM information_schema.statistics") && str_contains($mig, "index_name   = 'idx_te_tenant_person_date'"));
$a('skip-note SELECT used as no-op fallback',              substr_count($mig, 'already exists') >= 2);
$a('no raw ALTER TABLE that could throw',                  preg_match('/^\s*ALTER TABLE/m', $mig) === 0);

echo "\napi_bootstrap.php — friendly unknown-column message\n";
$bs = $read(__DIR__ . '/../core/api_bootstrap.php');
$a('message tells user to reload page',                    str_contains($bs, 'Try reloading the page'));
$a('mentions self-heal via migrations-on-every-request',   str_contains($bs, 'self-heals on the next click'));
$a('hint points to /admin/healthcheck',                    str_contains($bs, '/admin/healthcheck'));

echo "\nHealthcheck — column-level drift detection\n";
$hc = $read(__DIR__ . '/../api/admin_healthcheck.php');
$a('admin_hc_column_exists helper declared',               preg_match('/function\\s+admin_hc_column_exists\\s*\\(/', $hc) === 1);
$a('checks time_entries.person_id',                        str_contains($hc, "['time_entries', 'person_id']"));
$a('checks time_entries.placement_id',                     str_contains($hc, "['time_entries', 'placement_id']"));
$a('distinguishes missing table vs missing column',        str_contains($hc, "'skipped', 'detail' => \"{\$table} table not present on this tenant\"")
                                                           && str_contains($hc, 'exists but missing column'));
$a('uses information_schema.columns for lookup',           str_contains($hc, "FROM information_schema.columns"));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
