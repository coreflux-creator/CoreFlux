<?php
/**
 * Smoke — CFO Dashboard new-RBAC integration.
 *
 * Locks:
 *   - /session.php exposes user.module_access pulled from
 *     membership_module_access.
 *   - Wildcard map populated for master_admin / is_global_admin.
 *   - CFOGuard.jsx checks user.module_access.cfo in addition to the
 *     legacy role/global_role gate.
 *   - api_require_cfo() still wraps the new RBACResolver check
 *     (regression guard against the old role-only legacy gate).
 */
declare(strict_types=1);

$passes = 0; $failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nCFO new-RBAC integration smoke\n";
echo "================================\n\n";

// ─── session.php payload shape ───
echo "── /session.php ──\n";
$session = (string) file_get_contents('/app/session.php');
check('exposes user.module_access in the response',
    str_contains($session, "'module_access' => _buildModuleAccessMap"));
check('helper queries membership_module_access',
    str_contains($session, "FROM membership_module_access WHERE membership_id"));
check('master_admin / is_global_admin get wildcard map',
    str_contains($session, "'cfo' => 'admin', 'accounting' => 'admin'"));
check('falls back to empty map without membership',
    str_contains($session, 'if ($mid <= 0 || !class_exists') &&
    str_contains($session, 'return [];'));
check('wraps DB call in try/catch (degrades silently)',
    str_contains($session, 'try {') && str_contains($session, 'catch (\\Throwable $_)'));

// ─── CFOGuard.jsx new gate ───
echo "\n── CFOGuard.jsx ──\n";
$guard = (string) file_get_contents('/app/dashboard/src/pages/CFOGuard.jsx');
check('reads user.module_access',                  str_contains($guard, 'user.module_access'));
check('checks module_access.cfo level',            str_contains($guard, "moduleAccess.cfo"));
check('also honours wildcard (*) grant',           str_contains($guard, "moduleAccess['*']"));
check('treats read/write/admin as allowed',
    str_contains($guard, "['read', 'write', 'admin'].includes(cfoLevel)"));
check('layered allow: globalRole OR isGlobalAdm OR role OR module grant',
    str_contains($guard, 'hasModuleGrant'));

// ─── api_require_cfo() backend gate ───
echo "\n── api_require_cfo() ──\n";
$bs = (string) file_get_contents('/app/core/api_bootstrap.php');
check('still queries RBACResolver::moduleAccessFor',
    str_contains($bs, "RBACResolver::moduleAccessFor(\$membershipId, 'cfo')"));
check('accepts read/write/admin levels',
    str_contains($bs, "in_array(\$level, ['read', 'write', 'admin'], true)"));
check('returns ctx on master_admin / global_admin shortcut',
    str_contains($bs, "if (\$globalRole === 'master_admin' || \$isGlobalAdm) return \$ctx;"));

echo "\ncfo_rbac_integration smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
exit($failures ? 1 : 0);
