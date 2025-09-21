<?php
require_once 'core/session.php';
requireLogin();
require_once 'core/functions_timesheets.php';

$user = getCurrentUser();
$employee_id = $user['id'];

$timesheets = getTimesheetsByEmployee($employee_id);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Timesheet History</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <?php include 'partials/nav.php'; ?>
    <h2>My Timesheet Submissions</h2>
    <table>
        <tr>
            <th>Week Start</th>
            <th>Week End</th>
            <th>Hours</th>
            <th>Status</th>
        </tr>
        <?php foreach ($timesheets as $ts): ?>
        <tr>
            <td><?= $ts['week_start'] ?></td>
            <td><?= $ts['week_end'] ?></td>
            <td><?= $ts['hours_worked'] ?></td>
            <td><?= $ts['status'] ?? 'Pending' ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</body>
</html>
