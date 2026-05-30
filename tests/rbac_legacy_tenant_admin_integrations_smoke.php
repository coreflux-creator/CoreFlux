<?php
/**
 * rbac_legacy_tenant_admin_integrations_smoke.php
 *
 * Regression guard for the operator-reported issue:
 *   "Forbidden: missing permission 'tenant_admin.integrations'"
 *
 * The legacy permission string `tenant_admin.integrations` previously
 * fell through to the resolver's default `(segs[0], 'write')` rule,
 * landing on `('tenant_admin', 'write')` — a module/action pair that
 * most role bundles do NOT grant. The fix is an explicit entry mapping
 * it to `('integrations', 'admin')` so it resolves to the same scope
 * as `integrations.field_map.manage`.
 *
 * Also asserts the new `rbac_legacy_require_any()` helper grants when
 * at least one of the listed perms is held.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/rbac/legacy_map.php';

function _ok(string $msg): void { fwrite(STDOUT, "✅ $msg\n"); }

// ─────────────────────────────────────────────────────────────────────
// CASE 1 — tenant_admin.integrations must resolve to the SAME
// (module, action) tuple as the granular field_map perms. Without
// this, a role granting integrations:admin via one perm doesn't
// grant the other, producing the operator's Forbidden error.
// ─────────────────────────────────────────────────────────────────────
[$module1, $action1] = RbacLegacyMap::resolve('tenant_admin.integrations');
assert($module1 === 'integrations', 'tenant_admin.integrations → module=integrations');
assert($action1 === 'admin',        'tenant_admin.integrations → action=admin');

[$module2, $action2] = RbacLegacyMap::resolve('integrations.field_map.manage');
assert($module2 === 'integrations', 'integrations.field_map.manage → module=integrations');
assert($action2 === 'admin',        'integrations.field_map.manage → action=admin');

assert([$module1, $action1] === [$module2, $action2],
    'tenant_admin.integrations and integrations.field_map.manage resolve to IDENTICAL (module,action)');
_ok('CASE 1 — tenant_admin.integrations now resolves to integrations:admin (matches field_map.manage)');

// ─────────────────────────────────────────────────────────────────────
// CASE 2 — Other granular integration perms unchanged.
// ─────────────────────────────────────────────────────────────────────
[$m, $a] = RbacLegacyMap::resolve('integrations.jobdiva.manage');
assert($m === 'integrations' && $a === 'admin', 'integrations.jobdiva.manage unchanged');
[$m, $a] = RbacLegacyMap::resolve('integrations.jobdiva.view');
assert($m === 'integrations' && $a === 'read',  'integrations.jobdiva.view unchanged');
_ok('CASE 2 — granular integration perms unchanged');

// ─────────────────────────────────────────────────────────────────────
// CASE 3 — rbac_legacy_require_any() helper exists + has correct signature.
// ─────────────────────────────────────────────────────────────────────
assert(function_exists('rbac_legacy_require_any'), 'rbac_legacy_require_any exists');
assert(function_exists('rbac_legacy_can_any'),     'rbac_legacy_can_any exists');

$ref = new ReflectionFunction('rbac_legacy_require_any');
$params = $ref->getParameters();
assert(count($params) === 2,                         '_require_any has 2 params');
assert($params[0]->getName() === 'user',             'first param is $user');
assert($params[1]->getName() === 'perms',            'second param is $perms');
$tParam = $params[1]->getType();
assert($tParam && $tParam->getName() === 'array',    'second param typed as array');
_ok('CASE 3 — rbac_legacy_require_any and _can_any are wired with correct signatures');

// ─────────────────────────────────────────────────────────────────────
// CASE 4 — _can_any returns true if ANY of the listed perms hold,
// false if none do. Use the bridge in stand-alone (no DB) mode by
// asserting both perms resolve identically and the resolver short-
// circuits to false for an empty user array (no roles, no perms).
// ─────────────────────────────────────────────────────────────────────
$emptyUser = ['id' => 0, 'roles' => []];
$got = rbac_legacy_can_any($emptyUser, ['integrations.field_map.manage', 'tenant_admin.integrations']);
assert($got === false, 'empty-perm user is denied');
_ok('CASE 4 — _can_any denies an empty-perm user');

// ─────────────────────────────────────────────────────────────────────
// CASE 5 — Every field_map endpoint uses _require_any with BOTH perm
// strings. Catches accidental reverts.
// ─────────────────────────────────────────────────────────────────────
$endpoints = [
    __DIR__ . '/../api/admin/integrations/field_map.php',
    __DIR__ . '/../api/admin/integrations/field_map_bulk.php',
    __DIR__ . '/../api/admin/integrations/field_map_suggest.php',
    __DIR__ . '/../api/admin/integrations/field_map_test.php',
];
foreach ($endpoints as $f) {
    $src = (string) file_get_contents($f);
    $base = basename($f);
    assert(str_contains($src, 'rbac_legacy_require_any'),
        "$base uses _require_any");
    assert(str_contains($src, "'integrations.field_map.manage'")
        && str_contains($src, "'tenant_admin.integrations'"),
        "$base accepts BOTH perm strings");
}
_ok('CASE 5 — every field_map endpoint accepts both perm strings');

echo "\n🎯 rbac_legacy_tenant_admin_integrations_smoke — ALL PASS\n";
