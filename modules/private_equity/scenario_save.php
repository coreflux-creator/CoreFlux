<?php
require_once '../../core/autoload.php';
include_once '../../core/db_config.php';
session_start();

$tenant_id = $_SESSION['tenant_id'] ?? null;
if (!$tenant_id) die("Unauthorized");

$data = [
    'scenario_name' => $_POST['scenario_name'] ?? '',
    'pre_money' => $_POST['pre_money'] ?? 0,
    'post_money' => $_POST['post_money'] ?? 0,
    'exit_value' => $_POST['exit_value'] ?? 0,
    'cap_rate' => $_POST['cap_rate'] ?? null,
    'participation_cap' => $_POST['participation_cap'] ?? null,
    'tenant_id' => $tenant_id
];

$db->insert("pe_scenarios", $data);
header("Location: pe_dashboard.php");
exit;
