<?php
/**
 * mercury_connect regression smoke — guards against the
 *
 *   "PDO::lastInsertId(): Argument #1 (\$name) must be of type ?string, bool given"
 *
 * fatal that surfaced on the Mercury Bank "Connect" form. Root cause was a
 * `$pdo->lastInsertId(true)` call inside mercurySaveConnection() — PHP 8
 * tightened the signature to ?string.
 *
 * This smoke is a code-level guard so the bug can't sneak back in. It does
 * NOT exercise a live Mercury API call (no internet + no keys in the
 * sandbox) but it does PHP-lint the file and validate the source no longer
 * contains the offending pattern.
 *
 *   php -d zend.assertions=1 /app/tests/mercury_connect_lastinsertid_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};

// ----------------------------------------------------------------- file-level guards
$path = $ROOT . '/core/mercury_service.php';
$src  = (string) file_get_contents($path);
$rc   = 0; $o = [];
exec('php -l ' . escapeshellarg($path) . ' 2>&1', $o, $rc);
$a('core/mercury_service.php — php -l clean',           $rc === 0);
$a('no longer calls lastInsertId(true)',                strpos($src, 'lastInsertId(true)')  === false);
$a('no lastInsertId(false) either',                     strpos($src, 'lastInsertId(false)') === false);
$a('uses bare lastInsertId()',                          (bool) preg_match('/lastInsertId\(\s*\)/', $src));
$a('fallback SELECT still in place for UPSERT-update path',
    strpos($src, 'SELECT id FROM mercury_connections WHERE tenant_id') !== false);
$a('passes resolved id (not zero) into mercurySyncAccountsFromList',
    strpos($src, 'mercurySyncAccountsFromList($tenantId, $insertedId') !== false);

// Defense-in-depth: nothing else in the repo calls lastInsertId with a non-string arg.
echo "\nCodebase-wide scan\n";
$bad = [];
$it  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $p = (string) $file;
    $pn = str_replace('\\', '/', $p);
    if (!str_ends_with($p, '.php')) continue;
    if (strpos($pn, '/node_modules/') !== false) continue;
    if (strpos($pn, '/vendor/')       !== false) continue;
    if (strpos($pn, '/dashboard/')    !== false) continue;
    if (strpos($pn, '/tests/')        !== false) continue;
    $content = (string) file_get_contents($p);
    if (preg_match('/lastInsertId\(\s*(true|false|\d)/', $content)) {
        $bad[] = $p;
    }
}
$a('no other call sites pass bool/int to lastInsertId()', empty($bad));
if ($bad) foreach ($bad as $b) echo "    offender: {$b}\n";

// ----------------------------------------------------------------- summary
echo "\n=========================================\n";
echo "Mercury lastInsertId smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
