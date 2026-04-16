<?php
/**
 * CoreFlux React SPA Entry Point
 */

require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/data.php';

initSession();

// Check if user is logged in
if (!isset($_SESSION['user'])) {
    header("Location: login.php?redirect=spa");
    exit;
}

// Serve the React SPA directly (inline to avoid path issues)
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <link rel="icon" href="/favicon.ico" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>CoreFlux Dashboard</title>
    <script type="module" crossorigin src="/spa-assets/index-DDATFzNd.js"></script>
    <link rel="stylesheet" crossorigin href="/spa-assets/index-DZC1Gezh.css">
  </head>
  <body>
    <div id="root"></div>
  </body>
</html>
