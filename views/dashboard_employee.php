<?php
$userName = $_SESSION['name'] ?? 'Employee';
$tenantName = $_SESSION['tenant_name'] ?? 'Your Tenant';
?>

<section class="dashboard-welcome">
  <h1>Welcome, <?= htmlspecialchars($userName) ?> 👋</h1>
  <p>You’re currently working under <strong><?= htmlspecialchars($tenantName) ?></strong></p>
</section>

<section class="dashboard-grid">
  <div class="card">
    <h2>Submit Timesheet</h2>
    <p>Enter your hours for this week. You can save drafts or submit for approval.</p>
    <a href="/timesheets/my_timesheets.php" class="button">Submit Timesheet</a>
  </div>

  <div class="card">
    <h2>Timesheet History</h2>
    <p>View previously submitted timesheets and their approval status.</p>
    <a href="/timesheets/history.php" class="button">View History</a>
  </div>

  <div class="card">
    <h2>Placement Info</h2>
    <p>See the clients, projects, or roles assigned to you under this tenant.</p>
    <a href="/placements/my_info.php" class="button">My Placement</a>
  </div>

  <div class="card">
    <h2>Switch Tenant</h2>
    <p>Change to another tenant you're assigned to without logging out.</p>
    <a href="/switch_tenant.php" class="button">Switch Tenant</a>
  </div>

  <div class="card">
    <h2>Settings</h2>
    <p>Manage your name, password, and notification preferences.</p>
    <a href="/settings/index.php" class="button">Profile Settings</a>
  </div>
</section>
