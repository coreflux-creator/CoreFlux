<?php
/** Exercise the legacy email-primary-key shape on the disposable CI database. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'coreaccounting'
    || getenv('COREFLUX_STANDALONE_DATABASE') !== 'coreaccounting_ci'
    || !in_array('--confirm-disposable-fixture', $argv, true)) {
    fwrite(STDERR, "Disposable CoreAccounting CI database only.\n");
    exit(2);
}
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/migrate.php';

$pdo = getDB();
if (!$pdo || (string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'coreaccounting_ci') {
    throw new RuntimeException('Connected database is not the disposable CI database.');
}
$table = 'password_resets_legacy_fixture';
$exists = $pdo->query("SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = '$table'")->fetchColumn();
if ((int) $exists !== 0) {
    throw new RuntimeException('Legacy fixture already exists; refusing to modify it.');
}
$created = false;
try {
    $pdo->exec("CREATE TABLE `$table` (
        email VARCHAR(255) NOT NULL PRIMARY KEY,
        token VARCHAR(255) NOT NULL,
        created_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $created = true;
    $pdo->exec("INSERT INTO `$table` (email, token, created_at)
        VALUES ('qa@example.invalid', 'old-hash', '2026-01-01 00:00:00')");

    $migration = file_get_contents(__DIR__ . '/../core/migrations/160_password_reset_tokens.sql');
    if ($migration === false) throw new RuntimeException('Reset migration is missing.');
    $migration = str_replace('password_resets', $table, $migration);
    foreach (coreflux_split_sql_statements($migration) as $statement) {
        $pdo->exec($statement);
    }

    $columns = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['id', 'user_id', 'email', 'token_hash', 'expires_at', 'used_at', 'created_at'] as $column) {
        if (!in_array($column, $columns, true)) {
            throw new RuntimeException("Legacy table still lacks $column.");
        }
    }
    $insert = $pdo->prepare("INSERT INTO `$table`
        (user_id, email, token_hash, expires_at, created_at, token)
        VALUES (1, :email, :hash, '2026-12-01 00:00:00', NOW(), :legacy_token)");
    foreach (['new-hash-1', 'new-hash-2'] as $hash) {
        $insert->execute([':email' => 'qa@example.invalid', ':hash' => $hash,
            ':legacy_token' => $hash]);
    }
    $count = (int) $pdo->query("SELECT COUNT(*) FROM `$table`
        WHERE email = 'qa@example.invalid'")->fetchColumn();
    if ($count !== 3) {
        throw new RuntimeException('Repeated requests are still blocked by the legacy email key.');
    }
    echo "Legacy password reset migration preserved old rows and accepted repeated requests.\n";
} finally {
    if ($created) $pdo->exec("DROP TABLE `$table`");
}
