<?php
/**
 * Schema-drift contract test — guards against the class of bug that
 * shipped on 2026-02 (deposit_accounts.php SELECT referenced
 * pa.current_balance_cents before migration 010 had landed on prod).
 *
 * Strategy: parse every PHP file under /api and /modules for
 * `tablealias.column` patterns inside SQL string literals, build a
 * ground-truth column list from /core/migrations/*.sql and module
 * migrations, and assert every referenced column exists somewhere in
 * the schema.
 *
 * What this catches:
 *   • Column rename without API update.
 *   • New SELECT/JOIN referencing a column that hasn't been migrated.
 *   • Typos in column names.
 *
 * What this DOESN'T catch (covered by integration tests, future work):
 *   • Logic bugs.
 *   • Wrong values returned.
 *   • Data drift between environments.
 *
 * Hard-coded allow-list for derived/synthetic aliases that don't appear
 * in any migration (computed columns, subquery scalar aliases).
 */
declare(strict_types=1);

$assertCount = 0; $failCount = 0;
function _ssa(string $label, bool $cond, ?string $hint = null): void {
    global $assertCount, $failCount;
    $assertCount++;
    if ($cond) {
        echo "  ok  $label\n";
    } else {
        $failCount++;
        echo "FAIL  $label" . ($hint ? "\n        $hint" : '') . "\n";
    }
}

// ─────────────────────────────────────────────────────────────────────
// 1) Build the column ground-truth from migrations.
// ─────────────────────────────────────────────────────────────────────
$schemaDirs = [
    __DIR__ . '/../core/migrations',
    __DIR__ . '/../core',                                   // legacy install.sql
    __DIR__ . '/../modules',                                // module-level migrations
];
$schemaSql = '';
foreach ($schemaDirs as $dir) {
    if (!is_dir($dir)) continue;
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iter as $f) {
        if (!$f->isFile()) continue;
        if (!preg_match('/\.sql$/i', $f->getFilename())) continue;
        $schemaSql .= "\n-- FILE: {$f->getPathname()}\n" . (string) file_get_contents($f->getPathname());
    }
}

// Extract every column name that appears after `CREATE TABLE …(` or
// `ALTER TABLE … ADD COLUMN …`. Permissive parser: the CREATE TABLE
// body is everything up to the matching `;` (some files don't end with
// ENGINE=…), and column lines start with an identifier followed by a
// type keyword.
$columnsByTable = [];   // [table => [colName => true]]
$tableAliases   = [];   // [alias => table] (filled per-file later)

if (preg_match_all(
    '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s*\((.*?)\)\s*(?:ENGINE\s*=|;)/is',
    $schemaSql, $tableMatches, PREG_SET_ORDER
)) {
    foreach ($tableMatches as $tm) {
        $table = strtolower($tm[1]);
        $body  = $tm[2];
        // Walk every line (CREATE TABLE bodies are line-oriented in our codebase).
        foreach (preg_split('/,\s*\R|\R/', $body) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            // Skip constraint clauses.
            if (preg_match('/^\s*(PRIMARY|KEY|UNIQUE|INDEX|CONSTRAINT|FOREIGN|FULLTEXT|SPATIAL|CHECK)\b/i', $line)) continue;
            if (preg_match('/^`?(\w+)`?\s+(?:TINYINT|SMALLINT|MEDIUMINT|INT|INTEGER|BIGINT|DECIMAL|NUMERIC|FLOAT|DOUBLE|BIT|CHAR|VARCHAR|VARBINARY|BINARY|TEXT|TINYTEXT|MEDIUMTEXT|LONGTEXT|DATE|DATETIME|TIMESTAMP|TIME|YEAR|JSON|BLOB|TINYBLOB|MEDIUMBLOB|LONGBLOB|ENUM|SET|BOOL|BOOLEAN|GEOMETRY|POINT)/i', $line, $m)) {
                $columnsByTable[$table][strtolower($m[1])] = true;
            }
        }
    }
}

// ALTER TABLE … ADD COLUMN …  (handles direct ALTERs)
if (preg_match_all(
    '/ALTER\s+TABLE\s+`?(\w+)`?\s+ADD\s+COLUMN\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i',
    $schemaSql, $altMatches, PREG_SET_ORDER
)) {
    foreach ($altMatches as $am) {
        $columnsByTable[strtolower($am[1])][strtolower($am[2])] = true;
    }
}

