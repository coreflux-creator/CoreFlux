<?php
require_once 'includes/db.php';
require_once 'includes/functions_timesheet.php';

$employee_id = $_GET['employee_id'];
$timesheets = getTimesheetHistory($employee_id);
?>

<h2>Employee Timesheet History</h2>

<table>
    <tr>
        <th>Date</th>
        <th>Hours</th>
        <th>Project</th>
        <th>Status</th>
    </tr>
    <?php foreach ($timesheets as $row): ?>
    <tr>
        <td><?= $row['date'] ?></td>
        <td><?= $row['hours'] ?></td>
        <td><?= $row['project'] ?></td>
        <td><?= $row['status'] ?></td>
    </tr>
    <?php endforeach; ?>
</table>
