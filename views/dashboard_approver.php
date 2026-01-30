<?php
$userName = $_SESSION['name'] ?? 'Approver';
$tenantName = $_SESSION['tenant_name'] ?? 'Your Tenant';
?>

<section class="dashboard-welcome">
  <h1>Welcome, <?= htmlspecialchars($userName) ?> 👋</h1>
  <p>You’re currently viewing approval tasks for <strong><?= htmlspecialchars($tenantName) ?></strong></p>
</section>

<section class="dashboard-grid">
  <div class="card">
    <h2>Pending Approvals</h2>
    <p>Review and take action on timesheets submitted by your assigned employees.</p>
    <a href="/approvals/pending.php" class="button">Review Timesheets</a>
  </div>

  <div class="card">
    <h2>Approval History</h2>
    <p>Look back at what you’ve approved or rejected recently.</p>
    <a href="/approvals/history.php" class="button">View History</a>
  </div>

  <div class="card">
    <h2>Employee Info</h2>
    <p>See your assigned employees and their status.</p>
    <a href="/approvals/employee_list.php" class="button">View Employees</a>
  </div>

  <div class="card">
    <h2>Switch Tenant</h2>
    <p>Change to another tenant you’ve been granted access to.</p>
    <a href="/switch_tenant.php" class="button">Switch Tenant</a>
  </div>

  <div class="card">
    <h2>Settings</h2>
    <p>Update your approver profile and contact preferences.</p>
    <a href="/settings/index.php" class="button">Profile Settings</a>
  </div>
</section>
