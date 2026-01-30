<?php
session_start();

if (!isset($_SESSION['tenant_id']) || $_SESSION['role'] !== 'admin') {
    die("Unauthorized.");
}

$result = $_SESSION['waterfall_result'] ?? [];
$exit_value = $_SESSION['waterfall_exit_value'] ?? 0;
$scenario_name = $_SESSION['waterfall_scenario_name'] ?? 'Unknown';

// Group by class
$class_groups = [];
foreach ($result as $row) {
    $class = $row['class'];
    if (!isset($class_groups[$class])) {
        $class_groups[$class] = ['total' => 0, 'owners' => []];
    }
    $class_groups[$class]['total'] += $row['distribution'];
    $class_groups[$class]['owners'][] = $row['shareholder'];
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Advanced Waterfall Visuals</title>
  <link rel="stylesheet" href="/assets/css/styles.css" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-50 text-gray-900">
  <div class="max-w-5xl mx-auto p-6">
    <h1 class="text-3xl font-bold mb-6">📊 Advanced Waterfall Visualizations</h1>
    <p class="mb-4">Scenario: <strong><?= htmlspecialchars($scenario_name) ?></strong> | Exit Value: <strong>$<?= number_format($exit_value, 2) ?></strong></p>

    <h2 class="text-xl font-semibold mt-6 mb-2">Distributions by Class (Stacked Bar)</h2>
    <canvas id="classChart"></canvas>

    <h2 class="text-xl font-semibold mt-8 mb-2">Shareholder ROI (Bar)</h2>
    <canvas id="roiChart"></canvas>

    <div class="mt-10">
      <a href="waterfall_result_view.php" class="text-blue-600 hover:underline">← Back to Results</a>
    </div>
  </div>

  <script>
    const classChart = new Chart(document.getElementById('classChart'), {
      type: 'bar',
      data: {
        labels: <?= json_encode(array_keys($class_groups)) ?>,
        datasets: [{
          label: 'Total Distribution',
          data: <?= json_encode(array_map(fn($g) => $g['total'], $class_groups)) ?>,
          backgroundColor: ['#4ADE80', '#60A5FA', '#FCD34D', '#F87171']
        }]
      },
      options: {
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
      }
    });

    const roiChart = new Chart(document.getElementById('roiChart'), {
      type: 'bar',
      data: {
        labels: <?= json_encode(array_column($result, 'shareholder')) ?>,
        datasets: [{
          label: 'Distribution $',
          data: <?= json_encode(array_column($result, 'distribution')) ?>,
          backgroundColor: '#6366F1'
        }]
      },
      options: {
        scales: {
          y: { beginAtZero: true, title: { display: true, text: 'Distribution Amount ($)' } }
        }
      }
    });
  </script>
</body>
</html>
