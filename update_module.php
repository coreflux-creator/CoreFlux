<?php
session_start();
if (!isset($_POST['module']) || !isset($_SESSION['modules'])) {
  header("Location: dashboard.php");
  exit;
}

foreach ($_SESSION['modules'] as $mod) {
  if ($mod['name'] === $_POST['module']) {
    $_SESSION['active_module'] = $mod;
    break;
  }
}
header("Location: dashboard.php");
