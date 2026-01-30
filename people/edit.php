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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $role = $_POST['role'];
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    updateEmployee($pdo, $employeeId, $name, $email, $role, $isActive);

    header("Location: view.php?id=" . $employeeId);
    exit;
}
?>

<section class="dashboard-welcome">
  <h1>Edit Employee</h1>
</section>

<section class="dashboard-grid">
  <div class="card" style="grid-column: span 2;">
    <form method="POST">
      <label>Name</label>
      <input type="text" name="name" value="<?= htmlspecialchars($employee['name']) ?>" required />

      <label>Email</label>
      <input type="email" name="email" value="<?= htmlspecialchars($employee['email']) ?>" required />

      <label>Role</label>
      <select name="role" required>
        <option value="employee" <?= $employee['role'] === 'employee' ? 'selected' : '' ?>>Employee</option>
        <option value="approver" <?= $employee['role'] === 'approver' ? 'selected' : '' ?>>Approver</option>
        <option value="tenant_user" <?= $employee['role'] === 'tenant_user' ? 'selected' : '' ?>>Tenant User</option>
      </select>

      <label>
        <input type="checkbox" name="is_active" value="1" <?= $employee['is_active'] ? 'checked' : '' ?> />
        Active
      </label>

      <button type="submit" class="button">Save Changes</button>
    </form>
  </div>
</section>

<?php include '../partials/layout_end.php'; ?>
