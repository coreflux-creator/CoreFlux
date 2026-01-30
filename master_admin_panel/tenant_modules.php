<?php
require_once '../core/session.php';
require_once '../core/db_connection.php';

$tenant_id = $_GET['id'] ?? null;
if (!$tenant_id) {
    die("Missing tenant ID.");
}

// Simulated module access list
$available_modules = ['People', 'Finance', 'Accounting', 'Tax', 'Wealth Management'];
$enabled_modules = ['People', 'Tax']; // Pretend these are loaded from the DB

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $enabled_modules = $_POST['modules'] ?? [];
    echo "<p>Module settings updated for Tenant ID: $tenant_id</p>";
}
?>
<!DOCTYPE html>
<html>
<head>
  <title>Tenant Module Settings</title>
  <link rel="stylesheet" href="../assets/css/style.css" />
</head>
<body>
  <div class="container">
    <h1>Manage Module Access for Tenant ID <?= htmlspecialchars($tenant_id) ?></h1>
    <form method="post">
      <?php foreach ($available_modules as $module): ?>
        <label>
          <input type="checkbox" name="modules[]" value="<?= $module ?>" <?= in_array($module, $enabled_modules) ? 'checked' : '' ?> />
          <?= $module ?>
        </label><br/>
      <?php endforeach; ?>
      <br/>
      <button type="submit">Save Module Access</button>
    </form>
  </div>
</body>
</html>
