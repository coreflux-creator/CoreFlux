<?php
require_once '../core/session.php';
require_once '../core/db_connection.php';

$tenant_id = $_GET['id'] ?? null;
if (!$tenant_id) {
    die("Missing tenant ID.");
}

$settings = [
  'timezone' => 'America/New_York',
  'date_format' => 'm/d/Y',
  'language' => 'en'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings['timezone'] = $_POST['timezone'] ?? $settings['timezone'];
    $settings['date_format'] = $_POST['date_format'] ?? $settings['date_format'];
    $settings['language'] = $_POST['language'] ?? $settings['language'];
    echo "<p>Settings saved for Tenant ID: $tenant_id</p>";
}
?>
<!DOCTYPE html>
<html>
<head>
  <title>Tenant Settings</title>
  <link rel="stylesheet" href="../assets/css/style.css" />
</head>
<body>
  <div class="container">
    <h1>Tenant Settings for ID <?= htmlspecialchars($tenant_id) ?></h1>
    <form method="post">
      <label>Timezone:
        <input type="text" name="timezone" value="<?= htmlspecialchars($settings['timezone']) ?>" />
      </label><br/><br/>
      <label>Date Format:
        <input type="text" name="date_format" value="<?= htmlspecialchars($settings['date_format']) ?>" />
      </label><br/><br/>
      <label>Language:
        <input type="text" name="language" value="<?= htmlspecialchars($settings['language']) ?>" />
      </label><br/><br/>
      <button type="submit">Save Settings</button>
    </form>
  </div>
</body>
</html>
