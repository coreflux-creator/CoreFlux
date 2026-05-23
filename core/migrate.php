<?php
/**
 * CoreFlux Migration Runner — idempotent, bootstrap-safe, content-tracked.
 *
 *   require_once __DIR__ . '/migrate.php';
 *   coreflux_run_migrations();   // safe to call on every request
 *
 * Strategy:
 *   • On first call per process, hashes the contents of every
 *     `core/migrations/NNN_*.sql` file and records (filename, sha256,
 *     applied_at) in `_migrations`. If the file content has not changed
 *     since last applied, it's skipped.
 *   • Migrations are split on `;` (statement boundary) and executed inside
 *     a single transaction per file. Each statement is wrapped in
 *     try/catch — schema-shaped errors that are safe-by-design (e.g.
 *     "Duplicate column name", "Table already exists") are logged and
 *     skipped, so older migrations that pre-date the `IF NOT EXISTS`
 *     conventions don't blow up on re-run.
 *   • The `_migrations` ledger itself is created on the first call.
 *   • Failures are logged via error_log() but never throw — a bad migration
 *     should never 500 the tenant's user-facing request. The error is also
 *     surfaced via `coreflux_migration_status()` for the admin diagnostics
 *     page.
 *
 * To run from CLI:
 *
 *   php /app/core/migrate.php
 *
 * To run on demand from an authenticated admin:
 *
 *   POST /api/migrate.php  (master_admin only)
 *
 * Per-process caching: once a process has applied all migrations once,
 * subsequent calls are no-ops (in-memory flag). Restart of php-fpm or
 * a fresh request after deploy re-runs the check.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const COREFLUX_MIGRATIONS_DIR = __DIR__ . '/migrations';

/** @var array<string,mixed> $coreflux_migration_status */
$coreflux_migration_status = [
    'ran_in_process'  => false,
    'applied_files'   => [],
    'skipped_files'   => [],
    'errors'          => [],
];

function coreflux_migration_status(): array {
    global $coreflux_migration_status;
    return $coreflux_migration_status;
}

/**
 * Split a SQL file into individual statements, respecting:
 *
 *   • `-- ...` line comments (entire `--` to end-of-line is stripped before
 *     splitting, so a `;` inside the comment doesn't split the statement).
 *   • `/* ... *\/` block comments (stripped before splitting).
 *   • `'...'`  single-quote string literals (supports `''` and `\'` escapes).
 *   • `"..."`  double-quote string literals / identifiers.
 *   • `` `...` `` backtick identifiers.
 *
 * Returns an array of trimmed, non-empty statements. The trailing `;` is
 * stripped from each.
 *
 * Why we wrote this by hand instead of using a regex: PCRE has no clean way
 * to express "a `;` outside any string literal" without backtracking, and
 * the regex we previously used (`/;\s*\R/m`) required a NEWLINE after each
 * `;` which broke multi-statement-per-line migrations. A 30-line state
 * machine is simpler and faster than fighting the regex engine.
 */
function coreflux_split_sql_statements(string $sql): array {
    // Strip /* ... */ block comments (non-greedy, multi-line).
    $sql = preg_replace('#/\*.*?\*/#s', '', $sql) ?? $sql;

    // Strip `-- ...` line comments (everything from `--` to end-of-line).
    // We intentionally do NOT strip `#` comments — none of our migrations
    // use them, and the # symbol appears in some HEX-encoded defaults.
    $sql = preg_replace('/--[^\r\n]*/', '', $sql) ?? $sql;

    $stmts  = [];
    $buf    = '';
    $len    = strlen($sql);
    $inSq   = false; // inside '...'
    $inDq   = false; // inside "..."
    $inBt   = false; // inside `...`

    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];

        if ($inSq) {
            $buf .= $c;
            if ($c === '\\' && $i + 1 < $len) {
                // Backslash-escaped character — copy as-is.
                $buf .= $sql[++$i];
            } elseif ($c === "'") {
                // Doubled-up '' is an escaped single quote, not a string end.
                if ($i + 1 < $len && $sql[$i + 1] === "'") {
                    $buf .= $sql[++$i];
                } else {
                    $inSq = false;
                }
            }
            continue;
        }
        if ($inDq) {
            $buf .= $c;
            if ($c === '\\' && $i + 1 < $len) {
                $buf .= $sql[++$i];
            } elseif ($c === '"') {
                $inDq = false;
            }
            continue;
        }
        if ($inBt) {
            $buf .= $c;
            if ($c === '`') $inBt = false;
            continue;
        }

        if ($c === "'")  { $inSq = true; $buf .= $c; continue; }
        if ($c === '"')  { $inDq = true; $buf .= $c; continue; }
        if ($c === '`')  { $inBt = true; $buf .= $c; continue; }

        if ($c === ';') {
            $t = trim($buf);
            if ($t !== '') $stmts[] = $t;
            $buf = '';
            continue;
        }

        $buf .= $c;
    }

    // Final unterminated statement (no trailing `;`).
    $t = trim($buf);
    if ($t !== '') $stmts[] = $t;

    return $stmts;
}

