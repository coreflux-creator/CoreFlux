<?php
$userName = $_SESSION['name'] ?? 'Master Admin';
?>

<section class="dashboard-welcome">
  <h1>Welcome, <?= htmlspecialchars($userName) ?> 👋</h1>
  <p>You are operating at the platform-wide level. All tenants and users are accessible from this view.</p>
</section>

<section class="dashboard-grid">
  <div class="card">
    <h2>Tenant Management</h2>
    <p>View, add, or configure all tenants and subtenants on the platform.</p>
    <a href="/master/tenants.php" class="button">Manage Tenants</a>
  </div>

  <div class="card">
    <h2>User Directory</h2>
    <p>Search and manage all registered users across the system.</p>
    <a href="/master/users.php" class="button">User Directory</a>
  </div>

  <div class="card">
    <h2>Audit Logs</h2>
    <p>Access platform-wide logs of actions across all tenants and modules.</p>
    <a href="/master/audit_logs.php" class="button">View Logs</a>
  </div>

  <div class="card">
    <h2>Global Settings</h2>
    <p>Set global features, modules, and branding defaults for the CoreFlux system.</p>
    <a href="/master/global_settings.php" class="button">Configure Settings</a>
  </div>

  <div class="card">
    <h2>Module Defaults</h2>
    <p>Enable or disable modules globally and configure defaults per new tenant.</p>
    <a href="/master/module_defaults.php" class="button">Module Access</a>
  </div>

  <div class="card">
    <h2>System Health</h2>
    <p>Monitor performance, email/SMS status, and database usage across tenants.</p>
    <a href="/master/system_health.php" class="button">System Tools</a>
  </div>
</section>
