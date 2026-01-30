<?php
require_once '../../core/autoload.php';
include_once '../../core/db_config.php';
session_start();

$tenant_id = $_SESSION['tenant_id'] ?? null;
if (!$tenant_id) die("Unauthorized");

$scenarios = $db->query("SELECT * FROM pe_scenarios WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 5", [$tenant_id]);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Exit ROI Visualization</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="../../assets/css/style.css" />
</head>
<body class="p-6">
    <h1 class="text-2xl font-bold mb-6">Scenario ROI Comparison</h1>
    <canvas id="roiChart" width="800" height="400"></canvas>

    <script>
        const ctx = document.getElementById('roiChart').getContext('2d');
        const chart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: [<?= implode(",", array_map(fn($s) => "'".addslashes($s['scenario_name'])."'", $scenarios)) ?>],
                datasets: [{
                    label: 'Exit Value ($)',
                    data: [<?= implode(",", array_map(fn($s) => $s['exit_value'], $scenarios)) ?>],
                    backgroundColor: 'rgba(37, 99, 235, 0.7)'
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                    title: { display: true, text: 'Exit Value by Scenario' }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: value => '$' + value.toLocaleString() } }
                }
            }
        });
    </script>

    <div class="mt-6">
        <a href="scenario_compare.php" class="text-blue-600">← Back to Comparison</a>
    </div>
</body>
</html>
