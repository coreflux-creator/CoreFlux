<?php
/**
 * CoreFlux Deployment Script
 * 
 * Access via: https://corefluxapp.com/deploy.php?key=YOUR_SECRET_KEY
 * 
 * IMPORTANT: Change the secret key below before using!
 */

// ========== CONFIGURATION ==========
$SECRET_KEY = 'coreflux-deploy-2024';  // CHANGE THIS to something unique!
$PROJECT_ROOT = __DIR__;
// ====================================

// Security check
if (!isset($_GET['key']) || $_GET['key'] !== $SECRET_KEY) {
    http_response_code(403);
    die('Access denied. Invalid or missing deployment key.');
}

// Set headers for plain text output
header('Content-Type: text/plain; charset=utf-8');

// Increase execution time for build process
set_time_limit(300);

echo "========================================\n";
echo "  CoreFlux Deployment\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

// Helper function to run commands
function run_command($command, $description) {
    global $PROJECT_ROOT;
    
    echo "[*] $description\n";
    echo "    Command: $command\n";
    
    $output = [];
    $return_code = 0;
    
    exec("cd $PROJECT_ROOT && $command 2>&1", $output, $return_code);
    
    if (!empty($output)) {
        foreach ($output as $line) {
            echo "    $line\n";
        }
    }
    
    if ($return_code !== 0) {
        echo "    [ERROR] Command failed with code $return_code\n\n";
        return false;
    }
    
    echo "    [OK]\n\n";
    return true;
}

// Step 1: Git Pull
if (!run_command('git pull origin main', 'Pulling latest code from Git')) {
    die("Deployment failed at git pull\n");
}

// Step 2: Install dependencies
if (!run_command('cd frontend && yarn install 2>&1 || npm install 2>&1', 'Installing frontend dependencies')) {
    die("Deployment failed at dependency install\n");
}

// Step 3: Build frontend
if (!run_command('cd frontend && yarn build 2>&1 || npm run build 2>&1', 'Building frontend')) {
    die("Deployment failed at build\n");
}

// Step 4: Deploy to app directory
echo "[*] Deploying to /app directory\n";

$source_dir = $PROJECT_ROOT . '/frontend/dist';
$dest_dir = $PROJECT_ROOT . '/app';

// Clear old files
$old_files = ['index.html', 'favicon.svg', 'assets'];
foreach ($old_files as $file) {
    $path = $dest_dir . '/' . $file;
    if (is_dir($path)) {
        exec("rm -rf " . escapeshellarg($path));
    } elseif (file_exists($path)) {
        unlink($path);
    }
}

// Copy new files
exec("cp -r $source_dir/* $dest_dir/", $output, $return_code);

if ($return_code === 0) {
    echo "    [OK] Files copied successfully\n\n";
} else {
    die("    [ERROR] Failed to copy files\n");
}

// Step 5: List deployed files
echo "[*] Deployed files:\n";
$files = scandir($dest_dir);
foreach ($files as $file) {
    if ($file !== '.' && $file !== '..') {
        $path = $dest_dir . '/' . $file;
        $size = is_dir($path) ? 'directory' : filesize($path) . ' bytes';
        echo "    - $file ($size)\n";
    }
}

echo "\n========================================\n";
echo "  Deployment Complete!\n";
echo "========================================\n\n";
echo "Test your deployment:\n";
echo "  - Marketing site: https://corefluxapp.com/\n";
echo "  - React app:      https://corefluxapp.com/app/\n";
echo "  - API:            https://corefluxapp.com/api/\n";
