<?php
require_once '../core/session.php';
require_once '../core/db_connection.php';

$tenant_id = $_GET['id'] ?? null;
if (!$tenant_id) {
    die("Missing tenant ID.");
}

// Sample default branding settings
$branding = [
  'logo_url' => '',
  'primary_color' => '#005eff',
  'secondary_color' => '#f5f5f5',
  'footer_text' => 'Powered by CoreFlux'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $branding['logo_url'] = $_POST['logo_url'] ?? $branding['logo_url'];
    $branding['primary_color'] = $_POST['primary_color'] ?? $branding['primary_color'];
    $branding['secondary_color'] = $_POST['secondary_color'] ?? $branding['secondary_color'];
    $branding['footer_text'] = $_POST['footer_text'] ?? $branding['footer_text'];
    echo "<p>Branding updated for Tenant ID: $tenant_id</p>";
}
?>
<!DOCTYPE html>
<html>
<head>
  <title>Tenant Branding</title>
  <link rel="stylesheet" href="../assets/css/style.css" />
</head>
<body>
  <div class="container">
    <h1>Tenant Branding for ID <?= htmlspecialchars($tenant_id) ?></h1>
    <form method="post">
      <label>Logo URL:
        <input type="text" name="logo_url" value="<?= htmlspecialchars($branding['logo_url']) ?>" />
      </label><br/><br/>
      <label>Primary Color:
        <input type="color" name="primary_color" value="<?= htmlspecialchars($branding['primary_color']) ?>" />
      </label><br/><br/>
      <label>Secondary Color:
        <input type="color" name="secondary_color" value="<?= htmlspecialchars($branding['secondary_color']) ?>" />
      </label><br/><br/>
      <label>Footer Text:
        <input type="text" name="footer_text" value="<?= htmlspecialchars($branding['footer_text']) ?>" />
      </label><br/><br/>
      <button type="submit">Save Branding</button>
    </form>
  </div>
</body>
</html>
