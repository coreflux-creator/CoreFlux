<?php
require_once '../core/session.php';
require_once '../core/db_connection.php';

$tenant_id = $_GET['id'] ?? null;
if (!$tenant_id) {
    die("Missing tenant ID.");
}

$current_settings = [
    'from_name' => 'CoreFlux',
    'from_email' => 'no-reply@corefluxapp.com',
    'reply_to' => 'support@corefluxapp.com',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_settings['from_name'] = $_POST['from_name'];
    $current_settings['from_email'] = $_POST['from_email'];
    $current_settings['reply_to'] = $_POST['reply_to'];
    echo "<p>Email branding settings updated for Tenant ID: $tenant_id</p>";
}
?>
<!DOCTYPE html>
<html>
<head>
  <title>Tenant Email Branding</title>
  <link rel="stylesheet" href="../assets/css/style.css" />
</head>
<body>
  <div class="container">
    <h1>Email Branding Settings for Tenant ID <?= htmlspecialchars($tenant_id) ?></h1>
    <form method="post">
      <label>From Name:</label><br/>
      <input type="text" name="from_name" value="<?= htmlspecialchars($current_settings['from_name']) ?>" /><br/><br/>

      <label>From Email:</label><br/>
      <input type="email" name="from_email" value="<?= htmlspecialchars($current_settings['from_email']) ?>" /><br/><br/>

      <label>Reply-To Email:</label><br/>
      <input type="email" name="reply_to" value="<?= htmlspecialchars($current_settings['reply_to']) ?>" /><br/><br/>

      <button type="submit">Save Email Branding</button>
    </form>
  </div>
</body>
</html>
