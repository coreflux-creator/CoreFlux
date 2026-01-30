<?php
session_start();

// Dummy session simulation (replace with real session logic)
$_SESSION['user'] = [
    'first_name' => 'Kunal',
    'role' => 'admin',
    'avatar' => null,
    'tenants' => ['HQ', 'Branch1'],
    'modules' => [
        ['name' => 'People', 'route' => 'dashboard_dynamic.php?module=People'],
        ['name' => 'Finance', 'route' => 'dashboard_dynamic.php?module=Finance'],
        ['name' => 'Accounting', 'route' => 'dashboard_dynamic.php?module=Accounting'],
        ['name' => 'Tax', 'route' => 'dashboard_dynamic.php?module=Tax'],
        ['name' => 'Wealth Management', 'route' => 'dashboard_dynamic.php?module=Wealth'],
        ['name' => 'Reporting', 'route' => 'dashboard_dynamic.php?module=Reporting'],
        ['name' => 'CRM', 'route' => 'dashboard_dynamic.php?module=CRM']
    ]
];

// Set default module
$selectedModule = $_GET['module'] ?? 'People';

// Load module actions JSON
$moduleFile = "{$selectedModule}_module_actions.json";
$actions = [];
if (file_exists($moduleFile)) {
    $moduleData = json_decode(file_get_contents($moduleFile), true);
    foreach ($moduleData['actions'] as $action) {
        if (in_array($_SESSION['user']['role'], $action['roles'])) {
            $actions[] = $action;
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8" />
  <title>Dashboard - CoreFlux</title>
  <link rel="stylesheet" href="assets/css/dashboard.css" />
</head>
<body>
  <div class="header">
    <img src="assets/img/logo.png" alt="CoreFlux Logo" class="logo">
    <div class="menu">
      <div class="dropdown">
        <span>Modules ▾</span>
        <div class="dropdown-content">
          <?php foreach ($_SESSION['user']['modules'] as $mod): ?>
            <a href="<?= htmlspecialchars($mod['route']) ?>"><?= htmlspecialchars($mod['name']) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="dropdown">
        <span><?= htmlspecialchars($_SESSION['user']['tenants'][0]) ?> ▾</span>
        <div class="dropdown-content">
          <?php foreach ($_SESSION['user']['tenants'] as $tenant): ?>
            <a href="#"><?= htmlspecialchars($tenant) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
      <a href="#">Settings</a>
      <a href="#">User</a>
      <a href="#">Logout</a>
      <div class="avatar"><?= strtoupper(substr($_SESSION['user']['first_name'], 0, 1)) ?></div>
    </div>
  </div>

  <div class="sidebar">
    <strong>Navigation</strong>
    <?php foreach ($actions as $action): ?>
      <a href="<?= htmlspecialchars($action['route']) ?>"><?= htmlspecialchars($action['name']) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="main">
    <h2>Welcome back, <?= htmlspecialchars($_SESSION['user']['first_name']) ?>!</h2>
    <p>Your workspace and updates are below.</p>

    <div class="cards">
      <?php foreach ($actions as $action): ?>
        <div class="card">
          <img src="assets/icons/<?= htmlspecialchars($action['icon']) ?>" alt="<?= htmlspecialchars($action['name']) ?>">
          <h3><?= htmlspecialchars($action['name']) ?></h3>
          <p><?= htmlspecialchars($action['description'] ?? '') ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</body>
</html>
