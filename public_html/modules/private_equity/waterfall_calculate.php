<?php
require_once '../../core/autoload.php';
include_once '../../core/db_config.php';
include_once '../../core/helpers/waterfall_helper.php';
session_start();

if (!isset($_SESSION['tenant_id']) || $_SESSION['role'] !== 'admin') {
    die("Unauthorized access.");
}

$tenant_id = $_SESSION['tenant_id'];
$scenario_id = $_POST['scenario_id'] ?? null;
$custom_exit = $_POST['custom_exit'] ?? null;

if (!$scenario_id) die("Scenario ID is required.");

$scenario = $db->query("SELECT * FROM pe_scenarios WHERE id = ? AND tenant_id = ?", [$scenario_id, $tenant_id])[0] ?? null;
if (!$scenario) die("Scenario not found.");

$exit_value = $custom_exit ?: $scenario['exit_value'];
$cap_table = $db->query("SELECT * FROM pe_cap_tables WHERE scenario_id = ? AND tenant_id = ?", [$scenario_id, $tenant_id]);

$result = calculate_waterfall_distribution($exit_value, $cap_table, $scenario['cap_rate'], $scenario['participation_cap']);

$_SESSION['waterfall_result'] = $result;
$_SESSION['waterfall_exit_value'] = $exit_value;
$_SESSION['waterfall_scenario_name'] = $scenario['scenario_name'];

header("Location: waterfall_result_view.php");
exit;
