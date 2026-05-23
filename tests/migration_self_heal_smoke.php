<?php
/**
 * Smoke for the migration-pending self-healing path (2026-02 follow-on
 * to sync-history shipping).
 *
 * The PHP-FPM worker problem: a deploy lands new migration files but
 * the long-lived worker has $ranOnce=true cached and skips them. The
 * worker keeps returning errors like
 *   "Table 'coreflux.entity_sync_history' doesn't exist"
 * until it's recycled.
 *
 * Three defenses are now in place:
 *   1. core/migrate.php — file-set signature detection so the runner
 *      auto-reruns when a new .sql file appears.
 *   2. /api/admin/migrate.php — manual force-rerun endpoint.
 *   3. /api/integrations/sync_history.php — graceful empty + hint
 *      when the table is missing (no 500).
 *   4. SyncHistoryDrawer — renders a "Run pending migrations" button
 *      when the API flags `migration_pending`.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$lint = function (string $p): bool {
    $o = []; $rc = 0; @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
    return $rc === 0;
};
$ROOT = realpath(__DIR__ . '/..');

echo "Defense 1 — core/migrate.php auto-detects new files\n";
$migrate = (string) file_get_contents("{$ROOT}/core/migrate.php");
$assert('declares lastFileSetSig static',
    strpos($migrate, 'static $lastFileSetSig = null;') !== false);
$assert('computes signature from file mtimes + sizes',
    strpos($migrate, "\$sig .= \$p . '|' . (string) @filemtime(\$p) . '|' . (string) @filesize(\$p)") !== false);
$assert('signature includes both core and module migration files',
    strpos($migrate, "glob(\$appRootSig . '/modules/*/migrations/*.sql')") !== false);
$assert('skips ranOnce gate when files have changed',
    strpos($migrate, 'if ($ranOnce && !$force && !$filesChanged) return') !== false);

echo "\nDefense 2 — /api/admin/migrate.php force-rerun endpoint\n";
$apiPath = "{$ROOT}/api/admin/migrate.php";
$api     = (string) file_get_contents($apiPath);
$assert('file exists + parses',                  strlen($api) > 0 && $lint($apiPath));
$assert('RBAC: integrations.field_map.manage',
    strpos($api, "rbac_legacy_require(\$user, 'integrations.field_map.manage')") !== false);
$assert('POST-only',                             strpos($api, "api_method() !== 'POST'") !== false);
$assert('calls coreflux_run_migrations(true) to bypass cache',
    strpos($api, 'coreflux_run_migrations(true)') !== false);
$assert('returns status payload with errors list',
    strpos($api, "'status' => \$status") !== false);

echo "\nDefense 3 — sync_history endpoint handles missing table\n";
$ep = (string) file_get_contents("{$ROOT}/api/integrations/sync_history.php");
$assert("catches PDOException with 'entity_sync_history doesn\\'t exist'",
    strpos($ep, "str_contains(\$e->getMessage(), 'entity_sync_history')") !== false
    && strpos($ep, "str_contains(\$e->getMessage(), \"doesn't exist\")") !== false);
$assert('returns empty rows + migration_pending flag (no 500)',
    strpos($ep, "'rows' => []") !== false
    && strpos($ep, "'migration_pending' => true") !== false);
$assert('hint message points operator at /api/admin/migrate.php',
    strpos($ep, '/api/admin/migrate.php') !== false);
$assert('re-throws other PDOExceptions (not silently swallowed)',
    strpos($ep, 'throw $e;') !== false);

echo "\nDefense 4 — SyncHistoryDrawer renders self-heal UI\n";
$drawer = (string) file_get_contents("{$ROOT}/dashboard/src/components/SyncHistoryDrawer.jsx");
$assert('reads migration_pending flag from response',
    strpos($drawer, 'const migrationPending = !!data?.migration_pending;') !== false);
$assert('declares runMigration handler',
    strpos($drawer, 'const runMigration = async () =>') !== false);
$assert('runMigration POSTs /api/admin/migrate.php',
    strpos($drawer, "api.post('/api/admin/migrate.php')") !== false);
$assert('renders amber "Migration pending" banner',
    strpos($drawer, 'data-testid="sync-history-migration-pending"') !== false
    && strpos($drawer, '<strong>Migration pending.</strong>') !== false);
$assert('renders "Run pending migrations" button with stable test id',
    strpos($drawer, 'data-testid="sync-history-run-migration"') !== false);
$assert('reloads the API on successful migration',
    strpos($drawer, 'if (reload) reload();') !== false);
$assert('empty state ONLY shown when migration is NOT pending',
    strpos($drawer, '!loading && !error && !migrationPending && rows.length === 0') !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
