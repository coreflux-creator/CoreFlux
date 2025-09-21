<?php
include_once '../../partials/header.php';
include_once '../../core/db_config.php';

$tenant_id = $_SESSION['tenant_id'];
$scenarios = $db->query("SELECT id, scenario_name FROM pe_scenarios WHERE tenant_id = ? ORDER BY created_at DESC", [$tenant_id]);
?>

<div class="container mx-auto p-4">
  <h1 class="text-2xl font-bold mb-4">Ownership & Exit Visualization</h1>

  <form id="visualization-form" method="GET" class="mb-6">
    <label class="block font-semibold mb-1">Select Scenario:</label>
    <select name="scenario_id" class="border rounded w-full p-2" onchange="this.form.submit()">
      <option value="">-- Select Scenario --</option>
      <?php foreach ($scenarios as $scenario): ?>
        <option value="<?= $scenario['id'] ?>" <?= ($_GET['scenario_id'] ?? '') == $scenario['id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($scenario['scenario_name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </form>

  <div id="charts-area">
    <canvas id="ownershipChart" height="200"></canvas>
    <canvas id="proceedsChart" height="200" class="mt-10"></canvas>
    <canvas id="roiChart" height="200" class="mt-10"></canvas>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const scenarioId = "<?= $_GET['scenario_id'] ?? '' ?>";
if (scenarioId) {
  fetch(`data/fetch_visualization_data.php?scenario_id=${scenarioId}`)
    .then(res => res.json())
    .then(data => {
      new Chart(document.getElementById('ownershipChart'), {
        type: 'pie',
        data: { labels: data.labels, datasets: [{ data: data.ownership, label: "Ownership %" }] },
        options: { plugins: { title: { display: true, text: 'Ownership % (Fully Diluted)' } } }
      });
      new Chart(document.getElementById('proceedsChart'), {
        type: 'bar',
        data: { labels: data.labels, datasets: [{ data: data.proceeds, label: "Exit Proceeds", backgroundColor: 'rgba(54, 162, 235, 0.7)' }] },
        options: { plugins: { title: { display: true, text: 'Exit Proceeds by Shareholder' } } }
      });
      new Chart(document.getElementById('roiChart'), {
        type: 'bar',
        data: { labels: data.labels, datasets: [{ data: data.roi, label: "ROI", backgroundColor: 'rgba(75, 192, 192, 0.7)' }] },
        options: { plugins: { title: { display: true, text: 'Return on Investment (ROI)' } } }
      });
    });
}
</script>

<?php include_once '../../partials/footer.php'; ?>
