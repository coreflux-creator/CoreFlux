<?php
require_once '../../core/autoload.php';
include_once '../../core/db_config.php';
session_start();

$tenant_id = $_SESSION['tenant_id'] ?? null;
if (!$tenant_id) die("Unauthorized");

$data = [
  'tenant_id' => $tenant_id,
  'scenario_id' => $_POST['scenario_id'],
  'shareholder' => $_POST['shareholder'],
  'class' => $_POST['class'],
  'ownership_pct' => $_POST['ownership_pct'],
  'invested_amount' => $_POST['invested_amount'] ?? 0,
  'convertible_note' => isset($_POST['convertible_note']) ? 1 : 0,
];

$db->insert("pe_cap_tables", $data);
header("Location: cap_table_edit.php?scenario_id=" . $_POST['scenario_id']);
exit;
