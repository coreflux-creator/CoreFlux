<?php
session_start();
$user = $_SESSION['user'] ?? ['name' => 'User'];
$tenant = $_SESSION['tenant'] ?? 'HQ';
$subTenant = $_SESSION['subTenant'] ?? 'Branch1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CoreFlux Dashboard</title>
  <link rel="stylesheet" href="/assets/css/dashboard.css">
</head>
<body>

  <?php include __DIR__ . '/partials/header.php'; ?>

  <aside class="sidebar">
    <h3>Navigation</h3>
    <ul>
      <?php
        $module = $_GET['module'] ?? 'People';
        $path = __DIR__ . "/config/modules/{$module}_module_actions.json";
        if (file_exists($path)) {
          $actions = json_decode(file_get_contents($path), true);
          foreach ($actions['actions'] ?? [] as $action) {
            echo "<li><a href='{$action['link']}'>" . htmlspecialchars($action['name']) . "</a></li>";
          }
        }
      ?>
    </ul>
  </aside>

  <main class="content">

  <!-- content injected by page ends here -->

  </main>
  <?php include __DIR__ . '/partials/footer.php'; ?>
</body>
</html>
