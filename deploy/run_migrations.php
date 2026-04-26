<?php
/**
 * CoreFlux Migration Runner
 *
 * Scans core/migrations/ and modules/<m>/migrations/, tracks which files have
 * already been applied in a coreflux_migrations table, and runs the rest in
 * order. Idempotent: safe to run on every deploy.
 *
 * Usage (on the target host):
 *   php /app/deploy/run_migrations.php                 # apply pending
 *   php /app/deploy/run_migrations.php --status        # list applied + pending
 *   php /app/deploy/run_migrations.php --dry-run       # show what would run
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this from the CLI.\n");
    exit(2);
}

require_once __DIR__ . '/../core/config.php';

$argvs   = $argv;
$status  = in_array('--status', $argvs, true);
$dryRun  = in_array('--dry-run', $argvs, true);

$pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS coreflux_migrations (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    file_path     VARCHAR(255) NOT NULL UNIQUE,
    applied_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    checksum_sha  CHAR(64) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL);

$stmt = $pdo->query('SELECT file_path, checksum_sha FROM coreflux_migrations');
$applied = [];
foreach ($stmt as $r) $applied[$r['file_path']] = $r['checksum_sha'];

$root = dirname(__DIR__);
$paths = array_merge(
    glob($root . '/core/migrations/*.sql')    ?: [],
    glob($root . '/modules/*/migrations/*.sql') ?: []
);
// Exclude template / example module scaffolds (any module dir starting with '_')
$paths = array_values(array_filter($paths, function (string $p) use ($root): bool {
    return !preg_match('#/modules/_[^/]+/#', $p);
}));
sort($paths);

$pending = [];
foreach ($paths as $abs) {
    $rel = ltrim(str_replace($root, '', $abs), '/');
    if (!isset($applied[$rel])) $pending[] = $abs;
}

if ($status) {
    echo "Applied (" . count($applied) . "):\n";
    foreach ($applied as $p => $_) echo "  [ok] $p\n";
    echo "Pending (" . count($pending) . "):\n";
    foreach ($pending as $abs) echo "  [ .] " . ltrim(str_replace($root, '', $abs), '/') . "\n";
    exit(0);
}

if (!$pending) {
    echo "Nothing to do — all migrations applied.\n";
    exit(0);
}

echo "Will apply " . count($pending) . " migration(s):\n";
foreach ($pending as $abs) echo "  - " . ltrim(str_replace($root, '', $abs), '/') . "\n";
if ($dryRun) { echo "(dry-run — no changes made)\n"; exit(0); }

$insert = $pdo->prepare(
    'INSERT INTO coreflux_migrations (file_path, checksum_sha) VALUES (:p, :c)'
);

foreach ($pending as $abs) {
    $rel = ltrim(str_replace($root, '', $abs), '/');
    $sql = file_get_contents($abs);
    if ($sql === false) { fwrite(STDERR, "cannot read $rel\n"); exit(3); }
    echo "Applying $rel … ";
    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        fwrite(STDERR, "\nFAILED on $rel: " . $e->getMessage() . "\n");
        exit(4);
    }
    $insert->execute(['p' => $rel, 'c' => hash('sha256', $sql)]);
    echo "ok\n";
}

echo "\nAll pending migrations applied.\n";
