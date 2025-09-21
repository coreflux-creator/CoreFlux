<?php
// Recursively scan for any include/require of db.php (and absolute /public_html paths)
$root = __DIR__;
$hits = [];
$rx = '~\b(require|require_once|include|include_once)\s*\(([^)]*db\.php[^)]*)\)~i';

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
  if (!$file->isFile()) continue;
  $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
  if (!in_array($ext, ['php','inc','phtml','html'])) continue;
  $path = $file->getPathname();
  $lines = @file($path);
  if (!$lines) continue;
  foreach ($lines as $i => $line) {
    if (preg_match($rx, $line) || stripos($line, '/public_html/db.php') !== false) {
      $hits[] = [$path, $i+1, trim($line)];
    }
  }
}
header("Content-Type: text/plain; charset=utf-8");
if (!$hits) { echo "No include/require of db.php found.\n"; exit; }
foreach ($hits as [$p,$ln,$code]) {
  echo "$p:$ln  $code\n";
}
