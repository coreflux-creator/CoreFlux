<?php
function generateTimesheetEmail($approverName, $timesheetId) {
    $baseUrl = "https://corefluxapp.com/timesheets/approve.php";
    $approveUrl = "$baseUrl?id=$timesheetId&action=approve";
    $rejectUrl = "$baseUrl?id=$timesheetId&action=reject";

    return "
        <p>Hi $approverName,</p>
        <p>You have a new timesheet submission awaiting your review.</p>
        <p>
            <a href='$approveUrl' style='padding:10px 20px;background:#28a745;color:white;text-decoration:none;margin-right:10px;'>Approve</a>
            <a href='$rejectUrl' style='padding:10px 20px;background:#dc3545;color:white;text-decoration:none;'>Reject</a>
        </p>
        <p>Thanks,<br>CoreFlux Timesheets</p>
    ";
}
?>
