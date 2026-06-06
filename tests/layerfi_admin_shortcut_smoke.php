<?php
/**
 * Smoke — LayerFi per-tenant toggle shortcut on the Admin overview.
 *
 * Locks the contract added this session:
 *   1. /app/dashboard/src/pages/LayerFiToggleCard.jsx exists and uses
 *      the same `createLayerClient` API as the full settings page —
 *      so the toggle button hits exactly the same endpoint as the
 *      Settings → Integrations → LayerFi page (single source of truth).
 *   2. The card renders both an inline Power button AND a deep-link
 *      to the full /settings/integrations/layer page, with stable
 *      data-testids so playwright + curl-based smokes can drive it.
 *   3. AdminModule.jsx imports + mounts the card in the same row as
 *      IntegrationsHealthPanel — the new "primitives overview" row.
 *
 * Run: php -d zend.assertions=1 /app/tests/layerfi_admin_shortcut_smoke.php
 */
declare(strict_types=1);

$passes = 0; $failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nLayerFi admin-shortcut card smoke\n";
echo "=================================\n\n";

$cardPath  = __DIR__ . '/../dashboard/src/pages/LayerFiToggleCard.jsx';
$adminPath = __DIR__ . '/../dashboard/src/pages/AdminModule.jsx';

echo "── card shape ──\n";
check('LayerFiToggleCard.jsx exists', is_file($cardPath));
$src = (string) file_get_contents($cardPath);
check('imports createLayerClient from the canonical layerClient.js',
    str_contains($src, "from '../../../modules/accounting/ui/layer/layerClient'") &&
    str_contains($src, 'createLayerClient'));
check('reads status via client.status()',           str_contains($src, 'client.status()'));
check('writes via client.setTenantEnabled(next)',   str_contains($src, 'client.setTenantEnabled(next)'));
check('does NOT bypass the shared client',
    !preg_match("/fetch\(\s*['\"]\/api\/accounting\/layer-/", $src));

echo "\n── UI testids ──\n";
foreach ([
    'layerfi-toggle-card',
    'layerfi-toggle-button',
    'layerfi-toggle-deep-link',
    'layerfi-toggle-loading',
    'layerfi-toggle-error',
    'layerfi-toggle-toast',
] as $tid) {
    check("renders data-testid \"{$tid}\"", str_contains($src, "data-testid=\"{$tid}\""));
}
check('renders state pill on/off testid',
    str_contains($src, 'data-testid={`layerfi-toggle-state-${toggleOn ? \'on\' : \'off\'}`}'));

echo "\n── permission gating ──\n";
check('Power button respects canToggle (disabled state)',
    str_contains($src, 'disabled={loading || busy || !canToggle}'));
check('falls back to read-only badge when not allowed',
    str_contains($src, '!canToggle && status?.allowed === false'));

echo "\n── deep link to full settings ──\n";
check('Link targets /settings/integrations/layer',
    str_contains($src, '"/settings/integrations/layer"'));

echo "\n── Admin overview wiring ──\n";
$admin = (string) file_get_contents($adminPath);
check('AdminModule imports LayerFiToggleCard',
    str_contains($admin, "import LayerFiToggleCard from './LayerFiToggleCard'"));
check('AdminModule renders <LayerFiToggleCard />',
    str_contains($admin, '<LayerFiToggleCard />'));
check('LayerFi card sits next to IntegrationsHealthPanel',
    preg_match('/IntegrationsHealthPanel.*?LayerFiToggleCard/s', $admin) === 1);

$total = $passes + count($failures);
echo "\n=========================================\n";
echo "layerfi_admin_shortcut smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
echo "=========================================\n";
foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
exit($failures ? 1 : 0);
