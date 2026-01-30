<?php
require_once __DIR__ . '/config.php';

// Example: seed a demo user and one custom field
$pdo->exec("INSERT INTO users (name, email, role) VALUES ('John Doe', 'john@example.com', 'employee')");
$pdo->exec("INSERT INTO custom_fields (module, label, type, options, tenant_id) VALUES 
    ('timesheets', 'Project Code', 'text', '', 1)");

echo "Seed data inserted.";
