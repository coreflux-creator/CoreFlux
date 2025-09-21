<?php
require_once '../../core/autoload.php';
include_once '../../core/db_config.php';
session_start();

$tenant_id = $_SESSION['tenant_id'] ?? null;
if (!$tenant_id) die("Access denied");

$scenarios = $db->query("SELECT * FROM pe_scenarios WHERE tenant_id = ? ORDER BY created_at DESC", [$tenant_id]);
$cap_summary = $db->query("SELECT class, COUNT(*) as holders, SUM(ownership_pct) as total_pct FROM pe_cap_tables WHERE tenant_id = ? GROUP BY class", [$tenant_id]);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Private Equity Dashboard</title>
  <link rel="stylesheet" href="/assets/css/styles.css" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-50 text-gray-900">
  <div class="p-6">
    <h1 class="text-3xl font-bold mb-4">Private Equity Dashboard</h1>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
      <?php foreach ($cap_summary as $row): ?>
        <div class="bg-white shadow rounded-xl p-4">
          <h2 class="text-lg font-semibold"><?= htmlspecialchars($row['class']) ?></h2>
          <p class="text-sm">Holders: <?= $row['holders'] ?></p>
          <p class="text-sm">Ownership: <?= number_format($row['total_pct'], 2) ?>%</p>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Scenario Table -->
    <div class="bg-white shadow rounded-xl p-4">
      <h2 class="text-xl font-semibold mb-2">Scenarios</h2>
      <table class="w-full text-sm border-collapse">
        <thead>
          <tr class="bg-gray-100 text-left">
            <th class="p-2 border-b">Name</th>
            <th class="p-2 border-b">Pre-Money</th>
            <th class="p-2 border-b">Post-Money</th>
            <th class="p-2 border-b">Exit Value</th>
            <th class="p-2 border-b">Created</th>
            <th class="p-2 border-b">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($scenarios as $s): ?>
            <tr class="hover:bg-gray-50">
              <td class="p-2 border-b"><?= htmlspecialchars($s['scenario_name']) ?></td>
              <td class="p-2 border-b">$<?= number_format($s['pre_money'], 0) ?></td>
              <td class="p-2 border-b">$<?= number_format($s['post_money'], 0) ?></td>
              <td class="p-2 border-b">$<?= number_format($s['exit_value'], 0) ?></td>
              <td class="p-2 border-b"><?= date('Y-m-d', strtotime($s['created_at'])) ?></td>
              <td class="p-2 border-b">
                <a href="scenario_edit.php?id=<?= $s['id'] ?>" class="text-blue-600 hover:underline">Edit</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- ROI Chart -->
    <div class="mt-10 bg-white shadow rounded-xl p-4">
      <h2 class="text-xl font-semibold mb-4">Aggregate ROI Overview</h2>
      <canvas id="roiChart" height="120"></canvas>
    </div>
  </div>

  <script>
    const ctx = document.getElementById('roiChart').getContext('2d');
    const roiChart = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: <?= json_encode(array_column($scenarios, 'scenario_name')) ?>,
        datasets: [{
          label: 'Exit Value ($)',
          data: <?= json_encode(array_column($scenarios, 'exit_value')) ?>,
          backgroundColor: 'rgba(59, 130, 246, 0.5)',
          borderColor: 'rgba(59, 130, 246, 1)',
          borderWidth: 1
        }]
      },
      options: {
        responsive: true,
        scales: {
          y: {
            beginAtZero: true
          }
        }
      }
    });
  </script>
</body>
</html>
