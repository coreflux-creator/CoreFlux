<?php
require_once '../core/session.php';
require_once '../core/db_connection.php';

$tenant_id = $_GET['id'] ?? null;
if (!$tenant_id) {
    die("Missing tenant ID.");
}

// Mockup: Fetch users for the given tenant
$users = [
  ['id' => 1, 'name' => 'Alice Smith', 'email' => 'alice@example.com'],
  ['id' => 2, 'name' => 'Bob Johnson', 'email' => 'bob@example.com']
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // You would normally process new user creation or updates here
    echo "<p>User changes saved for Tenant ID: $tenant_id</p>";
}
?>
<!DOCTYPE html>
<html>
<head>
  <title>Tenant Users</title>
  <link rel="stylesheet" href="../assets/css/style.css" />
</head>
<body>
  <div class="container">
    <h1>Tenant Users for ID <?= htmlspecialchars($tenant_id) ?></h1>
    <form method="post">
      <table border="1" cellpadding="6" cellspacing="0">
        <tr>
          <th>ID</th><th>Name</th><th>Email</th>
        </tr>
        <?php foreach ($users as $user): ?>
        <tr>
          <td><?= $user['id'] ?></td>
          <td><?= htmlspecialchars($user['name']) ?></td>
          <td><?= htmlspecialchars($user['email']) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
      <br/>
      <button type="submit">Save Changes</button>
    </form>
  </div>
</body>
</html>
