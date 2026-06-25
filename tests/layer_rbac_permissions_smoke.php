<?php
/**
 * Smoke — LayerFi RBAC permission mapping.
 *
 * Locks the legacy_map resolution for the three permission strings the
 * Layer endpoints reference:
 *   - accounting.view                  → (accounting, read)
 *   - accounting.manage_integrations   → (accounting, admin)
 *   - coreflux.internal_sandbox        → PARKED (_platform, admin)
 *
 * Also asserts that `accounting.manage_integrations` is declared in the
 * accounting module manifest so the admin permission grid surfaces it.
 *
 * Run: php -d zend.assertions=1 /app/tests/layer_rbac_permissions_smoke.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/rbac/legacy_map.php';

$passes  = 0;
$failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nLayerFi RBAC permission mapping smoke\n";
echo "=====================================\n\n";

echo "── legacy_map resolution ──\n";

$accountingView = RbacLegacyMap::resolve('accounting.view');
check("accounting.view resolves to module 'accounting'", $accountingView[0] === 'accounting');
check("accounting.view resolves to action 'read'",        $accountingView[1] === 'read');

$accountingMgmt = RbacLegacyMap::resolve('accounting.manage_integrations');
check("accounting.manage_integrations module = 'accounting'", $accountingMgmt[0] === 'accounting');
check("accounting.manage_integrations action = 'admin'",      $accountingMgmt[1] === 'admin');

$corefluxSandbox = RbacLegacyMap::resolve('coreflux.internal_sandbox');
check("coreflux.internal_sandbox parked to '_platform'", $corefluxSandbox[0] === '_platform');
check("coreflux.internal_sandbox parked action 'admin'", $corefluxSandbox[1] === 'admin');
check("coreflux.internal_sandbox is PARKED",             RbacLegacyMap::isParked('coreflux.internal_sandbox'));

echo "\n── accounting manifest declarations ──\n";
$manifest = require __DIR__ . '/../modules/accounting/manifest.php';
$perms    = $manifest['permissions'] ?? [];
check("manifest declares accounting.view",                isset($perms['accounting.view']));
check("manifest declares accounting.manage_integrations", isset($perms['accounting.manage_integrations']));

echo "\n── legacy RBAC grants (rbac_config) ──\n";
$cfg = require __DIR__ . '/../core/rbac_config.php';
check("master_admin retains '*' catchall",            in_array('*', $cfg['master_admin'] ?? [], true));
check("tenant_admin retains '*' catchall",            in_array('*', $cfg['tenant_admin'] ?? [], true));
check("admin retains 'accounting.*' wildcard",        in_array('accounting.*', $cfg['admin'] ?? [], true));

echo "\n── LayerFi API endpoints reference declared perms ──\n";
$layerFiles = [
    'layer_status.php',
    'layer_audit_log.php',
    'layer_business_token.php',
    'layer_client_error.php',
    'layer_smoke_test.php',
    'layer_setup_tenant.php',
    'layer_tenant_enablement.php',
];
$apiDir = __DIR__ . '/../modules/accounting/api';
foreach ($layerFiles as $f) {
    $path = $apiDir . '/' . $f;
    if (!is_file($path)) { check("LayerFi endpoint exists: {$f}", false); continue; }
    $src = file_get_contents($path);
    $lintOut = [];
    $lintRc = 0;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $lintOut, $lintRc);
    check("{$f} compiles (php -l)", $lintRc === 0 && str_contains(implode("\n", $lintOut), 'No syntax errors'));
    // Each layer file references at least one of the gated permissions.
    $ok = (
        str_contains($src, "'accounting.view'") ||
        str_contains($src, "'accounting.manage_integrations'") ||
        str_contains($src, "'coreflux.internal_sandbox'")
    );
    check("{$f} gates on a declared LayerFi perm", $ok);
}

$total = $passes + count($failures);
echo "\n=========================================\n";
echo "layer_rbac_permissions smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
echo "=========================================\n";
if ($failures) {
    foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
    exit(1);
}
exit(0);
