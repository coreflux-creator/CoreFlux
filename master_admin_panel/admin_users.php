<?php
require_once '../core/session.php';
require_once '../core/db_connection.php';

// Placeholder: fetch list of admin users
$admins = [
    ['id' => 1, 'name' => 'Super Admin', 'email' => 'admin@coreflux.com', 'role' => 'super'],
    ['id' => 2, 'name' => 'Platform Support', 'email' => 'support@coreflux.com', 'role' => 'support']
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle add/edit/delete admin logic
    echo "<p>Admin user changes saved.</p>";
}
?>
<!DOCTYPE html>
<html>
<head>
  <title>Admin Users - Master Admin</title>
  <link rel="stylesheet" href="../assets/css/style.css" />
</head>
<body>
  <div class="container">
    <h1>Platform Admin Users</h1>
    <table>
      <thead>
        <tr><th>Name</th><th>Email</th><th>Role</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php foreach ($admins as $admin): ?>
        <tr>
          <td><?= htmlspecialchars($admin['name']) ?></td>
          <td><?= htmlspecialchars($admin['email']) ?></td>
          <td><?= htmlspecialchars($admin['role']) ?></td>
          <td>
            <a href="#">Edit</a> |
            <a href="#">Remove</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <h2>Add New Admin</h2>
    <form method="post">
      <input type="text" name="name" placeholder="Full Name" required /><br/>
      <input type="email" name="email" placeholder="Email" required /><br/>
      <select name="role">
        <option value="super">Super Admin</option>
        <option value="support">Support Staff</option>
      </select><br/><br/>
      <button type="submit">Add Admin</button>
    </form>
  </div>
</body>
</html>
