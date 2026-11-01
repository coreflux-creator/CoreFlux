<?php
/** Read-only comparison of shipped canonical migrations and their ledger rows. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !in_array(getenv('COREFLUX_ENV'), ['staging', 'coreaccounting'], true)
    || trim((string) getenv('COREFLUX_ACCOUNTING_DB_CONFIG_PATH')) === '') {
    fwrite(STDERR, "Isolated staging/CoreAccounting CLI and a private database config are required.\n");
    exit(2);
}

$databaseArgs = array_values(array_filter(
    $argv,
    static fn(string $arg): bool => str_starts_with($arg, '--database=')
));
$rootArgs = array_values(array_filter(
    $argv,
    static fn(string $arg): bool => str_starts_with($arg, '--root=')
));
$expectedDatabase = count($databaseArgs) === 1
    ? substr($databaseArgs[0], strlen('--database=')) : '';
$requestedRoot = count($rootArgs) === 1
    ? substr($rootArgs[0], strlen('--root=')) : dirname(__DIR__);
$root = realpath($requestedRoot);
if ($expectedDatabase === '' || $root === false
    || !is_file($root . '/core/config.php') || !is_file($root . '/core/migration_hash.php')) {
    fwrite(STDERR, "Use --database=NAME and an installed CoreFlux --root=PATH.\n");
    exit(2);
}

require_once $root . '/core/config.php';
require_once $root . '/core/migration_hash.php';
if (!hash_equals($expectedDatabase, (string) DB_NAME)) {
    fwrite(STDERR, "Configured database does not match --database.\n");
    exit(2);
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $pdo->exec('START TRANSACTION READ ONLY');
    if (!hash_equals($expectedDatabase, (string) $pdo->query('SELECT DATABASE()')->fetchColumn())) {
        throw new RuntimeException('Connected database identity differs from --database.');
    }

    $ledger = [];
    foreach ($pdo->query('SELECT filename, sha256, last_error FROM _migrations') as $row) {
        $ledger[(string) $row['filename']] = $row;
    }
    $files = array_merge(
        glob($root . '/core/migrations/*.sql') ?: [],
        glob($root . '/modules/*/migrations/*.sql') ?: []
    );
    sort($files, SORT_STRING);
    $seen = [];
    $counts = ['shipped' => 0, 'matched' => 0, 'pending' => 0,
        'changed' => 0, 'failed' => 0, 'recorded_not_packaged' => 0];
    $problems = [];
    $normalizedRoot = str_replace('\\', '/', $root);
    foreach ($files as $file) {
        $normalizedFile = str_replace('\\', '/', $file);
        if (preg_match('#/modules/_[^/]+/#', $normalizedFile)) continue;
        $name = str_starts_with($normalizedFile, $normalizedRoot . '/modules/')
            ? ltrim(substr($normalizedFile, strlen($normalizedRoot)), '/') : basename($normalizedFile);
        if (isset($seen[$name])) throw new RuntimeException("Duplicate migration identity: $name");
        $seen[$name] = true;
        $counts['shipped']++;
        $sql = file_get_contents($file);
        if ($sql === false) throw new RuntimeException("Unreadable migration: $name");
        $recorded = $ledger[$name] ?? null;
        if ($recorded === null) {
            $counts['pending']++;
            $problems[] = $name . ': pending';
        } elseif ($recorded['last_error'] !== null) {
            $counts['failed']++;
            $problems[] = $name . ': failed';
        } elseif (!corefluxMigrationHashMatches((string) $recorded['sha256'], $sql)) {
            $counts['changed']++;
            $problems[] = $name . ': changed';
        } else {
            $counts['matched']++;
        }
    }
    foreach ($ledger as $name => $_) {
        if (!isset($seen[$name])) $counts['recorded_not_packaged']++;
    }
    if ($counts['shipped'] === 0) $problems[] = 'No canonical migration files are packaged';
    if ($counts['recorded_not_packaged'] > 0) {
        $problems[] = $counts['recorded_not_packaged'] . ' recorded migrations are not packaged';
    }
    $pdo->rollBack();
    echo json_encode(['database' => $expectedDatabase, 'counts' => $counts,
        'problems' => array_slice($problems, 0, 20)], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit($problems ? 1 : 0);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Migration audit failed: ' . $error->getMessage() . "\n");
    exit(3);
}
