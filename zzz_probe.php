<?php
// Kill caches so we see current code
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
if (function_exists('opcache_reset')) { @opcache_reset(); }

echo "<h3>Runtime environment</h3>";
echo "FILE: ".__FILE__."<br>";
echo "DIR: ".__DIR__."<br>";
echo "DOCUMENT_ROOT: ".($_SERVER['DOCUMENT_ROOT'] ?? '(unset)')."<br>";
echo "HTTP_HOST: ".($_SERVER['HTTP_HOST'] ?? '(unset)')."<br>";

// Where we expect db.php
$probe = __DIR__ . '/config/db.php';
echo "<h3>Config probe</h3>";
echo "Expecting DB at: $probe<br>";
echo "Exists? " . (file_exists($probe) ? "YES" : "NO") . "<br>";

// Is public_html a symlink?
echo "<h3>Symlink check</h3>";
$ph = __DIR__;
$target = @readlink($ph);
echo "public_html readlink: " . ($target !== false ? $target : "(not a symlink or unreadable)") . "<br>";

// List any copies of forgot_password.php under public_html
echo "<h3>Scanning for forgot_password.php under public_html</h3>";
$found = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
  if (strtolower($f->getFilename()) === 'forgot_password.php') {
    $found[] = $f->getPathname();
  }
}
if ($found) {
  echo "<pre>".htmlspecialchars(implode(PHP_EOL, $found))."</pre>";
} else {
  echo "No copies found under public_html.<br>";
}

// Show .htaccess (common cause: rewrites)
$ht = __DIR__.'/.htaccess';
echo "<h3>.htaccess present?</h3>";
echo file_exists($ht) ? "YES ($ht)" : "NO";
