<?php
/**
 * Smoke: cross-sub-tenant consolidated reports.
 *
 * Static contract checks for:
 *   - Extracted standard_reports.php library (no top-level side effects)
 *   - per-tenant `modules/accounting/api/reports.php` now loads the lib
 *     instead of declaring report functions inline
 *   - new `/api/sub_tenant_consolidated_reports.php` master-only endpoint
 *   - React page `SubTenantConsolidatedReports.jsx`
 *   - Admin module wires the new route + sidebar link
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};

echo "Standard reports library extraction\n";
$lib = (string) file_get_contents(__DIR__ . '/../modules/accounting/lib/standard_reports.php');
$a('lib file exists',                          strlen($lib) > 200);
$a('declares reportIncomeStatement',           preg_match('/function\s+reportIncomeStatement\s*\(/', $lib) === 1);
$a('declares reportBalanceSheet',              preg_match('/function\s+reportBalanceSheet\s*\(/', $lib) === 1);
$a('declares reportCashFlowIndirect',          preg_match('/function\s+reportCashFlowIndirect\s*\(/', $lib) === 1);
$a('no top-level api_require_auth',            !str_contains($lib, 'api_require_auth()'));
$a('no top-level api_method check',            !preg_match('/^\s*if\s*\(\s*api_method\(\)/m', $lib));
$a('IS uses split :t_a / :t_je placeholders',  str_contains($lib, ':t_a') && str_contains($lib, ':t_je'));
$a('PHP parses cleanly',                       (int) shell_exec('php -l ' . escapeshellarg(__DIR__ . '/../modules/accounting/lib/standard_reports.php') . ' >/dev/null 2>&1; echo $?') === 0);

echo "\nPer-tenant reports endpoint refactor\n";
$ep = (string) file_get_contents(__DIR__ . '/../modules/accounting/api/reports.php');
$a('endpoint requires standard_reports.php',   str_contains($ep, "require_once __DIR__ . '/../lib/standard_reports.php'"));
$a('endpoint no longer declares functions',    !preg_match('/function\s+reportIncomeStatement\s*\(/', $ep));
$a('endpoint still gates auth',                str_contains($ep, 'api_require_auth()'));

echo "\nConsolidated reports endpoint\n";
$cr = (string) file_get_contents(__DIR__ . '/../api/sub_tenant_consolidated_reports.php');
$a('endpoint exists',                           strlen($cr) > 500);
$a('requires standard_reports.php lib',         str_contains($cr, "require_once __DIR__ . '/../modules/accounting/lib/standard_reports.php'"));
$a('does NOT require api/reports.php',          !str_contains($cr, "require_once") || !preg_match('/require_once[^;]*modules\/accounting\/api\/reports\.php/', $cr));
$a('resolves master parent',                    str_contains($cr, "tenant_type'] === 'master'"));
$a('master-only permission gate',               str_contains($cr, 'master_admin or master tenant_admin'));
$a('handles type=income_statement',             str_contains($cr, "if (\$type === 'income_statement')"));
$a('handles type=balance_sheet',                str_contains($cr, "if (\$type === 'balance_sheet')"));
$a('handles type=cash_flow_indirect',           str_contains($cr, "cash_flow_indirect"));
$a('?include_master flag',                      str_contains($cr, 'include_master'));
$a('aggregates by COA code',                    str_contains($cr, 'function _sumByCode'));
$a('returns by_tenant breakdown',               str_contains($cr, "'by_tenant'"));
$a('returns consolidated total',                str_contains($cr, "'consolidated'"));
$a('PHP parses cleanly',                        (int) shell_exec('php -l ' . escapeshellarg(__DIR__ . '/../api/sub_tenant_consolidated_reports.php') . ' >/dev/null 2>&1; echo $?') === 0);

echo "\nReact page\n";
$jsx = (string) file_get_contents(__DIR__ . '/../dashboard/src/pages/SubTenantConsolidatedReports.jsx');
$a('jsx exists',                                strlen($jsx) > 500);
$a('hits consolidated endpoint',                str_contains($jsx, '/api/sub_tenant_consolidated_reports.php'));
$a('type selector test-id',                     str_contains($jsx, 'data-testid="cr-type"'));
$a('include-master toggle test-id',             str_contains($jsx, 'data-testid="cr-include-master"'));
$a('refresh button test-id',                    str_contains($jsx, 'data-testid="cr-refresh"'));
$a('per-tenant breakdown table',                str_contains($jsx, 'data-testid="cr-per-tenant"'));
$a('IS view: revenue table test-id',            str_contains($jsx, 'testid="cr-revenue"'));
$a('BS view: assets table test-id',             str_contains($jsx, 'testid="cr-assets"'));

echo "\nAdmin module wiring\n";
$adm = (string) file_get_contents(__DIR__ . '/../dashboard/src/pages/AdminModule.jsx');
$a('imports SubTenantConsolidatedReports',      str_contains($adm, "import SubTenantConsolidatedReports from './SubTenantConsolidatedReports'"));
$a('routes /consolidated-reports',              str_contains($adm, "<Route path=\"/consolidated-reports\""));
$a('sidebar link Consolidated Reports',         str_contains($adm, "label: 'Consolidated Reports'"));
$a('overview action card linked',               str_contains($adm, '/admin/consolidated-reports'));

echo "\n--- " . ($pass + $fail) . " assertions, $fail failed ---\n";
exit($fail ? 1 : 0);
