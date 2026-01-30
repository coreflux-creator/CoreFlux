<?php
require_once '../../core/autoload.php';
include_once '../../core/db_config.php';
session_start();
include_once '../../partials/layout.php';

$scenario_id = $_GET['scenario_id'] ?? null;
if (!$scenario_id) die("No scenario selected");
?>

<h1 class="text-2xl font-bold mb-4">Add Cap Table Entry</h1>
<form action="cap_table_save.php" method="POST" class="space-y-4 max-w-2xl">
  <input type="hidden" name="scenario_id" value="<?= htmlspecialchars($scenario_id) ?>">
  <div>
    <label class="block text-sm font-semibold mb-1">Shareholder</label>
    <input type="text" name="shareholder" class="w-full border px-3 py-2 rounded" required>
  </div>
  <div>
    <label class="block text-sm font-semibold mb-1">Class</label>
    <input type="text" name="class" class="w-full border px-3 py-2 rounded" required>
  </div>
  <div>
    <label class="block text-sm font-semibold mb-1">Ownership %</label>
    <input type="number" step="0.01" name="ownership_pct" class="w-full border px-3 py-2 rounded" required>
  </div>
  <div>
    <label class="block text-sm font-semibold mb-1">Invested Amount ($)</label>
    <input type="number" name="invested_amount" class="w-full border px-3 py-2 rounded">
  </div>
  <div>
    <label><input type="checkbox" name="convertible_note" value="1"> Convertible Note</label>
  </div>
  <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Add Entry</button>
</form>
