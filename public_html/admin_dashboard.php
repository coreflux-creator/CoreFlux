<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <link rel="stylesheet" href="assets/css/styles.css">
   <title>Admin Dashboard</title>
</head>
<body>
   <!-- Header Section -->
   <header>
       <div class="logo-container">
           <img src="assets/icons/logo.png" alt="CoreFlux Logo" />
       </div>
       <nav>
           <ul>
               <li><a href="index.html">Home</a></li>
               <li><a href="profile.php">Profile</a></li>
               <li><a href="logout.php">Logout</a></li>
               <li><a href="tenant_switcher.php">Switch Tenant</a></li>
           </ul>
       </nav>
   </header>

   <!-- Hero Section -->
   <section class="hero">
       <h1>Welcome to the Admin Dashboard</h1>
       <p>Manage your platform and monitor all tenants.</p>
   </section>

   <!-- Stats Panel -->
   <section class="stats-panel">
       <div class="stats-card">
           <h3>Active Tenants</h3>
           <p>25</p>
       </div>
       <div class="stats-card">
           <h3>Total Users</h3>
           <p>120</p>
       </div>
       <div class="stats-card">
           <h3>Pending Approvals</h3>
           <p>5</p>
       </div>
   </section>

   <!-- Navigation to Features -->
   <section class="navigation">
       <h2>Manage Tenants</h2>
       <ul>
           <li><a href="tenant_management.php">View Tenants</a></li>
           <li><a href="tenant_settings.php">Tenant Settings</a></li>
       </ul>
   </section>
</body>
</html>
