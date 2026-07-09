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

// Find the built assets deterministically.
//
// Vite emits content-hashed filenames and old bundles can linger after Git
// deploys. Guessing by alphabetical order or mtime lets the app boot an older
// JS bundle with a newer CSS file, which makes production look unchanged after
// a successful push. The built dashboard/dist/index.html is the source of
// truth; .deploy-version is the fallback stamp written by the bundle sync.
$assetsDir = __DIR__ . '/spa-assets';
$jsFile = '';
$cssFile = '';

function corefluxSpaPickAssetFromDist(string $root, string $ext): string
{
    $distHtml = $root . '/dashboard/dist/index.html';
    if (!is_file($distHtml)) return '';
    $html = (string) file_get_contents($distHtml);
    if (!preg_match_all('#/(?:spa-assets|assets)/(index-[A-Za-z0-9_-]+\.' . preg_quote($ext, '#') . ')#', $html, $matches)) {
        return '';
    }
    foreach ($matches[1] as $name) {
        if (is_file($root . '/spa-assets/' . $name)) return $name;
    }
    return '';
}

function corefluxSpaPickAssetFromStamp(string $root, string $ext): string
{
    $stampFile = $root . '/.deploy-version';
    if (!is_file($stampFile)) return '';
    $stamp = (string) file_get_contents($stampFile);
    if (!preg_match('/expected_bundle:\s*\n((?:\s*-[^\n]*\n)+)/', $stamp, $block)) return '';
    foreach (preg_split('/\r?\n/', trim($block[1])) as $line) {
        $rel = trim((string) preg_replace('/^\s*-\s*/', '', $line));
        $name = basename($rel);
        if (preg_match('/^index-[A-Za-z0-9_-]+\.' . preg_quote($ext, '/') . '$/', $name)
            && is_file($root . '/spa-assets/' . $name)) {
            return $name;
        }
    }
    return '';
}

if (is_dir($assetsDir)) {
    $jsFile = corefluxSpaPickAssetFromDist(__DIR__, 'js') ?: corefluxSpaPickAssetFromStamp(__DIR__, 'js');
    $cssFile = corefluxSpaPickAssetFromDist(__DIR__, 'css') ?: corefluxSpaPickAssetFromStamp(__DIR__, 'css');
}

// Last-resort fallback for developer sandboxes where the dist/stamp files have
// not been generated yet.
if (is_dir($assetsDir) && ($jsFile === '' || $cssFile === '')) {
    $jsCandidate  = ['name' => '', 'mtime' => 0];
    $cssCandidate = ['name' => '', 'mtime' => 0];
    foreach (scandir($assetsDir) as $file) {
        $path = $assetsDir . '/' . $file;
        if ($jsFile === '' && preg_match('/^index-.*\.js$/', $file) && filemtime($path) > $jsCandidate['mtime']) {
            $jsCandidate = ['name' => $file, 'mtime' => filemtime($path)];
        }
        if ($cssFile === '' && preg_match('/^index-.*\.css$/', $file) && filemtime($path) > $cssCandidate['mtime']) {
            $cssCandidate = ['name' => $file, 'mtime' => filemtime($path)];
        }
    }
    $jsFile  = $jsFile ?: $jsCandidate['name'];
    $cssFile = $cssFile ?: $cssCandidate['name'];
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
    <script>
      // Tenant-pod config bridge to the SPA. Pulled from server env so
      // we don't bake provider keys into the React bundle. Each value
      // is OPTIONAL — features degrade gracefully when unset.
      //
      // INTUIT_PAYMENTS_PUBLISHABLE_KEY — when set, the QBO Payments
      // collect modal switches to the live Intuit tokenizer SDK.
      window.__INTUIT_PAYMENTS_KEY = <?php echo json_encode((string) (getenv('INTUIT_PAYMENTS_PUBLISHABLE_KEY') ?: '')); ?>;
      window.__INTUIT_PAYMENTS_ENV = <?php echo json_encode((string) (getenv('INTUIT_PAYMENTS_ENV') ?: 'sandbox')); ?>;
    </script>
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
