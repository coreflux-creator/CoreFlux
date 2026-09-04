<?php
/**
 * API package coverage + drift smoke.
 *
 * Ensures every deployed PHP endpoint implementation appears exactly once in
 * the inventory, every preferred route resolves, and the generated OpenAPI
 * document is current.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/core/ModuleRegistry.php';
require_once $root . '/core/api_router.php';
require_once $root . '/scripts/build_api_package.php';

$pass = 0;
$fail = 0;
$assert = static function (string $message, bool $condition) use (&$pass, &$fail): void {
    if ($condition) {
        $pass++;
        echo "  ✓ {$message}\n";
    } else {
        $fail++;
        echo "  ✗ {$message}\n";
    }
};

$inventoryFile = $root . '/api/endpoints.json';
$openapiFile = $root . '/api/openapi.json';
$assert('endpoint inventory exists', is_file($inventoryFile));
$assert('OpenAPI document exists', is_file($openapiFile));

$inventory = json_decode((string) file_get_contents($inventoryFile), true);
$openapi = json_decode((string) file_get_contents($openapiFile), true);
$assert('endpoint inventory is valid JSON', is_array($inventory));
$assert('OpenAPI document is valid JSON', is_array($openapi));
$assert('contract uses OpenAPI 3.1', ($openapi['openapi'] ?? null) === '3.1.0');

$built = apiPackageBuildDocuments($root);
$assert('endpoint inventory is current', (string) file_get_contents($inventoryFile) === $built['inventory']);
$assert('OpenAPI document is current', (string) file_get_contents($openapiFile) === $built['openapi']);

$records = $inventory['endpoints'] ?? [];
$sources = array_column($records, 'source_file');
$paths = array_column($records, 'preferred_path');
$assert('every source file appears once', count($sources) === count(array_unique($sources)));
$assert('every preferred path appears once', count($paths) === count(array_unique($paths)));
$publicRecords = array_values(array_filter(
    $records,
    static fn(array $endpoint): bool => ($endpoint['audience'] ?? '') !== 'internal'
));
$assert('OpenAPI path count matches public inventory', count($openapi['paths'] ?? []) === count($publicRecords));
$assert('internal bridges stay out of the public OpenAPI contract',
    count($records) - count($publicRecords) === 2
    && ($openapi['x-inventory-endpoint-count'] ?? null) === count($records)
);
$operationIds = [];
foreach (($openapi['paths'] ?? []) as $operations) {
    foreach ($operations as $operation) {
        if (is_array($operation) && isset($operation['operationId'])) {
            $operationIds[] = (string) $operation['operationId'];
        }
    }
}
$assert('every OpenAPI operationId is unique',
    $operationIds !== [] && count($operationIds) === count(array_unique($operationIds))
);

ModuleRegistry::reset($root . '/modules');
$registry = ModuleRegistry::getInstance();
$expectedSources = [];
foreach ($registry->getModuleIds() as $moduleId) {
    $dir = $root . '/modules/' . $moduleId . '/api';
    foreach (apiPackagePhpFiles($dir, false) as $file) {
        $expectedSources[] = apiPackageRelativePath($root, $file);
    }
}
foreach (apiPackagePhpFiles($root . '/api', true) as $file) {
    $relative = apiPackageRelativePath($root, $file);
    if ($relative !== 'api/index.php') $expectedSources[] = $relative;
}
sort($expectedSources);
$actualSources = $sources;
sort($actualSources);
$assert('inventory covers every deployed endpoint implementation', $actualSources === $expectedSources);

$routeFailures = [];
foreach ($records as $endpoint) {
    $source = (string) $endpoint['source_file'];
    $path = (string) $endpoint['preferred_path'];
    if (str_starts_with($source, 'modules/')) {
        $parsed = apiRouterParse('', $path);
        $resolved = $parsed['ok']
            ? apiRouterResolveFile($parsed['module_id'], $parsed['endpoint'], null, $parsed['subpath'])
            : null;
        if ($resolved === null || apiPackageRelativePath($root, $resolved) !== $source) {
            $routeFailures[] = "{$path} -> {$source}";
        }
        continue;
    }

    if (!str_ends_with($path, '.php')) {
        $parsed = apiRouterParse('', $path);
        $resolved = $parsed['ok']
            ? apiRouterResolveFile($parsed['module_id'], $parsed['endpoint'], null, $parsed['subpath'])
            : null;
        if ($resolved === null || apiPackageRelativePath($root, $resolved) !== $source) {
            $routeFailures[] = "{$path} -> {$source}";
        }
    } elseif (!is_file($root . '/' . $source)) {
        $routeFailures[] = "{$path} -> missing {$source}";
    }
}
$assert('every preferred route resolves to its inventoried implementation', $routeFailures === []);
if ($routeFailures) {
    foreach ($routeFailures as $failure) echo "    {$failure}\n";
}

$mobile = apiRouterParse('', '/api/auth/mobile_login');
$mobileFile = apiRouterResolveFile('auth', 'mobile_login');
$assert('extensionless mobile login route resolves',
    $mobile['ok'] === true
    && $mobileFile !== null
    && str_ends_with(str_replace('\\', '/', $mobileFile), '/api/auth/mobile_login.php')
);
$assert('direct namespaces do not receive synthetic module permission',
    apiRouterBasePermission($mobile) === null
);

$indexSource = (string) file_get_contents($root . '/api/index.php');
$compatPos = strpos($indexSource, 'apiRouterApplyV1Compatibility($parsed)');
$resolvePos = strpos($indexSource, 'apiRouterResolveFile(');
$assert('v1 compatibility is applied before route dispatch',
    $compatPos !== false && $resolvePos !== false && $compatPos < $resolvePos
);

$bootstrap = (string) file_get_contents($root . '/core/api_bootstrap.php');
$assert('CORS allows bearer authentication', str_contains($bootstrap, 'Access-Control-Allow-Headers: Authorization'));

$jwtSource = (string) file_get_contents($root . '/core/jwt.php');
$assert('JWT signing fails closed without a strong configured secret',
    !str_contains($jwtSource, "\$s = 'coreflux-dev-jwt-secret-CHANGE-ME'")
    && str_contains($jwtSource, 'at least 32 characters')
);

echo "\nAPI package smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
