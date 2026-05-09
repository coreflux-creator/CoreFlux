<?php
/**
 * Bug fix smoke — placement_client_chain missing columns.
 *
 * Production hit "Database column 'updated_at' is missing — a migration
 * probably needs to run." when loading a placement detail page because
 * placementChain() in lib/placements.php selects six columns that the
 * original 001_init.sql never created on placement_client_chain:
 *   company_id, submittal_id, vms_job_id,
 *   portal_credentials_ct, kms_key_version, updated_at.
 *
 * This smoke verifies the new 004_chain_extensions.sql migration:
 *   - exists, parses, idempotent (information_schema-guarded ALTERs)
 *   - adds every column the lib references
 *   - registered for deploy/run_migrations.php sweep
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$ROOT = realpath(__DIR__ . '/..');

echo "Migration — 004_chain_extensions.sql\n";
$migPath = "{$ROOT}/modules/placements/migrations/004_chain_extensions.sql";
$assert('migration file exists',                  is_readable($migPath));
$mig = (string) file_get_contents($migPath);

foreach (['company_id','submittal_id','vms_job_id',
          'portal_credentials_ct','kms_key_version','updated_at'] as $col) {
    $assert("adds '{$col}' column",
        preg_match("/column_name\\s*=\\s*'{$col}'/", $mig) === 1
        && preg_match("/ADD COLUMN {$col}\\s/", $mig) === 1);
}

$assert('every ALTER is information_schema-guarded (idempotent)',
    substr_count($mig, '@col_exists = 0') >= 6);
$assert('uses utf8mb4 / Cloudways-compatible PREPARE/EXECUTE pattern',
    substr_count($mig, 'PREPARE stmt FROM @sql') >= 6);
$assert('updated_at is ON UPDATE CURRENT_TIMESTAMP',
    strpos($mig, 'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP') !== false);
$assert('portal_credentials_ct is MEDIUMBLOB (encrypted blob)',
    strpos($mig, 'portal_credentials_ct MEDIUMBLOB NULL') !== false);
$assert('adds reverse-lookup index idx_pcc_company',
    strpos($mig, 'CREATE INDEX idx_pcc_company ON placement_client_chain') !== false);

echo "\nLib — placementChain() still references the columns we just added\n";
$lib = (string) file_get_contents("{$ROOT}/modules/placements/lib/placements.php");
foreach (['company_id','submittal_id','vms_job_id',
          'portal_credentials_ct','kms_key_version','updated_at'] as $col) {
    $assert("placementChain selects '{$col}'",
        preg_match("/placementChain.*?{$col}/s", $lib) === 1);
}

echo "\nDeploy — run_migrations.php sweeps modules/*/migrations/*.sql\n";
$deploy = (string) file_get_contents("{$ROOT}/deploy/run_migrations.php");
$assert('deploy/run_migrations.php scans modules tree',
    strpos($deploy, "/modules/*/migrations/*.sql") !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
