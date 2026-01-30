<?php
$user = $_SESSION['user'] ?? ['name' => 'User'];
$tenant = $_SESSION['tenant'] ?? 'HQ';
$subTenant = $_SESSION['subTenant'] ?? 'Branch1';
?>
<header class="top-header">
  <div class="logo-container">
<img src="https://www.corefluxapp.com/assets/icons/logo.png" alt="CoreFlux Logo" class="logo">

  </div>
  <nav class="top-nav">
    <div class="dropdown">
      <button class="dropbtn">Modules</button>
      <div class="dropdown-content">
        <a href="/dashboard_dynamic.php?module=People">People</a>
        <a href="/dashboard_dynamic.php?module=Finance">Finance</a>
        <a href="/dashboard_dynamic.php?module=Accounting">Accounting</a>
        <a href="/dashboard_dynamic.php?module=Tax">Tax</a>
        <a href="/dashboard_dynamic.php?module=Wealth">Wealth Management</a>
        <a href="/dashboard_dynamic.php?module=Reporting">Reporting</a>
        <a href="/dashboard_dynamic.php?module=CRM">CRM</a>
      </div>
    </div>

    <div class="dropdown">
      <button class="dropbtn"><?= htmlspecialchars($tenant) ?></button>
      <div class="dropdown-content">
        <a href="#"><?= htmlspecialchars($subTenant) ?></a>
      </div>
    </div>

    <a href="#">Settings</a>
    <a href="#">User</a>
    <a href="/logout.php">Logout</a>
    <div class="avatar"><?= strtoupper($user['name'][0]) ?></div>
  </nav>
</header>
