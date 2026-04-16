<?php
header('Content-Type: text/plain');
echo "=== Laravel .env Fix ===\n\n";

$laravel_root = __DIR__ . '/laravel';
$env_file = $laravel_root . '/.env';

// Create proper .env with actual credentials
$env_content = <<<'ENV'
APP_NAME=CoreFlux
APP_ENV=production
APP_KEY=base64:J8xVrGqXp2NmLkW9yT4aZbCdEfHiKoMnPsRuWvYz123=
APP_DEBUG=true
APP_URL=https://corefluxapp.com

LOG_CHANNEL=stack
LOG_LEVEL=debug

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=grcudkpvcd
DB_USERNAME=grcudkpvcd
DB_PASSWORD=7DgX7F4RPz

SESSION_DRIVER=file
SESSION_LIFETIME=120

CACHE_DRIVER=file
QUEUE_CONNECTION=sync

SANCTUM_STATEFUL_DOMAINS=corefluxapp.com
ENV;

file_put_contents($env_file, $env_content);
echo "Created .env with database credentials\n\n";

// Make sure storage directories exist
$dirs = [
    '/storage/framework/sessions',
    '/storage/framework/views', 
    '/storage/framework/cache',
    '/storage/logs'
];
foreach ($dirs as $dir) {
    $path = $laravel_root . $dir;
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
        echo "Created: $dir\n";
    }
}

// Clear cached config
$cache_files = glob($laravel_root . '/bootstrap/cache/*.php');
foreach ($cache_files as $file) {
    unlink($file);
    echo "Cleared: " . basename($file) . "\n";
}

echo "\n=== Done! Try logging in now ===\n";
