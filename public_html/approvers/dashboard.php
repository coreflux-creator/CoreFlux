<?php
require_once '../core/session.php';
require_once '../core/db_connection.php';
require_once '../core/functions_timesheets.php';

$user_id = $_SESSION['user_id'] ?? null;
$tenant_id = $_SESSION['tenant_id'] ?? null;
if (!$user_id || !$tenant_id) {
    header("Location: ../login.html");
    exit;
}

// Placeholder for future: fetch assigned approvals by approver_email

?>
<!DOCTYPE html>
<html>
<head>
  <title>Approver Dashboard</title>
  <link rel="stylesheet" href="../assets/css/style.css" />
</head>
<body>
  <div class="container">
    <h1>Timesheet Approvals</h1>
    <p>Welcome, approver. This dashboard will list pending timesheets needing your review.</p>
    <!-- Future: table of pending timesheets, approve/reject buttons -->
  </div>
</body>
</html>
