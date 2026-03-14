<?php
/**
 * CoreFlux Deployment Script (v3 - Pure PHP)
 */

$SECRET_KEY = 'coreflux-deploy-2024';

if (!isset($_GET['key']) || $_GET['key'] !== $SECRET_KEY) {
    die('Access denied.');
}

header('Content-Type: text/html; charset=utf-8');
echo "<pre style='font-family:monospace;font-size:14px;'>";
echo "CoreFlux Deployment - " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

$root = __DIR__;
$src = $root . '/frontend/dist';
$dest = $root . '/app';

// Check source exists
if (!is_dir($src)) {
    die("[ERROR] frontend/dist not found!\n");
}

echo "[1] Clearing old files in /app...\n";

// Delete old assets folder
$assets = $dest . '/assets';
if (is_dir($assets)) {
    array_map('unlink', glob("$assets/*"));
    rmdir($assets);
    echo "    Removed old assets/\n";
}

// Delete old files
foreach (['index.html', 'favicon.svg', 'logo.png', 'logo.svg'] as $f) {
    $file = $dest . '/' . $f;
    if (file_exists($file)) {
        unlink($file);
        echo "    Removed $f\n";
    }
}

echo "\n[2] Copying new files...\n";

// Copy index.html
copy($src . '/index.html', $dest . '/index.html');
echo "    Copied index.html\n";

// Copy favicon.svg
if (file_exists($src . '/favicon.svg')) {
    copy($src . '/favicon.svg', $dest . '/favicon.svg');
    echo "    Copied favicon.svg\n";
}

// Copy assets folder
$src_assets = $src . '/assets';
if (is_dir($src_assets)) {
    mkdir($dest . '/assets', 0755, true);
    foreach (glob($src_assets . '/*') as $file) {
        $filename = basename($file);
        // Skip large files (> 1MB)
        if (filesize($file) > 1000000) {
            echo "    Skipped $filename (too large)\n";
            continue;
        }
        copy($file, $dest . '/assets/' . $filename);
        echo "    Copied assets/$filename\n";
    }
}

echo "\n[3] Deployed files:\n";
foreach (glob($dest . '/*') as $f) {
    $name = basename($f);
    $size = is_dir($f) ? 'dir' : round(filesize($f)/1024) . 'KB';
    echo "    - $name ($size)\n";
}

echo "\n========================================\n";
echo "DONE! Test: https://corefluxapp.com/app/\n";
echo "</pre>";
