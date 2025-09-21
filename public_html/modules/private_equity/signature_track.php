<?php
require_once '../../core/autoload.php';
include_once '../../core/db_config.php';
session_start();

$tenant_id = $_SESSION['tenant_id'] ?? null;
if (!$tenant_id) die("Unauthorized");

$signatures = $db->query("SELECT * FROM pe_signatures WHERE tenant_id = ? ORDER BY signed_at DESC", [$tenant_id]);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Digital Signature Tracker</title>
    <link rel="stylesheet" href="../../assets/css/style.css" />
</head>
<body class="p-6">
    <h1 class="text-2xl font-bold mb-6">Investor Signature Tracking</h1>

    <table class="w-full border">
        <thead>
            <tr>
                <th>Investor</th>
                <th>Scenario</th>
                <th>Email</th>
                <th>Signed At</th>
                <th>IP Address</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($signatures as $sig): ?>
                <tr>
                    <td><?= htmlspecialchars($sig['investor_name']) ?></td>
                    <td><?= htmlspecialchars($sig['scenario_name']) ?></td>
                    <td><?= htmlspecialchars($sig['email']) ?></td>
                    <td><?= date("Y-m-d H:i", strtotime($sig['signed_at'])) ?></td>
                    <td><?= htmlspecialchars($sig['ip_address']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="mt-6">
        <a href="memo_customize.php" class="text-blue-600">← Back to Memo Builder</a>
    </div>
</body>
</html>
