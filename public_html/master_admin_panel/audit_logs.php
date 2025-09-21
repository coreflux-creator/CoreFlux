<?php
require_once '../core/session.php';
require_once '../core/db_connection.php';

// Dummy audit log data
$audit_logs = [
    ['timestamp' => '2025-06-09 12:00:00', 'user' => 'admin', 'action' => 'Logged in'],
    ['timestamp' => '2025-06-09 12:10:00', 'user' => 'admin', 'action' => 'Updated tenant branding'],
    ['timestamp' => '2025-06-09 12:15:00', 'user' => 'kunal', 'action' => 'Viewed audit logs'],
];

?>
<!DOCTYPE html>
<html>
<head>
  <title>Audit Logs</title>
  <link rel="stylesheet" href="../assets/css/style.css" />
</head>
<body>
  <div class="container">
    <h1>Audit Logs</h1>
    <table>
      <thead>
        <tr>
          <th>Timestamp</th>
          <th>User</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($audit_logs as $log): ?>
          <tr>
            <td><?= htmlspecialchars($log['timestamp']) ?></td>
            <td><?= htmlspecialchars($log['user']) ?></td>
            <td><?= htmlspecialchars($log['action']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</body>
</html>
