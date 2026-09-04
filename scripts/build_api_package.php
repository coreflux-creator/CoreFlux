<?php
/**
 * Build the distributable CoreFlux API package.
 *
 * Outputs:
 *   - api/endpoints.json  complete endpoint implementation inventory
 *   - api/openapi.json    OpenAPI 3.1 contract for preferred HTTP paths
 *
 * Usage:
 *   php scripts/build_api_package.php
 *   php scripts/build_api_package.php --check
 */

declare(strict_types=1);

const API_PACKAGE_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

/** @return array<string, mixed> */
function apiPackageBuildInventory(string $root): array
{
    require_once $root . '/core/ModuleRegistry.php';

    ModuleRegistry::reset($root . '/modules');
    $registry = ModuleRegistry::getInstance();
    $moduleIds = $registry->getModuleIds();
    $endpoints = [];

    foreach ($moduleIds as $moduleId) {
        $apiDir = $root . '/modules/' . $moduleId . '/api';
        if (!is_dir($apiDir)) continue;

        foreach (apiPackagePhpFiles($apiDir, false) as $file) {
            $stem = pathinfo($file, PATHINFO_FILENAME);
            $routeName = str_replace('_', '-', $stem);
            if (preg_match('/^[0-9]/', $routeName)) $routeName = 'form-' . $routeName;
            $source = apiPackageRelativePath($root, $file);
            $contents = (string) file_get_contents($file);
            $preferred = '/api/v1/' . $moduleId . '/' . $routeName;

            $endpoints[] = apiPackageEndpointRecord(
                $source,
                $preferred,
                apiPackageMethods($contents),
                apiPackageSummary($contents, $moduleId . ' ' . $routeName),
                $moduleId,
                'tenant',
                'session_or_bearer',
                [
                    '/api/' . $moduleId . '/' . $routeName,
                    '/modules/' . $moduleId . '/api/' . basename($file),
                ]
            );
        }
    }

    $directRoot = $root . '/api';
    foreach (apiPackagePhpFiles($directRoot, true) as $file) {
        $source = apiPackageRelativePath($root, $file);
        if ($source === 'api/index.php') continue;

        $contents = (string) file_get_contents($file);
        $relative = substr($source, strlen('api/'));
        $directPath = '/api/' . str_replace('\\', '/', $relative);
        $segments = explode('/', substr($relative, 0, -4));
        $topNamespace = $segments[0] ?? 'legacy';
        $preferred = $directPath;
        $aliases = [];

        // The router can safely resolve extensionless non-module namespaces.
        // Registered module namespaces retain their direct .php path because
        // their extensionless path belongs to the module router.
        if (count($segments) >= 2 && !in_array($topNamespace, $moduleIds, true)) {
            $preferred = '/api/' . implode('/', $segments);
            $aliases[] = $directPath;
        }

        [$audience, $auth] = apiPackageClassifyDirect($source, $contents);
        $tag = $topNamespace !== '' ? $topNamespace : 'legacy';
        $endpoints[] = apiPackageEndpointRecord(
            $source,
            $preferred,
            apiPackageMethods($contents),
            apiPackageSummary($contents, str_replace(['_', '/'], ' ', substr($relative, 0, -4))),
            $tag,
            $audience,
            $auth,
            $aliases
        );
    }

    usort($endpoints, static function (array $a, array $b): int {
        return [$a['preferred_path'], $a['source_file']] <=> [$b['preferred_path'], $b['source_file']];
    });

    $counts = [
        'implementations' => count($endpoints),
        'module' => 0,
        'direct' => 0,
        'preferred_routes' => count($endpoints),
        'legacy_aliases' => 0,
        'authenticated' => 0,
        'public_contract' => 0,
    ];
    foreach ($endpoints as $endpoint) {
        if (str_starts_with($endpoint['source_file'], 'modules/')) $counts['module']++;
        else $counts['direct']++;
        $counts['legacy_aliases'] += count($endpoint['aliases']);
        if ($endpoint['auth'] === 'session_or_bearer') $counts['authenticated']++;
        if ($endpoint['audience'] !== 'internal') $counts['public_contract']++;
    }

    return [
        'schema_version' => 1,
        'api_version' => 'v1',
        'counts' => $counts,
        'endpoints' => $endpoints,
    ];
}

/** @return list<string> */
function apiPackagePhpFiles(string $directory, bool $recursive): array
{
    if (!is_dir($directory)) return [];
    $files = [];
    if ($recursive) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if ($entry->isFile() && strtolower($entry->getExtension()) === 'php') {
                $files[] = $entry->getPathname();
            }
        }
    } else {
        foreach (new DirectoryIterator($directory) as $entry) {
            if ($entry->isFile() && strtolower($entry->getExtension()) === 'php') {
                $files[] = $entry->getPathname();
            }
        }
    }
    sort($files, SORT_STRING);
    return $files;
}

function apiPackageRelativePath(string $root, string $file): string
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $file = str_replace('\\', '/', $file);
    return ltrim(substr($file, strlen($root)), '/');
}

