<?php
require_once '../core/db_connection.php';

// Placeholder for admin-only tenant registration logic
?>
<!DOCTYPE html>
<html>
<head>
  <title>Register Tenant - CoreFlux</title>
  <link rel="stylesheet" href="../assets/css/style.css" />
</head>
<body>
  <div class="login-wrapper">
    <img src="../assets/logo.png" class="logo" alt="CoreFlux" />
    <h2>Register New Tenant</h2>
    <form method="post" action="register.php">
      <input type="text" name="company" placeholder="Company Name" required />
      <input type="email" name="admin_email" placeholder="Admin Email" required />
      <input type="password" name="admin_password" placeholder="Password" required />
      <button type="submit">Register</button>
    </form>
  </div>
</body>
</html>
