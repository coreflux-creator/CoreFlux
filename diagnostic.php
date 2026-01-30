<?php
// Simple diagnostic file
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>CoreFlux Diagnostic</h1>";
echo "<pre>";

// Check if core files exist
$files = [
    'core/config.php',
    'core/auth.php', 
    'core/modules.php',
    'core/db.php',
    'modules/people/views/overview.php',
    'assets/css/dashboard.css',
    'dashboard.php'
];

echo "File Check:\n";
foreach ($files as $file) {
    $path = __DIR__ . '/' . $file;
    $exists = file_exists($path) ? '✓ EXISTS' : '✗ MISSING';
    echo "  {$file}: {$exists}\n";
}

echo "\n\nPHP Version: " . phpversion();
echo "\nDocument Root: " . $_SERVER['DOCUMENT_ROOT'];
echo "\nScript Path: " . __FILE__;
echo "\nCurrent Dir: " . __DIR__;

echo "</pre>";
