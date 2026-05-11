<?php
/**
 * POST /api/admin/retry-migration
 *
 * Body: { "file": "modules/time/migrations/007_backfill_person_id.sql" }
 *  - OR -
 * Body: { "all_failed": true }
 *
 * Deletes the `_migrations` ledger row(s) for the given file (or for every
 * row whose `sha256` starts with the `FAIL:` sentinel), then re-runs
 * `coreflux_run_migrations(force=true)` so the file is retried immediately.
 *
 * master_admin only. Audit-logged.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/migrate.php';

$ctx = api_require_role(['master_admin']);

if (api_method() !== 'POST') api_error('Method not allowed', 405);

$body       = api_json_body();
$file       = isset($body['file'])       ? (string) $body['file']    : '';
$allFailed  = !empty($body['all_failed']);
if (!$file && !$allFailed) api_error('file or all_failed required', 422);

$pdo = getDB();
$removed = [];

try {
    if ($allFailed) {
        $rows = $pdo->query("SELECT filename FROM _migrations WHERE sha256 LIKE 'FAIL:%'")->fetchAll(PDO::FETCH_COLUMN);
        if ($rows) {
            $in = implode(',', array_fill(0, count($rows), '?'));
            $del = $pdo->prepare("DELETE FROM _migrations WHERE filename IN ($in)");
            $del->execute($rows);
            $removed = $rows;
        }
    } else {
        $del = $pdo->prepare('DELETE FROM _migrations WHERE filename = :f');
        $del->execute(['f' => $file]);
        if ($del->rowCount() > 0) $removed[] = $file;
    }
} catch (\Throwable $e) {
    api_error('Failed to clear ledger row: ' . $e->getMessage(), 500);
}

// Force re-run.
$status = coreflux_run_migrations(true);

// Best-effort audit.
try {
    if (function_exists('audit_log')) {
        audit_log($ctx['tenant_id'] ?? null, $ctx['user']['id'] ?? null, 'admin.retry_migration', [
            'removed_files' => $removed,
            'errors'        => $status['errors'] ?? [],
        ]);
    }
} catch (\Throwable $_) { /* best effort */ }

api_ok([
    'ok'              => true,
    'cleared_ledger'  => $removed,
    'migration_status'=> $status,
]);
