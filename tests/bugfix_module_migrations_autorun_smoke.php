<?php
/**
 * Smoke: core/migrate.php auto-applies module migrations.
 *
 * Regression: tenants without going through installer were missing
 * `v_timesheet_day_fin` (modules/reports/migrations/001_init.sql) because
 * `coreflux_run_migrations()` only globbed `core/migrations/*.sql`.
 *
 * This smoke just inspects the migrate.php source to ensure module
 * migrations are now included and the basename collision is avoided
 * via relative-path keying.
 */
declare(strict_types=1);

$src = (string) file_get_contents(__DIR__ . '/../core/migrate.php');
$fail = 0;
$pass = 0;
function _a(string $name, bool $ok): void {
    global $fail, $pass;
    if ($ok) { $pass++; echo "  ok  $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
}

echo "core/migrate.php — module migration coverage\n";
_a('globs modules/*/migrations/*.sql',
    str_contains($src, "modules/*/migrations/*.sql"));
_a('skips underscored module dirs (e.g. _archive)',
    str_contains($src, '/modules/_'));
_a('core files keyed by basename (legacy ledger entries preserved)',
    str_contains($src, 'basename($path)'));
_a('module files keyed by relative path to avoid collisions',
    str_contains($src, 'str_replace($appRoot, ') &&
    str_contains($src, "/modules/"));
_a('reports/001_init.sql (executive snapshot view) exists',
    is_file(__DIR__ . '/../modules/reports/migrations/001_init.sql'));
_a('reports/001_init.sql defines v_timesheet_day_fin',
    str_contains((string) file_get_contents(__DIR__ . '/../modules/reports/migrations/001_init.sql'),
                 'CREATE VIEW v_timesheet_day_fin'));

echo "\n--- " . ($pass + $fail) . " assertions, $fail failed ---\n";
exit($fail ? 1 : 0);
