<?php
/**
 * Smoke test for the sub-tenant URL-flat scope refactor:
 *
 *   - setRequestModuleScope('foo') makes currentModuleKey() return 'foo'
 *     even on /api/*.php URLs that don't carry the /modules/<key>/ prefix.
 *   - Core report endpoints (exec_dashboard, reports_staffing,
 *     reports_ai_explain, exec_filters) call setRequestModuleScope('staffing')
 *     so a shared-mode sub-tenant correctly resolves to the master parent's
 *     id for catalog reads.
 *   - exec_dashboard.php carries two distinct tenant placeholders (`:t` for
 *     isolated financial tables, `:ct` for shared catalog tables) so it
 *     doesn't conflate the two scopes inside the same endpoint.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/tenant_scope.php';

$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};

// ----------------------------------------------------------------- override mechanics
$_SERVER['REQUEST_URI'] = '/api/reports_staffing.php';
$a('with no override + non-module URL, currentModuleKey() returns null', currentModuleKey() === null);

setRequestModuleScope('staffing');
$a('after setRequestModuleScope(\'staffing\'), currentModuleKey() returns \'staffing\'', currentModuleKey() === 'staffing');

setRequestModuleScope('accounting');
$a('override is replaced (not stacked) by subsequent calls', currentModuleKey() === 'accounting');

clearRequestModuleScope();
$a('clearRequestModuleScope() removes the override', currentModuleKey() === null);

try {
    setRequestModuleScope('Invalid Module With Spaces');
    $a('setRequestModuleScope() rejects invalid keys', false);
} catch (InvalidArgumentException $e) {
    $a('setRequestModuleScope() rejects invalid keys', true);
}

// ----------------------------------------------------------------- URL-prefix detection still works
$_SERVER['REQUEST_URI'] = '/modules/billing/api/invoices.php?id=1';
$a('URL-prefix detection still recognises /modules/<key>/api/ paths', currentModuleKey() === 'billing');
clearRequestModuleScope();
$a('URL prefix wins after clear (no override; URL drives)', currentModuleKey() === 'billing');

// ----------------------------------------------------------------- explicit override beats URL
setRequestModuleScope('staffing');
$a('explicit override BEATS the URL-prefix detection', currentModuleKey() === 'staffing');
clearRequestModuleScope();

// ----------------------------------------------------------------- core report endpoints declare scope
$endpoints = [
    '/app/api/reports_staffing.php',
    '/app/api/reports_ai_explain.php',
    '/app/api/exec_filters.php',
];
foreach ($endpoints as $f) {
    $src = file_get_contents($f);
    $hasPin   = str_contains($src, "setRequestModuleScope('staffing')");
    $usesEff  = str_contains($src, 'effectiveTenantIdForRequest()');
    $base = basename($f);
    $a("$base calls setRequestModuleScope('staffing')", $hasPin);
    $a("$base derives \$tenantId via effectiveTenantIdForRequest()", $usesEff);
}

// ----------------------------------------------------------------- exec_dashboard.php has dual-scope split
$execSrc = file_get_contents('/app/api/exec_dashboard.php');
$a('exec_dashboard.php defines $catalogTid for shared-catalog queries',
   str_contains($execSrc, '$catalogTid'));
$a('exec_dashboard.php binds :ct on placement subqueries',
   str_contains($execSrc, "p.tenant_id = :ct"));
$a('exec_dashboard.php people queries use :ct',
   str_contains($execSrc, "FROM people\n          WHERE tenant_id = :ct"));
$a('exec_dashboard.php financial queries still use :t (billing_invoices)',
   str_contains($execSrc, "billing_invoices\n          WHERE tenant_id = :t"));
$a('exec_dashboard.php financial queries still use :t (ap_bills)',
   str_contains($execSrc, "ap_bills\n          WHERE tenant_id = :t"));
$a('exec_dashboard.php financial queries still use :t (payroll_runs)',
   str_contains($execSrc, "payroll_runs\n          WHERE tenant_id = :t"));

// ----------------------------------------------------------------- time_entries cross-scope query carries BOTH placeholders
$a('exec_dashboard.php time_entries+placements join carries both :t and :ct via array_merge($pwParams, [\'t\' => ...])',
   str_contains($execSrc, "array_merge(\$pwParams, ['t' => \$tenantId,"));

echo "\n=========================================\n";
echo "Sub-tenant URL-flat scope refactor smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
