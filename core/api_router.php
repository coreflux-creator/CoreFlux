<?php
/**
 * CoreFlux API Router — path parsing + dispatch helpers.
 *
 * Extracted so the parsing is a pure function and unit-testable without
 * standing up a full HTTP request.
 */

declare(strict_types=1);

/**
 * Parse a CoreFlux API request into (api_version, module_id, endpoint, subpath).
 *
 * Inputs are taken explicitly so this function is pure and testable:
 *   - $pathInfo:   what Apache / PHP-FPM put in $_SERVER['PATH_INFO']
 *   - $requestUri: what arrived in $_SERVER['REQUEST_URI']
 *
 * Either source can drive the parse. PATH_INFO wins if non-empty, else we
 * extract from REQUEST_URI by stripping the `/api/` (and optional `index.php/`)
 * prefix. An optional version segment (`v1`) is accepted before the module id.
 *
 * Returns:
 *   ['ok' => true,  'api_version' => 'v1', 'module_id' => 'people',
 *    'endpoint' => 'employees', 'subpath' => ['123']]
 * or
 *   ['ok' => false, 'error' => 'human-readable message', 'status' => 400]
 *
 * Both module_id and endpoint must match /^[a-z][a-z0-9_]*$/ — defence in
 * depth against path traversal even if a host bypasses .htaccess rewrites.
 */
function apiRouterParse(string $pathInfo, string $requestUri): array {
    $path = $pathInfo;
    if ($path === '') {
        $uri = parse_url($requestUri, PHP_URL_PATH) ?? '/';
        if (preg_match('#^/api/(?:index\.php/?)?(.*)$#', $uri, $m)) {
            $path = '/' . $m[1];
        }
    }
    $path = '/' . trim($path, '/');
    $segments = $path === '/' ? [] : array_values(array_filter(explode('/', $path), 'strlen'));

    $apiVersion = null;
    if (isset($segments[0]) && preg_match('/^v[1-9][0-9]*$/', $segments[0])) {
        $apiVersion = array_shift($segments);
    }

    if (count($segments) < 2) {
        return [
            'ok'     => false,
            'status' => 400,
            'error'  => 'expected /api/<module>/<endpoint> or /api/v1/<module>/<resource>',
        ];
    }

    $moduleId = array_shift($segments);
    $endpoint = array_shift($segments);

    $idRe       = '/^[a-z][a-z0-9_]*$/';
    $endpointRe = '/^[a-z][a-z0-9_-]*$/';   // kebab-case allowed for spec §38 paths
    if (!preg_match($idRe, $moduleId)) {
        return ['ok' => false, 'status' => 400, 'error' => "invalid module id: {$moduleId}"];
    }
    if (!preg_match($endpointRe, $endpoint)) {
        return ['ok' => false, 'status' => 400, 'error' => "invalid endpoint name: {$endpoint}"];
    }

    return [
        'ok'          => true,
        'api_version' => $apiVersion,
        'module_id'   => $moduleId,
        'endpoint'    => $endpoint,
        'subpath'     => $segments,
    ];
}

/**
 * Normalize v1 resource/action paths into legacy query keys for endpoint
 * compatibility during the migration window.
 *
 * Examples:
 *   /api/v1/time/entries/123         -> $_GET['id'] = 123
 *   /api/v1/time/entries/123/approve -> $_GET['id'] = 123, $_GET['action'] = approve
 *
 * Explicit query-string values win so old callers remain stable.
 */
function apiRouterApplyV1Compatibility(array $parsed): void {
    if (($parsed['api_version'] ?? null) !== 'v1') return;
    $subpath = $parsed['subpath'] ?? [];
    if (!is_array($subpath) || $subpath === []) return;

    $first = (string) ($subpath[0] ?? '');
    if ($first !== '' && ctype_digit($first) && !isset($_GET['id'])) {
        $_GET['id'] = $first;
    }

    $second = (string) ($subpath[1] ?? '');
    if ($second !== '' && preg_match('/^[a-z][a-z0-9_-]*$/', $second) && !isset($_GET['action'])) {
        $_GET['action'] = str_replace('-', '_', $second);
    }
}

/**
 * Resolve a parsed route to an absolute file path under /modules/<id>/api/.
 * Returns null if the module isn't registered or the endpoint file is missing.
 *
 * Tries the literal endpoint name first, then maps kebab-case to
 * snake_case (e.g. `journal-entries` → `journal_entries.php`) so spec
 * §38 paths line up with the existing snake_case file conventions.
 *
 * Caller is expected to have already loaded ModuleRegistry.
 */
function apiRouterResolveFile(string $moduleId, string $endpoint, ?string $modulesDir = null): ?string {
    $registry = ModuleRegistry::getInstance();
    if (!$registry->hasModule($moduleId)) return null;

    $modulesDir = $modulesDir ?? dirname(__DIR__) . '/modules';
    $candidates = [
        "{$modulesDir}/{$moduleId}/api/{$endpoint}.php",
    ];
    if (str_contains($endpoint, '-')) {
        $candidates[] = "{$modulesDir}/{$moduleId}/api/" . str_replace('-', '_', $endpoint) . '.php';
    }
    foreach ($candidates as $c) {
        if (file_exists($c)) return $c;
    }
    return null;
}
