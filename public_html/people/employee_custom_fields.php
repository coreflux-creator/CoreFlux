<?php
require_once 'includes/db.php';
require_once 'includes/functions_custom_fields.php';

$employee_id = $_GET['employee_id'];
$fields = getCustomFieldsForEmployee($employee_id);
?>

<h2>Custom Field Values</h2>
<table>
    <tr>
        <th>Field</th>
        <th>Value</th>
    </tr>
    <?php foreach ($fields as $row): ?>
    <tr>
        <td><?= $row['label'] ?></td>
        <td><?= $row['value'] ?></td>
    </tr>
    <?php endforeach; ?>
</table>
