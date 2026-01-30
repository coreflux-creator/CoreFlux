<?php
require_once '../core/session.php';
require_once '../core/db_connection.php';

$tenant_id = $_GET['id'] ?? null;
if (!$tenant_id) {
    die("Missing tenant ID.");
}

$upload_dir = "../assets/img/tenants/";
$upload_path = $upload_dir . "tenant_" . $tenant_id . "_logo.png";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['logo'])) {
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_path)) {
        echo "<p>Logo uploaded successfully for Tenant ID: $tenant_id</p>";
    } else {
        echo "<p>Error uploading logo.</p>";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
  <title>Tenant Logo Upload</title>
  <link rel="stylesheet" href="../assets/css/style.css" />
</head>
<body>
  <div class="container">
    <h1>Upload Logo for Tenant ID <?= htmlspecialchars($tenant_id) ?></h1>
    <form method="post" enctype="multipart/form-data">
      <input type="file" name="logo" accept="image/png" required /><br/><br/>
      <button type="submit">Upload Logo</button>
    </form>
    <br/>
    <?php if (file_exists($upload_path)): ?>
      <p>Current Logo:</p>
      <img src="<?= $upload_path ?>" style="max-height: 100px;" />
    <?php endif; ?>
  </div>
</body>
</html>
