<?php
require_once '../partials/layout_start.php';
require_once 'includes/people_helper.php';

if (!isset($_GET['id'])) {
    echo "<p>No employee selected.</p>";
    include '../partials/layout_end.php';
    exit;
}

$employeeId = (int) $_GET['id'];
$employee = getEmployeeProfile($pdo, $employeeId);

if (!$employee) {
    echo "<p>Employee not found.</p>";
    include '../partials/layout_end.php';
    exit;
}
?>

<section class="dashboard-welcome">
  <h1>Employee Profile: <?= htmlspecialchars($employee['name']) ?></h1>
  <p><?= htmlspecialchars($employee['email']) ?> • <?= htmlspecialchars($employee['role']) ?> • <?= htmlspecialchars($employee['tenant_name']) ?></p>
</section>

<section class="dashboard-grid">
  <div class="card">
    <h2>Status</h2>
    <p><?= $employee['is_active'] ? 'Active' : 'Inactive' ?></p>
    <p>Start Date: <?= htmlspecialchars($employee['start_date']) ?></p>
  </div>

  <div class="card">
    <h2>Assigned Approver(s)</h2>
    <ul>
      <?php foreach ($employee['approvers'] as $approver): ?>
        <li><?= htmlspecialchars($approver['name']) ?> (<?= htmlspecialchars($approver['email']) ?>)</li>
      <?php endforeach; ?>
    </ul>
    <a href="assign_approver.php?id=<?= $employeeId ?>" class="button">Update Approvers</a>
  </div>

  <div class="card" style="grid-column: span 2;">
    <h2>Timesheet Summary</h2>
    <ul>
      <?php foreach ($employee['timesheets'] as $ts): ?>
        <li><?= $ts['week_start'] ?> – <?= $ts['status'] ?> (<?= $ts['hours_worked'] ?> hrs)</li>
      <?php endforeach; ?>
    </ul>
    <a href="/timesheets/my_timesheets.php?employee_id=<?= $employeeId ?>" class="button">View Full History</a>
  </div>
</section>

<?php include '../partials/layout_end.php'; ?>
