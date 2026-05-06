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

// Find the built assets dynamically.
//
// IMPORTANT: We pick the bundle with the most recent mtime, NOT the
// alphabetically-last one. Vite emits content-hashed filenames like
// `index-7wDAi7LA.js`. Old bundles from previous builds linger in this
// directory until a deploy script cleans them up — and the previous
// alphabetical loop would non-deterministically serve whichever happened
// to sort last (often an older bundle), making fresh deploys appear to
// have no effect. update.php now also prunes stale bundles after each
// successful pull, but mtime-based selection here is the belt-and-suspenders
// guarantee that the newest file wins regardless.
$assetsDir = __DIR__ . '/spa-assets';
$jsFile = '';
$cssFile = '';

if (is_dir($assetsDir)) {
    $jsCandidate  = ['name' => '', 'mtime' => 0];
    $cssCandidate = ['name' => '', 'mtime' => 0];
    foreach (scandir($assetsDir) as $file) {
        $path = $assetsDir . '/' . $file;
        if (preg_match('/^index-.*\.js$/', $file) && filemtime($path) > $jsCandidate['mtime']) {
            $jsCandidate = ['name' => $file, 'mtime' => filemtime($path)];
        }
        if (preg_match('/^index-.*\.css$/', $file) && filemtime($path) > $cssCandidate['mtime']) {
            $cssCandidate = ['name' => $file, 'mtime' => filemtime($path)];
        }
    }
    $jsFile  = $jsCandidate['name'];
    $cssFile = $cssCandidate['name'];
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
    <link rel="manifest" href="/spa-assets/manifest.webmanifest" />
    <meta name="theme-color" content="#0f172a" />
    <meta name="apple-mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-status-bar-style" content="default" />
    <meta name="apple-mobile-web-app-title" content="CoreFlux" />
    <script type="module" crossorigin src="/spa-assets/<?php echo $jsFile; ?>"></script>
    <link rel="stylesheet" crossorigin href="/spa-assets/<?php echo $cssFile; ?>">
  </head>
  <body>
    <div id="root"></div>
    <script>
      if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
          navigator.serviceWorker.register('/spa-assets/sw.js').catch(function () { /* offline-first nice-to-have */ });
        });
      }
    </script>
  </body>
</html>
