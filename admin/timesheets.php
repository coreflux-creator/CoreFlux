<?php
require_once '../core/session.php';
require_once '../core/db_connection.php';
require_once '../core/functions_timesheets.php';

$tenant_id = $_SESSION['tenant_id'];
// Extend to show timesheets across employees or filters
?>
<!DOCTYPE html>
<html>
<head>
  <title>Timesheets - Admin</title>
  <link rel="stylesheet" href="../assets/css/style.css" />
</head>
<body>
  <h1>Admin Timesheet View</h1>
  <p>This will list all submitted timesheets for review and management.</p>
</body>
</html>
