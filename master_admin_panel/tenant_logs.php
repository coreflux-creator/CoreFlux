<?php
require_once '../core/session.php';
require_once '../core/db_connection.php';

$tenant_id = $_GET['id'] ?? null;
if (!$tenant_id) {
    die("Missing tenant ID.");
}

// Sample logs (in a real app, you'd fetch these from a database)
$logs = [
    ['timestamp' => '2025-06-01 14:00', 'event' => 'User login', 'user' => 'admin@tenant.com'],
    ['timestamp' => '2025-06-01 14:05', 'event' => 'Updated timesheet', 'user' => 'john@tenant.com'],
    ['timestamp' => '2025-06-01 14:15', 'event' => 'Added new employee', 'user' => 'hr@tenant.com'],
    ['timestamp' => '2025-06-01 14:20', 'event' => 'Changed password', 'user' => 'admin@tenant.com']
];
?>
<!DOCTYPE html>
<html>
<head>
  <title>Tenant Logs</title>
  <link rel="stylesheet" href="../assets/css/style.css" />
</head>
<body>
  <div class="container">
    <h1>Activity Logs for Tenant ID <?= htmlspecialchars($tenant_id) ?></h1>
    <table>
      <thead>
        <tr>
          <th>Timestamp</th>
          <th>Event</th>
          <th>User</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($logs as $log): ?>
          <tr>
            <td><?= $log['timestamp'] ?></td>
            <td><?= $log['event'] ?></td>
            <td><?= $log['user'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</body>
</html>
