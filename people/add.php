<?php
require_once '../partials/layout_start.php';
require_once 'includes/people_helper.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $role = $_POST['role'];
    $tenantId = $_SESSION['tenant_id'];
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    createNewEmployee($pdo, $name, $email, $role, $isActive, $tenantId);

    header("Location: index.php");
    exit;
}
?>

<section class="dashboard-welcome">
  <h1>Add New Employee</h1>
</section>

<section class="dashboard-grid">
  <div class="card" style="grid-column: span 2;">
    <form method="POST">
      <label>Name</label>
      <input type="text" name="name" required />

      <label>Email</label>
      <input type="email" name="email" required />

      <label>Role</label>
      <select name="role" required>
        <option value="employee">Employee</option>
        <option value="approver">Approver</option>
        <option value="tenant_user">Tenant User</option>
      </select>

      <label>
        <input type="checkbox" name="is_active" value="1" checked />
        Active
      </label>

      <button type="submit" class="button">Create Employee</button>
    </form>
  </div>
</section>

<?php include '../partials/layout_end.php'; ?>
