<?php
/**
 * CoreFlux Deployment Script (v2)
 * 
 * Access via: https://corefluxapp.com/deploy.php?key=YOUR_SECRET_KEY
 */

// ========== CONFIGURATION ==========
$SECRET_KEY = 'coreflux-deploy-2024';
$PROJECT_ROOT = __DIR__;
// ====================================

// Security check
if (!isset($_GET['key']) || $_GET['key'] !== $SECRET_KEY) {
    http_response_code(403);
    die('Access denied.');
}

// Disable output buffering for real-time output
ob_implicit_flush(true);
ob_end_flush();

header('Content-Type: text/plain; charset=utf-8');
set_time_limit(300);

echo "========================================\n";
echo "  CoreFlux Deployment\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

// Check if dist folder already exists with recent build
$dist_dir = $PROJECT_ROOT . '/frontend/dist';
$dest_dir = $PROJECT_ROOT . '/app';

if (is_dir($dist_dir) && file_exists($dist_dir . '/index.html')) {
    echo "[*] Found existing build in frontend/dist\n";
    echo "    Skipping build, deploying directly...\n\n";
} else {
    echo "[!] No build found. You need to build manually via SSH:\n";
    echo "    cd ~/public_html/frontend && yarn install && yarn build\n\n";
    die("Deployment stopped - no build available.\n");
}

// Deploy to app directory
echo "[*] Deploying to /app directory...\n";

// Clear old files
exec("rm -rf " . escapeshellarg($dest_dir . '/assets'));
exec("rm -f " . escapeshellarg($dest_dir . '/index.html'));
exec("rm -f " . escapeshellarg($dest_dir . '/favicon.svg'));

// Copy new files
exec("cp -r $dist_dir/* $dest_dir/ 2>&1", $output, $return_code);

if ($return_code === 0) {
    echo "    [OK] Files deployed!\n\n";
} else {
    echo "    [ERROR] " . implode("\n", $output) . "\n";
    die("Deployment failed.\n");
}

// List deployed files
echo "[*] Deployed files:\n";
$files = scandir($dest_dir);
foreach ($files as $file) {
    if ($file !== '.' && $file !== '..') {
        $path = $dest_dir . '/' . $file;
        $size = is_dir($path) ? 'dir' : round(filesize($path)/1024, 1) . 'KB';
        echo "    - $file ($size)\n";
    }
}

echo "\n========================================\n";
echo "  Deployment Complete!\n";
echo "========================================\n";
echo "\nTest: https://corefluxapp.com/app/\n";
