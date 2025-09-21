<?php
require_once '../core/db.php';
require_once '../core/functions_timesheets.php';

$timesheet_id = $_GET['id'] ?? null;

if ($timesheet_id && resendApprovalEmail($timesheet_id)) {
    echo "Approval email resent successfully.";
} else {
    echo "Failed to resend approval email.";
}
?>
