<?php
/**
 * Read-only inventory for CoreFlux AES-256-GCM ciphertext columns.
 *
 * Prints table/column names and non-empty row counts only. It never selects,
 * decrypts, hashes, or prints ciphertext values.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../core/db.php';

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

$nonEmpty = [];
$totalRows = 0;
foreach ($columns as $column) {
    $table = (string) $column['TABLE_NAME'];
    $name = (string) $column['COLUMN_NAME'];
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        continue;
    }
    $count = (int) $db->query(
        "SELECT COUNT(*) FROM `{$table}` WHERE `{$name}` IS NOT NULL AND OCTET_LENGTH(`{$name}`) > 0"
    )->fetchColumn();
    if ($count > 0) {
        $nonEmpty[] = ['column' => $table . '.' . $name, 'rows' => $count];
        $totalRows += $count;
    }
}

echo 'Encryption inventory: non_empty_columns=' . count($nonEmpty)
    . ' ciphertext_rows=' . $totalRows . PHP_EOL;
echo 'Encryption inventory detail: '
    . json_encode($nonEmpty, JSON_UNESCAPED_SLASHES) . PHP_EOL;

