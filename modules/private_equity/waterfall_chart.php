<?php
include_once '../../core/db_config.php';
session_start();

$tenant_id = $_SESSION['tenant_id'] ?? null;
$scenario_id = $_GET['scenario_id'] ?? null;

if (!$tenant_id || !$scenario_id) {
  echo "<p class='text-red-600'>Invalid scenario.</p>";
  exit;
}

$scenario = $db->query("SELECT * FROM pe_scenarios WHERE id = ? AND tenant_id = ?", [$scenario_id, $tenant_id])[0] ?? null;
$cap_table = $db->query("SELECT * FROM pe_cap_tables WHERE scenario_id = ? AND tenant_id = ?", [$scenario_id, $tenant_id]);

if (!$scenario || !$cap_table) {
  echo "<p class='text-red-600'>Scenario not found or no data available.</p>";
  exit;
}

$exit_value = floatval($scenario['exit_value']);
$cap_rate = floatval($scenario['cap_rate']);
$use_tiers = intval($scenario['trigger_tiers']);
$cap_multiple = floatval($scenario['participation_cap'] ?: 2);

$total_common_pct = 0;
$total_preferred = 0;
$distributions = [];

// First, calculate each holder's share
foreach ($cap_table as $row) {
  $name = $row['shareholder'];
  $class = strtolower($row['class']);
  $pct = floatval($row['ownership_pct']);
  $investment = floatval($row['invested_amount']);
  $convertible = intval($row['convertible_note']);

  if ($convertible && $cap_rate > 0) {
    // Convert at cap rate
    $pct = ($investment / ($scenario['cap_rate'] ?: 1)) * 100 / $scenario['post_money'];
  }

  $distributions[] = [
    'name' => $name,
    'class' => $class,
    'pct' => $pct,
    'invested' => $investment,
    'convertible' => $convertible
  ];

  if ($class === 'preferred') {
    $total_preferred += $investment * $cap_multiple;
  } else {
    $total_common_pct += $pct;
  }
}

$remaining = $exit_value;
$chart_data = [];

// Pay out preferred returns first
foreach ($distributions as &$row) {
  if ($row['class'] === 'preferred') {
    $preferred_target = $row['invested'] * $cap_multiple;
    $pay = min($preferred_target, $remaining);
    $row['distribution'] = $pay;
    $remaining -= $pay;
  } else {
    $row['distribution'] = 0;
  }
}

// Then distribute remaining to common pro-rata
foreach ($distributions as &$row) {
  if ($row['class'] === 'common' && $total_common_pct > 0) {
    $share = $remaining * ($row['pct'] / $total_common_pct);
    $row['distribution'] += $share;
  }
}

// Output
?>
<div class="p-6">
  <h2 class="text-xl font-bold mb-4">Waterfall Results</h2>
  <table class="w-full border text-sm">
    <thead>
      <tr class="bg-gray-100">
        <th>Shareholder</th>
        <th>Class</th>
        <th>Ownership %</th>
        <th>Invested</th>
        <th>Convertible</th>
        <th>Total Distribution</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($distributions as $row): ?>
        <tr>
          <td><?= htmlspecialchars($row['name']) ?></td>
          <td><?= ucfirst($row['class']) ?></td>
          <td><?= number_format($row['pct'], 2) ?>%</td>
          <td>$<?= number_format($row['invested'], 0) ?></td>
          <td><?= $row['convertible'] ? 'Yes' : 'No' ?></td>
          <td class="font-bold text-green-700">$<?= number_format($row['distribution'], 2) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
