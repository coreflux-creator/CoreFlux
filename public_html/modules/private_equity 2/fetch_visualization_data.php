<?php
include_once '../../core/db_config.php';
session_start();

$scenario_id = $_GET['scenario_id'] ?? null;
$tenant_id = $_SESSION['tenant_id'] ?? null;

if (!$scenario_id || !$tenant_id) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid scenario or tenant"]);
    exit;
}

$rows = $db->query("SELECT shareholder, ownership_pct, invested_amount FROM pe_cap_tables WHERE scenario_id = ? AND tenant_id = ?", [$scenario_id, $tenant_id]);
$scenario = $db->query("SELECT exit_value FROM pe_scenarios WHERE id = ? AND tenant_id = ?", [$scenario_id, $tenant_id])[0] ?? null;

$labels = [];
$ownership = [];
$proceeds = [];
$roi = [];

$exit_value = floatval($scenario['exit_value'] ?? 0);
$total_ownership = array_sum(array_column($rows, 'ownership_pct'));

foreach ($rows as $row) {
    $labels[] = $row['shareholder'];
    $pct = floatval($row['ownership_pct']);
    $ownership[] = round($pct, 4);

    $share_proceeds = $exit_value * ($pct / $total_ownership);
    $proceeds[] = round($share_proceeds, 2);

    $investment = floatval($row['invested_amount']);
    $roi[] = $investment > 0 ? round($share_proceeds / $investment, 2) : 0;
}

header('Content-Type: application/json');
echo json_encode([
  'labels' => $labels,
  'ownership' => $ownership,
  'proceeds' => $proceeds,
  'roi' => $roi
]);
