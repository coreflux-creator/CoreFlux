<?php
require_once '../../core/autoload.php';
include_once '../../core/db_config.php';
session_start();

if (!isset($_SESSION['tenant_id']) || $_SESSION['role'] !== 'investor') {
    die("Access restricted to investors.");
}

$tenant_id = $_SESSION['tenant_id'];
$investor_email = $_SESSION['user_email'] ?? 'Investor';

// Load investor-linked cap table rows
$investments = $db->query("
    SELECT s.scenario_name, s.exit_value, s.id as scenario_id, c.ownership_pct, c.invested_amount
    FROM pe_cap_tables c
    JOIN pe_scenarios s ON c.scenario_id = s.id
    WHERE c.tenant_id = ? AND c.shareholder_email = ?
", [$tenant_id, $investor_email]);

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Investor Dashboard</title>
  <link rel="stylesheet" href="/assets/css/styles.css" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-50 text-gray-900">
  <div class="p-6 max-w-6xl mx-auto">
    <h1 class="text-3xl font-bold mb-4">Welcome, <?= htmlspecialchars($investor_email) ?></h1>
    <p class="mb-6 text-gray-600">Here’s a summary of your investments across scenarios.</p>

    <!-- Portfolio Table -->
    <div class="bg-white shadow rounded-xl p-4 mb-8">
      <h2 class="text-xl font-semibold mb-3">Your Investment Portfolio</h2>
      <table class="w-full text-sm border-collapse">
        <thead>
          <tr class="bg-gray-100 text-left">
            <th class="p-2 border-b">Scenario</th>
            <th class="p-2 border-b">Ownership %</th>
            <th class="p-2 border-b">Invested ($)</th>
            <th class="p-2 border-b">Exit Value ($)</th>
            <th class="p-2 border-b">Memo</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($investments as $i): ?>
            <tr class="hover:bg-gray-50">
              <td class="p-2 border-b"><?= htmlspecialchars($i['scenario_name']) ?></td>
              <td class="p-2 border-b"><?= number_format($i['ownership_pct'], 2) ?>%</td>
              <td class="p-2 border-b">$<?= number_format($i['invested_amount'], 0) ?></td>
              <td class="p-2 border-b">$<?= number_format($i['exit_value'], 0) ?></td>
              <td class="p-2 border-b">
                <a href="/private_uploads/memos/<?= $tenant_id ?>/<?= $i['scenario_name'] ?>__latest.pdf" target="_blank" class="text-blue-600 hover:underline">Download</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Chart -->
    <div class="bg-white shadow rounded-xl p-4">
      <h2 class="text-xl font-semibold mb-4">Projected Returns</h2>
      <canvas id="roiChart" height="120"></canvas>
    </div>
  </div>

  <script>
    const ctx = document.getElementById('roiChart').getContext('2d');
    const roiChart = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: <?= json_encode(array_column($investments, 'scenario_name')) ?>,
        datasets: [{
          label: 'Exit Value ($)',
          data: <?= json_encode(array_column($investments, 'exit_value')) ?>,
          backgroundColor: 'rgba(16, 185, 129, 0.5)',
          borderColor: 'rgba(16, 185, 129, 1)',
          borderWidth: 1
        }]
      },
      options: {
        responsive: true,
        scales: {
          y: { beginAtZero: true }
        }
      }
    });
  </script>
</body>
</html>
