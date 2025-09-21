<?php
session_start();
if (!isset($_SESSION['user'])) {
  header("Location: login.html");
  exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Dashboard – CoreFlux</title>
  <style>
    body {
      margin: 0;
      font-family: 'Segoe UI', sans-serif;
      display: flex;
      background: #f7f9fb;
    }
    .sidebar {
      width: 220px;
      background: #2d3748;
      color: white;
      height: 100vh;
      padding: 1rem;
    }
    .sidebar h2 {
      font-size: 1.2rem;
      margin-bottom: 2rem;
    }
    .sidebar a {
      display: block;
      color: white;
      text-decoration: none;
      margin: 1rem 0;
    }
    .sidebar a:hover {
      text-decoration: underline;
    }
    .main {
      flex: 1;
      padding: 2rem;
    }
    .topbar {
      background: #183d56;
      color: white;
      padding: 1rem 2rem;
    }
    .topbar span {
      float: right;
    }
    h1 {
      color: #183d56;
    }
  </style>
</head>
<body>

  <div class="sidebar">
    <h2>CoreFlux</h2>
    <a href="dashboard.php">Dashboard</a>
    <a href="admin/timesheets.php">Timesheets</a>
    <a href="admin/placements.php">Placements</a>
    <a href="auth/logout.php">Logout</a>
  </div>

  <div class="main">
    <div class="topbar">
      Welcome, <?php echo htmlspecialchars($_SESSION['user']); ?>
      <span><a href="auth/logout.php" style="color:white;">Logout</a></span>
    </div>
    <h1>Dashboard</h1>
    <p>This is your workspace. Select a module from the sidebar to begin.</p>
  </div>

</body>
</html>
