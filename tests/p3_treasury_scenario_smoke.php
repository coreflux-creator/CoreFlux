<?php
/**
 * Treasury What-If Scenario Builder smoke test.
 *
 * Asserts:
 *   - core/treasury/liquidity_projection.php is the shared engine (all
 *     three callers — main forecast, per-bill what-if, and scenario —
 *     reuse the same projection math).
 *   - api/treasury_scenario.php parses, RBAC-gated, accepts POST/GET,
 *     validates events array (kind, amount > 0, YYYY-MM-DD date),
 *     caps at 50 events, clamps dates inside window.
 *   - returns baseline + simulated + delta envelope with all the keys
 *     the React page renders (lowest_balance, lowest_balance_date,
 *     runway_days_to_zero, ending_cash, daily, net_event_impact,
 *     inflow_total, outflow_total).
 *   - kebab alias /modules/treasury/api/scenario.php delegates.
 *   - TreasuryScenario.jsx mounts at /modules/treasury/scenario, renders
 *     event composer + stack + KPI tiles + dual-bar chart + runway
 *     alert + safe banner.
 *   - TreasuryModule has the new "What-If Scenario" tab + route.
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

echo "Library — core/treasury/liquidity_projection.php\n";
$libPath = "{$ROOT}/core/treasury/liquidity_projection.php";
$assert('library exists',                         is_readable($libPath));
$assert('parses',                                 $lint($libPath));
$lib = (string) file_get_contents($libPath);
$assert('declares strict_types',                  strpos($lib, 'declare(strict_types=1)') !== false);
$assert('exports liquidityBaselineDatasets',      strpos($lib, 'function liquidityBaselineDatasets(') !== false);
$assert('exports liquidityWalkProjection',        strpos($lib, 'function liquidityWalkProjection(') !== false);
$assert('exports liquidityBucketDatasets',        strpos($lib, 'function liquidityBucketDatasets(') !== false);
$assert('walker accepts extraInflows + extraOutflows overlays',
    strpos($lib, 'array $extraInflowsByDate = []') !== false
    && strpos($lib, 'array $extraOutflowsByDate = []') !== false);
$assert('walker tracks lowest balance + date + runway',
    strpos($lib, "'lowest_balance'") !== false
    && strpos($lib, "'lowest_balance_date'") !== false
    && strpos($lib, "'runway_days_to_zero'") !== false);
$assert('baseline pulls cash GL + AR + TP + AP',
    strpos($lib, 'FROM accounting_bank_accounts ba') !== false
    && strpos($lib, 'FROM billing_invoices') !== false
    && strpos($lib, 'FROM treasury_payments') !== false
    && strpos($lib, 'FROM ap_bills') !== false);
$assert('baseline applies vendor+amount dedup heuristic',
    strpos($lib, "strtolower((string) \$r['payee_name'])") !== false);
$assert('baseline can exclude a specific bill_id (per-bill what-if)',
    strpos($lib, '$excludeBillId') !== false);

echo "\nEndpoint — api/treasury_scenario.php\n";
$apiPath = "{$ROOT}/api/treasury_scenario.php";
$assert('endpoint exists',                        is_readable($apiPath));
$assert('parses',                                 $lint($apiPath));
$api = (string) file_get_contents($apiPath);
$assert('requires shared projection lib',         strpos($api, "require_once __DIR__ . '/../core/treasury/liquidity_projection.php'") !== false);
$assert('RBAC: treasury.payment.view',            strpos($api, "RBAC::requirePermission(\$user, 'treasury.payment.view')") !== false);
$assert('accepts POST + GET',                     strpos($api, "in_array(api_method(), ['POST', 'GET'], true)") !== false);

echo "\nValidation — events array\n";
$assert('events must be an array',                strpos($api, "'events must be an array'") !== false);
$assert('event count cap (50)',                   strpos($api, 'count($rawEvents) > 50') !== false);
$assert('kind whitelist (inflow|outflow)',        strpos($api, "in_array(\$kind, ['inflow', 'outflow'], true)") !== false);
$assert('amount > 0',                             strpos($api, '$amount <= 0') !== false);
$assert('date YYYY-MM-DD format guard',
    strpos($api, "preg_match('/^\\d{4}-\\d{2}-\\d{2}\$/', \$date)") !== false);
$assert('clamps date >= today',                   strpos($api, '$date < $today')   !== false);
$assert('clamps date <= forecast end',            strpos($api, '$date > $endDate') !== false);
$assert('days clamped 1..365',                    strpos($api, 'max(1, min(365,') !== false);

echo "\nResponse envelope\n";
$assert('returns events (echoed back)',           strpos($api, "'events'      => \$events") !== false);
$assert('returns baseline + simulated',
    strpos($api, "'baseline'    => [") !== false
    && strpos($api, "'simulated'   => [") !== false);
$assert('returns delta envelope',                 strpos($api, "'delta'       => [") !== false);
$assert('delta includes net_event_impact',        strpos($api, "'net_event_impact'") !== false);
$assert('delta includes inflow_total + outflow_total',
    strpos($api, "'inflow_total'") !== false
    && strpos($api, "'outflow_total'") !== false);
$assert('delta includes lowest_balance_shift',    strpos($api, "'lowest_balance_shift'") !== false);
$assert('delta includes runway_days_lost',        strpos($api, "'runway_days_lost'") !== false);
$assert('returns guards envelope',                strpos($api, "'guards'      => [") !== false);
$assert('runs walker baseline + simulated',
    strpos($api, '$baseline  = liquidityWalkProjection(') !== false
    && strpos($api, '$simulated = liquidityWalkProjection(') !== false);

echo "\nKebab alias — /modules/treasury/api/scenario.php\n";
$alias = "{$ROOT}/modules/treasury/api/scenario.php";
$assert('alias file exists',                      is_readable($alias));
$assert('alias delegates to platform endpoint',
    strpos((string) file_get_contents($alias), '/api/treasury_scenario.php') !== false);

echo "\nUI — TreasuryScenario.jsx page\n";
$pgPath = "{$ROOT}/dashboard/src/pages/TreasuryScenario.jsx";
$assert('page file exists',                       is_readable($pgPath));
$pg = (string) file_get_contents($pgPath);
$assert('imports api client',                     strpos($pg, "import { api } from '../lib/api'") !== false);
$assert('posts to /api/treasury_scenario.php',    strpos($pg, "api.post('/api/treasury_scenario.php'") !== false);
$assert('page root testid',                       strpos($pg, 'data-testid="treasury-scenario-page"') !== false);
$assert('window selector testid',                 strpos($pg, 'data-testid="scenario-window-select"') !== false);
$assert('event composer testid',                  strpos($pg, 'data-testid="scenario-event-composer"') !== false);
$assert('event-kind selector testid',             strpos($pg, 'data-testid="scenario-event-kind"') !== false);
$assert('event-amount input testid',              strpos($pg, 'data-testid="scenario-event-amount"') !== false);
$assert('event-date input testid',                strpos($pg, 'data-testid="scenario-event-date"') !== false);
$assert('event-label input testid',               strpos($pg, 'data-testid="scenario-event-label"') !== false);
$assert('event add button testid',                strpos($pg, 'data-testid="scenario-event-add"') !== false);
$assert('per-event row testid template',          strpos($pg, 'data-testid={`scenario-event-row-${idx}`}') !== false);
$assert('per-event remove testid template',       strpos($pg, 'data-testid={`scenario-event-remove-${idx}`}') !== false);
$assert('summary tiles testid',                   strpos($pg, 'data-testid="scenario-summary-tiles"') !== false);
$assert('chart testid',                           strpos($pg, 'data-testid="scenario-chart"') !== false);
$assert('runway alert testid',                    strpos($pg, 'data-testid="scenario-runway-alert"') !== false);
$assert('safe banner testid',                     strpos($pg, 'data-testid="scenario-safe-banner"') !== false);
$assert('no-banks nudge testid',                  strpos($pg, 'data-testid="scenario-no-banks-nudge"') !== false);
$assert('error testid',                           strpos($pg, 'data-testid="scenario-error"') !== false);
$assert('auto-runs baseline on mount',            strpos($pg, 'React.useEffect(() => { run([], days);') !== false);
$assert('amount validation guards positive',      strpos($pg, '!amount || amount <= 0') !== false);
$assert('date input min={today()}',               strpos($pg, 'min={today()}') !== false);
$assert('formats currency via toLocaleString',    strpos($pg, 'toLocaleString') !== false);

echo "\nRouting — TreasuryModule\n";
$tm = (string) file_get_contents("{$ROOT}/modules/treasury/ui/TreasuryModule.jsx");
$assert('imports TreasuryScenario',               strpos($tm, "import TreasuryScenario   from '../../../dashboard/src/pages/TreasuryScenario'") !== false);
$assert('mounts /scenario route',                 strpos($tm, '<Route path="scenario"      element={<TreasuryScenario />} />') !== false);
$assert('adds "What-If Scenario" tab',            strpos($tm, '<TreasuryTab to="scenario"    label="What-If Scenario" />') !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
