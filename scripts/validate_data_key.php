<?php
/** Validate the active CoreFlux data key against every stored ciphertext. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/encryption.php';

$probe = 'coreflux-key-validation-' . bin2hex(random_bytes(8));
if (decryptField(encryptField($probe)) !== $probe) {
    fwrite(STDERR, "Data-key validation failed: encryption round trip failed.\n");
    exit(1);
}

$db = getDB();
$columns = $db->query(
    "SELECT TABLE_NAME, COLUMN_NAME
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND DATA_TYPE IN ('binary','varbinary','tinyblob','blob','mediumblob','longblob')
        AND (
            COLUMN_NAME REGEXP '(_ct|_enc|_cipher)$'
            OR COLUMN_NAME IN ('ssn_cipher','routing_cipher','account_cipher')
        )
      ORDER BY TABLE_NAME, COLUMN_NAME"
)->fetchAll(PDO::FETCH_ASSOC);

$tested = 0;
$failures = [];
foreach ($columns as $column) {
    $table = (string) $column['TABLE_NAME'];
    $name = (string) $column['COLUMN_NAME'];
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $name)) continue;
    $rows = $db->query(
        "SELECT `{$name}` AS ciphertext FROM `{$table}` WHERE `{$name}` IS NOT NULL AND OCTET_LENGTH(`{$name}`) > 0"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        try {
            $plain = decryptField((string) $row['ciphertext']);
            if ($plain === null) throw new RuntimeException('unexpected null plaintext');
            $tested++;
        } catch (Throwable $e) {
            $failures[$table . '.' . $name] = ($failures[$table . '.' . $name] ?? 0) + 1;
        }
    }
}

echo 'Data-key validation: ciphertext_rows_tested=' . $tested
    . ' failed_rows=' . array_sum($failures) . PHP_EOL;
if ($failures) {
    echo 'Data-key validation failures: ' . json_encode($failures, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}
echo "Data-key validation passed; plaintext values were not printed.\n";

