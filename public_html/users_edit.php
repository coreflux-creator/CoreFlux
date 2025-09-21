<?php
require_once '../config/db.php';
require_once '../helpers/custom_field_helpers.php';

$tenant_id = 1;
$module = 'users';
$record_id = $_GET['id'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    save_custom_values($pdo, $module, $record_id, $_POST, $tenant_id);
    echo "<p>Saved successfully.</p>";
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit User</title>
    <link rel="stylesheet" href="../assets/styles.css">
</head>
<body>
    <h1>Edit User</h1>
    <form method="POST">
        <input type="text" name="username" placeholder="Username"><br>
        <input type="email" name="email" placeholder="Email"><br>

        <h2>Custom Fields</h2>
        <?php render_custom_fields($pdo, $module, $record_id, $tenant_id); ?>

        <button type="submit">Save</button>
    </form>
</body>
</html>