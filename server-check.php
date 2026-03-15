<?php
header('Content-Type: text/plain');
echo "=== Server Configuration Check ===\n\n";

echo "Document Root: " . $_SERVER['DOCUMENT_ROOT'] . "\n";
echo "Script Path: " . __FILE__ . "\n\n";

echo "=== Directory Structure ===\n";
$root = dirname(__FILE__);

$dirs = ['laravel', 'laravel/public', 'laravel/routes', 'app', 'frontend'];
foreach ($dirs as $dir) {
    $path = $root . '/' . $dir;
    $exists = is_dir($path) ? 'EXISTS' : 'NOT FOUND';
    echo "/$dir: $exists\n";
}

echo "\n=== Laravel Check ===\n";
$laravel_index = $root . '/laravel/public/index.php';
if (file_exists($laravel_index)) {
    echo "Laravel public/index.php: EXISTS\n";
} else {
    echo "Laravel public/index.php: NOT FOUND\n";
}

$api_routes = $root . '/laravel/routes/api.php';
if (file_exists($api_routes)) {
    echo "Laravel routes/api.php: EXISTS\n";
    echo "\nAPI Routes content:\n";
    echo "---\n";
    echo file_get_contents($api_routes);
} else {
    echo "Laravel routes/api.php: NOT FOUND\n";
}

echo "\n=== Current .htaccess ===\n";
$htaccess = $root . '/.htaccess';
if (file_exists($htaccess)) {
    echo file_get_contents($htaccess);
} else {
    echo ".htaccess NOT FOUND\n";
}
