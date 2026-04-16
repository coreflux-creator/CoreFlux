<?php
// Cleanup script - removes all the fix/debug scripts
$files_to_delete = [
    'fix-laravel.php',
    'fix-env.php', 
    'fix-middleware.php',
    'fix-config.php',
    'server-check.php',
    'test-api.php',
    'check.php',
    'cleanup.php'  // this file too
];

header('Content-Type: text/plain');
echo "Cleaning up debug scripts...\n\n";

foreach ($files_to_delete as $file) {
    $path = __DIR__ . '/' . $file;
    if (file_exists($path)) {
        unlink($path);
        echo "Deleted: $file\n";
    }
}

echo "\nDone! Your site is clean.\n";
