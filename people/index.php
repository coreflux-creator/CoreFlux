<?php
require_once '../partials/layout_start.php';
require_once 'includes/people_helper.php';

$currentTenantId = $_SESSION['tenant_id'];
$currentUserId = $_SESSION['user_id'];

$employees = getAccessibleEmployees($pdo, $currentUserId, $currentTenantId);
?>

<section class="dashboard-welcome">
  <h1>Employee Directory</h1>
  <p>View and manage employees across your tenant and subtenants.</p>
</section>

<section class="dashboard-grid">
  <div class="card" style="grid-column: span 2;">
    <table class="data-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Email</th>
          <th>Role</th>
          <th>Tenant</th>
          <th>Start Date</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($employees as $emp): ?>
          <tr>
            <td><?= htmlspecialchars($emp['name']) ?></td>
            <td><?= htmlspecialchars($emp['email']) ?></td>
            <td><?= htmlspecialchars($emp['role']) ?></td>
            <td><?= htmlspecialchars($emp['tenant_name']) ?></td>
            <td><?= htmlspecialchars($emp['start_date']) ?></td>
            <td><?= $emp['is_active'] ? 'Active' : 'Inactive' ?></td>
            <td>
              <a href="view.php?id=<?= $emp['user_id'] ?>">View</a> |
              <a href="edit.php?id=<?= $emp['user_id'] ?>">Edit</a> |
              <a href="assign_approver.php?id=<?= $emp['user_id'] ?>">Assign</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php include '../partials/layout_end.php'; ?>
