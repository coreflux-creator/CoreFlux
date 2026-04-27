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

$file = apiRouterResolveFile('people', 'nope_does_not_exist');
$assert("returns null for missing endpoint", $file === null);

$file = apiRouterResolveFile('not_a_real_module', 'whatever');
$assert("returns null for unregistered module", $file === null);

// ---------------------------------------------------------------------------
echo "\n";
echo "Total: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
