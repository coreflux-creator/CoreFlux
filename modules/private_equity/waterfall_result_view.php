<?php
session_start();

if (!isset($_SESSION['tenant_id']) || $_SESSION['role'] !== 'admin') {
    die("Unauthorized access.");
}

$result = $_SESSION['waterfall_result'] ?? [];
$exit_value = $_SESSION['waterfall_exit_value'] ?? 0;
$scenario_name = $_SESSION['waterfall_scenario_name'] ?? 'Unknown';

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Waterfall Results</title>
  <link rel="stylesheet" href="/assets/css/styles.css" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-50 text-gray-900">
  <div class="max-w-5xl mx-auto p-6">
    <h1 class="text-3xl font-bold mb-4">💧 Waterfall Results</h1>
    <p class="mb-4">Scenario: <strong><?= htmlspecialchars($scenario_name) ?></strong> | Exit Value: <strong>$<?= number_format($exit_value, 2) ?></strong></p>

    <div class="mb-4 space-x-4">
      <a href="export_waterfall.php?format=pdf" class="inline-block px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Download PDF</a>
      <a href="export_waterfall.php?format=csv" class="inline-block px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700">Download CSV</a>
    </div>

    <table class="min-w-full bg-white border mt-6 shadow rounded-xl">
      <thead>
        <tr class="bg-gray-100 text-left text-sm font-medium text-gray-700">
          <th class="px-4 py-2">Shareholder</th>
          <th class="px-4 py-2">Class</th>
          <th class="px-4 py-2">Distribution</th>
          <th class="px-4 py-2">Ownership %</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($result as $row): ?>
        <tr class="border-t text-sm">
          <td class="px-4 py-2"><?= htmlspecialchars($row['shareholder']) ?></td>
          <td class="px-4 py-2"><?= htmlspecialchars($row['class']) ?></td>
          <td class="px-4 py-2">$<?= number_format($row['distribution'], 2) ?></td>
          <td class="px-4 py-2"><?= number_format($row['ownership_pct'], 2) ?>%</td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <canvas id="distributionChart" class="mt-8"></canvas>

    <div class="mt-8">
      <a href="waterfall_builder.php" class="text-blue-600 hover:underline">← Back to Builder</a>
    </div>
  </div>

  <script>
    const ctx = document.getElementById('distributionChart');
    const chart = new Chart(ctx, {
      type: 'pie',
      data: {
        labels: <?= json_encode(array_column($result, 'shareholder')) ?>,
        datasets: [{
          label: 'Distributions',
          data: <?= json_encode(array_column($result, 'distribution')) ?>,
          backgroundColor: ['#60A5FA', '#F472B6', '#34D399', '#FBBF24', '#A78BFA', '#F87171']
        }]
      }
    });
  </script>
</body>
</html>
