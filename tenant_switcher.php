<?php
session_start();

// Assuming $_SESSION['user_role'] and $_SESSION['tenant_id'] are already set
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Update tenant ID based on selected tenant
    $_SESSION['tenant_id'] = $_POST['tenant_id'];
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Fetch available tenants for the user from the database
$availableTenants = array('Tenant 1', 'Tenant 2', 'Tenant 3');  // Example, fetch from DB
?>

<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <link rel="stylesheet" href="assets/css/styles.css">
   <title>Switch Tenant</title>
</head>
<body>
   <header>
       <div class="logo-container">
           <img src="assets/icons/logo.png" alt="CoreFlux Logo" />
       </div>
   </header>

   <section class="tenant-switcher">
       <h2>Select a Tenant</h2>
       <form action="tenant_switcher.php" method="POST">
           <select name="tenant_id">
               <?php foreach ($availableTenants as $tenant) { ?>
                   <option value="<?= $tenant ?>"><?= $tenant ?></option>
               <?php } ?>
           </select>
           <button type="submit">Switch Tenant</button>
       </form>
   </section>
</body>
</html>
