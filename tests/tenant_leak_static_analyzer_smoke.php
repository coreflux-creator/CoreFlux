<?php
/**
 * Tenant-leak sentry — codebase-wide static analyzer.
 *
 * Scans every PHP file under /app for `->prepare(SQL)` calls and finds
 * statements that touch a tenant-scoped table but do NOT mention
 * `tenant_id` anywhere in the SQL text. Those statements are the #1
 * cross-tenant data-leak vector — a missing `WHERE tenant_id = :t`
 * silently returns rows from every other tenant in the database.
 *
 * The list of tenant-scoped tables is auto-derived by scanning every
 * .sql file under /app/core/migrations + /app/modules/*//*migrations
 * + /app/sql for CREATE TABLE blocks that declare a `tenant_id`
 * column (or ALTER TABLE ... ADD COLUMN tenant_id).
 *
 * Skips: /node_modules/, /vendor/, /lib/PHPMailer/, /dashboard/,
 * /tests/, /legacy/, "/private_equity 2/" (dupe folder).
 *
 * Exclusions (for legitimate cases where tenant scoping is provided by
 * an outer mechanism, e.g. child-of-already-scoped-parent or a join
 * onto a parent table that DOES carry tenant_id):
 *   Add a `// tenant-leak-allow: <reason>` line within 3 lines BEFORE
 *   the prepare() to whitelist the statement.
 *
 *   php -d zend.assertions=1 /app/tests/tenant_leak_static_analyzer_smoke.php
 *
 * Exits non-zero the moment any unjustified leak appears.
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};

// ----------------------------------------------------------------- tenant-scoped table discovery
function discoverTenantScopedTables(string $root): array {
    $tables = [];
    $sqlFiles = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = (string) $f;
        $pn = str_replace('\\', '/', $p);
        if (!str_ends_with($p, '.sql')) continue;
        if (strpos($pn, '/node_modules/') !== false) continue;
        if (strpos($pn, '/vendor/') !== false) continue;
        if (strpos($pn, '/legacy/') !== false) continue;
        if (strpos($pn, '/private_equity 2/') !== false) continue;
        $sqlFiles[] = $p;
    }
    foreach ($sqlFiles as $f) {
        $sql = (string) file_get_contents($f);
        // CREATE TABLE blocks with a tenant_id column.
        if (preg_match_all(
            '/CREATE TABLE\s+(?:IF NOT EXISTS\s+)?[`"]?(\w+)[`"]?\s*\((.*?)\)\s*ENGINE/is',
            $sql, $m, PREG_SET_ORDER
        )) {
            foreach ($m as $mm) {
                if (preg_match('/[`"]?tenant_id[`"]?\s+(BIGINT|INT|INTEGER)/i', $mm[2])) {
                    $tables[$mm[1]] = true;
                }
            }
        }
        // ALTER TABLE ... ADD tenant_id (tables that gained scoping later).
        if (preg_match_all(
            '/ALTER TABLE\s+[`"]?(\w+)[`"]?\s+ADD\s+(?:COLUMN\s+)?[`"]?tenant_id[`"]?/i',
            $sql, $m2, PREG_SET_ORDER
        )) {
            foreach ($m2 as $mm) $tables[$mm[1]] = true;
        }
    }
    // `users.tenant_id` is a legacy compatibility column. User access is
    // tenant-bound through user_tenants/tenant_memberships, while users remains
    // the platform identity graph.
    unset($tables['users']);
    return $tables;
}

$tenantTables = discoverTenantScopedTables($ROOT);
$a('discovered at least 50 tenant-scoped tables', count($tenantTables) >= 50);
echo "  · " . count($tenantTables) . " tenant-scoped tables in scope\n";

// ----------------------------------------------------------------- file discovery
$skip = ['/node_modules/', '/vendor/', '/lib/PHPMailer/', '/dashboard/', '/tests/', '/legacy/', '/private_equity 2/'];
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = (string) $f;
    $pn = str_replace('\\', '/', $p);
    if (!str_ends_with($p, '.php')) continue;
    foreach ($skip as $s) if (strpos($pn, $s) !== false) continue 2;
    $files[] = $p;
}
sort($files);
$a('discovered php files to scan', count($files) > 0);
echo "  · scanning " . count($files) . " php files\n";

// ----------------------------------------------------------------- per-file analyzer
function reconstructSql(array $tokens, int $openParenIdx): ?string {
    $depth = 0; $parts = []; $sawNonStringPiece = false;
    for ($i = $openParenIdx; $i < count($tokens); $i++) {
        $t = $tokens[$i];
        if (is_string($t)) {
            if ($t === '(') { $depth++; continue; }
            if ($t === ')') { $depth--; if ($depth === 0) break; continue; }
            if ($t === '.') continue;
            if ($t === ',') { if ($depth === 1) break; continue; }
            $sawNonStringPiece = true; continue;
        }
        [$id, $text] = [$t[0], $t[1]];
        if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) continue;
        if ($id === T_CONSTANT_ENCAPSED_STRING) {
            $q = $text[0]; $inner = substr($text, 1, -1);
            if ($q === '"') $inner = strtr($inner, ['\\"' => '"', '\\\\' => '\\', '\\n' => "\n", '\\t' => "\t"]);
            else            $inner = strtr($inner, ["\\'" => "'", '\\\\' => '\\']);
            $parts[] = $inner; continue;
        }
        if ($id === T_START_HEREDOC) {
            $buf = ''; $j = $i + 1;
            while ($j < count($tokens) && (is_array($tokens[$j]) ? $tokens[$j][0] !== T_END_HEREDOC : true)) {
                if (is_array($tokens[$j])) $buf .= $tokens[$j][1];
                $j++;
            }
            $parts[] = $buf; $i = $j; continue;
        }
        $sawNonStringPiece = true;
    }
    if ($sawNonStringPiece || !$parts) return null;
    return implode('', $parts);
}

/** Find which tenant-scoped tables a SQL statement references. */
function findTenantTablesInSql(string $sql, array $tenantTables): array {
    // Tokenise by word boundaries; check FROM/JOIN/UPDATE/INTO targets.
    $hits = [];
    if (preg_match_all('/\b(FROM|JOIN|UPDATE|INTO)\s+[`"]?(\w+)[`"]?/i', $sql, $m, PREG_SET_ORDER)) {
        foreach ($m as $mm) {
            $name = strtolower($mm[2]);
            if (isset($tenantTables[$name])) $hits[$name] = true;
        }
    }
    return array_keys($hits);
}

