<?php
require_once '../core/db.php';
require_once 'includes/people_helper.php';

if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    die("Upload failed.");
}

$handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
$tenantId = $_SESSION['tenant_id'];
$created = 0;

// Skip header
fgetcsv($handle);

while (($row = fgetcsv($handle)) !== false) {
    [$name, $email, $role] = $row;

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
    if (!in_array($role, ['employee', 'approver', 'tenant_user'])) continue;

    createNewEmployee($pdo, trim($name), trim($email), trim($role), 1, $tenantId);
    $created++;
}

fclose($handle);
header("Location: index.php?imported=$created");
exit;
