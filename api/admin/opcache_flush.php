<?php
/**
 * POST /api/admin/opcache_flush.php
 *
 * One-shot PHP OPcache flush. Exists to break the chicken-and-egg where
 * a deploy lands fresh .php files on disk but the FPM workers keep
 * executing the bytecode from before the deploy — so backend fixes
 * silently never take effect even though static frontend assets DO
 * update immediately.
 *
 * Why a brand-new file? Existing endpoints (migrate.php, field_map.php)
 * are already in OPcache's index — when we add an opcache_reset() call
 * to them on disk, OPcache won't notice (validate_timestamps=0 is the
 * recommended prod setting on this hosting). A previously-unseen path
 * forces FPM to compile and execute the fresh bytecode the first time
 * it's requested. From inside that fresh request we can opcache_reset()
 * the entire pool.
 *
 * RBAC: integrations.field_map.manage (the closest existing admin-only
 * permission — same as migrate.php). Privileged because clearing
 * opcache imposes a temporary latency cost on the next few requests
 * (every script has to re-compile).
 *
 * Response:
 *   { ok: true, available: bool, reset: bool, status: opcache_get_status() }
 */
declare(strict_types=1);

// Inline fatal-error trap (see field_map.php for rationale). Belt-and-
// braces in case api_bootstrap's global handler hasn't deployed yet.
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err === null) return;
    $fatalMask = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
    if (($err['type'] & $fatalMask) === 0) return;
    if (headers_sent()) return;
    @http_response_code(500);
    @header('Content-Type: application/json; charset=utf-8');
    while (ob_get_level() > 0) { @ob_end_clean(); }
    echo json_encode([
        'error' => 'Fatal PHP error: ' . $err['message'],
        'status' => 500, 'kind' => 'fatal',
        'file' => isset($err['file']) ? basename((string) $err['file']) : null,
        'line' => $err['line'] ?? null,
        'origin' => 'opcache_flush.php inline shutdown handler',
    ], JSON_UNESCAPED_SLASHES);
});

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
rbac_legacy_require($user, 'integrations.field_map.manage');

if (api_method() !== 'POST') api_error('Method not allowed', 405);

$available = function_exists('opcache_reset');
$reset     = false;
if ($available) {
    // @ swallows the warning emitted when opcache is enabled-for-cli but
    // not for the SAPI — harmless in either direction.
    $reset = (bool) @opcache_reset();
}

// opcache_get_status() returns a giant dump; we trim to summary stats so
// the response stays small but the operator can confirm the cache is now
// effectively empty.
$summary = null;
if (function_exists('opcache_get_status')) {
    $full = @opcache_get_status(false);
    if (is_array($full)) {
        $summary = [
            'enabled'              => $full['opcache_enabled'] ?? null,
            'cache_full'           => $full['cache_full'] ?? null,
            'num_cached_scripts'   => $full['opcache_statistics']['num_cached_scripts'] ?? null,
            'num_cached_keys'      => $full['opcache_statistics']['num_cached_keys'] ?? null,
            'hits'                 => $full['opcache_statistics']['hits'] ?? null,
            'misses'               => $full['opcache_statistics']['misses'] ?? null,
        ];
    }
}

api_ok([
    'ok'        => $reset || !$available,
    'available' => $available,
    'reset'     => $reset,
    'summary'   => $summary,
    'hint'      => $available
        ? 'OPcache flushed. Other FPM workers in the pool may still hold old bytecode until they each serve one more request.'
        : 'OPcache extension is not loaded on this server — nothing to flush. Backend changes should take effect immediately on disk write.',
]);
