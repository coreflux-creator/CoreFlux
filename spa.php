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

// Find the built assets dynamically
$assetsDir = __DIR__ . '/spa-assets';
$jsFile = '';
$cssFile = '';

if (is_dir($assetsDir)) {
    $files = scandir($assetsDir);
    foreach ($files as $file) {
        if (preg_match('/^index-.*\.js$/', $file)) {
            $jsFile = $file;
        }
        if (preg_match('/^index-.*\.css$/', $file)) {
            $cssFile = $file;
        }
    }
}

// Fallback if assets not found
if (empty($jsFile) || empty($cssFile)) {
    echo "Error: React app assets not found. Please ensure spa-assets folder exists.";
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <link rel="icon" href="/favicon.ico" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>CoreFlux Dashboard</title>
    <script type="module" crossorigin src="/spa-assets/<?php echo $jsFile; ?>"></script>
    <link rel="stylesheet" crossorigin href="/spa-assets/<?php echo $cssFile; ?>">
  </head>
  <body>
    <div id="root"></div>
  </body>
</html>
