<?php
require_once '../../core/autoload.php';
include_once '../../core/db_config.php';
session_start();

if (!isset($_SESSION['is_master_admin']) || !$_SESSION['is_master_admin']) {
    die("Unauthorized");
}

$scenarios = $db->query("SELECT s.*, t.tenant_name FROM pe_scenarios s
                         JOIN tenants t ON s.tenant_id = t.id
                         ORDER BY s.created_at DESC");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Private Equity Admin Dashboard</title>
    <link rel="stylesheet" href="../../assets/css/style.css" />
</head>
<body class="p-6">
    <h1 class="text-2xl font-bold mb-4">Private Equity Module – Admin Panel</h1>

    <h2 class="text-xl font-semibold mb-2">All Scenarios Across Tenants</h2>
    <table class="w-full border mt-4">
        <thead>
            <tr>
                <th>Tenant</th>
                <th>Scenario Name</th>
                <th>Pre-Money</th>
                <th>Post-Money</th>
                <th>Exit</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($scenarios as $s): ?>
                <tr>
                    <td><?= htmlspecialchars($s['tenant_name']) ?></td>
                    <td><?= htmlspecialchars($s['scenario_name']) ?></td>
                    <td>$<?= number_format($s['pre_money']) ?></td>
                    <td>$<?= number_format($s['post_money']) ?></td>
                    <td>$<?= number_format($s['exit_value']) ?></td>
                    <td><?= date("Y-m-d", strtotime($s['created_at'])) ?></td>
                    <td>
                        <a href="admin_pe_edit.php?id=<?= $s['id'] ?>" class="text-blue-600">Edit</a> |
                        <a href="admin_pe_delete.php?id=<?= $s['id'] ?>" class="text-red-600" onclick="return confirm('Delete this scenario?')">Delete</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
