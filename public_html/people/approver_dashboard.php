<?php
require_once '../core/session.php';
require_once '../core/db.php';
require_once '../core/functions_timesheets.php';

checkUserLoggedIn();

$email = $_SESSION['email'];
$stmt = $pdo->prepare("SELECT * FROM timesheets WHERE approver_email = ? ORDER BY week_start DESC");
$stmt->execute([$email]);
$timesheets = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Approver Dashboard</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<?php include '../partials/nav.php'; ?>

<div class="container">
    <h2>Timesheets You're Approving</h2>

    <table class="data-table">
        <thead>
            <tr>
                <th>Employee</th>
                <th>Week Start</th>
                <th>Week End</th>
                <th>Hours</th>
                <th>Status</th>
                <th>Submitted</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($timesheets as $ts): ?>
            <tr>
                <td><?= htmlspecialchars(getEmployeeNameById($ts['employee_id'])) ?></td>
                <td><?= htmlspecialchars($ts['week_start']) ?></td>
                <td><?= htmlspecialchars($ts['week_end']) ?></td>
                <td><?= htmlspecialchars($ts['hours_worked']) ?></td>
                <td><?= htmlspecialchars($ts['status']) ?></td>
                <td><?= htmlspecialchars($ts['created_at']) ?></td>
                <td>
                    <?php if ($ts['status'] === 'submitted'): ?>
                        <a href="resend_timesheet_email.php?id=<?= $ts['id'] ?>">Resend Email</a>
                    <?php else: ?>
                        <span>N/A</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
</body>
</html>
