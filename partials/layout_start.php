<?php
if (!isset($_SESSION)) session_start();
if (!isset($_SESSION['user_id'])) {
  header("Location: login.html");
  exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<?php include 'head.php'; ?>
<body>
<?php include 'header.php'; ?>
<div class="main-container">
  <?php include 'sidebar.php'; ?>
  <main class="content">
