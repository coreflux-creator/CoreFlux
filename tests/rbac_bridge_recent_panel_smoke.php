<?php
/**
 * Smoke — RbacBridgeHealthPanel "Recent disagreements" section.
 *
 * The backend `/api/admin/rbac_bridge_health.php` already returns a
 * `recent: [...]` array of the last 20 audit rows; the panel previously
 * ignored it and only rendered top_perms. This smoke locks the new
 * surface so the recent stream stays wired in.
 *
 * Run: php -d zend.assertions=1 /app/tests/rbac_bridge_recent_panel_smoke.php
 */
declare(strict_types=1);

$passes  = 0;
$failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nRBAC bridge recent-disagreements panel smoke\n";
echo "============================================\n\n";

$panel  = __DIR__ . '/../dashboard/src/pages/RbacBridgeHealthPanel.jsx';
$apiSrc = __DIR__ . '/../api/admin/rbac_bridge_health.php';

echo "── panel renders recent stream ──\n";
check('panel exists', is_file($panel));
$src = is_file($panel) ? file_get_contents($panel) : '';

check('iterates data.recent',                str_contains($src, 'data.recent.slice'));
check('guards on Array.isArray + non-empty', str_contains($src, 'Array.isArray(data.recent) && data.recent.length > 0'));
check('data-testid rbac-bridge-recent',      str_contains($src, "data-testid=\"rbac-bridge-recent\""));
check('data-testid rbac-bridge-recent-section', str_contains($src, "data-testid=\"rbac-bridge-recent-section\""));
check('per-row data-testid template',        str_contains($src, 'data-testid={`rbac-bridge-recent-row-${r.id}`}'));
check('renders occurred_at column',          str_contains($src, 'r.occurred_at'));
check('renders perm column',                 str_contains($src, '{r.perm}'));
check('renders module:action',               str_contains($src, '{r.module}:{r.action}'));
check('renders user_id (or em-dash)',        str_contains($src, 'r.user_id ? `#${r.user_id}`'));
check('legacy_ok colour-coded ✓/✗',          preg_match('/r\.legacy_ok\s*\?\s*[\'"]✓[\'"]/', $src) === 1);
check('new_ok colour-coded ✓/✗',             preg_match('/r\.new_ok\s*\?\s*[\'"]✓[\'"]/', $src) === 1);
check('limits to last 10 rows in UI',        str_contains($src, '.slice(0, 10)'));

echo "\n── backend endpoint already supplies the data ──\n";
check('rbac_bridge_health.php exists', is_file($apiSrc));
$api = is_file($apiSrc) ? file_get_contents($apiSrc) : '';
check('endpoint returns recent[]',           str_contains($api, "'recent'" . "            => \$recent")
                                          || str_contains($api, "'recent'"));
check('endpoint gates to admin only',        str_contains($api, "'master_admin', 'tenant_admin'"));
check('endpoint window_hours clamp (1..168)', str_contains($api, 'max(1, min(168'));
check('endpoint scopes to current tenant',   str_contains($api, 'tenant_id = :t'));

echo "\n── existing aggregate panel surfaces still present ──\n";
check('rbac-bridge-health-total still rendered',     str_contains($src, "data-testid=\"rbac-bridge-health-total\""));
check('rbac-bridge-top-perms still rendered',        str_contains($src, "data-testid=\"rbac-bridge-top-perms\""));
check('refresh button still present',                str_contains($src, "data-testid=\"rbac-bridge-health-refresh\""));

$total = $passes + count($failures);
echo "\n=========================================\n";
echo "rbac_bridge_recent_panel smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
echo "=========================================\n";
if ($failures) {
    foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
    exit(1);
}
exit(0);
