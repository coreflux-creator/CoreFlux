<?php
require_once '../core/session.php';
require_once '../core/db_connection.php';

$tenant_id = $_GET['id'] ?? null;
if (!$tenant_id) {
    die("Missing tenant ID.");
}

// Simulated delete logic
$deleted = true; // Replace with actual SQL delete if needed

?>
<!DOCTYPE html>
<html>
<head>
  <title>Delete Tenant</title>
  <link rel="stylesheet" href="../assets/css/style.css" />
</head>
<body>
  <div class="container">
    <h1>Delete Tenant</h1>
    <?php if ($deleted): ?>
      <p>Tenant ID <?= htmlspecialchars($tenant_id) ?> has been deleted.</p>
    <?php else: ?>
      <p>There was a problem deleting Tenant ID <?= htmlspecialchars($tenant_id) ?>.</p>
    <?php endif; ?>
    <a href="tenants.php">← Back to Tenant List</a>
  </div>
</body>
</html>
