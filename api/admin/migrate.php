<?php
/**
 * POST /api/admin/migrate.php
 *
 * Force-runs all pending CoreFlux migrations (`core/migrations/*.sql`
 * and `modules/* /migrations/*.sql`) and returns the status payload.
 *
 * Use this when a deploy lands new migration files but the long-lived
 * PHP-FPM worker has $ranOnce=true cached and is skipping them. The
 * migrate runner's "file-set signature" auto-detection in
 * core/migrate.php should handle this automatically since 2026-02, but
 * this endpoint exists as a manual safety net.
 *
 * RBAC: integrations.field_map.manage (the closest existing admin-only
 * permission — master_admin + tenant_admin via the `integrations:admin`
 * level role bundle). Migration application is a privileged action.
 *
 * Response:
 *   {
 *     ok: true,
 *     status: {
 *       ran_in_process: bool,
 *       skipped_files:  [filename, ...],
 *       applied_files:  [filename, ...],   // populated by the runner
 *       errors:         [{file, msg}, ...]
 *     }
 *   }
 *
 * The runner is idempotent (content-hashed in `_migrations`) so calling
 * this repeatedly is safe — it'll only execute files whose content
 * differs from the recorded hash.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';
require_once __DIR__ . '/../../core/migrate.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
rbac_legacy_require($user, 'integrations.field_map.manage');

if (api_method() !== 'POST') api_error('Method not allowed', 405);

$status = coreflux_run_migrations(true);   // force=true bypasses $ranOnce

// Flush PHP OPcache so any updated .php files on disk are picked up by
// the long-lived FPM workers immediately. Symptom this fixes: after a
// deploy, a .php file is updated on disk but FPM workers keep running
// the cached bytecode from before the deploy — so backend bug fixes
// appear to not deploy at all (observed 2026-02 on the field_map.php
// endpoint where new try/catch blocks weren't executing).
//
// opcache_reset() invalidates EVERY cached script in this worker. Other
// FPM workers in the pool still hold their old caches until each one
// services a request that re-touches a script, OR until they're
// recycled. We accept that staleness — the typical CoreFlux pool is
// small enough that a few subsequent requests will round-robin through
// every worker.
$opcache = ['available' => false, 'reset' => false];
if (function_exists('opcache_reset')) {
    $opcache['available'] = true;
    $opcache['reset']     = (bool) @opcache_reset();
}

api_ok([
    'ok'      => empty($status['errors'] ?? []),
    'status'  => $status,
    'opcache' => $opcache,
]);