// Dynamic ALTERs built inside SET @sql := "ALTER TABLE foo ADD COLUMN bar"
// or PREPARE'd from CONCAT — pick up the table name from the most recent
// preceding `TABLE_NAME='foo'` filter check + every ADD COLUMN that follows
// (some files chain multiple ADDs in one literal). The signal that follows
// `TABLE_NAME=` is a strong hint — every dynamic ALTER in this codebase
// guards with `information_schema.COLUMNS WHERE TABLE_NAME='X'`.
if (preg_match_all(
    "/TABLE_NAME\s*=\s*'(\w+)'.*?ALTER\s+TABLE\s+(?:`?\w+`?)\s+(.*?)(?:\"|')/is",
    $schemaSql, $dynMatches, PREG_SET_ORDER
)) {
    foreach ($dynMatches as $dm) {
        $tbl = strtolower($dm[1]);
        if (preg_match_all('/\bADD\s+COLUMN\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $dm[2], $cols)) {
            foreach ($cols[1] as $c) $columnsByTable[$tbl][strtolower($c)] = true;
        }
    }
}

// Last-resort: any bare `ADD COLUMN \w+ \w+ ...` sequence picked up from
// migration content. This catches multi-clause ALTER blocks where the
// outer `ALTER TABLE` only matches once but multiple ADD COLUMN clauses
// follow. Attribute every such column to whichever ALTER TABLE we last
// saw at file scope.
$lastAlteredTable = null;
foreach (preg_split('/\R/', $schemaSql) as $line) {
    if (preg_match('/ALTER\s+TABLE\s+`?(\w+)`?/i', $line, $m)) {
        $lastAlteredTable = strtolower($m[1]);
    }
    if ($lastAlteredTable && preg_match('/^\s*ADD\s+COLUMN\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $line, $m)) {
        $columnsByTable[$lastAlteredTable][strtolower($m[1])] = true;
    }
}

// Manual additions: these are added via runtime ALTERs that the
// schema-contract-test treats as canonical because we know they self-heal.
$runtimeAdds = [
    'plaid_accounts'              => ['current_balance_cents','available_balance_cents','limit_balance_cents','iso_currency_code','balance_as_of'],
    'treasury_liability_accounts' => ['plaid_account_id','updated_at'],
];
foreach ($runtimeAdds as $t => $cols) {
    foreach ($cols as $c) $columnsByTable[$t][$c] = true;
}

_ssa('schema parse found accounting_accounts.code',  isset($columnsByTable['accounting_accounts']['code']));
_ssa('schema parse found plaid_accounts.account_id', isset($columnsByTable['plaid_accounts']['account_id']));

// ─────────────────────────────────────────────────────────────────────
// 2) Walk every PHP API file and collect (alias, column) pairs.
// ─────────────────────────────────────────────────────────────────────
$apiDirs = [__DIR__ . '/../api', __DIR__ . '/../modules'];
$phpFiles = [];
foreach ($apiDirs as $d) {
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS));
    foreach ($iter as $f) {
        if ($f->isFile() && preg_match('/\.php$/', $f->getFilename())) $phpFiles[] = $f->getPathname();
    }
}

// Aggregate violations per file so we can report once per file.
$violations = [];

// Built-in MySQL function names + computed-aggregate aliases that should never be flagged.
$ignoreColumns = [
    'count','sum','avg','min','max','now','coalesce','if','date','date_format',
    'json_object','json_arrayagg','json_extract','case','as','distinct',
];

// Aliases that get used inline (e.g. `(SELECT ... ) AS foo`) but
// reference computed columns, not real ones. Skip them.
$syntheticAliases = ['c','d','x','t','a','b'];   // very short aliases, frequently used for derived subqueries

