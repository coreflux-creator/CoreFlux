<?php
header('Content-Type: text/plain');
echo "=== Laravel Config Fix ===\n\n";

$laravel_root = __DIR__ . '/laravel';

// Check view.php config
$view_config = $laravel_root . '/config/view.php';
echo "Checking: $view_config\n";

if (file_exists($view_config)) {
    echo "Current content:\n";
    echo file_get_contents($view_config);
    echo "\n\n";
} else {
    echo "view.php NOT FOUND - creating it...\n";
}

// Create proper view.php config
$view_content = <<<'PHP'
<?php

return [
    'paths' => [
        resource_path('views'),
    ],
    'compiled' => env(
        'VIEW_COMPILED_PATH',
        realpath(storage_path('framework/views'))
    ),
];
PHP;

file_put_contents($view_config, $view_content);
echo "Created/Updated view.php config\n\n";

// Clear config cache
$cache_file = $laravel_root . '/bootstrap/cache/config.php';
if (file_exists($cache_file)) {
    unlink($cache_file);
    echo "Cleared config cache\n";
}

// Check .env exists
$env_file = $laravel_root . '/.env';
if (!file_exists($env_file)) {
    echo "\nWARNING: .env file missing!\n";
    $env_example = $laravel_root . '/.env.example';
    if (file_exists($env_example)) {
        copy($env_example, $env_file);
        echo "Copied .env.example to .env\n";
    }
}

// Check storage directories exist and are writable
$storage_dirs = [
    '/storage/framework/views',
    '/storage/framework/cache',
    '/storage/framework/sessions',
    '/storage/logs'
];

echo "\nStorage directories:\n";
foreach ($storage_dirs as $dir) {
    $path = $laravel_root . $dir;
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
        echo "Created: $dir\n";
    } else {
        echo "Exists: $dir\n";
    }
}

echo "\n=== Done! Try the API again ===\n";
