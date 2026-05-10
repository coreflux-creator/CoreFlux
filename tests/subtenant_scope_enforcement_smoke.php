<?php
/**
 * Smoke: tenant_scope auto-resolves module-aware tenant_id for sub-tenants.
 *
 * Verifies that scopedQuery/Insert/Update/Delete now go through
 * effectiveTenantIdForRequest() which detects the active module from the
 * URL and routes through tenant_module_scope (shared vs isolated).
 *
 * No DB required — purely source-level checks of the wiring.
 */
declare(strict_types=1);

$src = (string) file_get_contents(__DIR__ . '/../core/tenant_scope.php');
$sub = (string) file_get_contents(__DIR__ . '/../core/sub_tenants.php');

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};

echo "core/tenant_scope.php — module-aware scoping\n";
$a('declares currentModuleKey()',                  str_contains($src, 'function currentModuleKey()'));
$a('parses /modules/<key>/ from REQUEST_URI',      str_contains($src, "/modules/([a-z][a-z0-9_-]*)/"));
$a('declares effectiveTenantIdForRequest()',       str_contains($src, 'function effectiveTenantIdForRequest()'));
$a('lazy-requires core/sub_tenants.php',           str_contains($src, "require_once \$fn") ||
                                                   str_contains($src, "require_once \$fn;"));
$a('falls back to currentTenantId on legacy DB',   str_contains($src, 'catch (\\Throwable'));
$a('scopedQuery uses effectiveTenantIdForRequest', str_contains($src, "params['tenant_id'] = effectiveTenantIdForRequest()"));
$a('scopedInsert uses effectiveTenantIdForRequest',str_contains($src, "data['tenant_id']  = \$data['tenant_id']  ?? effectiveTenantIdForRequest()"));
$a('scopedUpdate uses effectiveTenantIdForRequest',str_contains($src, "'tenant_id' => effectiveTenantIdForRequest()"));
$a('scopedDelete uses effectiveTenantIdForRequest',str_contains($src, "'tenant_id' => effectiveTenantIdForRequest()"));

echo "\ncore/sub_tenants.php — scope policy\n";
$a('default scope: people=shared',                 str_contains($sub, "'people'     => 'shared'"));
$a('default scope: placements=shared',             str_contains($sub, "'placements' => 'shared'"));
$a('default scope: companies=shared',              str_contains($sub, "'companies'  => 'shared'"));
$a('default scope: accounting=isolated',           str_contains($sub, "'accounting' => 'isolated'"));
$a('default scope: payroll=isolated',              str_contains($sub, "'payroll'    => 'isolated'"));
$a('default scope: ap=isolated',                   str_contains($sub, "'ap'         => 'isolated'"));
$a('effectiveTenantIdForModule defined',           str_contains($sub, 'function effectiveTenantIdForModule'));
$a('master tenant returns own id',                 str_contains($sub, "tenant_type'] !== 'sub'"));
$a('isolated returns sub-tenant id',               str_contains($sub, "\$mode === 'shared' ? (int) \$tenant['parent_id'] : (int) \$tenant['id']"));

// File-load test: make sure the syntax is clean and the helpers are
// callable in a CLI (no REQUEST_URI) context — they must NOT explode and
// must default sensibly.
echo "\nRuntime sanity (CLI, no REQUEST_URI)\n";
require_once __DIR__ . '/../core/tenant_scope.php';
$a('currentModuleKey() returns null with no URL',  currentModuleKey() === null);

// Simulate /modules/people/api/list.php
$_SERVER['REQUEST_URI'] = '/modules/people/api/list.php';
$a('detects module key from /modules/people/...',  currentModuleKey() === 'people');

// Simulate /modules/accounting/api/reports.php
$_SERVER['REQUEST_URI'] = '/modules/accounting/api/reports.php?type=balance_sheet';
$a('detects module key from /modules/accounting/', currentModuleKey() === 'accounting');

// Core endpoint → no module
$_SERVER['REQUEST_URI'] = '/api/sub_tenants.php';
$a('core /api/* yields no module key',             currentModuleKey() === null);

// Hyphenated slug
$_SERVER['REQUEST_URI'] = '/modules/cash-flow-test/api/x.php';
$a('hyphenated module slugs match',                currentModuleKey() === 'cash-flow-test');

echo "\n--- " . ($pass + $fail) . " assertions, $fail failed ---\n";
exit($fail ? 1 : 0);