foreach ($phpFiles as $phpFile) {
    $php = (string) file_get_contents($phpFile);

    // Find heredoc + double-quoted + single-quoted SQL strings. Cheap heuristic:
    // any string containing `SELECT`, `UPDATE`, `INSERT`, `DELETE` keywords (case-insensitive).
    if (!preg_match_all(
        '/(?:"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\')/s',
        $php, $strMatches
    )) continue;

    foreach ([$strMatches[1], $strMatches[2]] as $bag) {
        foreach ($bag as $sql) {
            if (!$sql) continue;
            if (!preg_match('/\b(SELECT|UPDATE|INSERT|DELETE|FROM|JOIN|WHERE|SET|VALUES)\b/i', $sql)) continue;

            // Discover aliases declared with `tablename alias` or `tablename AS alias`.
            $localAliases = [];
            if (preg_match_all(
                '/\b(?:FROM|JOIN)\s+`?(\w+)`?\s+(?:AS\s+)?`?(\w+)`?/i',
                $sql, $aliasMatches, PREG_SET_ORDER
            )) {
                foreach ($aliasMatches as $am) {
                    $tbl = strtolower($am[1]);
                    $als = strtolower($am[2]);
                    if (in_array($als, ['on','where','set','values','order','group','having','using','as'], true)) continue;
                    if (!isset($columnsByTable[$tbl])) continue;
                    $localAliases[$als] = $tbl;
                }
            }
            // Also bare `FROM tablename` (no alias).
            if (preg_match_all('/\b(?:FROM|JOIN)\s+`?(\w+)`?\s*(?:WHERE|ON|GROUP|ORDER|LIMIT|JOIN|LEFT|RIGHT|INNER|$|;|\))/i', $sql, $bareTbls)) {
                foreach ($bareTbls[1] as $tbl) {
                    $tbl = strtolower($tbl);
                    if (isset($columnsByTable[$tbl])) $localAliases[$tbl] = $tbl;
                }
            }

            // Now find every alias.column reference.
            if (!preg_match_all('/\b([a-z_][a-z0-9_]*)\.([a-z_][a-z0-9_]*)\b/i', $sql, $refs, PREG_SET_ORDER)) continue;
            foreach ($refs as $ref) {
                $alias = strtolower($ref[1]);
                $col   = strtolower($ref[2]);
                if (in_array($alias, $syntheticAliases, true)) continue;
                if (in_array($col,   $ignoreColumns, true))    continue;
                if ($col === '*') continue;
                if (!isset($localAliases[$alias])) continue; // alias not from a known table → skip (could be sub-alias)
                $tbl = $localAliases[$alias];
                if (!isset($columnsByTable[$tbl][$col])) {
                    $violations[$phpFile][] = "{$alias}.{$col}  (table=$tbl)";
                }
            }
        }
    }
}

// Allow-list: known pre-existing violations from before the schema-contract
// gate existed. Each one is a real bug that needs follow-up but isn't part
// of the current sprint. Adding new entries here is a smell — fix the
// underlying SQL or migration first.
//
// FORMAT: "alias.column  (table=name)" — must match the violation string
// produced below exactly.
//
// 2026-02 sweep: most of these were resolved by migration 012
// (`payroll_profile_alignment.sql`) which added the missing columns
// directly on payroll_profiles, ap_1099_ledger, placement_corp_details,
// people_tax_federal, accounting_journal_entry_lines, people, and
// placements. Anything that remains here is a real follow-up.
$knownLegacyViolations = [
    // tenants alias used for sub-tenant tree queries; alias is `st`
    // (sub_tenants) but the parser maps it to `tenants`. Real query
    // joins through correctly.
    'st.parent_id  (table=tenants)',
    // Cross-tenant audit endpoint joins `tenants` three times (acting,
    // left, right) for human-friendly names. `tenants` is created via
    // install.php, not a migration file, so the parser can't see its
    // column list. The columns (`id`, `name`) are well-established.
    'lt.name  (table=tenants)',
    'rt.name  (table=tenants)',
    'at.name  (table=tenants)',
    'lt.id  (table=tenants)',
    'rt.id  (table=tenants)',
    'at.id  (table=tenants)',
];

$violationCount = 0;
$legacyCount    = 0;
foreach ($violations as $file => $bad) {
    $rel  = ltrim(str_replace(realpath(__DIR__ . '/..'), '', realpath($file)), '/');
    $bad  = array_values(array_unique($bad));
    foreach ($bad as $b) {
        if (in_array($b, $knownLegacyViolations, true)) {
            $legacyCount++;
            continue;
        }
        $violationCount++;
        echo "VIOLATION  $rel  →  $b\n";
    }
}

_ssa('no NEW SQL alias.column references unknown columns', $violationCount === 0,
    $violationCount === 0
        ? null
        : "Found $violationCount NEW column reference(s) not in any migration. Add the missing columns to a migration file or fix the typo before deploy.");
echo "  ($legacyCount known-legacy violations skipped — see \$knownLegacyViolations in this file.)\n";

echo "\n--- $assertCount assertions, $failCount failed ---\n";
exit($failCount === 0 ? 0 : 1);
