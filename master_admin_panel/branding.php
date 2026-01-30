<?php
require_once '../core/session.php';
require_once '../core/db_connection.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Save branding settings
}

?>
<!DOCTYPE html>
<html>
<head>
  <title>Tenant Branding - Master Admin</title>
  <link rel="stylesheet" href="../assets/css/style.css" />
</head>
<body>
  <div class="container">
    <h1>Tenant Branding Settings</h1>
    <form method="post" enctype="multipart/form-data" action="branding.php">
      <label>Tenant Logo:</label><br/>
      <input type="file" name="tenant_logo" accept="image/*"><br/><br/>

      <label>Reply-To Email:</label><br/>
      <input type="email" name="reply_to" required><br/><br/>

      <label>Design Theme:</label><br/>
      <select name="design_mode">
        <option value="swirl">Swirl</option>
        <option value="abstract">Abstract</option>
        <option value="white">White</option>
        <option value="block">Block</option>
      </select><br/><br/>

      <button type="submit">Save Branding</button>
    </form>
  </div>
</body>
</html>
