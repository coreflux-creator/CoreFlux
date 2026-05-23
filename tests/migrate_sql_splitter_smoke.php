<?php
/**
 * Smoke for coreflux_split_sql_statements() — the new quote-aware,
 * comment-aware SQL statement splitter in core/migrate.php.
 *
 * This test exists because the prior splitter (`preg_split('/;\s*\R/m')`)
 * produced ~440 false-positive errors in prod on 2026-02-XX:
 *
 *   • Multi-statement-per-line files like
 *     "PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;"
 *     stayed glued together (no newline between `;` characters) and
 *     MariaDB rejected them as multi-query statements.
 *
 *   • A `;` inside a `--` line comment (`-- Captured for fast lookup;`)
 *     in 069_entity_sync_history.sql split the CREATE TABLE definition
 *     mid-way, producing nonsense statements and syntax errors.
 *
 * This test exercises both regression cases plus a handful of edge cases
 * (escaped quotes, block comments, backtick identifiers, unterminated
 * tail statement).
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/migrate.php';

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};

echo "Splitter — regression: multi-statement single-line PREPARE/EXECUTE/DEALLOCATE\n";
$sql = "PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;";
$out = coreflux_split_sql_statements($sql);
$assert('splits into exactly 3 statements', count($out) === 3);
$assert('first  is PREPARE stmt FROM @sql', ($out[0] ?? '') === 'PREPARE stmt FROM @sql');
$assert('second is EXECUTE stmt',           ($out[1] ?? '') === 'EXECUTE stmt');
$assert('third  is DEALLOCATE PREPARE stmt', ($out[2] ?? '') === 'DEALLOCATE PREPARE stmt');

echo "\nSplitter — regression: `;` inside `--` line comment must not split\n";
$sql = <<<SQL
CREATE TABLE foo (
    id BIGINT NOT NULL,
    -- Captured for fast lookup; matches external_entity_mappings.
    name VARCHAR(64) NOT NULL,
    -- Who triggered this; populated for manual button presses.
    created_at TIMESTAMP NOT NULL
);
SQL;
$out = coreflux_split_sql_statements($sql);
$assert('comments-with-semicolons do NOT split CREATE TABLE',
    count($out) === 1);
$assert('the single statement contains all 3 columns',
    strpos($out[0] ?? '', 'id BIGINT') !== false
    && strpos($out[0] ?? '', 'name VARCHAR') !== false
    && strpos($out[0] ?? '', 'created_at TIMESTAMP') !== false);
$assert('comment text is stripped from the statement',
    strpos($out[0] ?? '', 'Captured for fast lookup') === false
    && strpos($out[0] ?? '', 'Who triggered this') === false);

echo "\nSplitter — `;` inside single-quoted string literal must not split\n";
$sql = "INSERT INTO t (s) VALUES ('a;b;c'); SELECT 1;";
$out = coreflux_split_sql_statements($sql);
$assert('splits into 2 statements (not 4)',          count($out) === 2);
$assert("preserves 'a;b;c' inside string literal",
    strpos($out[0] ?? '', "'a;b;c'") !== false);
$assert('second statement is SELECT 1',              ($out[1] ?? '') === 'SELECT 1');

echo "\nSplitter — doubled-up '' single-quote escape\n";
$sql = "INSERT INTO t (s) VALUES ('it''s; ok'); DROP TABLE t;";
$out = coreflux_split_sql_statements($sql);
$assert('splits into 2 statements',                  count($out) === 2);
$assert("preserves doubled-up '' inside literal",
    strpos($out[0] ?? '', "'it''s; ok'") !== false);
$assert('DROP TABLE is its own statement',           ($out[1] ?? '') === 'DROP TABLE t');

echo "\nSplitter — `;` inside double-quoted identifier must not split\n";
$sql = 'CREATE TABLE "weird;name" (id INT); SELECT 1;';
$out = coreflux_split_sql_statements($sql);
$assert('splits into 2 statements', count($out) === 2);
$assert('preserves "weird;name" identifier',
    strpos($out[0] ?? '', '"weird;name"') !== false);

echo "\nSplitter — `;` inside backtick identifier must not split\n";
$sql = "CREATE TABLE `odd;table` (id INT); SELECT 1;";
$out = coreflux_split_sql_statements($sql);
$assert('splits into 2 statements', count($out) === 2);
$assert('preserves `odd;table` identifier',
    strpos($out[0] ?? '', '`odd;table`') !== false);

echo "\nSplitter — `/* ... */` block comment with `;` inside must not split\n";
$sql = "CREATE TABLE foo (id INT) /* hello; world; */; SELECT 1;";
$out = coreflux_split_sql_statements($sql);
$assert('splits into 2 statements',           count($out) === 2);
$assert('block comment content is stripped',
    strpos($out[0] ?? '', 'hello') === false);

echo "\nSplitter — unterminated final statement (no trailing `;`) is still returned\n";
$sql = "SELECT 1;\nSELECT 2";
$out = coreflux_split_sql_statements($sql);
$assert('returns 2 statements',  count($out) === 2);
$assert('second statement is SELECT 2', ($out[1] ?? '') === 'SELECT 2');

echo "\nSplitter — whitespace-only or empty input yields empty array\n";
$assert('empty string → []',       coreflux_split_sql_statements('') === []);
$assert('whitespace → []',         coreflux_split_sql_statements("   \n\n  \t") === []);
$assert('semicolon-only → []',     coreflux_split_sql_statements(';;;') === []);

echo "\nSplitter — real-world fixture: 069_entity_sync_history.sql\n";
$real = (string) file_get_contents(__DIR__ . '/../core/migrations/069_entity_sync_history.sql');
$out  = coreflux_split_sql_statements($real);
$assert('exactly 1 statement (single CREATE TABLE)', count($out) === 1);
$assert('the statement starts with CREATE TABLE IF NOT EXISTS entity_sync_history',
    str_starts_with($out[0] ?? '', 'CREATE TABLE IF NOT EXISTS entity_sync_history'));
$assert('the statement ends with the ENGINE clause',
    str_ends_with($out[0] ?? '', 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'));

echo "\nSplitter — real-world fixture: 007_subtenant_provisioning.sql (multi-stmt per line)\n";
$real = (string) file_get_contents(__DIR__ . '/../core/migrations/007_subtenant_provisioning.sql');
$out  = coreflux_split_sql_statements($real);
$assert('produces > 5 statements (each PREPARE/EXECUTE/DEALLOCATE is its own)',
    count($out) > 5);
$assert('no statement contains "EXECUTE stmt; DEALLOCATE" (would mean splitter still gluing)',
    !array_filter($out, static fn(string $s) => strpos($s, 'EXECUTE stmt; DEALLOCATE') !== false));

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
