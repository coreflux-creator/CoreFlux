<?php
require_once 'includes/db.php';
require_once 'includes/functions_custom_fields.php';

$employee_id = $_GET['id'];
$employee = getEmployeeById($employee_id);
$custom_fields = getCustomFieldsForEmployee($employee_id);
?>

<h2>Employee Profile</h2>
<p>Name: <?= $employee['name'] ?></p>
<p>Email: <?= $employee['email'] ?></p>

<h3>Custom Fields</h3>
<ul>
<?php foreach ($custom_fields as $field): ?>
    <li><strong><?= htmlspecialchars($field['label']) ?>:</strong> <?= htmlspecialchars($field['value']) ?></li>
<?php endforeach; ?>
</ul>
