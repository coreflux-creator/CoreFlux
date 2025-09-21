<?php include '../partials/header.php'; ?>

<div class="main-content">
  <h1>Admin Dashboard</h1>
  <p>Welcome to the CoreFlux Master Admin Panel. Use this dashboard to monitor and manage global system settings, tenants, and modules.</p>

  <div class="dashboard-grid">

    <div class="card stats-card">
      <h2>Total Tenants</h2>
      <p class="big-number">27</p>
    </div>

    <div class="card stats-card">
      <h2>Active Modules</h2>
      <p class="big-number">143</p>
    </div>

    <div class="card stats-card">
      <h2>Admin Users</h2>
      <p class="big-number">11</p>
    </div>

    <div class="card stats-card">
      <h2>Design Modes Set</h2>
      <p class="big-number">4</p>
    </div>

  </div>

  <div class="dashboard-widgets">

    <div class="card wide-card">
      <h2>Tenant Activity (Last 30 Days)</h2>
      <div id="tenantActivityChart" style="height: 250px; background: #f0f4f8; text-align: center; line-height: 250px;">
        [Tenant Activity Chart Placeholder]
      </div>
    </div>

    <div class="card wide-card">
      <h2>Recent Actions</h2>
      <ul class="action-list">
        <li>🛠 John Doe updated module settings for Tenant Alpha</li>
        <li>🎨 Switched design mode for Tenant Beta to "Swirl"</li>
        <li>🔐 Added new security policy to global settings</li>
        <li>👤 Created new admin user: Alexis S.</li>
      </ul>
    </div>

  </div>

</div>

<?php include '../partials/footer.php'; ?>