/** @return list<string> */
function apiPackageMethods(string $contents): array
{
    $found = [];
    preg_match_all('/[\'\"](GET|POST|PUT|PATCH|DELETE)[\'\"]/', $contents, $matches);
    foreach ($matches[1] ?? [] as $method) $found[$method] = true;

    $ordered = [];
    foreach (API_PACKAGE_METHODS as $method) {
        if (isset($found[$method])) $ordered[] = $method;
    }
    return $ordered ?: ['GET'];
}

function apiPackageSummary(string $contents, string $fallback): string
{
    if (preg_match('#/\*\*(.*?)\*/#s', $contents, $match)) {
        $lines = preg_split('/\R/', $match[1]) ?: [];
        foreach ($lines as $line) {
            $line = trim(preg_replace('/^\s*\*\s?/', '', $line) ?? '');
            if ($line === '' || str_starts_with($line, '@')) continue;
            if (preg_match('#^(GET|POST|PUT|PATCH|DELETE)\s#', $line)) continue;
            return rtrim($line, '.');
        }
    }
    return ucwords(str_replace(['-', '_'], ' ', $fallback));
}

/** @return array{0:string,1:string} */
function apiPackageClassifyDirect(string $source, string $contents): array
{
    $normalized = strtolower($source);
    $hasAuthGuard = (bool) preg_match(
        '/\b(?:api_require_auth|api_require_admin|api_require_role|api_require_cfo|requireAuth)\s*\(/',
        $contents
    );

    if (str_starts_with($normalized, 'api/internal/')) return ['internal', 'custom'];
    if (str_contains($normalized, '/webhooks/') || str_contains(basename($normalized), 'webhook')) {
        return ['webhook', 'custom'];
    }
    if (str_starts_with($normalized, 'api/auth/')) {
        return ['authentication', $hasAuthGuard ? 'session_or_bearer' : 'custom'];
    }
    if (str_starts_with($normalized, 'api/admin/') || str_starts_with(basename($normalized), 'admin_')) {
        return ['admin', $hasAuthGuard ? 'session_or_bearer' : 'custom'];
    }
    if (preg_match('/(?:callback|approve_by_email|email_approval|share)/', $normalized)) {
        return ['signed_or_public', $hasAuthGuard ? 'session_or_bearer' : 'custom'];
    }
    return ['tenant', $hasAuthGuard ? 'session_or_bearer' : 'custom'];
}

/** @return array<string, mixed> */
function apiPackageEndpointRecord(
    string $source,
    string $preferred,
    array $methods,
    string $summary,
    string $tag,
    string $audience,
    string $auth,
    array $aliases
): array {
    sort($aliases, SORT_STRING);
    return [
        'source_file' => $source,
        'preferred_path' => $preferred,
        'methods' => array_values($methods),
        'summary' => $summary,
        'tag' => $tag,
        'audience' => $audience,
        'auth' => $auth,
        'aliases' => array_values(array_unique($aliases)),
    ];
}

