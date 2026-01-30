<?php
require_once '../core/session.php';
require_once '../core/db_connection.php';

$settings = [
    'default_timezone' => 'America/New_York',
    'email_from_address' => 'no-reply@corefluxapp.com',
    'support_contact_email' => 'support@coreflux.com',
    'default_design_mode' => 'abstract'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($settings as $key => &$value) {
        if (isset($_POST[$key])) {
            $value = $_POST[$key];
        }
    }
    // Save logic here (e.g. update global_config table)
    echo "<p>Platform settings updated.</p>";
}
?>
<!DOCTYPE html>
<html>
<head>
  <title>Platform Settings - Master Admin</title>
  <link rel="stylesheet" href="../assets/css/style.css" />
</head>
<body>
  <div class="container">
    <h1>Global Platform Settings</h1>
    <form method="post">
      <label>Default Timezone:</label><br/>
      <input type="text" name="default_timezone" value="<?= htmlspecialchars($settings['default_timezone']) ?>" /><br/><br/>

      <label>System "From" Email Address:</label><br/>
      <input type="email" name="email_from_address" value="<?= htmlspecialchars($settings['email_from_address']) ?>" /><br/><br/>

      <label>Support Contact Email:</label><br/>
      <input type="email" name="support_contact_email" value="<?= htmlspecialchars($settings['support_contact_email']) ?>" /><br/><br/>

      <label>Default Design Mode:</label><br/>
      <select name="default_design_mode">
        <option value="abstract" <?= $settings['default_design_mode'] == 'abstract' ? 'selected' : '' ?>>Abstract</option>
        <option value="swirl" <?= $settings['default_design_mode'] == 'swirl' ? 'selected' : '' ?>>Swirl</option>
        <option value="white" <?= $settings['default_design_mode'] == 'white' ? 'selected' : '' ?>>White</option>
        <option value="block" <?= $settings['default_design_mode'] == 'block' ? 'selected' : '' ?>>Block</option>
      </select><br/><br/>

      <button type="submit">Save Settings</button>
    </form>
  </div>
</body>
</html>
