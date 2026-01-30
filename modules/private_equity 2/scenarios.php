<?php
include_once '../../partials/header.php';
include_once '../../core/db_config.php';

$tenant_id = $_SESSION['tenant_id'];
$scenarios = $db->query("SELECT * FROM pe_scenarios WHERE tenant_id = ? ORDER BY created_at DESC", [$tenant_id]);
?>

<div class="container mx-auto p-4">
  <h1 class="text-2xl font-bold mb-4">Scenario Manager</h1>

  <form method="POST" action="data/save_scenario.php" class="grid grid-cols-2 gap-4 bg-white p-4 border rounded mb-6">
    <div>
      <label class="block font-semibold mb-1">Scenario Name</label>
      <input type="text" name="scenario_name" class="border rounded p-2 w-full" required>
    </div>
    <div>
      <label class="block font-semibold mb-1">Pre-Money Valuation</label>
      <input type="number" step="0.01" name="pre_money" class="border rounded p-2 w-full" required>
    </div>
    <div>
      <label class="block font-semibold mb-1">Post-Money Valuation</label>
      <input type="number" step="0.01" name="post_money" class="border rounded p-2 w-full">
    </div>
    <div>
      <label class="block font-semibold mb-1">Exit Value</label>
      <input type="number" step="0.01" name="exit_value" class="border rounded p-2 w-full">
    </div>
    <div>
      <label class="block font-semibold mb-1">Cap Rate</label>
      <input type="number" step="0.01" name="cap_rate" class="border rounded p-2 w-full">
    </div>
    <div>
      <label class="block font-semibold mb-1">Option Pool %</label>
      <input type="number" step="0.0001" name="option_pool_pct" class="border rounded p-2 w-full">
    </div>
    <div>
      <label class="block font-semibold mb-1">Participation Cap (e.g. 2 = 2x)</label>
      <input type="number" step="0.01" name="participation_cap" class="border rounded p-2 w-full">
    </div>
    <div class="flex items-center mt-6">
      <label class="mr-2">Use Tiered Logic</label>
      <input type="checkbox" name="trigger_tiers" value="1" checked>
    </div>
    <div class="col-span-2 text-right">
      <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded">Save Scenario</button>
    </div>
  </form>

  <h2 class="text-xl font-bold mb-2">Existing Scenarios</h2>
  <table class="w-full text-sm border">
    <thead>
      <tr class="bg-gray-100">
        <th>Name</th>
        <th>Pre</th>
        <th>Post</th>
        <th>Exit</th>
        <th>Cap Rate</th>
        <th>Option Pool %</th>
        <th>Cap</th>
        <th>Tiered</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($scenarios as $row): ?>
        <tr>
          <td><?= htmlspecialchars($row['scenario_name']) ?></td>
          <td><?= number_format($row['pre_money']) ?></td>
          <td><?= number_format($row['post_money']) ?></td>
          <td><?= number_format($row['exit_value']) ?></td>
          <td><?= number_format($row['cap_rate']) ?></td>
          <td><?= $row['option_pool_pct'] * 100 ?>%</td>
          <td><?= $row['participation_cap'] ?>x</td>
          <td><?= $row['trigger_tiers'] ? 'Yes' : 'No' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php include_once '../../partials/footer.php'; ?>
