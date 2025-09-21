<?php
require_once 'includes/db.php';
require_once 'includes/functions_timesheet.php';

$user_id = $_SESSION['user_id'];
$timesheets = getTimesheetHistory($user_id);
?>

<h2>My Timesheet History</h2>
<a href="submit_timesheet.php">Submit New Timesheet</a>

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
