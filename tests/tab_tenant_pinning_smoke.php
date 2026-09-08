<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '[ok] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failures++;
};

$scope = $read('core/tenant_scope.php');
$boot = $read('core/api_bootstrap.php');
$session = $read('session.php');
$client = $read('dashboard/src/lib/api.js');
$app = $read('dashboard/src/App.jsx');
$header = $read('dashboard/src/layout/Header.jsx');
$connecteamApi = $read('api/connecteam.php');
$connecteamUi = $read('dashboard/src/pages/ConnecteamSettings.jsx');

$check('tenant header is parsed strictly',
    str_contains($scope, "HTTP_X_COREFLUX_TENANT_ID")
    && str_contains($scope, "preg_match('/^[1-9][0-9]*$/'"));
$check('request-local tenant takes precedence over shared session tenant',
    strpos($scope, "__cf_request_tenant_id") < strpos($scope, "\$_SESSION['tenant_id']"));
$check('a tenant different from the session is access-checked',
    str_contains($boot, 'if ((int) $sessionTenantId === $requestedTenantId)')
    && str_contains($boot, 'tenantAccessContextForUser($user ?? [], $requestedTenantId)'));
$check('JWT callers cannot override their token tenant',
    str_contains($boot, 'JWT tenant does not match the requested tenant'));
$check('pinned requests do not rewrite the shared session role',
    substr_count($boot, '$requestedTenantId === null && isset($_SESSION') >= 2);
$check('session bootstrap resolves the tab-pinned tenant',
    str_contains($session, 'requestedTenantHeaderId()')
    && str_contains($session, 'tenantAccessContextForUser'));
$check('browser API client uses tab-local storage and tenant header',
    str_contains($client, 'window.sessionStorage')
    && str_contains($client, "'X-CoreFlux-Tenant-Id': pinnedTenantId"));
$check('SPA pins the selected tenant before switching',
    str_contains($app, 'pinTenantId(tenantId)')
    && str_contains($app, "'X-CoreFlux-Tenant-Id': pinnedTenantId"));
$check('platform mode and logout clear the tab pin',
    substr_count($header, 'clearPinnedTenantId') >= 3);
$check('Connecteam response names every resolved tenant scope',
    str_contains($connecteamApi, "'tenant_context'")
    && str_contains($connecteamApi, "'people_catalog'"));
$check('Connecteam UI displays the workspace and matching directory',
    str_contains($connecteamUi, 'connecteam-tenant-context')
    && str_contains($connecteamUi, 'People directory used for matching'));

exit($failures === 0 ? 0 : 1);
