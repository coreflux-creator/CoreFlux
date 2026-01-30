<?php
require_once '../../core/autoload.php';
include_once '../../core/db_config.php';
session_start();

if (!isset($_SESSION['is_master_admin']) || !$_SESSION['is_master_admin']) {
    die("Unauthorized");
}

$id = $_GET['id'] ?? null;
if (!$id) die("Missing scenario ID");

$db->query("DELETE FROM pe_scenarios WHERE id = ?", [$id]);
header("Location: admin_pe_dashboard.php");
exit;
?>