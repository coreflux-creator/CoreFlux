<?php
include_once '../../partials/header.php';
include_once '../../core/db_config.php';

$tenant_id = $_SESSION['tenant_id'] ?? null;
$scenarios = $db->query("SELECT id, scenario_name FROM pe_scenarios WHERE tenant_id = ? ORDER BY created_at DESC", [$tenant_id]);
?>

<div class="container mx-auto p-4">
  <h1 class="text-2xl font-bold mb-4">Investor Memo Generator</h1>

  <form method="GET" action="memo_download.php" target="_blank">
    <label class="block font-semibold mb-1">Select Scenario:</label>
    <select name="scenario_id" class="border rounded p-2 w-full max-w-md" required>
      <option value="">-- Choose Scenario --</option>
      <?php foreach ($scenarios as $s): ?>
        <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['scenario_name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="mt-4 bg-blue-600 text-white px-4 py-2 rounded">Download PDF</button>
  </form>
</div>

<?php include_once '../../partials/footer.php'; ?>
