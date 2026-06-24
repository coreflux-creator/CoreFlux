<?php
/**
 * Smoke: liquidity forecast variance.
 *
 * Locks the deterministic accuracy loop for Treasury projections:
 * replay projected daily movement for a historical window, compare it to
 * posted bank-account GL movement, and expose WAPE/bias/error metrics.
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    {$name}\n"; }
    else     { $fail++; echo "  FAIL  {$name}\n"; }
};
$lint = static function (string $path): bool {
    $out = []; $rc = 0;
    @exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $rc);
    return $rc === 0;
};

$apiPath = "{$ROOT}/api/liquidity_forecast_variance.php";
$aliasPath = "{$ROOT}/modules/treasury/api/liquidity_forecast_variance.php";
$uiPath = "{$ROOT}/dashboard/src/pages/LiquidityForecast.jsx";

$api = (string) file_get_contents($apiPath);
$ui = (string) file_get_contents($uiPath);

echo "Endpoint\n";
$a('variance endpoint parses', $lint($apiPath));
$a('GET-only + treasury view RBAC',
    str_contains($api, "if (api_method() !== 'GET') api_error('Method not allowed', 405)")
    && str_contains($api, "rbac_legacy_require(\$user, 'treasury.payment.view')"));
$a('clamps days and validates start_date',
    str_contains($api, "max(1, min(365, (int) (api_query('days') ?? 30)))")
    && str_contains($api, "api_query('start_date')")
    && str_contains($api, "preg_match('/^\\d{4}-\\d{2}-\\d{2}\$/', \$startDate)"));
$a('replays baseline projection with shared engine',
    str_contains($api, 'liquidityBaselineDatasets($tid, $startDate, $endDate, $entityId)')
    && str_contains($api, 'liquidityBucketDatasets($datasets)')
    && str_contains($api, 'liquidityProjectionEvidence($tid, $startDate, $endDate, $days, $datasets)')
    && str_contains($api, 'liquidityProjectionSourceDetail($datasets)'));
$a('actuals come from posted bank GL movement',
    str_contains($api, 'function liquidityForecastVarianceActuals(')
    && str_contains($api, 'FROM accounting_bank_accounts ba')
    && str_contains($api, 'JOIN accounting_accounts aa ON aa.tenant_id = ba.tenant_id AND aa.code = ba.gl_account_code')
    && str_contains($api, 'JOIN accounting_journal_lines jl')
    && str_contains($api, "je.status = 'posted'")
    && str_contains($api, 'je.posting_date BETWEEN :s AND :e'));
$a('daily rows compare projected vs actual',
    str_contains($api, "'projected' => [")
    && str_contains($api, "'actual' => [")
    && str_contains($api, "'variance' => \$variance")
    && str_contains($api, "'absolute_error' => round(\$absoluteError, 2)"));
$a('metrics expose WAPE, bias, and exception counts',
    str_contains($api, "'wape' => \$wape")
    && str_contains($api, "'bias' => \$bias")
    && str_contains($api, "'accuracy_score'")
    && str_contains($api, "'missed_inflow_days'")
    && str_contains($api, "'early_or_late_outflow_days'"));

echo "\nAlias\n";
$a('module alias exists and delegates', is_readable($aliasPath)
    && str_contains((string) file_get_contents($aliasPath), '/../../../api/liquidity_forecast_variance.php'));

echo "\nUI\n";
$a('LiquidityForecast reads variance endpoint',
    str_contains($ui, "useApi('/api/v1/treasury/liquidity-forecast-variance?days=30')"));
$a('Forecast accuracy panel renders metrics and exceptions',
    str_contains($ui, 'data-testid="liquidity-forecast-accuracy"')
    && str_contains($ui, 'data-testid="liquidity-accuracy-metrics"')
    && str_contains($ui, 'data-testid="liquidity-accuracy-exceptions"')
    && str_contains($ui, 'function ForecastAccuracyPanel('));
$a('loading and error states are testable',
    str_contains($ui, 'data-testid="liquidity-accuracy-loading"')
    && str_contains($ui, 'data-testid="liquidity-accuracy-error"'));

echo "\nSyntax\n";
$a('alias parses', $lint($aliasPath));
$a('test parses', $lint(__FILE__));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
