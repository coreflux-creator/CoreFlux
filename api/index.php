<?php
/**
 * CoreFlux Central API Router — entry point.
 *
 * Routes /api/<module_id>/<endpoint>[/...subpath] to the matching
 * /modules/<module_id>/api/<endpoint>.php file.
 *
 * Path parsing + file resolution live in /app/core/api_router.php so they
 * can be unit-tested without an HTTP request. This file is just the glue.
 *
 * Coexists with direct-file URLs (per HARD_RULES R1). New code uses the
 * router; existing direct-file callers (including the April-16 React
 * bundle) continue to work unchanged.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/ModuleRegistry.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/api_router.php';

// Tracing
$requestId = bin2hex(random_bytes(8));
header("X-Request-ID: {$requestId}");

// Parse
$parsed = apiRouterParse(
    $_SERVER['PATH_INFO']    ?? '',
    $_SERVER['REQUEST_URI']  ?? '/'
);
if (!$parsed['ok']) {
    api_error('Bad request: ' . $parsed['error'], $parsed['status'], [
        'request_id' => $requestId,
    ]);
}

// The central router is a URL-flat entry point, so tenant-scoped helpers
// cannot infer the owning module from REQUEST_URI. Pin the parsed module
// before authentication or any endpoint query, and normalize v1 subpaths
// into the legacy id/action query parameters used by module endpoints.
apiRouterApplyV1Compatibility($parsed);
setRequestModuleScope($parsed['module_id']);

// Resolve module + endpoint file
$endpointFile = apiRouterResolveFile($parsed['module_id'], $parsed['endpoint']);
if ($endpointFile === null) {
    // Distinguish "module not registered" from "endpoint file missing"
    $registry = ModuleRegistry::getInstance();
    if (!$registry->hasModule($parsed['module_id'])) {
        api_error("Module not found: {$parsed['module_id']}", 404, [
            'request_id' => $requestId,
        ]);
    }
    api_error("Endpoint not found: {$parsed['module_id']}/{$parsed['endpoint']}", 404, [
        'request_id' => $requestId,
    ]);
}

// Auth (idempotent — module file may also call api_require_auth())
$authCtx = api_require_auth();

// Module-level RBAC gate. The user must hold at least the base
// '<module>.view' permission to reach any endpoint inside that module.
// Per-endpoint permissions remain the module's responsibility (it can call
// rbac_legacy_require($authCtx['user'], 'foo.bar.action') itself).
$baseModulePerm = apiRouterBasePermission($parsed);
if ($baseModulePerm !== null) {
    rbac_legacy_require($authCtx['user'], $baseModulePerm);
}

// Stash router context for the included module file
$GLOBALS['CF_API_REQUEST_ID'] = $requestId;
$GLOBALS['CF_API_MODULE_ID']  = $parsed['module_id'];
$GLOBALS['CF_API_ENDPOINT']   = $parsed['endpoint'];
$GLOBALS['CF_API_SUBPATH']    = $parsed['subpath'];

// Dispatch
require $endpointFile;
