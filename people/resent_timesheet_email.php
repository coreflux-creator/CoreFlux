<?php
require_once '../core/db.php';
require_once '../core/functions_email.php';
require_once '../core/functions_timesheets.php';

if (!isset($_GET['id'])) {
    die('Missing timesheet ID.');
}

$timesheet_id = intval($_GET['id']);
$timesheet = getTimesheetById($timesheet_id);

if (!$timesheet) {
    die('Timesheet not found.');
}

$employee_name = getEmployeeNameById($timesheet['employee_id']);
$token = bin2hex(random_bytes(16));
$expires = date('Y-m-d H:i:s', strtotime('+3 days'));

// Store token
$stmt = $pdo->prepare("INSERT INTO approval_tokens (timesheet_id, token, expires_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at)");
$stmt->execute([$timesheet_id, $token, $expires]);

$approve_url = "https://yourdomain.com/people/timesheet_approve.php?token=$token&action=approve";
$reject_url  = "https://yourdomain.com/people/timesheet_approve.php?token=$token&action=reject";

$subject = "Timesheet Approval Request";
$body = "
    <p>Hello,</p>
    <p>$employee_name has submitted a timesheet for your approval.</p>
    <p><strong>Week:</strong> {$timesheet['week_start']} to {$timesheet['week_end']}</p>
    <p><strong>Total Hours:</strong> {$timesheet['hours_worked']}</p>
    <p>
        <a href='$approve_url'>Approve</a> | 
        <a href='$reject_url'>Reject</a>
    </p>
    <p>This link will expire in 3 days.</p>
";

sendEmail($timesheet['approver_email'], $subject, $body);
header("Location: view_timesheets.php?resend=success");
exit;
?>
