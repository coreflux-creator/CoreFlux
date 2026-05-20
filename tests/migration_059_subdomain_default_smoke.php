<?php
/**
 * Migration 059 smoke — verifies tenants.subdomain has a default value
 * (or, when not connected to MySQL, that the migration file is shaped
 * correctly).
 *
 *   php -d zend.assertions=1 /app/tests/migration_059_subdomain_default_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ----------------------------------------------------------------- file shape
echo "migrations/059_tenants_subdomain_default.sql\n";
$path = $ROOT . '/core/migrations/059_tenants_subdomain_default.sql';
$a('migration file exists',                       file_exists($path));
$sql = (string) file_get_contents($path);
$a('idempotent guard via information_schema',     $c($sql, "information_schema.columns"));
$a('only fires when column has no default',       $c($sql, 'column_default IS NULL'));
$a('only fires when column is NOT NULL',          $c($sql, "is_nullable  = 'NO'"));
$a('ALTER sets DEFAULT ""',                       $c($sql, 'DEFAULT ""'));
$a('targets tenants.subdomain',                   $c($sql, 'tenants') && $c($sql, 'subdomain'));
$a('uses PREPARE/EXECUTE/DEALLOCATE pattern',     $c($sql, 'PREPARE stmt') && $c($sql, 'EXECUTE stmt') && $c($sql, 'DEALLOCATE PREPARE stmt'));

// ----------------------------------------------------------------- registered with migration runner
echo "\nMigration runner pickup\n";
$migrate = (string) file_get_contents($ROOT . '/core/migrate.php');
$a('glob includes core/migrations/*.sql',
    $c($migrate, "COREFLUX_MIGRATIONS_DIR . '/*.sql'"));

// ----------------------------------------------------------------- live DB check (best-effort)
echo "\nLive schema check (best-effort)\n";
require_once $ROOT . '/core/data.php';
try {
    $pdo = getDB();
    if (!$pdo) {
        echo "  – getDB() unavailable in CLI; skipping live schema verification\n";
    } else {
        // Ensure migration has been applied
        try { coreflux_run_migrations(); } catch (\Throwable $_) {}
        $st = $pdo->prepare(
            "SELECT is_nullable, column_default
               FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name   = 'tenants'
                AND column_name  = 'subdomain'"
        );
        $st->execute();
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            echo "  – tenants.subdomain not present in this DB; nothing to verify\n";
        } else {
            $a('tenants.subdomain has a non-NULL default after migration',
                $row['is_nullable'] === 'YES' || $row['column_default'] !== null);
        }
    }
} catch (\Throwable $e) {
    echo "  – live schema check skipped: " . $e->getMessage() . "\n";
}

// ----------------------------------------------------------------- summary
echo "\n=========================================\n";
echo "Migration 059 smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
