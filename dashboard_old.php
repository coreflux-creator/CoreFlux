<?php
session_start();

if (!isset($_SESSION['user'])) {
  header("Location: login.html");
  exit;
}

$user = $_SESSION['user'];
$modules = $_SESSION['modules'] ?? [];
$activeModule = $_SESSION['active_module'] ?? null;
$tenant = $_SESSION['tenant'] ?? '';
$tenants = $user['tenants'] ?? [];

$moduleSlug = strtolower(str_replace(' ', '_', $activeModule['name']));
$heroPath = __DIR__ . "/assets/icons/hero-{$moduleSlug}.png";
$heroImage = file_exists($heroPath) ? "/assets/icons/hero-{$moduleSlug}.png" : "/assets/icons/hero-default.png";

// Determine allowed files
$page = $_GET['page'] ?? '';
$allowedRoutes = array_column($activeModule['actions'], 'route');
$allowedFiles = array_map(fn($r) => pathinfo($r, PATHINFO_FILENAME), $allowedRoutes);
$includePath = __DIR__ . "/people/{$page}.php";
?>

<?php
// Handle AJAX request (return only <main> content)
if (
  isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
) {
  ob_start();
  if ($page && in_array($page, $allowedFiles) && file_exists($includePath)) {
    include $includePath;
  } else {
    include __DIR__ . "/people/overview.php";
  }
  $mainContent = ob_get_clean();
  echo '<main class="main-content" id="main-content">' . $mainContent . '</main>';
  exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>CoreFlux Dashboard</title>
  <link rel="stylesheet" href="/assets/css/styles.css" />
  <style>
    body { margin: 0; font-family: sans-serif; background: #f4f6fa; }
    .top-nav {
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: #002c70;
      color: white;
      padding: 0.75rem 1.5rem;
    }
    .logo-container img { height: 36px; }
    .sidebar {
      width: 240px;
      background: #002c70;
      color: white;
      padding: 1.5rem 1rem;
      height: calc(100vh - 64px);
    }
    .sidebar h4 { color: #cfd8f0; font-size: 1rem; margin-bottom: 1rem; }
    .sidebar a {
      display: block;
      padding: 10px 12px;
      color: #cfd8f0;
      text-decoration: none;
      border-radius: 6px;
      font-size: 14px;
      margin-bottom: 4px;
    }
    .sidebar a:hover { background: rgba(255, 255, 255, 0.1); }
    .main-content {
      flex: 1;
      padding: 2rem;
      text-align: center;
    }
    .layout { display: flex; }
    select, .logout-button {
      padding: 6px;
      border-radius: 4px;
      margin-left: 0.5rem;
    }
    .logout-button {
      background-color: #004d99;
      color: white;
      border: none;
      cursor: pointer;
      text-decoration: none;
      padding: 6px 12px;
    }
    .hero-illustration {
      max-width: 300px;
      margin: 1.5rem auto;
    }
    .feature-icons {
      display: flex;
      justify-content: center;
      flex-wrap: wrap;
      gap: 30px;
      margin-top: 2rem;
    }
    .feature-icons .card {
      width: 140px;
      text-align: center;
    }
    .feature-icons .card img {
      width: 64px;
      height: 64px;
      margin-bottom: 0.5rem;
    }
  </style>
</head>
<body>

<header class="top-nav">
  <div class="logo-container">
    <img src="/assets/icons/logo-new.png" alt="CoreFlux Logo" />
  </div>

  <div>
    <form method="POST" action="update_module.php" style="display: inline;">
      <select name="module" onchange="this.form.submit()">
        <?php foreach ($modules as $mod): ?>
          <option value="<?= htmlspecialchars($mod['name']) ?>" <?= $mod['name'] === $activeModule['name'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($mod['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>

    <form method="POST" action="update_tenant.php" style="display: inline;">
      <select name="tenant" onchange="this.form.submit()">
        <?php foreach ($tenants as $t): ?>
          <option value="<?= htmlspecialchars($t) ?>" <?= $t === $tenant ? 'selected' : '' ?>>
            <?= htmlspecialchars($t) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>

    <a href="logout.php" class="logout-button">Logout</a>
  </div>
</header>

<div class="layout">
  <aside class="sidebar">
    <h4><?= htmlspecialchars($activeModule['name']) ?> Menu</h4>
    <?php foreach ($activeModule['actions'] as $action): ?>
      <?php
        $filename = pathinfo($action['route'], PATHINFO_FILENAME);
        $isAllowed = strtolower($user['role']) === 'admin' || $filename !== 'hiring_pipeline';
      ?>
      <?php if ($isAllowed): ?>
        <a href="dashboard.php?page=<?= $filename ?>">
          <?= htmlspecialchars($action['name']) ?>
        </a>
      <?php endif; ?>
    <?php endforeach; ?>
  </aside>

  <main class="main-content" id="main-content">
    <?php
      if ($page && in_array($page, $allowedFiles) && file_exists($includePath)) {
        include $includePath;
      } else {
        include __DIR__ . "/people/overview.php";
      }
    ?>
  </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const main = document.getElementById('main-content');

  document.body.addEventListener('click', (e) => {
    const link = e.target.closest('a');
    if (!link || !link.href.includes('dashboard.php?page=')) return;

    e.preventDefault();
    const url = new URL(link.href);
    const params = url.search;

    fetch('dashboard.php' + params, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(res => res.text())
      .then(html => {
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        const newContent = doc.querySelector('#main-content');
        if (newContent) {
          main.innerHTML = newContent.innerHTML;
          window.history.pushState({}, '', url.pathname + url.search);
        }
      })
      .catch(err => {
        console.error('Error loading content:', err);
        alert('Failed to load content.');
      });
  });
});
</script>

</body>
</html>
