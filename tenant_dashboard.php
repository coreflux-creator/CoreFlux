<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <link rel="stylesheet" href="assets/css/styles.css">
   <title>Tenant Admin Dashboard</title>
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
       <h1>Welcome to the Tenant Admin Dashboard</h1>
       <p>Manage your tenants and modules here.</p>
   </section>

   <!-- Stats Panel -->
   <section class="stats-panel">
       <div class="stats-card">
           <h3>Active Users</h3>
           <p>50</p>
       </div>
       <div class="stats-card">
           <h3>Pending Timesheets</h3>
           <p>8</p>
       </div>
       <div class="stats-card">
           <h3>Manage Roles</h3>
           <a href="user_roles.php">Configure Roles</a>
       </div>
   </section>
</body>
</html>
