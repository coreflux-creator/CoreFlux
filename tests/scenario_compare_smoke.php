<?php
/**
 * Compare Scenarios smoke test.
 *
 * Asserts:
 *   - api/treasury_scenario_compare.php parses, POST-only, RBAC-gated.
 *   - Validates BOTH scenarios independently (kind whitelist, positive
 *     amount, YYYY-MM-DD), 50-event cap per side, label length cap.
 *   - Reuses the shared liquidity engine (no duplicate SQL).
 *   - Runs THREE projections from the SAME baseline datasets.
 *   - Returns baseline + scenario_a + scenario_b + pairwise deltas
 *     (a_vs_baseline, b_vs_baseline, a_vs_b) + guards.
 *   - Daily series included on every projection so the UI can chart
 *     three lines with no further round-trips.
 *   - Module-namespaced kebab alias delegates.
 *   - TreasuryScenarioCompare.jsx: page mounts, loads saved presets
 *     library, defaults to top 2, blocks self-comparison, renders
 *     three-series SVG chart, pairwise delta cards, side-by-side
 *     event stacks.
 *   - TreasuryModule routes /compare and adds tab.
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

echo "Endpoint — api/treasury_scenario_compare.php\n";
$apiPath = "{$ROOT}/api/treasury_scenario_compare.php";
$assert('endpoint exists',                       is_readable($apiPath));
$assert('parses',                                $lint($apiPath));
$api = (string) file_get_contents($apiPath);
$assert('declares strict_types',                 strpos($api, 'declare(strict_types=1)') !== false);
$assert('POST-only — 405 on others',
    strpos($api, "if (api_method() !== 'POST') api_error('Method not allowed', 405)") !== false);
$assert('RBAC: treasury.payment.view',           strpos($api, "rbac_legacy_require(\$user, 'treasury.payment.view')") !== false);
$assert('reuses shared engine (zero new SQL)',
    strpos($api, "require_once __DIR__ . '/../core/treasury/liquidity_projection.php'") !== false);

echo "\nValidation — both scenarios checked symmetrically\n";
$assert('label length cap (120) per scenario',
    strpos($api, "max 120 chars") !== false);
$assert('events must be array per scenario',
    strpos($api, "events must be an array") !== false);
$assert('50-event cap per scenario',
    strpos($api, "events max 50") !== false);
$assert('kind whitelist enforced (inflow|outflow)',
    strpos($api, "in_array(\$kind, ['inflow','outflow'], true)") !== false);
$assert('amount > 0 enforced',                   strpos($api, '$amount <= 0') !== false);
$assert('date YYYY-MM-DD format guard',
    strpos($api, "preg_match('/^\\d{4}-\\d{2}-\\d{2}\$/', \$date)") !== false);
$assert('dates clamped to active forecast window',
    strpos($api, '$date < $today')   !== false
    && strpos($api, '$date > $endDate') !== false);

echo "\nProjection math — three runs off the same baseline\n";
$assert('baseline pulled once via shared engine',
    strpos($api, '$datasets = liquidityBaselineDatasets($tid, $today, $endDate);') !== false);
$assert('baseline projection',                   strpos($api, '$baseline = liquidityWalkProjection(') !== false);
$assert('projection A overlays scenario_a maps',
    strpos($api, '$projA = liquidityWalkProjection(') !== false
    && strpos($api, "\$a['inflows_by_date'], \$a['outflows_by_date']") !== false);
$assert('projection B overlays scenario_b maps',
    strpos($api, '$projB = liquidityWalkProjection(') !== false
    && strpos($api, "\$b['inflows_by_date'], \$b['outflows_by_date']") !== false);
$assert('source detail generated for baseline + both scenarios',
    strpos($api, '$baselineSourceDetail = liquidityProjectionSourceDetail($datasets);') !== false
    && strpos($api, '$sourceDetailA = liquidityProjectionSourceDetail($datasets, [') !== false
    && strpos($api, '$sourceDetailB = liquidityProjectionSourceDetail($datasets, [') !== false);
$assert('daily rows include attached source detail for all three series',
    strpos($api, '$baselineDaily = liquidityAttachDailySourceDetail(') !== false
    && strpos($api, '$dailyA = liquidityAttachDailySourceDetail(') !== false
    && strpos($api, '$dailyB = liquidityAttachDailySourceDetail(') !== false);

echo "\nResponse envelope — three series + pairwise deltas\n";
$assert('returns baseline series with enriched daily',    preg_match("/'baseline'\\s*=>.*?'daily'\\s*=> \\\$baselineDaily/s", $api) === 1);
$assert('returns scenario_a (label, events, daily, totals)',
    strpos($api, "'scenario_a'") !== false
    && strpos($api, "'source_detail'        => \$sourceDetailA") !== false
    && strpos($api, "'daily'               => \$dailyA") !== false
    && strpos($api, "'inflow_total'") !== false
    && strpos($api, "'net_event_impact'") !== false);
$assert('returns scenario_b symmetrically',
    strpos($api, "'scenario_b'") !== false
    && strpos($api, "'source_detail'        => \$sourceDetailB") !== false
    && strpos($api, "'daily'               => \$dailyB") !== false);
$assert("returns 'deltas' envelope with all three pairs",
    strpos($api, "'a_vs_baseline'") !== false
    && strpos($api, "'b_vs_baseline'") !== false
    && strpos($api, "'a_vs_b'") !== false);
$assert('delta envelope shape matches single-scenario shape',
    strpos($api, "'lowest_balance_shift'") !== false
    && strpos($api, "'lowest_date_shift_days'") !== false
    && strpos($api, "'runway_days_lost'") !== false
    && strpos($api, "'crosses_zero'") !== false);
$assert('returns guards envelope',               strpos($api, "'guards'") !== false);

echo "\nKebab alias — /modules/treasury/api/scenario_compare.php\n";
$alias = "{$ROOT}/modules/treasury/api/scenario_compare.php";
$assert('alias file exists',                     is_readable($alias));
$assert('alias delegates to platform endpoint',
    strpos((string) file_get_contents($alias), '/api/treasury_scenario_compare.php') !== false);

echo "\nUI — TreasuryScenarioCompare.jsx page\n";
$pgPath = "{$ROOT}/dashboard/src/pages/TreasuryScenarioCompare.jsx";
$assert('page file exists',                      is_readable($pgPath));
$pg = (string) file_get_contents($pgPath);
$assert('imports api + useApi',                  strpos($pg, "import { api, useApi } from '../lib/api'") !== false);
$assert('reads saved-preset library',            strpos($pg, "useApi('/api/treasury_scenario_presets.php')") !== false);
$assert('posts to compare endpoint',             strpos($pg, "api.post('/api/treasury_scenario_compare.php'") !== false);
$assert('page root testid',                      strpos($pg, 'data-testid="scenario-compare-page"') !== false);
$assert('window selector testid',                strpos($pg, 'data-testid="scenario-compare-window-select"') !== false);
$assert('"need two presets" empty-state testid', strpos($pg, 'data-testid="scenario-compare-need-two"') !== false);
$assert('Scenario A picker testid',              strpos($pg, 'testid="scenario-compare-pick-a"') !== false);
$assert('Scenario B picker testid',              strpos($pg, 'testid="scenario-compare-pick-b"') !== false);
$assert('blocks self-comparison (warning)',
    strpos($pg, 'data-testid="scenario-compare-same-warning"') !== false
    && strpos($pg, 'a.id === b.id') !== false);
$assert('three pairwise delta cards (a-vs-baseline, b-vs-baseline, a-vs-b)',
    strpos($pg, 'testid="scenario-compare-delta-a-vs-baseline"') !== false
    && strpos($pg, 'testid="scenario-compare-delta-b-vs-baseline"') !== false
    && strpos($pg, 'testid="scenario-compare-delta-a-vs-b"') !== false);
$assert('chart testid',                          strpos($pg, 'data-testid="scenario-compare-chart"') !== false);
$assert('uses SVG <path> for three series',
    strpos($pg, 'pathFor(s.points)') !== false
    && strpos($pg, 'chart.series.map((s)') !== false);
$assert('side-by-side event-stack testids',
    strpos($pg, 'testid="scenario-compare-events-a"') !== false
    && strpos($pg, 'testid="scenario-compare-events-b"') !== false);
$assert('default-picks first two presets when library loads',
    strpos($pg, 'presets.length >= 2 && !aId && !bId') !== false);
$assert('error + loading testids',
    strpos($pg, 'data-testid="scenario-compare-error"') !== false
    && strpos($pg, 'data-testid="scenario-compare-loading"') !== false);

echo "\nRouting — TreasuryModule\n";
$tm = (string) file_get_contents("{$ROOT}/modules/treasury/ui/TreasuryModule.jsx");
$assert('imports TreasuryScenarioCompare',       strpos($tm, "import TreasuryScenarioCompare  from '../../../dashboard/src/pages/TreasuryScenarioCompare'") !== false);
$assert('mounts /compare route',                 strpos($tm, '<Route path="compare"       element={<TreasuryScenarioCompare />} />') !== false);
$assert('adds "Compare Scenarios" tab',          strpos($tm, '<TreasuryTab to="compare"     label="Compare Scenarios" />') !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
