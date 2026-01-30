<?php
require_once '../../core/autoload.php';
include_once '../../core/db_config.php';
session_start();

if (!isset($_SESSION['tenant_id']) || $_SESSION['role'] !== 'admin') {
    die("Access restricted to administrators.");
}

$tenant_id = $_SESSION['tenant_id'];
$scenarios = $db->query("SELECT id, scenario_name, exit_value FROM pe_scenarios WHERE tenant_id = ?", [$tenant_id]);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Waterfall Builder</title>
  <link rel="stylesheet" href="/assets/css/styles.css" />
</head>
<body class="bg-gray-50 text-gray-900">
  <div class="max-w-4xl mx-auto p-6">
    <h1 class="text-3xl font-bold mb-6">💧 Waterfall Builder</h1>

    <form method="POST" action="waterfall_calculate.php" class="bg-white shadow p-6 rounded-xl space-y-4">
      <div>
        <label for="scenario_id" class="block text-sm font-medium text-gray-700">Scenario</label>
        <select name="scenario_id" id="scenario_id" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
          <option value="">-- Select Scenario --</option>
          <?php foreach ($scenarios as $s): ?>
            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['scenario_name']) ?> ($<?= number_format($s['exit_value']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label for="custom_exit" class="block text-sm font-medium text-gray-700">Override Exit Value (optional)</label>
        <input type="number" name="custom_exit" id="custom_exit" step="0.01" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
        <p class="text-xs text-gray-500 mt-1">If provided, this will override the scenario's exit value.</p>
      </div>

      <div class="flex justify-between">
        <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">Run Waterfall</button>
        <a href="pe_dashboard.php" class="text-blue-600 hover:underline mt-2 inline-block">← Back to Dashboard</a>
      </div>
    </form>
  </div>
</body>
</html>
