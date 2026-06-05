<?php
/**
 * Smoke — Layer nav role gating.
 *
 * Locks the contract added by P3-A:
 *   - /app/dashboard/src/lib/layerNavGate.js exports the helpers
 *     canSeeLayerSandbox, canSeeLayerIntegration, filterLayerNav
 *   - the role → permission mapping in the helper mirrors rbac_config:
 *       master_admin  → sees both
 *       tenant_admin  → sees both
 *       admin         → sees integration only (NOT sandbox)
 *       manager / employee → sees neither
 *   - App.jsx imports the helper and applies it on both the DB-session
 *     path and the demo-session fallback.
 *   - The Accounting module nav still declares both Layer entries (so the
 *     gating actually has something to filter against).
 *
 * Run: php -d zend.assertions=1 /app/tests/layer_nav_gate_smoke.php
 */
declare(strict_types=1);

$passes  = 0;
$failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nLayer nav role gating smoke\n";
echo "===========================\n\n";

$gatePath = __DIR__ . '/../dashboard/src/lib/layerNavGate.js';
$appPath  = __DIR__ . '/../dashboard/src/App.jsx';

echo "── layerNavGate.js shape ──\n";
check('layerNavGate.js exists', is_file($gatePath));
$gateSrc = is_file($gatePath) ? file_get_contents($gatePath) : '';
check('exports canSeeLayerSandbox',     str_contains($gateSrc, 'export function canSeeLayerSandbox'));
check('exports canSeeLayerIntegration', str_contains($gateSrc, 'export function canSeeLayerIntegration'));
check('exports filterLayerNav',         str_contains($gateSrc, 'export function filterLayerNav'));

echo "\n── role → permission mapping ──\n";
// SANDBOX_ROLES = master_admin + tenant_admin only
check('SANDBOX_ROLES includes master_admin', preg_match('/SANDBOX_ROLES\s*=\s*new Set\([^)]*master_admin/', $gateSrc) === 1);
check('SANDBOX_ROLES includes tenant_admin', preg_match('/SANDBOX_ROLES\s*=\s*new Set\([^)]*tenant_admin/', $gateSrc) === 1);
check('SANDBOX_ROLES excludes admin (sandbox is platform-only)',
    preg_match('/SANDBOX_ROLES\s*=\s*new Set\(\[\s*[\'"]master_admin[\'"]\s*,\s*[\'"]tenant_admin[\'"]\s*\]\)/', $gateSrc) === 1);

// INTEGRATION_ROLES = master_admin + tenant_admin + admin
check('INTEGRATION_ROLES includes master_admin', preg_match('/INTEGRATION_ROLES\s*=\s*new Set\([^)]*master_admin/', $gateSrc) === 1);
check('INTEGRATION_ROLES includes tenant_admin', preg_match('/INTEGRATION_ROLES\s*=\s*new Set\([^)]*tenant_admin/', $gateSrc) === 1);
check('INTEGRATION_ROLES includes admin',        preg_match('/INTEGRATION_ROLES\s*=\s*new Set\([^)]*[\'"]admin[\'"]/', $gateSrc) === 1);

echo "\n── filterLayerNav logic ──\n";
check('filters by route layer-sandbox',     str_contains($gateSrc, "'layer-sandbox'") || str_contains($gateSrc, '"layer-sandbox"'));
check('filters by route layer-integration', str_contains($gateSrc, "'layer-integration'") || str_contains($gateSrc, '"layer-integration"'));
check('returns a new object (does not mutate)', str_contains($gateSrc, '...mod'));
check('only touches accounting module', str_contains($gateSrc, "mod.id !== 'accounting'") || str_contains($gateSrc, 'mod.id !== "accounting"'));

echo "\n── App.jsx wiring ──\n";
check('App.jsx exists', is_file($appPath));
$appSrc = is_file($appPath) ? file_get_contents($appPath) : '';
check('App.jsx imports filterLayerNav',
    str_contains($appSrc, "from './lib/layerNavGate'") && str_contains($appSrc, 'filterLayerNav'));
check('App.jsx applies gate after DB-session merge',
    preg_match('/data\.modules\s*=\s*filterLayerNav\(\s*data\.modules\s*,\s*data\.user\s*\)/', $appSrc) === 1);
check('App.jsx applies gate on demo-session fallback',
    preg_match('/demoSession\.modules\s*=\s*filterLayerNav\(\s*demoSession\.modules\s*,\s*demoSession\.user\s*\)/', $appSrc) === 1);

echo "\n── nav entries still declared (so gate has something to filter) ──\n";
check('DEMO_SESSION declares Layer Sandbox route',     str_contains($appSrc, "'layer-sandbox'"));
check('DEMO_SESSION declares Layer Integration route', str_contains($appSrc, "'layer-integration'"));
check('LAYER_SANDBOX_ENABLED build flag still present', str_contains($appSrc, 'LAYER_SANDBOX_ENABLED'));

$total = $passes + count($failures);
echo "\n=========================================\n";
echo "layer_nav_gate smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
echo "=========================================\n";
if ($failures) {
    foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
    exit(1);
}
exit(0);
