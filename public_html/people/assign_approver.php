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
$allApprovers = getAllApprovers($pdo, $_SESSION['tenant_id']);
$currentApprovers = getApproverIdsForEmployee($pdo, $employeeId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedIds = $_POST['approvers'] ?? [];
    assignApproversToEmployee($pdo, $employeeId, $selectedIds);
    header("Location: view.php?id=" . $employeeId);
    exit;
}
?>

<section class="dashboard-welcome">
  <h1>Assign Approvers: <?= htmlspecialchars($employee['name']) ?></h1>
</section>

<section class="dashboard-grid">
  <div class="card" style="grid-column: span 2;">
    <form method="POST">
      <label for="approvers">Select Approvers</label>
      <select name="approvers[]" multiple size="6" style="width: 100%;">
        <?php foreach ($allApprovers as $approver): ?>
          <option value="<?= $approver['id'] ?>" <?= in_array($approver['id'], $currentApprovers) ? 'selected' : '' ?>>
            <?= htmlspecialchars($approver['name']) ?> (<?= htmlspecialchars($approver['email']) ?>)
          </option>
        <?php endforeach; ?>
      </select>

      <button type="submit" class="button" style="margin-top: 1re
