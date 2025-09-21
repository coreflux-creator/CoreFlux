<?php
require_once 'core/session.php';
requireLogin();
require_once 'core/functions_timesheets.php';
require_once 'core/functions_custom_fields.php';

$user = getCurrentUser();
$employee_id = $user['id']; // Assuming user is employee
$tenant_id = $user['tenant_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $week_start = $_POST['week_start'];
    $week_end = $_POST['week_end'];
    $hours_worked = $_POST['hours_worked'];
    $approver_email = $_POST['approver_email'];

    submitTimesheet($employee_id, $week_start, $week_end, $hours_worked, $approver_email);
    echo "Timesheet submitted.";
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Submit Timesheet</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <?php include 'partials/nav.php'; ?>
    <h2>Submit Timesheet</h2>
    <form method="POST">
        <label>Week Start:</label><br>
        <input type="date" name="week_start" required><br><br>

        <label>Week End:</label><br>
        <input type="date" name="week_end" required><br><br>

        <label>Total Hours Worked:</label><br>
        <input type="number" name="hours_worked" step="0.01" required><br><br>

        <label>Approver Email:</label><br>
        <input type="email" name="approver_email" required><br><br>

        <button type="submit">Submit Timesheet</button>
    </form>
</body>
</html>
