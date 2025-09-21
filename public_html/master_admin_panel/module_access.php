<?php
require_once '../core/session.php';
require_once '../core/db_connection.php';

// Dummy list of modules
$modules = [
  'people' => 'People',
  'finance' => 'Finance',
  'accounting' => 'Accounting',
  'tax' => 'Tax',
  'wealth' => 'Wealth Management'
];

// Normally you'd fetch current tenant/module settings from DB
$tenant_id = $_GET['id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $enabled_modules = $_POST['modules'] ?? [];

    // Save to tenant_modules table (not shown)
    // Example: saveTenantModules($tenant_id, $enabled_modules);
    echo "<p>Modules updated successfully.</p>";
}
?>
<!DOCTYPE html>
<html>
<head>
  <title>Tenant Module Access - Master Admin</title>
  <link rel="stylesheet" href="../assets/css/style.css" />
</head>
<body>
  <div class="container">
    <h1>Module Access for Tenant ID <?= htmlspecialchars($tenant_id) ?></h1>
    <form method="post">
      <?php foreach ($modules as $key => $label): ?>
        <label>
          <input type="checkbox" name="modules[]" value="<?= $key ?>" />
          <?= $label ?>
        </label><br/>
      <?php endforeach; ?>
      <br/>
      <button type="submit">Save Modules</button>
    </form>
  </div>
</body>
</html>
