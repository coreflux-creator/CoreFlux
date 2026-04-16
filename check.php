<?php
header('Content-Type: text/plain');
echo "=== /app directory contents ===\n\n";

$dir = __DIR__ . '/app';

if (!is_dir($dir)) {
    die("/app directory not found!");
}

function listDir($path, $indent = "") {
    $files = scandir($path);
    foreach ($files as $f) {
        if ($f === '.' || $f === '..') continue;
        $full = $path . '/' . $f;
        $size = is_dir($full) ? 'DIR' : filesize($full) . ' bytes';
        echo $indent . "- $f ($size)\n";
        if (is_dir($full)) {
            listDir($full, $indent . "  ");
        }
    }
}

listDir($dir);

echo "\n=== index.html contents ===\n\n";
$index = $dir . '/index.html';
if (file_exists($index)) {
    echo file_get_contents($index);
} else {
    echo "index.html NOT FOUND!";
}
