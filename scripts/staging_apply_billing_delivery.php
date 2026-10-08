<?php
/** Apply only the two billing-delivery migrations on the isolated CoreFlux staging app. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--apply') {
    fwrite(STDERR, "Run only on isolated staging with --apply.\n");
    exit(2);
}

require_once __DIR__ . '/../core/migrate.php';
$pdo = getDB();
if (!$pdo
    || realpath(__DIR__ . '/..') !== '/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html'
    || $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'muzqvdvqbx'
    || (int) $pdo->query('SELECT is_simulation FROM tenants WHERE id = 999')->fetchColumn() !== 1) {
    throw new RuntimeException('Refusing a non-staging application, database, or tenant.');
}

$paths = [
    '157_mail_cc_audit.sql' => __DIR__ . '/../core/migrations/157_mail_cc_audit.sql',
    'modules/billing/migrations/020_entity_delivery.sql' => __DIR__ . '/../modules/billing/migrations/020_entity_delivery.sql',
];
$find = $pdo->prepare('SELECT sha256 FROM _migrations WHERE filename = :f');
$save = $pdo->prepare('INSERT INTO _migrations (filename, sha256, applied_at, duration_ms)
    VALUES (:f, :h, NOW(), :ms)');
foreach ($paths as $name => $path) {
    $sql = file_get_contents($path);
    if ($sql === false || $sql === '') throw new RuntimeException("Missing migration {$name}");
    $hash = hash('sha256', $sql);
    $find->execute(['f' => $name]);
    $prior = $find->fetchColumn();
    if ($prior === $hash) {
        echo "Already applied: {$name}\n";
        continue;
    }
    if ($prior !== false) throw new RuntimeException("Existing ledger hash differs for {$name}");
    $started = microtime(true);
    foreach (coreflux_split_sql_statements($sql) as $statement) {
        if (trim($statement) === '') continue;
        $result = $pdo->query($statement);
        if ($result) {
            $result->closeCursor();
            while ($result->nextRowset()) { /* drain prepared no-op results */ }
        }
    }
    $save->execute(['f' => $name, 'h' => $hash,
        'ms' => (int) ((microtime(true) - $started) * 1000)]);
    echo "Applied: {$name}\n";
}
