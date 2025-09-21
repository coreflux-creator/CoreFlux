<?php
require_once '../core/session.php';
require_once '../core/db_connection.php';

$tenant_id = $_GET['id'] ?? null;
if (!$tenant_id) {
    die("Missing tenant ID.");
}

// Mocked permissions list
$permissions = [
    'require_2fa' => false,
    'allow_external_ip_access' => true,
    'password_expiration_days' => 90,
    'max_login_attempts' => 5
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($permissions as $key => &$value) {
        if (isset($_POST[$key])) {
            $value = $_POST[$key];
        }
    }
    echo "<p>Security settings saved for tenant ID: $tenant_id</p>";
}
?>
<!DOCTYPE html>
<html>
<head>
  <title>Tenant Security Settings</title>
  <link rel="stylesheet" href="../assets/css/style.css" />
</head>
<body>
  <div class="container">
    <h1>Security Settings for Tenant ID <?= htmlspecialchars($tenant_id) ?></h1>
    <form method="post">
      <label>Require 2FA:</label>
      <input type="checkbox" name="require_2fa" <?= $permissions['require_2fa'] ? 'checked' : '' ?> /><br/><br/>

      <label>Allow External IP Access:</label>
      <input type="checkbox" name="allow_external_ip_access" <?= $permissions['allow_external_ip_access'] ? 'checked' : '' ?> /><br/><br/>

      <label>Password Expiration (days):</label>
      <input type="number" name="password_expiration_days" value="<?= $permissions['password_expiration_days'] ?>" /><br/><br/>

      <label>Max Login Attempts:</label>
      <input type="number" name="max_login_attempts" value="<?= $permissions['max_login_attempts'] ?>" /><br/><br/>

      <button type="submit">Save Security Settings</button>
    </form>
  </div>
</body>
</html>
