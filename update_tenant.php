<?php
session_start();
if (in_array($_POST['tenant'], $_SESSION['user']['tenants'])) {
  $_SESSION['tenant'] = $_POST['tenant'];
}
header("Location: dashboard.php");
