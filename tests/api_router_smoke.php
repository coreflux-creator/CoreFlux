<?php
/**
 * API router smoke test.
 *
 * Exercises path parsing + file resolution against the real /modules tree.
 *   php /app/tests/api_router_smoke.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/ModuleRegistry.php';
require_once __DIR__ . '/../core/api_router.php';

$pass = 0; $fail = 0;
$assert = function(string $what, bool $cond) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  ✓ $what\n"; }
    else        { $fail++; echo "  ✗ $what\n"; }
};

// Reset registry so it discovers fresh
ModuleRegistry::reset();

// ---------------------------------------------------------------------------
echo "Path parsing — happy path (PATH_INFO set)\n";
$r = apiRouterParse('/people/employees', '/api/people/employees');
$assert("ok",                       $r['ok'] === true);
$assert("module_id=people",         $r['module_id'] === 'people');
$assert("endpoint=employees",       $r['endpoint']  === 'employees');
$assert("subpath empty",            $r['subpath']   === []);
$assert("legacy path has no api_version", ($r['api_version'] ?? null) === null);

// ---------------------------------------------------------------------------
echo "\nPath parsing — happy path with subpath\n";
$r = apiRouterParse('/payroll/runs/123/lines', '/api/payroll/runs/123/lines');
$assert("ok",                       $r['ok'] === true);
$assert("module_id=payroll",        $r['module_id'] === 'payroll');
$assert("endpoint=runs",            $r['endpoint']  === 'runs');
$assert("subpath=[123,lines]",      $r['subpath'] === ['123', 'lines']);

// ---------------------------------------------------------------------------
echo "\nPath parsing — fallback to REQUEST_URI when PATH_INFO empty\n";
$r = apiRouterParse('', '/api/people/employees?limit=10');
$assert("ok via REQUEST_URI",       $r['ok'] === true);
$assert("query string ignored",     $r['endpoint'] === 'employees');

$r = apiRouterParse('', '/api/index.php/people/employees');
$assert("strips index.php prefix",  $r['ok'] === true && $r['endpoint'] === 'employees');

// ---------------------------------------------------------------------------
echo "\nPath parsing — error cases\n";
$r = apiRouterParse('', '/api/v1/time/entries/123/approve');
$assert("v1 ok",                    $r['ok'] === true);
$assert("api_version=v1",           ($r['api_version'] ?? null) === 'v1');
$assert("v1 module_id=time",        $r['module_id'] === 'time');
$assert("v1 endpoint=entries",      $r['endpoint'] === 'entries');
$assert("v1 subpath=[123,approve]", $r['subpath'] === ['123', 'approve']);

$_GET = [];
apiRouterApplyV1Compatibility($r);
$assert("v1 compatibility sets id",     ($_GET['id'] ?? null) === '123');
$assert("v1 compatibility sets action", ($_GET['action'] ?? null) === 'approve');

$_GET = ['id' => '999', 'action' => 'reject'];
apiRouterApplyV1Compatibility($r);
$assert("v1 compatibility preserves explicit id",     $_GET['id'] === '999');
$assert("v1 compatibility preserves explicit action", $_GET['action'] === 'reject');
$_GET = [];

$r = apiRouterParse('', '/api/v1/reports/report-builder/run');
$assert("v1 collection action ok", $r['ok'] === true && $r['module_id'] === 'reports' && $r['endpoint'] === 'report-builder');
$_GET = [];
apiRouterApplyV1Compatibility($r);
$assert("v1 collection action sets action", ($_GET['action'] ?? null) === 'run');
$_GET = [];

$r = apiRouterParse('', '/api/v1/treasury/recommendations/decision-detail/123');
$assert("v1 action-first item route ok", $r['ok'] === true && $r['module_id'] === 'treasury' && $r['endpoint'] === 'recommendations');
$_GET = [];
apiRouterApplyV1Compatibility($r);
$assert("v1 action-first item route sets action", ($_GET['action'] ?? null) === 'decision_detail');
$assert("v1 action-first item route sets id", ($_GET['id'] ?? null) === '123');
$_GET = [];

$r = apiRouterParse('', '/api/v1/reports/export-templates/123/clone');
$assert("v1 export template item action ok", $r['ok'] === true && $r['module_id'] === 'reports' && $r['endpoint'] === 'export-templates');
$_GET = [];
apiRouterApplyV1Compatibility($r);
$assert("v1 export template item action sets id", ($_GET['id'] ?? null) === '123');
$assert("v1 export template item action sets action", ($_GET['action'] ?? null) === 'clone');
$_GET = [];

$r = apiRouterParse('', '/api/v1/people/custom-field-definitions');
$assert("v1 custom field definitions ok", $r['ok'] === true && $r['module_id'] === 'people' && $r['endpoint'] === 'custom-field-definitions');
$_GET = [];
apiRouterApplyV1Compatibility($r);
$assert("v1 custom field definitions sets entity_type", ($_GET['entity_type'] ?? null) === 'people');
$_GET = [];

$r = apiRouterParse('', '/api/v1/people/custom-field-values/123');
$assert("v1 custom field values ok", $r['ok'] === true && $r['module_id'] === 'people' && $r['endpoint'] === 'custom-field-values');
$_GET = [];
apiRouterApplyV1Compatibility($r);
$assert("v1 custom field values sets entity_type", ($_GET['entity_type'] ?? null) === 'people');
$assert("v1 custom field values sets record_id", ($_GET['record_id'] ?? null) === '123');
$_GET = [];

$r = apiRouterParse('', '/api/v1/people/custom-field-layouts/detail');
$assert("v1 custom field layouts ok", $r['ok'] === true && $r['module_id'] === 'people' && $r['endpoint'] === 'custom-field-layouts');
$_GET = [];
apiRouterApplyV1Compatibility($r);
$assert("v1 custom field layouts sets entity_type", ($_GET['entity_type'] ?? null) === 'people');
$assert("v1 custom field layouts sets surface", ($_GET['surface'] ?? null) === 'detail');
$_GET = [];

$r = apiRouterParse('', '/api/v1/placements/custom-field-definitions');
$assert("v1 placements custom field definitions ok", $r['ok'] === true && $r['module_id'] === 'placements' && $r['endpoint'] === 'custom-field-definitions');
$_GET = [];
apiRouterApplyV1Compatibility($r);
$assert("v1 placements custom field definitions sets entity_type", ($_GET['entity_type'] ?? null) === 'placements');
$_GET = [];

$r = apiRouterParse('', '/api/v1/placements/custom-field-values/456');
$assert("v1 placements custom field values ok", $r['ok'] === true && $r['module_id'] === 'placements' && $r['endpoint'] === 'custom-field-values');
$_GET = [];
apiRouterApplyV1Compatibility($r);
$assert("v1 placements custom field values sets entity_type", ($_GET['entity_type'] ?? null) === 'placements');
$assert("v1 placements custom field values sets record_id", ($_GET['record_id'] ?? null) === '456');
$_GET = [];

$r = apiRouterParse('', '/api/v1/people/graph/resolve');
$assert("v1 people graph resolve ok", $r['ok'] === true && $r['module_id'] === 'people' && $r['endpoint'] === 'graph');
$_GET = [];
apiRouterApplyV1Compatibility($r);
$assert("v1 people graph resolve sets action", ($_GET['action'] ?? null) === 'resolve');
$_GET = [];

$r = apiRouterParse('', '/api/v1/platform/audit-log?event=workflow');
$assert("v1 platform audit-log ok", $r['ok'] === true && $r['module_id'] === 'platform' && $r['endpoint'] === 'audit-log');
$_GET = [];
apiRouterApplyV1Compatibility($r);
$assert("v1 platform audit-log adds no synthetic action", !isset($_GET['action']));
$_GET = [];

$r = apiRouterParse('', '/api/v1/platform/workflow/inbox');
$assert("v1 platform workflow inbox ok", $r['ok'] === true && $r['module_id'] === 'platform' && $r['endpoint'] === 'workflow');
$_GET = [];
apiRouterApplyV1Compatibility($r);
$assert("v1 platform workflow inbox sets path", ($_GET['path'] ?? null) === 'inbox');
$_GET = [];

$r = apiRouterParse('', '/api/v1/platform/workflow/instances/123');
$assert("v1 platform workflow instance detail ok", $r['ok'] === true && $r['subpath'] === ['instances', '123']);
$_GET = [];
apiRouterApplyV1Compatibility($r);
$assert("v1 platform workflow instance detail sets id", ($_GET['id'] ?? null) === '123');
$assert("v1 platform workflow instance detail adds no action", !isset($_GET['action']));
$_GET = [];

$r = apiRouterParse('', '/api/v1/platform/workflow/instances/123/act');
$assert("v1 platform workflow instance action ok", $r['ok'] === true && $r['subpath'] === ['instances', '123', 'act']);
$_GET = [];
apiRouterApplyV1Compatibility($r);
$assert("v1 platform workflow instance action sets id", ($_GET['id'] ?? null) === '123');
$assert("v1 platform workflow instance action sets action", ($_GET['action'] ?? null) === 'act');
$_GET = ['id' => '999', 'action' => 'comment'];
apiRouterApplyV1Compatibility($r);
$assert("v1 platform workflow preserves explicit id", $_GET['id'] === '999');
$assert("v1 platform workflow preserves explicit action", $_GET['action'] === 'comment');
$_GET = [];

$r = apiRouterParse('', '/api/');
$assert("missing module + endpoint → 400", $r['ok'] === false && $r['status'] === 400);

$r = apiRouterParse('', '/api/people');
$assert("missing endpoint → 400",          $r['ok'] === false && $r['status'] === 400);

$r = apiRouterParse('/PEOPLE/employees', '/api/PEOPLE/employees');
$assert("uppercase module rejected",       $r['ok'] === false);

$r = apiRouterParse('/people/../etc/passwd', '/api/people/../etc/passwd');
$assert("path traversal rejected",         $r['ok'] === false);

$r = apiRouterParse('/people/employees;bad', '/api/people/employees;bad');
$assert("special chars rejected",          $r['ok'] === false);

// ---------------------------------------------------------------------------
echo "\nFile resolution\n";
$file = apiRouterResolveFile('people', 'employees');
$assert("resolves real people/employees endpoint",
    $file !== null && str_ends_with($file, '/modules/people/api/employees.php'));

$file = apiRouterResolveFile('people', 'graph');
$assert("resolves people/graph platform alias",
    $file !== null && str_ends_with($file, '/api/people_graph.php'));

$file = apiRouterResolveFile('platform', 'audit-log');
$assert("resolves platform/audit-log alias without module manifest",
    $file !== null && str_ends_with($file, '/api/audit_log.php'));

$file = apiRouterResolveFile('platform', 'workflow');
$assert("resolves platform/workflow alias without module manifest",
    $file !== null && str_ends_with($file, '/api/workflow.php'));

$assert("platform audit-log skips synthetic base permission",
    apiRouterBasePermission(['module_id' => 'platform', 'endpoint' => 'audit-log']) === null);
$assert("platform workflow skips synthetic base permission",
    apiRouterBasePermission(['module_id' => 'platform', 'endpoint' => 'workflow']) === null);
$assert("normal module keeps base permission",
    apiRouterBasePermission(['module_id' => 'people', 'endpoint' => 'employees']) === 'people.view');

$file = apiRouterResolveFile('people', 'nope_does_not_exist');
$assert("returns null for missing endpoint", $file === null);

$file = apiRouterResolveFile('not_a_real_module', 'whatever');
$assert("returns null for unregistered module", $file === null);

$file = apiRouterResolveFile('reports', 'report-builder');
$assert("resolves reports/report-builder platform alias",
    $file !== null && str_ends_with($file, '/api/report_builder.php'));

$file = apiRouterResolveFile('reports', 'export-templates');
$assert("resolves reports/export-templates platform alias",
    $file !== null && str_ends_with($file, '/api/export_templates.php'));

$file = apiRouterResolveFile('treasury', 'recommendations');
$assert("resolves treasury/recommendations module endpoint",
    $file !== null && str_ends_with($file, '/modules/treasury/api/recommendations.php'));

$file = apiRouterResolveFile('treasury', 'policy');
$assert("resolves treasury/policy module endpoint",
    $file !== null && str_ends_with($file, '/modules/treasury/api/policy.php'));

$file = apiRouterResolveFile('treasury', 'payments');
$assert("resolves treasury/payments module endpoint",
    $file !== null && str_ends_with($file, '/modules/treasury/api/payments.php'));

$file = apiRouterResolveFile('treasury', 'deposit-accounts');
$assert("resolves treasury/deposit-accounts module endpoint",
    $file !== null && str_ends_with($file, '/modules/treasury/api/deposit_accounts.php'));

$file = apiRouterResolveFile('treasury', 'liability-accounts');
$assert("resolves treasury/liability-accounts module endpoint",
    $file !== null && str_ends_with($file, '/modules/treasury/api/liability_accounts.php'));

$file = apiRouterResolveFile('treasury', 'cash-position');
$assert("resolves treasury/cash-position module endpoint",
    $file !== null && str_ends_with($file, '/modules/treasury/api/cash_position.php'));

$file = apiRouterResolveFile('treasury', 'account-transactions');
$assert("resolves treasury/account-transactions module endpoint",
    $file !== null && str_ends_with($file, '/modules/treasury/api/account_transactions.php'));

$file = apiRouterResolveFile('treasury', 'liquidity-forecast');
$assert("resolves treasury/liquidity-forecast module endpoint",
    $file !== null && str_ends_with($file, '/modules/treasury/api/liquidity_forecast.php'));

$file = apiRouterResolveFile('treasury', 'liquidity-forecast-variance');
$assert("resolves treasury/liquidity-forecast-variance module endpoint",
    $file !== null && str_ends_with($file, '/modules/treasury/api/liquidity_forecast_variance.php'));

$file = apiRouterResolveFile('people', 'custom-field-definitions');
$assert("resolves custom field definitions platform alias",
    $file !== null && str_ends_with($file, '/api/custom_field_definitions.php'));

$file = apiRouterResolveFile('people', 'custom-field-values');
$assert("resolves custom field values platform alias",
    $file !== null && str_ends_with($file, '/api/custom_field_values.php'));

$file = apiRouterResolveFile('people', 'custom-field-layouts');
$assert("resolves custom field layouts platform alias",
    $file !== null && str_ends_with($file, '/api/custom_field_layouts.php'));

// ---------------------------------------------------------------------------
echo "\n";
echo "Total: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
