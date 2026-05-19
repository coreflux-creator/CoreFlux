<?php
/**
 * P0 smoke — Inline AP Bill Liquidity Impact tool.
 *
 * Asserts:
 *   - api/ap_bill_liquidity_impact.php parses, GET-only, RBAC-gated.
 *   - bill_id required (422), unknown bill 404, days clamped 1..365.
 *   - pay_date defaults to today, stays inside the forecast window,
 *     never accepts a past date.
 *   - reuses the SAME data sources as liquidity_forecast (cash GL,
 *     billing_invoices AR, treasury_payments + ap_bills outflow union
 *     with same vendor+amount dedup heuristic).
 *   - excludes the simulated bill itself from baseline outflows so the
 *     comparison only adds the "what if I pay today" overlay (otherwise
 *     a bill due-in-window would be double-counted on its due_date AND
 *     on $payDate).
 *   - returns baseline / simulated / delta envelope with lowest_balance,
 *     lowest_balance_date, runway_days_to_zero on each side.
 *   - module-namespaced kebab alias /modules/ap/api/bill_liquidity_impact.php
 *     delegates to the platform endpoint.
 *   - BillDetail.jsx mounts a <LiquidityImpactPanel /> only when
 *     amount_due > 0, with date picker, error/loading state, the
 *     baseline → simulated shift line, runway-loss warning, and a
 *     "balance stays positive" affirmation when no impact.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$lint = function (string $p): bool {
    $o = []; $rc = 0; @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
    return $rc === 0;
};
$ROOT = realpath(__DIR__ . '/..');

echo "Endpoint — api/ap_bill_liquidity_impact.php\n";
$apiPath = "{$ROOT}/api/ap_bill_liquidity_impact.php";
$assert('endpoint exists',                       is_readable($apiPath));
$assert('parses',                                $lint($apiPath));
$api = (string) file_get_contents($apiPath);
$assert('declares strict_types',                 strpos($api, 'declare(strict_types=1)') !== false);
$assert('requires api_bootstrap',                strpos($api, "require_once __DIR__ . '/../core/api_bootstrap.php'") !== false);
$assert('requires RBAC',                         strpos($api, "require_once __DIR__ . '/../core/RBAC.php'") !== false);
$assert('GET only — 405 on other verbs',         strpos($api, "if (api_method() !== 'GET') api_error('Method not allowed', 405)") !== false);
$assert('RBAC: treasury.payment.view',           strpos($api, "rbac_legacy_require(\$user, 'treasury.payment.view')") !== false);

echo "\nValidation\n";
$assert('bill_id required (422)',                strpos($api, "api_error('bill_id required', 422)") !== false);
$assert('unknown bill → 404',                    strpos($api, "api_error('Bill not found', 404)") !== false);
$assert('days clamped 1..365',                   strpos($api, 'max(1, min(365, (int) (api_query(\'days\') ?? 90)))') !== false);
$assert('pay_date defaults to today',            strpos($api, "(string) (api_query('pay_date') ?? \$today)") !== false);
$assert('pay_date format guard (YYYY-MM-DD)',    strpos($api, "preg_match('/^\\d{4}-\\d{2}-\\d{2}\$/'") !== false);
$assert('pay_date clamped >= today',             strpos($api, 'if ($payDate < $today)') !== false);
$assert('pay_date clamped <= forecast end',      strpos($api, 'if ($payDate > $endDate)') !== false);
$assert('zero-balance bill short-circuits',      strpos($api, "no liquidity impact") !== false);

echo "\nData sources — delegated to shared engine\n";
$assert('imports core/treasury/liquidity_projection.php',
    strpos($api, "require_once __DIR__ . '/../core/treasury/liquidity_projection.php'") !== false);
$assert('calls liquidityBaselineDatasets with excludeBillId',
    strpos($api, 'liquidityBaselineDatasets($tid, $today, $endDate, null, $billId)') !== false);
$assert('calls liquidityBucketDatasets',
    strpos($api, 'liquidityBucketDatasets($datasets)') !== false);
$assert('comment explains why simulated bill is excluded from baseline',
    strpos($api, '// Skip the bill we\'re simulating from baseline outflows') !== false);

echo "\nSimulation math — delegated to shared engine\n";
$assert('runs baseline projection via shared walker',
    strpos($api, '$baseline  = liquidityWalkProjection(') !== false);
$assert('runs simulated projection with extra outflow on pay_date',
    strpos($api, '$simulated = liquidityWalkProjection(') !== false
    && strpos($api, '[$payDate => $billAmount]') !== false);
$assert('emits delta envelope (lowest shift, runway lost, crosses_zero)',
    strpos($api, "'lowest_balance_shift'") !== false
    && strpos($api, "'lowest_date_shift_days'") !== false
    && strpos($api, "'runway_days_lost'") !== false
    && strpos($api, "'crosses_zero'") !== false);
$assert('response includes baseline + simulated + delta keys',
    strpos($api, "'baseline'      => [") !== false
    && strpos($api, "'simulated'     => [") !== false
    && strpos($api, "'delta'         => [") !== false);

echo "\nKebab alias — /modules/ap/api/bill_liquidity_impact.php\n";
$alias = "{$ROOT}/modules/ap/api/bill_liquidity_impact.php";
$assert('alias file exists',                     is_readable($alias));
$assert('alias delegates to platform endpoint',
    strpos((string) file_get_contents($alias), "/api/ap_bill_liquidity_impact.php") !== false);

echo "\nUI — BillDetail.jsx LiquidityImpactPanel\n";
$bd = (string) file_get_contents("{$ROOT}/modules/ap/ui/BillDetail.jsx");
$assert('mounts panel only when amount_due > 0',
    strpos($bd, 'Number(bill.amount_due) > 0 && (') !== false
    && strpos($bd, '<LiquidityImpactPanel billId={id} amountDue={Number(bill.amount_due)} />') !== false);
$assert('declares LiquidityImpactPanel function', strpos($bd, 'function LiquidityImpactPanel(') !== false);
$assert('uses /api/ap_bill_liquidity_impact.php',
    strpos($bd, '/api/ap_bill_liquidity_impact.php?bill_id=') !== false);
$assert('date picker testid',                    strpos($bd, 'data-testid="ap-bill-liquidity-impact-date"') !== false);
$assert('panel root testid',                     strpos($bd, 'data-testid="ap-bill-liquidity-impact"') !== false);
$assert('renders baseline → simulated shift',    strpos($bd, 'data-testid="ap-bill-liquidity-impact-shift"') !== false);
$assert('runway warning testid',                 strpos($bd, 'data-testid="ap-bill-liquidity-impact-runway"') !== false);
$assert('"balance stays positive" affirmation',  strpos($bd, 'data-testid="ap-bill-liquidity-impact-safe"') !== false);
$assert('loading + error states',
    strpos($bd, 'data-testid="ap-bill-liquidity-impact-loading"') !== false
    && strpos($bd, 'data-testid="ap-bill-liquidity-impact-error"') !== false);
$assert('zero-balance note surfaced',            strpos($bd, 'data-testid="ap-bill-liquidity-impact-note"') !== false);
$assert('formatter uses toLocaleString for currency',
    strpos($bd, 'toLocaleString') !== false);
$assert('date picker disallows past dates (min={today})',
    strpos($bd, 'min={today}') !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
