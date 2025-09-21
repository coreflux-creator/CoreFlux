<?php
include_once '../../partials/header.php';
include_once '../../core/db_config.php';

$tenant_id = $_SESSION['tenant_id'];
$scenarios = $db->query("SELECT id, scenario_name FROM pe_scenarios WHERE tenant_id = ? ORDER BY created_at DESC", [$tenant_id]);
?>

<div class="container mx-auto p-4">
  <h1 class="text-2xl font-bold mb-4">Waterfall Distribution Calculator</h1>

  <form method="GET" class="mb-6">
    <label class="block font-semibold mb-1">Choose Scenario:</label>
    <select name="scenario_id" class="border rounded p-2 w-full max-w-md" onchange="this.form.submit()">
      <option value="">-- Select Scenario --</option>
      <?php foreach ($scenarios as $s): ?>
        <option value="<?= $s['id'] ?>" <?= ($_GET['scenario_id'] ?? '') == $s['id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($s['scenario_name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </form>

  <?php if (!empty($_GET['scenario_id'])): ?>
    <iframe src="waterfall_chart.php?scenario_id=<?= $_GET['scenario_id'] ?>"
            class="w-full h-[1000px] border rounded shadow"></iframe>
  <?php endif; ?>
</div>

<?php include_once '../../partials/footer.php'; ?>
