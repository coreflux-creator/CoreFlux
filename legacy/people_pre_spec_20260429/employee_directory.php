<h2>Employee Directory</h2>
<?php
$isAdmin = strtolower($user['role']) === 'admin';
$employees = [
  ['id' => 1, 'name' => 'Jane Doe', 'email' => 'jane@company.com'],
  ['id' => 2, 'name' => 'John Smith', 'email' => 'john@company.com']
];
?>

<table border="1" cellpadding="8" cellspacing="0" style="width: 90%; margin: 2rem auto;">
  <thead>
    <tr><th>Name</th><th>Email</th><?php if ($isAdmin): ?><th>Actions</th><?php endif; ?></tr>
  </thead>
  <tbody>
    <?php foreach ($employees as $emp): ?>
      <tr>
        <td><?= htmlspecialchars($emp['name']) ?></td>
        <td><?= htmlspecialchars($emp['email']) ?></td>
        <?php if ($isAdmin): ?>
          <td>
            <a href="edit_employee.php?id=<?= $emp['id'] ?>">Edit</a> |
            <a href="delete_employee.php?id=<?= $emp['id'] ?>">Delete</a>
          </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php if ($isAdmin): ?>
  <div style="text-align: center; margin-top: 1rem;">
    <a href="add_employee.php">Add New Employee</a>
  </div>
<?php endif; ?>