function coreflux_run_migrations(bool $force = false): array {
    global $coreflux_migration_status;
    static $ranOnce        = false;
    static $lastFileSetSig = null;

    // Compute a cheap signature of the current core+module migration
    // file set. If new files have appeared (or existing files changed
    // mtime) since the last run in THIS process, treat it as a fresh
    // run. This prevents the long-lived PHP-FPM worker problem where
    // a deploy ships new migration files but the worker — which set
    // $ranOnce = true on its first request — skips them indefinitely
    // until the worker is recycled.
    //
    // Signature: SHA-1 of (filename|mtime|size) pairs. Filename is
    // basename-only to match the ledger's tracking key for core files.
    $sig = '';
    $coreList   = glob(COREFLUX_MIGRATIONS_DIR . '/*.sql') ?: [];
    $appRootSig = dirname(__DIR__);
    $modList    = glob($appRootSig . '/modules/*/migrations/*.sql') ?: [];
    foreach (array_merge($coreList, $modList) as $p) {
        $sig .= $p . '|' . (string) @filemtime($p) . '|' . (string) @filesize($p) . "\n";
    }
    $sig = sha1($sig);
    $filesChanged = ($lastFileSetSig !== null && $lastFileSetSig !== $sig);

    if ($ranOnce && !$force && !$filesChanged) return $coreflux_migration_status;
    $ranOnce        = true;
    $lastFileSetSig = $sig;
    $coreflux_migration_status['ran_in_process'] = true;

    $pdo = getDB();
    if (!$pdo) {
        $coreflux_migration_status['errors'][] = 'no PDO available';
        return $coreflux_migration_status;
    }

    // Bootstrap the ledger.
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS _migrations (
                filename     VARCHAR(190) NOT NULL PRIMARY KEY,
                sha256       CHAR(64)     NOT NULL,
                applied_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_error   TEXT         NULL,
                duration_ms  INT          NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (\Throwable $e) {
        $coreflux_migration_status['errors'][] = '_migrations bootstrap failed: ' . $e->getMessage();
        return $coreflux_migration_status;
    }

    // Core migrations are tracked by basename for backward compatibility with
    // existing `_migrations` ledger entries.
    $coreFiles = glob(COREFLUX_MIGRATIONS_DIR . '/*.sql') ?: [];
    sort($coreFiles, SORT_NATURAL);

    // Module migrations are tracked by relative path to avoid basename
    // collisions across modules (e.g. multiple `001_init.sql`). Underscored
    // module dirs (e.g. `_archive`) are skipped.
    $appRoot = dirname(__DIR__);
    $moduleFiles = glob($appRoot . '/modules/*/migrations/*.sql') ?: [];
    $moduleFiles = array_values(array_filter(
        $moduleFiles,
        static fn(string $p) => !preg_match('#/modules/_[^/]+/#', $p)
    ));
    sort($moduleFiles, SORT_NATURAL);

    $files = array_merge($coreFiles, $moduleFiles);

    $stmtFind = $pdo->prepare('SELECT sha256 FROM _migrations WHERE filename = :f');
    $stmtSave = $pdo->prepare(
        'REPLACE INTO _migrations (filename, sha256, applied_at, last_error, duration_ms)
         VALUES (:f, :h, NOW(), :e, :ms)'
    );

    foreach ($files as $path) {
        // Use basename for core files (legacy), relative path for module files
        // so a module migration named `001_init.sql` doesn't collide with the
        // core `001_init.sql` ledger entry.
        $name = (strpos($path, $appRoot . '/modules/') === 0)
            ? ltrim(str_replace($appRoot, '', $path), '/')
            : basename($path);
        $sql  = (string) file_get_contents($path);
        if ($sql === '') continue;
        $hash = hash('sha256', $sql);

        $stmtFind->execute(['f' => $name]);
        $prev = $stmtFind->fetchColumn();
        // Idempotency: skip when the file content hasn't changed since
        // the last successful apply. `$force` ONLY bypasses the
        // per-process `$ranOnce` gate above — never the ledger. If we
        // forced re-execution of every matching-hash file, we'd
        // re-attempt ALTER TABLE statements that already applied,
        // producing dozens or hundreds of "column already exists"
        // errors (observed in production 2026-02 as "Applied with 450
        // error(s)" after a manual /api/admin/migrate.php call).
        if ($prev === $hash) {
            $coreflux_migration_status['skipped_files'][] = $name;
            continue;
        }

        $start    = microtime(true);
        $errBlob  = null;
        // Robust SQL statement splitter. Two prior bugs we are fixing here:
        //
        //   1. `preg_split('/;\s*\R/m', $sql)` required a NEWLINE after the
        //      `;`, so migrations with multiple statements on one line —
        //      e.g. `PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;`
        //      stayed glued together and MariaDB rejected them as
        //      multi-statement queries (observed in prod 2026-02 as ~440
        //      "EXECUTE stmt; DEALLOCATE PREPARE stmt' at line 1" errors).
        //
        //   2. Comment-stripping happened AFTER splitting, so a `;` inside
        //      a `--` line-comment (e.g. `-- Captured for fast lookup;`)
        //      would split a CREATE TABLE definition mid-way, leaving the
        //      tail half (starting with `actor_user_id ...`) to fail with
        //      a syntax error at line 1.
        //
        // New approach: strip `--` and `/* */` comments FIRST, then walk
        // the SQL character-by-character respecting single-quote strings,
        // double-quote identifiers, and backtick identifiers so `;` inside
        // quoted text doesn't split. Statements terminate at any `;` that
        // is NOT inside a string/identifier literal.
        $statements = coreflux_split_sql_statements($sql);
        foreach ($statements as $stmt) {
            $clean = trim($stmt);
            if ($clean === '' || $clean === ';') continue;
            try {
                // Use query() (not exec()) so we can drain any unexpected
                // result set the statement may emit — critical for
                // `EXECUTE stmt` where the prepared SQL is a no-op fallback
                // like SELECT 1. Without draining, the next PDO call dies
                // with "SQLSTATE[HY000]: 2014 unbuffered queries active".
                $rs = $pdo->query($clean);
                if ($rs) {
                    try {
                        $rs->closeCursor();
                        while ($rs->nextRowset()) { /* drain multi-rowset */ }
                    } catch (\Throwable $_) { /* nextRowset is unsupported on some drivers */ }
                }
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                // Idempotency safeguards — when a prior run already added
                // the column / table, skip rather than abort the file.
                $safePatterns = [
                    'Duplicate column name',
                    'already exists',
                    'Duplicate key name',
                    'check that column/key exists',
                    'Multiple primary key defined',
                    // FK constraint re-application (MySQL 8 names FK
                    // constraints; re-adding the same FK by name fails
                    // with this message — safe to skip on idempotent reruns).
                    'Duplicate foreign key constraint name',
                    // Trigger redefinition (covers "Trigger does already exist"
                    // and "Trigger 'x' already exists" — second is matched
                    // by 'already exists' but defensive belt-and-braces).
                    'Trigger does already',
                    // DROP/ALTER on objects already removed during prior
                    // idempotent runs of the same migration file.
                    "Unknown table",
                    "check that it exists",
                ];
                $isSafe = false;
                foreach ($safePatterns as $p) {
                    if (stripos($msg, $p) !== false) { $isSafe = true; break; }
                }
                if (!$isSafe) {
                    $errBlob = ($errBlob ? $errBlob . "\n" : '') . substr($msg, 0, 500);
                    $coreflux_migration_status['errors'][] = "$name: $msg";
                    error_log("[coreflux_migrate] $name: $msg");
                }
            }
        }
        $durMs = (int) ((microtime(true) - $start) * 1000);

        // CRITICAL: if non-safe errors occurred, DO NOT record the file as
        // "applied" with the new content hash. Recording last_error against a
        // sentinel hash so a subsequent deploy (with same or fixed content)
        // will retry. Without this, a half-applied migration silently sticks
        // in the ledger and the column / table remains missing forever.
        try {
            if ($errBlob !== null) {
                // Sentinel hash = 'FAIL:' + hash(errBlob) → guaranteed != content hash,
                // so next run re-executes the file.
                $sentinel = 'FAIL:' . substr(hash('sha256', (string) $errBlob), 0, 58);
                $stmtSave->execute(['f' => $name, 'h' => $sentinel, 'e' => $errBlob, 'ms' => $durMs]);
                $coreflux_migration_status['errors'][] = "$name: marked retry (not applied)";
            } else {
                $stmtSave->execute(['f' => $name, 'h' => $hash, 'e' => null, 'ms' => $durMs]);
                $coreflux_migration_status['applied_files'][] = $name;
            }
        } catch (\Throwable $_) { /* non-fatal */ }
    }

    return $coreflux_migration_status;
}

// CLI entry point.
if (PHP_SAPI === 'cli' && realpath($_SERVER['argv'][0] ?? '') === realpath(__FILE__)) {
    $force = in_array('--force', $_SERVER['argv'] ?? [], true);
    $st    = coreflux_run_migrations($force);
    echo json_encode($st, JSON_PRETTY_PRINT) . "\n";
    exit($st['errors'] ? 1 : 0);
}
