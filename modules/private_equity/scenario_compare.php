<?php
require_once '../../core/autoload.php';
include_once '../../core/db_config.php';
session_start();

$tenant_id = $_SESSION['tenant_id'] ?? null;
if (!$tenant_id) die("Unauthorized");

$scenarios = $db->query("SELECT * FROM pe_scenarios WHERE tenant_id = ? ORDER BY created_at DESC", [$tenant_id]);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Scenario Comparison</title>
    <link rel="stylesheet" href="../../assets/css/style.css" />
</head>
<body class="p-6">
    <h1 class="text-2xl font-bold mb-4">Compare Scenarios</h1>

    <table>
        <thead>
            <tr>
                <th>Scenario</th>
                <th>Pre-Money</th>
                <th>Post-Money</th>
                <th>Exit Value</th>
                <th>Cap Rate</th>
                <th>Participation Cap</th>
                <th>Convertible Note</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($scenarios as $s): ?>
                <tr>
                    <td><?= htmlspecialchars($s['scenario_name']) ?></td>
                    <td>$<?= number_format($s['pre_money'], 0) ?></td>
                    <td>$<?= number_format($s['post_money'], 0) ?></td>
                    <td>$<?= number_format($s['exit_value'], 0) ?></td>
                    <td><?= $s['cap_rate'] ?></td>
                    <td><?= $s['participation_cap'] ?>x</td>
                    <td><?= $s['convertible_investment'] ? '$' . number_format($s['convertible_investment']) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="mt-6">
        <a href="memo_customize.php" class="text-blue-600">← Back to Memo Builder</a>
    </div>
</body>
</html>
