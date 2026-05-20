<?php
/**
 * Plaid bank link HY093 regression smoke — guards against duplicated
 * named placeholders in /api/plaid_bank_link.php that broke the
 * "Connect bank" flow with:
 *
 *   SQLSTATE[HY093]: Invalid parameter number
 *
 * Root cause: PDO with `ATTR_EMULATE_PREPARES => false` (set globally in
 * core/db.php) does NOT allow the same named placeholder to be referenced
 * twice in a single prepared statement. The endpoint had two such patterns:
 *
 *   `AND (bank_name = :bk          OR :bk   = "")`
 *   `AND (tla.institution_name = :inst OR :inst = "")`
 *
 * Both reformulated to branch the SQL based on whether the institution
 * label is empty so each placeholder is bound exactly once.
 *
 *   php -d zend.assertions=1 /app/tests/plaid_bank_link_hy093_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};

// ----------------------------------------------------------------- target file
$path = $ROOT . '/api/plaid_bank_link.php';
$src  = (string) file_get_contents($path);

// PHP syntax check first.
$rc = 0; $o = [];
exec('php -l ' . escapeshellarg($path) . ' 2>&1', $o, $rc);
$a('api/plaid_bank_link.php — php -l clean',                $rc === 0);

// ----------------------------------------------------------------- targeted regressions
$a('no `OR :bk = ""`   duplicated placeholder',             strpos($src, 'OR :bk = ""')   === false);
$a('no `OR :inst = ""` duplicated placeholder',             strpos($src, 'OR :inst = ""') === false);

// ----------------------------------------------------------------- positive: branched SQL pattern
$a('depository branch builds SQL conditionally on institution',
    strpos($src, "(\$hasInst ? ' AND bank_name = :bk' : '')") !== false);
$a('liability branch builds SQL conditionally on institution',
    strpos($src, "(\$hasInst ? ' AND tla.institution_name = :inst' : '')") !== false);
$a('depository execute uses dynamic $params array',
    (bool) preg_match('/\$stmt->execute\(\$params\);\s*\n\s*\$adoptId/', $src));
$a('liability execute uses dynamic $params array',
    (bool) preg_match('/\$stmt->execute\(\$params\);\s*\n\s*\$adopt\s*=/', $src));
$a('comments reference HY093 + EMULATE_PREPARES so the fix is self-documenting',
    strpos($src, 'HY093')             !== false
    && strpos($src, 'EMULATE_PREPARES') !== false);

// ----------------------------------------------------------------- codebase-wide guard
echo "\nCodebase-wide scan for duplicate named placeholders in same SQL\n";
$bad = [];
$it  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $p = (string) $file;
    if (!str_ends_with($p, '.php')) continue;
    foreach (['/node_modules/', '/vendor/', '/dashboard/', '/tests/', '/lib/PHPMailer/'] as $skip) {
        if (strpos($p, $skip) !== false) continue 2;
    }
    $content = (string) file_get_contents($p);
    // Heuristic: look for `OR :name = ""` and `OR :name IS NULL` patterns
    // that historically pair with the same placeholder appearing earlier
    // in the statement. The actual two-placeholder detector would need a
    // full SQL parser, so we limit to the exact `OR :x = ""` antipattern.
    if (preg_match('/OR\s+:[a-zA-Z_][a-zA-Z0-9_]*\s*=\s*""/m', $content)) {
        $bad[] = $p;
    }
}
$a('no other endpoints use the `OR :x = ""` antipattern', empty($bad));
if ($bad) foreach ($bad as $b) echo "    offender: {$b}\n";

// ----------------------------------------------------------------- summary
echo "\n=========================================\n";
echo "Plaid bank link HY093 smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
