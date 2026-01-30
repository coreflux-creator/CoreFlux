<?php
require_once '../core/session.php';
require_once '../core/db_connection.php';
require_once '../core/functions_custom_fields.php';

$tenant_id = $_SESSION['tenant_id'];
$module = $_GET['module'] ?? 'people';

$fields = getCustomFields($tenant_id, $module);
?>
<!DOCTYPE html>
<html>
<head>
  <title>Custom Fields - Admin</title>
  <link rel="stylesheet" href="../assets/css/style.css" />
</head>
<body>
  <h1>Custom Fields for <?= htmlspecialchars($module) ?></h1>
  <table>
    <tr><th>Label</th><th>Type</th><th>Required</th></tr>
    <?php foreach ($fields as $field): ?>
    <tr>
      <td><?= htmlspecialchars($field['label']) ?></td>
      <td><?= htmlspecialchars($field['field_type']) ?></td>
      <td><?= $field['required'] ? 'Yes' : 'No' ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</body>
</html>
