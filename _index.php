<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include 'core/autoload.php';
include 'partials/header.php';
?>

<div class="hero">
  <h1>Welcome to CoreFlux</h1>
  <p>Strategic Tools for People, Finance, and Growth</p>
  <div class="buttons">
    <a href="/signup.html" class="btn">Get Started</a>
    <a href="/login.html" class="btn">Login</a>
  </div>
</div>

<?php include 'partials/footer.php'; ?>
