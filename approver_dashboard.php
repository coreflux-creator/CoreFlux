<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <link rel="stylesheet" href="assets/css/styles.css">
   <title>Approver Dashboard</title>
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
       <h1>Welcome to the Approver Dashboard</h1>
       <p>Approve or reject timesheets here.</p>
   </section>

   <!-- Timesheet Approval Section -->
   <section class="timesheet-approval">
       <h3>Timesheets Pending Approval</h3>
       <ul>
           <li><a href="timesheet_view.php?timesheet_id=1">Employee 1</a></li>
           <li><a href="timesheet_view.php?timesheet_id=2">Employee 2</a></li>
       </ul>
   </section>
</body>
</html>
