<?php
require_once '../../core/autoload.php';
include_once '../../core/db_config.php';
session_start();

$tenant_id = $_SESSION['tenant_id'] ?? null;

if (!$tenant_id) {
    die("Unauthorized");
}

$scenarios = $db->query("SELECT * FROM pe_scenarios WHERE tenant_id = ? ORDER BY created_at DESC", [$tenant_id]);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Private Equity Dashboard</title>
    <link rel="stylesheet" href="../../assets/css/style.css" />
</head>
<body class="p-6 bg-gray-50">
    <h1 class="text-3xl font-bold text-blue-800 mb-6">📊 Private Equity Dashboard</h1>

    <div class="mb-6">
        <a href="scenario_create.php" class="inline-block bg-blue-600 text-white px-4 py-2 rounded shadow hover:bg-blue-700 transition">
            ➕ New Scenario
        </a>
    </div>

    <?php if (count($scenarios) === 0): ?>
        <p class="text-gray-600">No scenarios yet. Click above to create one.</p>
    <?php else: ?>
        <table class="w-full border-collapse bg-white shadow rounded-lg overflow-hidden">
            <thead class="bg-blue-100 text-blue-900">
                <tr>
                    <th class="px-4 py-3 text-left">Scenario</th>
                    <th class="px-4 py-3 text-left">Pre-Money</th>
                    <th class="px-4 py-3 text-left">Post-Money</th>
                    <th class="px-4 py-3 text-left">Exit Value</th>
                    <th class="px-4 py-3 text-left">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($scenarios as $s): ?>
                    <tr class="border-t">
                        <td class="px-4 py-2"><?= htmlspecialchars($s['scenario_name']) ?></td>
                        <td class="px-4 py-2">$<?= number_format($s['pre_money'], 0) ?></td>
                        <td class="px-4 py-2">$<?= number_format($s['post_money'], 0) ?></td>
                        <td class="px-4 py-2">$<?= number_format($s['exit_value'], 0) ?></td>
                        <td class="px-4 py-2 space-x-2">
                            <a href="scenario_edit.php?id=<?= $s['id'] ?>" class="text-blue-600 hover:underline">Edit</a>
                            <a href="memo_customize.php?scenario_id=<?= $s['id'] ?>" class="text-green-600 hover:underline">Memo</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</body>
</html>
