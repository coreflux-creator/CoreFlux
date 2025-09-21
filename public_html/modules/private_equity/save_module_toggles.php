<?php
session_start();
require_once('../core/db_config.php');

// Reset all to 0 first
$pdo->exec("UPDATE admin_modules SET is_active = 0");
$pdo->exec("UPDATE admin_module_features SET is_enabled = 0");

// Then activate selected ones
if (!empty($_POST['modules'])) {
    foreach ($_POST['modules'] as $module_id => $val) {
        $stmt = $pdo->prepare("UPDATE admin_modules SET is_active = 1 WHERE id = ?");
        $stmt->execute([$module_id]);
    }
}

if (!empty($_POST['features'])) {
    foreach ($_POST['features'] as $feature_id => $val) {
        $stmt = $pdo->prepare("UPDATE admin_module_features SET is_enabled = 1 WHERE id = ?");
        $stmt->execute([$feature_id]);
    }
}

$_SESSION['msg'] = "Module toggles saved.";
header("Location: module_toggles.php");
exit;
