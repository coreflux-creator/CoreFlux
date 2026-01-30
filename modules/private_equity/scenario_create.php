<?php
require_once '../../core/autoload.php';
include_once '../../core/db_config.php';
session_start();

$tenant_id = $_SESSION['tenant_id'] ?? null;

if (!$tenant_id) {
    die("Unauthorized");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['scenario_name'];
    $pre = $_POST['pre_money'];
    $post = $_POST['post_money'];
    $exit = $_POST['exit_value'];
    $cap_rate = $_POST['cap_rate'];
    $participation = $_POST['participation_cap'];

    $db->query("INSERT INTO pe_scenarios (tenant_id, scenario_name, pre_money, post_money, exit_value, cap_rate, participation_cap) 
                VALUES (?, ?, ?, ?, ?, ?, ?)", [
        $tenant_id, $name, $pre, $post, $exit, $cap_rate, $participation
    ]);

    header("Location: pe_dashboard.php");
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Create Scenario</title>
    <link rel="stylesheet" href="../../assets/css/style.css" />
</head>
<body class="p-6 bg-gray-50">
    <h1 class="text-3xl font-bold text-blue-800 mb-6">➕ Create New Investment Scenario</h1>

    <form method="post" class="bg-white shadow-md rounded-lg p-6 max-w-2xl space-y-4">
        <div>
            <label class="block font-semibold mb-1">Scenario Name</label>
            <input type="text" name="scenario_name" required class="w-full border px-3 py-2 rounded" />
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block font-semibold mb-1">Pre-Money Valuation ($)</label>
                <input type="number" name="pre_money" required class="w-full border px-3 py-2 rounded" />
            </div>
            <div>
                <label class="block font-semibold mb-1">Post-Money Valuation ($)</label>
                <input type="number" name="post_money" required class="w-full border px-3 py-2 rounded" />
            </div>
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block font-semibold mb-1">Exit Value ($)</label>
                <input type="number" name="exit_value" required class="w-full border px-3 py-2 rounded" />
            </div>
            <div>
                <label class="block font-semibold mb-1">Cap Rate</label>
                <input type="text" name="cap_rate" placeholder="e.g. 20%" required class="w-full border px-3 py-2 rounded" />
            </div>
        </div>
        <div>
            <label class="block font-semibold mb-1">Participation Cap (x)</label>
            <input type="number" step="0.1" name="participation_cap" placeholder="e.g. 1.5" required class="w-full border px-3 py-2 rounded" />
        </div>
        <button type="submit" class="bg-blue-600 text-white px-5 py-2 rounded shadow hover:bg-blue-700">
            Create Scenario
        </button>
    </form>
</body>
</html>
