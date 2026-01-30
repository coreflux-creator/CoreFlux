<?php
require_once __DIR__ . '/config.php';

$queries = [
    "CREATE TABLE IF NOT EXISTS custom_fields (
        id INT AUTO_INCREMENT PRIMARY KEY,
        module VARCHAR(50),
        label VARCHAR(255),
        type VARCHAR(50),
        options TEXT,
        tenant_id INT
    )",
    "CREATE TABLE IF NOT EXISTS custom_field_values (
        id INT AUTO_INCREMENT PRIMARY KEY,
        field_id INT,
        record_id INT,
        value TEXT,
        FOREIGN KEY (field_id) REFERENCES custom_fields(id)
    )"
];

foreach ($queries as $query) {
    $pdo->exec($query);
}

echo "Custom field tables created or already exist.";
