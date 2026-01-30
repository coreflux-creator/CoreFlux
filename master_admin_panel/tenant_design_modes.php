<?php
require_once '../core/session.php';
require_once '../core/db_connection.php';

$tenant_id = $_GET['id'] ?? null;
if (!$tenant_id) {
    die("Missing tenant ID.");
}

$current_mode = 'abstract'; // Simulate current mode

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_mode = $_POST['design_mode'] ?? 'abstract';
    echo "<p>Design mode updated for Tenant ID: $tenant_id</p>";
}

$modes = ['abstract', 'swirl', 'white', 'block'];
?>
<!DOCTYPE html>
<html>
<head>
  <title>Tenant Design Modes</title>
  <link rel="stylesheet" href="../assets/css/style.css" />
</head>
<body>
  <div class="container">
    <h1>Set Design Mode for Tenant ID <?= htmlspecialchars($tenant_id) ?></h1>
    <form method="post">
      <?php foreach ($modes as $mode): ?>
        <label>
          <input type="radio" name="design_mode" value="<?= $mode ?>" <?= $current_mode === $mode ? 'checked' : '' ?> />
          <?= ucfirst($mode) ?>
        </label><br/>
      <?php endforeach; ?>
      <br/>
      <button type="submit">Save Design Mode</button>
    </form>
  </div>
</body>
</html>
