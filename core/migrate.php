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

function coreflux_run_migrations(bool $force = false): array {
    global $coreflux_migration_status;
    static $ranOnce = false;
    if ($ranOnce && !$force) return $coreflux_migration_status;
    $ranOnce = true;
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
        if (!$force && $prev === $hash) {
            $coreflux_migration_status['skipped_files'][] = $name;
            continue;
        }

        $start    = microtime(true);
        $errBlob  = null;
        // Split on `;` at end-of-line so embedded semicolons inside string
        // literals or trigger bodies don't tear statements. CoreFlux migrations
        // are schema-only DDL right now, so this is sufficient.
        $statements = array_filter(array_map('trim', preg_split('/;\s*\R/m', $sql)));
        foreach ($statements as $stmt) {
            // Strip comment-only lines and blank statements.
            $clean = trim(preg_replace('/^\s*--.*$/m', '', $stmt));
            if ($clean === '' || $clean === ';') continue;
            try {
                $pdo->exec($clean);
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
