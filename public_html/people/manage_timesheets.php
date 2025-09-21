<?php
require_once '../core/session.php';
require_once '../core/db.php';
require_once '../core/functions_timesheets.php';
require_once '../core/functions_users.php';

checkUserLoggedIn();
checkIsAdmin();

$employees = getAllEmployees(); // Assuming this function exists
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Timesheets</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <?php include '../partials/nav.php'; ?>

    <div class="container">
        <h2>All Timesheets</h2>

        <?php foreach ($employees as $emp): ?>
            <h3><?= htmlspecialchars($emp['name']) ?></h3>
            <?php
                $timesheets = getTimesheetsByEmployee($emp['id']);
                if (count($timesheets) === 0) {
                    echo "<p>No timesheets submitted.</p>";
                    continue;
                }
            ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Week Start</th>
                        <th>Week End</th>
                        <th>Hours</th>
                        <th>Status</th>
                        <th>Approver</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($timesheets as $ts): ?>
                        <tr>
                            <td><?= htmlspecialchars($ts['week_start']) ?></td>
                            <td><?= htmlspecialchars($ts['week_end']) ?></td>
                            <td><?= htmlspecialchars($ts['hours_worked']) ?></td>
                            <td><?= htmlspecialchars($ts['status'] ?? 'submitted') ?></td>
                            <td><?= htmlspecialchars($ts['approver_email']) ?></td>
                            <td>
                                <?php if (($ts['status'] ?? 'submitted') === 'submitted'): ?>
                                    <a href="../people/resend_timesheet_email.php?id=<?= $ts['id'] ?>">Resend Email</a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>
    </div>
</body>
</html>
