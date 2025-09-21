<?php
require_once '../../core/autoload.php';
include_once '../../core/db_config.php';
session_start();

if (!isset($_SESSION['is_master_admin']) || !$_SESSION['is_master_admin']) {
    die("Unauthorized");
}

$id = $_GET['id'] ?? null;
if (!$id) die("No scenario selected");

$scenario = $db->query("SELECT * FROM pe_scenarios WHERE id = ?", [$id])[0] ?? null;
if (!$scenario) die("Scenario not found");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $scenario_name = $_POST['scenario_name'];
    $pre_money = $_POST['pre_money'];
    $post_money = $_POST['post_money'];
    $exit_value = $_POST['exit_value'];

    $db->query("UPDATE pe_scenarios SET scenario_name = ?, pre_money = ?, post_money = ?, exit_value = ? WHERE id = ?", [
        $scenario_name, $pre_money, $post_money, $exit_value, $id
    ]);
    header("Location: admin_pe_dashboard.php");
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit PE Scenario</title>
    <link rel="stylesheet" href="../../assets/css/style.css" />
</head>
<body class="p-6">
    <h1 class="text-2xl font-bold mb-4">Edit Scenario: <?= htmlspecialchars($scenario['scenario_name']) ?></h1>

    <form method="post" class="space-y-4 max-w-lg">
        <div>
            <label class="block font-semibold">Scenario Name</label>
            <input type="text" name="scenario_name" value="<?= htmlspecialchars($scenario['scenario_name']) ?>" class="w-full border px-3 py-2" />
        </div>
        <div>
            <label class="block font-semibold">Pre-Money Valuation</label>
            <input type="number" name="pre_money" value="<?= $scenario['pre_money'] ?>" class="w-full border px-3 py-2" />
        </div>
        <div>
            <label class="block font-semibold">Post-Money Valuation</label>
            <input type="number" name="post_money" value="<?= $scenario['post_money'] ?>" class="w-full border px-3 py-2" />
        </div>
        <div>
            <label class="block font-semibold">Exit Value</label>
            <input type="number" name="exit_value" value="<?= $scenario['exit_value'] ?>" class="w-full border px-3 py-2" />
        </div>
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Update</button>
    </form>

    <div class="mt-4">
        <a href="admin_pe_dashboard.php" class="text-blue-600">← Back to Admin Dashboard</a>
    </div>
</body>
</html>
