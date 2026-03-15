<?php
header('Content-Type: text/plain');
echo "=== Laravel .env Fix ===\n\n";

$laravel_root = __DIR__ . '/laravel';
$env_file = $laravel_root . '/.env';

// Check current .env
if (file_exists($env_file)) {
    echo "Current .env:\n";
    echo file_get_contents($env_file);
    echo "\n\n";
}

// Create proper .env
$env_content = <<<'ENV'
APP_NAME=CoreFlux
APP_ENV=production
APP_KEY=base64:xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx=
APP_DEBUG=true
APP_URL=https://corefluxapp.com

LOG_CHANNEL=stack
LOG_LEVEL=debug

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_username
DB_PASSWORD=your_password

SESSION_DRIVER=file
SESSION_LIFETIME=120

CACHE_DRIVER=file
QUEUE_CONNECTION=sync

SANCTUM_STATEFUL_DOMAINS=corefluxapp.com
ENV;

file_put_contents($env_file, $env_content);
echo "Created new .env with SESSION_DRIVER=file\n\n";

// Make sure session directory exists and is writable
$session_dir = $laravel_root . '/storage/framework/sessions';
if (!is_dir($session_dir)) {
    mkdir($session_dir, 0775, true);
    echo "Created sessions directory\n";
}

// Clear any cached config
$cache_files = [
    '/bootstrap/cache/config.php',
    '/bootstrap/cache/routes-v7.php',
    '/bootstrap/cache/services.php',
];
foreach ($cache_files as $file) {
    $path = $laravel_root . $file;
    if (file_exists($path)) {
        unlink($path);
        echo "Cleared: $file\n";
    }
}

echo "\n=== IMPORTANT ===\n";
echo "You need to update the .env file with your actual database credentials!\n";
echo "Edit: /laravel/.env\n";
echo "\nThen try logging in again.\n";
