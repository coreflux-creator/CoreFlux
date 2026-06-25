<?php
/**
 * HY093 sentry — codebase-wide static analyzer.
 *
 * Scans every PHP file under /app for `->prepare(SQL)` calls, reconstructs
 * the SQL string (handling `.` concatenation, heredoc, single + double
 * quotes), strips quoted string literals and comments out of the SQL, then
 * counts `:name` placeholder occurrences. Any name that appears more than
 * once in the same statement is reported as an offender — because PDO with
 * ATTR_EMULATE_PREPARES=false (set globally in core/db.php) refuses to
 * re-bind the same named parameter and aborts the query with
 * "SQLSTATE[HY093]: Invalid parameter number" at execute() time.
 *
 * Skips: /node_modules/, /vendor/, /lib/PHPMailer/, /dashboard/, /tests/.
 *
 *   php -d zend.assertions=1 /app/tests/hy093_static_analyzer_smoke.php
 *
 * Exits 1 (fails the smoke suite) the moment any new offender appears so
 * the bug class can never silently sneak back in.
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};

// ----------------------------------------------------------------- file discovery
$skip = ['/node_modules/', '/vendor/', '/lib/PHPMailer/', '/dashboard/', '/tests/'];
$files = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $f) {
    $p = (string) $f;
    $pn = str_replace('\\', '/', $p);
    if (!str_ends_with($p, '.php')) continue;
    foreach ($skip as $s) if (strpos($pn, $s) !== false) continue 2;
    $files[] = $p;
}
sort($files);
$a('discovered php files to scan', count($files) > 0);
echo "  · scanning " . count($files) . " files\n";

// ----------------------------------------------------------------- per-file analyzer
/**
 * Given a token stream and an index pointing at the `(` immediately after
 * a `prepare` identifier, return [$concatenatedSqlOrNull, $linenoOfPrepare].
 * Walks forward collecting string-literal pieces joined by `.` until the
 * matching closing `)`. Returns null when the SQL is built from variables
 * (which the static analyzer can't see anyway).
 */
function reconstructSql(array $tokens, int $openParenIdx): ?string {
    $depth = 0;
    $parts = [];
    $sawNonStringPiece = false;
    for ($i = $openParenIdx; $i < count($tokens); $i++) {
        $t = $tokens[$i];
        if (is_string($t)) {
            if ($t === '(') { $depth++; continue; }
            if ($t === ')') { $depth--; if ($depth === 0) break; continue; }
            if ($t === '.') continue;           // concatenation operator
            if ($t === ',') {                   // 2nd argument to prepare (driver options) — stop
                if ($depth === 1) break;
                continue;
            }
            $sawNonStringPiece = true;
            continue;
        }
        [$id, $text] = [$t[0], $t[1]];
        if ($id === T_WHITESPACE) continue;
        if ($id === T_COMMENT || $id === T_DOC_COMMENT) continue;
        if ($id === T_CONSTANT_ENCAPSED_STRING) {
            // Strip the surrounding quote char, un-escape minimally.
            $q = $text[0];
            $inner = substr($text, 1, -1);
            if ($q === '"') {
                $inner = strtr($inner, ['\\"' => '"', '\\\\' => '\\', '\\n' => "\n", '\\t' => "\t"]);
            } else { // single-quoted
                $inner = strtr($inner, ["\\'" => "'", '\\\\' => '\\']);
            }
            $parts[] = $inner;
            continue;
        }
        if ($id === T_START_HEREDOC) {
            // Collect heredoc contents.
            $buf = '';
            $j = $i + 1;
            while ($j < count($tokens) && (is_array($tokens[$j]) ? $tokens[$j][0] !== T_END_HEREDOC : true)) {
                if (is_array($tokens[$j])) $buf .= $tokens[$j][1];
                $j++;
            }
            $parts[] = $buf;
            $i = $j;
            continue;
        }
        // Variables, function calls, etc. inside the SQL argument — opaque to us.
        $sawNonStringPiece = true;
    }
    if ($sawNonStringPiece || !$parts) return null;
    return implode('', $parts);
}

