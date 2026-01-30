<?php
include_once '../../partials/header.php';
include_once '../../core/db_config.php';

// Fetch scenarios
$scenarios = $db->query("SELECT id, scenario_name FROM pe_scenarios WHERE tenant_id = ?", [$_SESSION['tenant_id']]);
?>

<div class="container mx-auto p-4">
  <h1 class="text-2xl font-bold mb-4">Cap Table Entry</h1>

  <form id="cap-table-form" method="POST" action="data/save_cap_table.php">
    <div class="mb-4">
      <label class="block font-semibold mb-1">Select Scenario:</label>
      <select name="scenario_id" class="border rounded w-full p-2">
        <?php foreach ($scenarios as $scenario): ?>
          <option value="<?= $scenario['id'] ?>"><?= htmlspecialchars($scenario['scenario_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <table class="w-full border text-sm" id="cap-table">
      <thead>
        <tr class="bg-gray-100">
          <th>Shareholder</th>
          <th>Class</th>
          <th>Ownership %</th>
          <th>Invested Amount</th>
          <th>Convertible Note?</th>
          <th>Memo Notes</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><input type="text" name="shareholder[]" class="border p-1 w-full"></td>
          <td>
            <select name="class[]" class="border p-1 w-full">
              <option value="Common">Common</option>
              <option value="Preferred">Preferred</option>
              <option value="Convertible">Convertible</option>
            </select>
          </td>
          <td><input type="number" step="0.0001" name="ownership_pct[]" class="border p-1 w-full"></td>
          <td><input type="number" step="0.01" name="invested_amount[]" class="border p-1 w-full"></td>
          <td><input type="checkbox" name="convertible_note[]" value="1" class="mx-auto"></td>
          <td><input type="text" name="memo_notes[]" class="border p-1 w-full"></td>
          <td><button type="button" class="remove-row text-red-600">&times;</button></td>
        </tr>
      </tbody>
    </table>

    <div class="mt-4">
      <button type="button" id="add-row" class="bg-blue-600 text-white px-3 py-1 rounded">Add Row</button>
      <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded ml-2">Save Cap Table</button>
    </div>
  </form>
</div>

<script src="js/private_equity.js"></script>

<?php include_once '../../partials/footer.php'; ?>
