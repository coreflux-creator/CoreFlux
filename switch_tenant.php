<?php
session_start();

$targetTenant = $_GET['tenant_id'] ?? null;
$targetSubtenant = $_GET['subtenant_id'] ?? null;
$available = $_SESSION['available_roles'] ?? [];

$matched = false;

foreach ($available as $entry) {
    if (
        $entry['tenant_id'] == $targetTenant &&
        ($entry['subtenant_id'] ?? null) == $targetSubtenant
    ) {
        $_SESSION['active_tenant_id'] = $targetTenant;
        $_SESSION['active_subtenant_id'] = $targetSubtenant;
        $_SESSION['role'] = $entry['role'];
        $matched = true;
        break;
    }
}

if ($matched) {
    switch ($_SESSION['role']) {
        case 'master_admin': header("Location: admin_dashboard.php"); break;
        case 'tenant_admin': header("Location: tenant_dashboard.php"); break;
        case 'tenant_user':  header("Location: user_dashboard.php"); break;
        case 'employee':     header("Location: employee_dashboard.php"); break;
        case 'approver':     header("Location: approver_dashboard.php"); break;
        default:             header("Location: login.php?error=invalid-role"); break;
    }
} else {
    header("Location: login.php?error=unauthorized");
}
exit;
