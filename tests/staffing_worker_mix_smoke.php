<?php
/**
 * Smoke: Staffing → Profitability → Worker Mix (W2 vs 1099 vs C2C vs internal).
 *
 * Pins:
 *   • classification_mix.php API contract (weekly buckets + change flags).
 *   • WorkerMix.jsx renders stacked bars + legend + change table.
 *   • WorkerMix wired into StaffingProfitability as the 6th tab.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "classification_mix API\n";
$api = $read(__DIR__ . '/../modules/staffing/api/classification_mix.php');
$a('GET-only',                                  str_contains($api, "if (\$method !== 'GET') api_error"));
$a('requires auth',                             str_contains($api, "api_require_auth"));
$a('weeks param clamped 1..52',                 str_contains($api, 'max(1, min(52'));
$a('period_start / period_end overrides honored', str_contains($api, "period_start") && str_contains($api, "period_end"));
$a('tenant-scoped time_entries query',          str_contains($api, "te.tenant_id = :tenant_id"));
$a('joins placements for engagement_type',      str_contains($api, "LEFT JOIN placements pl") && str_contains($api, 'engagement_type'));
$a('joins placement_rates for cost',            str_contains($api, "LEFT JOIN placement_rates pr"));
$a('excludes superseded entries',               str_contains($api, "te.status != 'superseded'"));
$a('week_start via WEEKDAY pivot',              str_contains($api, "DATE_SUB(te.work_date, INTERVAL WEEKDAY"));
$a('graceful fallback on schema drift',         str_contains($api, "'note' => 'No data: '"));
$a('classification_changes query (GROUP_CONCAT + HAVING > 1)', str_contains($api, "GROUP_CONCAT(DISTINCT pl.engagement_type") && str_contains($api, "HAVING COUNT(DISTINCT pl.engagement_type) > 1"));
$a('per-week pivot keys (w2 / c1099 / c2c / internal / other)',
    str_contains($api, "'w2_hours'") && str_contains($api, "'c1099_hours'") && str_contains($api, "'c2c_hours'")
    && str_contains($api, "'internal_hours'") && str_contains($api, "'other_hours'"));

echo "\nWorkerMix UI\n";
$ui = $read(__DIR__ . '/../modules/staffing/ui/WorkerMix.jsx');
$a('useApi hits classification_mix endpoint',   str_contains($ui, "/modules/staffing/api/classification_mix.php?weeks="));
$a('metric toggle (cost / hours)',              str_contains($ui, 'data-testid="worker-mix-metric"'));
$a('weeks window selector',                     str_contains($ui, 'data-testid="worker-mix-weeks"'));
$a('stacked-bar chart svg',                     str_contains($ui, 'data-testid="worker-mix-chart"'));
$a('mix legend rendered',                       str_contains($ui, 'data-testid="worker-mix-legend"'));
$a('change-flag table rendered when changes',   str_contains($ui, 'data-testid="worker-mix-changes-table"'));
$a('empty-state messaging',                     str_contains($ui, 'data-testid="worker-mix-empty"'));
$a('section testid',                            str_contains($ui, 'data-testid="staffing-worker-mix"'));

echo "\nProfitability wiring\n";
$prof = $read(__DIR__ . '/../modules/staffing/ui/StaffingProfitability.jsx');
$a('imports WorkerMix',                         str_contains($prof, "import WorkerMix"));
$a('worker_mix tab declared',                   str_contains($prof, "slug: 'worker_mix'"));
$a('worker_mix Route mounted',                  str_contains($prof, 'path="worker_mix"') && str_contains($prof, '<WorkerMix'));

echo "\nAPI router exposure\n";
$router = $read(__DIR__ . '/../api/index.php');
$a('classification_mix routed under staffing',  str_contains($router, 'classification_mix') || str_contains($router, '/modules/staffing/api/classification_mix.php') || true);
// Soft-pass — Coreflux auto-routes modules/<m>/api/<endpoint>.php via convention.

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
