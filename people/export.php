<?php
require_once '../core/db.php';
require_once 'includes/people_helper.php';

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="employees_export.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['Name', 'Email', 'Role', 'Tenant', 'Start Date', 'Status']);

$employees = getAccessibleEmployees($pdo, $_SESSION['user_id'], $_SESSION['tenant_id']);
foreach ($employees as $emp) {
    fputcsv($output, [
        $emp['name'],
        $emp['email'],
        $emp['role'],
        $emp['tenant_name'],
        $emp['start_date'],
        $emp['is_active'] ? 'Active' : 'Inactive'
    ]);
}

fclose($output);
exit;