/** Strip SQL string literals + comments so we don't count placeholders that aren't really placeholders. */
function stripSqlNoise(string $sql): string {
    // Block comments /* ... */
    $sql = (string) preg_replace('!/\*.*?\*/!s', ' ', $sql);
    // Line comments -- ... and # ...
    $sql = (string) preg_replace('/(?:^|\s)--[^\n]*/', ' ', $sql);
    $sql = (string) preg_replace('/(?:^|\s)#[^\n]*/',  ' ', $sql);
    // Single-quoted string literals (with '' escape)
    $sql = (string) preg_replace("/'(?:[^']|'')*'/", "''", $sql);
    // Double-quoted string literals (MySQL accepts both; ANSI_QUOTES not used here)
    $sql = (string) preg_replace('/"(?:[^"]|"")*"/', '""', $sql);
    return $sql;
}

/** Find `:name` placeholders ignoring PostgreSQL `::cast` syntax. */
function extractPlaceholders(string $sql): array {
    if (!preg_match_all('/(?<![:\w]):([a-zA-Z_][a-zA-Z0-9_]*)\b/', $sql, $m)) return [];
    return $m[1];
}

// ----------------------------------------------------------------- scan
$offenders = [];   // [ ['file' => ..., 'line' => ..., 'dup' => ['name' => count, ...], 'sql' => ...] ]
$scanned   = 0;    // statements actually inspected (SQL reconstructed)

foreach ($files as $path) {
    $src = (string) file_get_contents($path);
    if (strpos($src, '->prepare(') === false && strpos($src, ' prepare(') === false) {
        continue; // skip files that can't possibly have a prepare() call
    }
    $tokens = @token_get_all($src);
    if (!$tokens) continue;

    for ($i = 0; $i < count($tokens); $i++) {
        $tok = $tokens[$i];
        if (!is_array($tok)) continue;
        if ($tok[0] !== T_STRING || strcasecmp($tok[1], 'prepare') !== 0) continue;

        // Walk forward to the next '(' — must be the method-call's open paren.
        $j = $i + 1;
        while ($j < count($tokens) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
        if ($j >= count($tokens) || $tokens[$j] !== '(') continue;

        $sql = reconstructSql($tokens, $j);
        if ($sql === null) continue;
        // Skip very short fragments that aren't really SQL.
        if (strlen(trim($sql)) < 10) continue;
        $scanned++;

        $clean = stripSqlNoise($sql);
        $names = extractPlaceholders($clean);
        if (!$names) continue;
        $counts = array_count_values($names);
        $dup = array_filter($counts, static fn(int $n) => $n > 1);
        if ($dup) {
            $offenders[] = [
                'file' => $path,
                'line' => $tok[2],
                'dup'  => $dup,
                'sql'  => $sql,
            ];
        }
    }
}

echo "  · reconstructed SQL for {$scanned} prepare() calls\n";
$a('static analyzer reconstructed at least 100 prepare() calls', $scanned >= 100);

// ----------------------------------------------------------------- offender report
echo "\nHY093 offender report\n";
if ($offenders) {
    echo "  · " . count($offenders) . " duplicate-placeholder statement(s) detected:\n";
    foreach ($offenders as $row) {
        $dupStr = implode(', ', array_map(
            static fn($n, $k) => ":{$k} ×{$n}",
            $row['dup'],
            array_keys($row['dup'])
        ));
        echo "    ✗ {$row['file']}:{$row['line']} — {$dupStr}\n";
        // Print the SQL with extra indent for forensics.
        $sql = preg_replace('/\s+/', ' ', trim($row['sql']));
        if (strlen($sql) > 240) $sql = substr($sql, 0, 240) . '…';
        echo "        SQL: {$sql}\n";
    }
}
$a('zero statements re-use the same named placeholder', count($offenders) === 0);

// ----------------------------------------------------------------- sanity: analyzer must catch a known bad pattern
echo "\nSelf-test (synthetic bad input — analyzer must catch this)\n";
$bad = '<?php $pdo->prepare("SELECT id FROM t WHERE a = :x AND b = :x");';
$tokens = token_get_all($bad);
$found = false;
for ($i = 0; $i < count($tokens); $i++) {
    if (is_array($tokens[$i]) && $tokens[$i][0] === T_STRING && strcasecmp($tokens[$i][1], 'prepare') === 0) {
        $j = $i + 1;
        while ($j < count($tokens) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
        $sql = reconstructSql($tokens, $j);
        if ($sql !== null) {
            $clean = stripSqlNoise($sql);
            $names = extractPlaceholders($clean);
            $counts = array_count_values($names);
            $found = (bool) array_filter($counts, static fn(int $n) => $n > 1);
        }
    }
}
$a('analyzer flags `:x` referenced twice in synthetic input', $found);

// ----------------------------------------------------------------- summary
echo "\n=========================================\n";
echo "HY093 sentry smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