/** @param array<string, mixed> $inventory @return array<string, mixed> */
function apiPackageBuildOpenApi(array $inventory): array
{
    $paths = [];
    $tags = [];
    foreach ($inventory['endpoints'] as $endpoint) {
        // Internal HMAC bridges are inventoried and probed locally but are not
        // advertised in the externally distributable OpenAPI contract.
        if ($endpoint['audience'] === 'internal') continue;
        $path = $endpoint['preferred_path'];
        $tags[$endpoint['tag']] = true;
        foreach ($endpoint['methods'] as $method) {
            $lower = strtolower($method);
            $operationId = strtolower($method . '_' . trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $path) ?? '', '_'));
            $operation = [
                'operationId' => $operationId,
                'summary' => $endpoint['summary'],
                'tags' => [$endpoint['tag']],
                'x-source-file' => $endpoint['source_file'],
                'x-audience' => $endpoint['audience'],
                'x-auth-model' => $endpoint['auth'],
                'responses' => [
                    '200' => ['$ref' => '#/components/responses/Success'],
                    '201' => ['$ref' => '#/components/responses/Success'],
                    '400' => ['$ref' => '#/components/responses/Error'],
                    '401' => ['$ref' => '#/components/responses/Unauthorized'],
                    '403' => ['$ref' => '#/components/responses/Forbidden'],
                    '404' => ['$ref' => '#/components/responses/Error'],
                    '405' => ['$ref' => '#/components/responses/Error'],
                    '422' => ['$ref' => '#/components/responses/Error'],
                    '500' => ['$ref' => '#/components/responses/Error'],
                ],
            ];
            if ($endpoint['aliases']) $operation['x-legacy-paths'] = $endpoint['aliases'];
            if ($endpoint['auth'] === 'session_or_bearer') {
                $operation['security'] = [
                    ['bearerAuth' => []],
                    ['sessionCookie' => []],
                ];
            } else {
                $operation['security'] = [];
                $operation['description'] = 'This endpoint uses a purpose-specific authentication or public-token flow. See the source endpoint documentation before integrating.';
            }
            if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
                $operation['requestBody'] = [
                    'required' => false,
                    'content' => [
                        'application/json' => [
                            'schema' => ['type' => 'object', 'additionalProperties' => true],
                        ],
                    ],
                ];
            }
            $paths[$path][$lower] = $operation;
        }
    }
    ksort($paths, SORT_STRING);

    $tagList = [];
    foreach (array_keys($tags) as $tag) $tagList[] = ['name' => $tag];
    usort($tagList, static fn(array $a, array $b): int => $a['name'] <=> $b['name']);

    return [
        'openapi' => '3.1.0',
        'info' => [
            'title' => 'CoreFlux API',
            'version' => '1.0.0',
            'description' => 'Versioned CoreFlux application and integration API. The endpoint inventory is generated from the deployed PHP implementations; schemas remain intentionally permissive until each domain contract is promoted from implementation-derived to explicitly modeled.',
        ],
        'servers' => [
            ['url' => 'https://www.corefluxapp.com', 'description' => 'Production'],
            ['url' => 'http://127.0.0.1:8080', 'description' => 'Local development'],
        ],
        'tags' => $tagList,
        'paths' => $paths,
        'components' => [
            'securitySchemes' => [
                'bearerAuth' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                    'bearerFormat' => 'JWT',
                    'description' => 'JWT access token issued by POST /api/auth/mobile_login.',
                ],
                'sessionCookie' => [
                    'type' => 'apiKey',
                    'in' => 'cookie',
                    'name' => 'PHPSESSID',
                    'description' => 'Same-origin web session used by the CoreFlux SPA.',
                ],
            ],
            'schemas' => [
                'ApiError' => [
                    'type' => 'object',
                    'required' => ['error', 'status'],
                    'properties' => [
                        'error' => ['type' => 'string'],
                        'status' => ['type' => 'integer'],
                    ],
                    'additionalProperties' => true,
                ],
            ],
            'responses' => [
                'Success' => [
                    'description' => 'Successful response. Shape is endpoint-specific.',
                    'content' => [
                        'application/json' => [
                            'schema' => ['type' => ['object', 'array'], 'additionalProperties' => true],
                        ],
                    ],
                ],
                'Error' => [
                    'description' => 'Request failed.',
                    'content' => [
                        'application/json' => ['schema' => ['$ref' => '#/components/schemas/ApiError']],
                    ],
                ],
                'Unauthorized' => [
                    'description' => 'Authentication is missing or invalid.',
                    'content' => [
                        'application/json' => ['schema' => ['$ref' => '#/components/schemas/ApiError']],
                    ],
                ],
                'Forbidden' => [
                    'description' => 'The authenticated principal lacks the required tenant permission.',
                    'content' => [
                        'application/json' => ['schema' => ['$ref' => '#/components/schemas/ApiError']],
                    ],
                ],
            ],
        ],
        'x-endpoint-count' => count($paths),
        'x-inventory-endpoint-count' => $inventory['counts']['implementations'],
        'x-inventory-url' => '/api/endpoints.json',
    ];
}

function apiPackageEncode(array $document): string
{
    return json_encode(
        $document,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
}

/** @return array{inventory:string,openapi:string} */
function apiPackageBuildDocuments(string $root): array
{
    $inventory = apiPackageBuildInventory($root);
    return [
        'inventory' => apiPackageEncode($inventory),
        'openapi' => apiPackageEncode(apiPackageBuildOpenApi($inventory)),
    ];
}

function apiPackageMain(array $argv): int
{
    $root = dirname(__DIR__);
    $check = in_array('--check', $argv, true);
    $documents = apiPackageBuildDocuments($root);
    $targets = [
        'inventory' => $root . '/api/endpoints.json',
        'openapi' => $root . '/api/openapi.json',
    ];

    $failed = false;
    foreach ($targets as $key => $target) {
        $expected = $documents[$key];
        if ($check) {
            if (!is_file($target) || (string) file_get_contents($target) !== $expected) {
                fwrite(STDERR, "OUT OF DATE: " . apiPackageRelativePath($root, $target) . PHP_EOL);
                $failed = true;
            }
            continue;
        }
        if (file_put_contents($target, $expected) === false) {
            fwrite(STDERR, "Unable to write {$target}" . PHP_EOL);
            return 1;
        }
    }

    if ($check) {
        if ($failed) {
            fwrite(STDERR, "Run: php scripts/build_api_package.php" . PHP_EOL);
            return 1;
        }
        echo "API package is current." . PHP_EOL;
        return 0;
    }

    $inventory = json_decode($documents['inventory'], true, 512, JSON_THROW_ON_ERROR);
    echo "Wrote API package: {$inventory['counts']['implementations']} implementations, "
        . count($inventory['endpoints']) . " preferred routes." . PHP_EOL;
    return 0;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(apiPackageMain($argv));
}
