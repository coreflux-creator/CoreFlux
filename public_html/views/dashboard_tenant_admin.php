<?php
$tenantName = $_SESSION['tenant_name'] ?? 'Your Tenant';
$userName = $_SESSION['name'] ?? 'Tenant Admin';
?>

<section class="dashboard-welcome">
  <h1>Welcome, <?= htmlspecialchars($userName) ?> 👋</h1>
  <p>You’re viewing the dashboard for <strong><?= htmlspecialchars($tenantName) ?></strong></p>
</section>

<section class="dashboard-grid">
  <div class="card">
    <h2>Manage Users</h2>
    <p>Create, edit, or remove users within this tenant and subtenants.</p>
    <a href="/people/manage_users.php" class="button">Manage Users</a>
  </div>

  <div class="card">
    <h2>Module Access</h2>
    <p>Enable or disable modules for different users and roles.</p>
    <a href="/admin/module_access.php" class="button">Configure Modules</a>
  </div>

  <div class="card">
    <h2>Custom Fields</h2>
    <p>Create and assign drag-and-drop custom fields to forms across modules.</p>
    <a href="/admin/custom_fields.php" class="button">Edit Fields</a>
  </div>

  <div class="card">
    <h2>Reports & Analytics</h2>
    <p>Build and run custom reports across all modules and subtenants.</p>
    <a href="/reports/index.php" class="button">View Reports</a>
  </div>

  <div class="card">
    <h2>Switch Tenant</h2>
    <p>Jump between tenants/subtenants you have access to without re-login.</p>
    <a href="/switch_tenant.php" class="button">Switch</a>
  </div>

  <div class="card">
    <h2>Settings</h2>
    <p>Configure branding, layout, and user permissions for your tenant.</p>
    <a href="/settings/index.php" class="button">Go to Settings</a>
  </div>
</section>
