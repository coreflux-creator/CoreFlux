<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/functions_timesheet.php';

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

echo "<h1>Welcome to CoreFlux</h1>";

if ($user_role === 'admin') {
    echo "<a href='employee_profile.php'>Manage Employees</a><br>";
    echo "<a href='approver_dashboard.php'>Approver Dashboard</a><br>";
    echo "<a href='view_timesheet.php'>Timesheet Entries</a><br>";
} elseif ($user_role === 'approver') {
    echo "<a href='approver_dashboard.php'>View Submissions</a><br>";
} else {
    echo "<a href='my_timesheets.php'>Submit/View My Timesheets</a><br>";
}
?>
