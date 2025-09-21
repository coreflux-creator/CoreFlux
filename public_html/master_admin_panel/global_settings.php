<?php
require_once '../core/session.php';
require_once '../core/db_connection.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Save global settings logic here
    $maintenance_mode = $_POST['maintenance_mode'] ?? 'off';
    $default_timezone = $_POST['default_timezone'] ?? 'UTC';
    $support_email = $_POST['support_email'] ?? 'support@corefluxapp.com';

    echo "<p>Global settings saved.</p>";
}
?>
<!DOCTYPE html>
<html>
<head>
  <title>Global Settings</title>
  <link rel="stylesheet" href="../assets/css/style.css" />
</head>
<body>
  <div class="container">
    <h1>Global Settings</h1>
    <form method="post">
      <label for="maintenance_mode">Maintenance Mode:</label>
      <select name="maintenance_mode" id="maintenance_mode">
        <option value="off">Off</option>
        <option value="on">On</option>
      </select>
      <br/><br/>
      <label for="default_timezone">Default Timezone:</label>
      <input type="text" name="default_timezone" id="default_timezone" value="UTC" />
      <br/><br/>
      <label for="support_email">Support Email:</label>
      <input type="email" name="support_email" id="support_email" value="support@corefluxapp.com" />
      <br/><br/>
      <button type="submit">Save Settings</button>
    </form>
  </div>
</body>
</html>