// ----------------------------------------------------------------- scan
$offenders = [];
$scanned = 0;
$exemptions = [];

foreach ($files as $path) {
    $src = (string) file_get_contents($path);
    if (strpos($src, '->prepare(') === false && strpos($src, ' prepare(') === false) continue;
    $tokens = @token_get_all($src);
    if (!$tokens) continue;

    // Pre-split source into lines for nearby-comment exemption detection.
    $lines = explode("\n", $src);

    for ($i = 0; $i < count($tokens); $i++) {
        $tok = $tokens[$i];
        if (!is_array($tok)) continue;
        if ($tok[0] !== T_STRING || strcasecmp($tok[1], 'prepare') !== 0) continue;
        $j = $i + 1;
        while ($j < count($tokens) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
        if ($j >= count($tokens) || $tokens[$j] !== '(') continue;

        $sql = reconstructSql($tokens, $j);
        if ($sql === null) continue;
        if (strlen(trim($sql)) < 10) continue;
        $scanned++;

        $hits = findTenantTablesInSql($sql, $tenantTables);
        if (!$hits) continue;

        // If SQL contains tenant_id anywhere, statement is presumed safe.
        if (stripos($sql, 'tenant_id') !== false) continue;

        // Check for inline exemption comment within 3 lines BEFORE the prepare().
        $line = $tok[2];
        $exempt = false; $exemptReason = '';
        for ($k = max(0, $line - 4); $k < $line; $k++) {
            $cur = $lines[$k] ?? '';
            if (preg_match('/tenant-leak-allow:\s*(.+)$/', $cur, $em)) {
                $exempt = true;
                $exemptReason = trim($em[1]);
                break;
            }
        }
        if ($exempt) {
            $exemptions[] = ['file' => $path, 'line' => $line, 'reason' => $exemptReason, 'tables' => $hits];
            continue;
        }

        $offenders[] = ['file' => $path, 'line' => $line, 'tables' => $hits, 'sql' => $sql];
    }
}

echo "  · reconstructed SQL for {$scanned} prepare() calls\n";
echo "  · " . count($exemptions) . " statements have explicit `tenant-leak-allow:` exemption\n";
$a('analyzer reconstructed at least 100 prepare() calls', $scanned >= 100);

// ----------------------------------------------------------------- offender report
echo "\nTenant-leak offender report\n";
if ($offenders) {
    echo "  · " . count($offenders) . " statement(s) touch tenant-scoped tables WITHOUT mentioning tenant_id:\n";
    foreach ($offenders as $row) {
        echo "    ✗ {$row['file']}:{$row['line']} — tables: " . implode(',', $row['tables']) . "\n";
        $sql = preg_replace('/\s+/', ' ', trim($row['sql']));
        if (strlen($sql) > 280) $sql = substr($sql, 0, 280) . '…';
        echo "        SQL: {$sql}\n";
    }
}
$a('zero statements leak across tenants', count($offenders) === 0);

// ----------------------------------------------------------------- sanity self-test
echo "\nSelf-test (synthetic bad input — analyzer must catch this)\n";
$bad = '<?php $pdo->prepare("SELECT * FROM placements WHERE id = :id");';
$tokens = token_get_all($bad);
$found = false;
$fakeTables = ['placements' => true];
for ($i = 0; $i < count($tokens); $i++) {
    if (is_array($tokens[$i]) && $tokens[$i][0] === T_STRING && strcasecmp($tokens[$i][1], 'prepare') === 0) {
        $jj = $i + 1;
        while ($jj < count($tokens) && is_array($tokens[$jj]) && $tokens[$jj][0] === T_WHITESPACE) $jj++;
        $sql = reconstructSql($tokens, $jj);
        if ($sql !== null) {
            $hits = findTenantTablesInSql($sql, $fakeTables);
            if ($hits && stripos($sql, 'tenant_id') === false) $found = true;
        }
    }
}
$a('analyzer flags placements-without-tenant-id in synthetic input', $found);

echo "\n=========================================\n";
echo "Tenant-leak sentry smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
