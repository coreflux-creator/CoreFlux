<?php
/**
 * Simulation Harness — Phase H2 smoke (2026-02-XX).
 *
 * Validates:
 *   1. Mock layer (manager + plaid + openai + email).
 *   2. Mock determinism: same seed → byte-identical responses.
 *   3. Fault injection (rate_limit/timeout/server_error/malformed).
 *   4. Call telemetry: every mock invocation lands in simMockCalls().
 *   5. Two new scenarios (duplicate_webhook + ap_payment_lifecycle).
 *   6. Admin API endpoint shape (list/detail/scenarios/discipline).
 *   7. SPA dashboard wiring (route + dashboard quick action).
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "Mock manager (sim/mocks/manager.php)\n";
require_once __DIR__ . '/../sim/mocks/manager.php';
$a('exports simMockEnable',                function_exists('simMockEnable'));
$a('exports simMockDisable',               function_exists('simMockDisable'));
$a('exports simShouldMock',                function_exists('simShouldMock'));
$a('exports simMockSetFault',              function_exists('simMockSetFault'));
$a('exports simMockCalls',                 function_exists('simMockCalls'));

simMockDisable();
$a('simShouldMock false when disabled',    simShouldMock('plaid') === false);
simMockEnable(['plaid']);
$a('simShouldMock true after enable',      simShouldMock('plaid') === true);
$a('disable removes specific service',     (function() { simMockDisable('plaid'); return !simShouldMock('plaid'); })());

echo "\nPlaid mock — deterministic + telemetry\n";
require_once __DIR__ . '/../sim/mocks/plaid.php';
simMockReset(); simMockEnable(['plaid']); simSeed(123);
$accountsA = simMockPlaidGetAccounts('access-test-tok');
simSeed(123);
$accountsB = simMockPlaidGetAccounts('access-test-tok');
$a('plaid get_accounts deterministic',     simHash($accountsA) === simHash($accountsB));
$a('plaid returns 4 accounts',             count($accountsA['accounts']) === 4);
$a('plaid response carries request_id',    !empty($accountsA['request_id']));
$a('plaid call recorded in telemetry',     count(simMockCalls('plaid')) >= 2);

simMockReset(); simSeed(7);
$txA = simMockPlaidSyncTransactions('access-test-tok', null, 10);
$a('plaid sync_transactions returns 10',   count($txA['added']) === 10);
$a('plaid txns have category arrays',      is_array($txA['added'][0]['category']));

echo "\nFault injection\n";
simMockReset(); simMockEnable(['plaid']);
simMockSetFault('plaid', 'rate_limit');
$threw = false;
try { simMockPlaidGetAccounts('any'); } catch (\Throwable $e) { $threw = str_contains($e->getMessage(), 'rate_limit_exceeded'); }
$a('rate_limit fault throws',              $threw);
simMockSetFault('plaid', 'timeout');
$threw = false;
try { simMockPlaidGetAccounts('any'); } catch (\Throwable $e) { $threw = str_contains($e->getMessage(), 'timeout'); }
$a('timeout fault throws',                 $threw);
// After fault thrown, next call must be normal again (one-shot)
$accts = simMockPlaidGetAccounts('any');
$a('fault is one-shot (next call normal)', !empty($accts['accounts']));

echo "\nOpenAI mock — narrative library\n";
require_once __DIR__ . '/../sim/mocks/openai.php';
simMockReset(); simMockEnable(['openai']); simSeed(42);
$ai = simMockAiAsk(['feature_class' => 'csv_mapping', 'prompt' => 'map these columns', 'payload' => []]);
$a('aiAsk returns narrative',              !empty($ai['narrative']));
$a('aiAsk marks sim=true in meta',         !empty($ai['meta']['sim']));
$a('aiAsk carries disclaimer',             str_contains($ai['meta']['disclaimer'], 'advisory'));
$ext = simMockAiExtract(['kind' => 'bill']);
$a('aiExtract returns extracted payload',  !empty($ext['extracted']));
$a('aiExtract bill has line items',        !empty($ext['extracted']['lines']));

echo "\nEmail mock — captures sends\n";
require_once __DIR__ . '/../sim/mocks/email.php';
simMockReset(); simMockEnable(['resend']);
$em = simMockSendEmail(['to' => ['ops@coreflux.test'], 'subject' => 'Sim weekly digest', 'html' => '<p>hi</p>']);
$a('email mock returns message_id',        !empty($em['message_id']));
$a('email mock captures into log',         count(simMockCalls('resend')) === 1);

echo "\nNew scenarios\n";
foreach ([
    'duplicate_webhook_idempotent.json' => 'duplicate_webhook_idempotent',
    'ap_payment_lifecycle.json'         => 'ap_payment_lifecycle',
] as $file => $name) {
    $p = __DIR__ . '/../sim/scenarios/' . $file;
    $a("scenario file exists: {$file}",    is_file($p));
    $sc = json_decode($read($p), true);
    $a("scenario {$name} valid JSON",      is_array($sc) && ($sc['name'] ?? '') === $name);
    $a("scenario {$name} has invariants",  !empty($sc['invariants']));
}
$dup = json_decode($read(__DIR__ . '/../sim/scenarios/duplicate_webhook_idempotent.json'), true);
$a('duplicate scenario has 2 emit_event steps',
    count(array_filter($dup['steps'], fn ($s) => ($s['action'] ?? '') === 'emit_event')) === 2);
$a('duplicate scenario reuses same source_record_id',
    $dup['steps'][0]['source_record_id'] === $dup['steps'][1]['source_record_id']);

$lc = json_decode($read(__DIR__ . '/../sim/scenarios/ap_payment_lifecycle.json'), true);
$a('lifecycle scenario advances clock',    in_array('advance_clock', array_column($lc['steps'], 'action'), true));
$a('lifecycle scenario emits bill + payment',
    in_array('ap.bill.approved',  array_column($lc['steps'], 'event_type'), true)
    && in_array('ap.payment.cleared', array_column($lc['steps'], 'event_type'), true));
$a('lifecycle scenario asserts AP↔GL parity',
    in_array('ap_module_matches_gl', $lc['invariants'] ?? [], true));

echo "\nAdmin API — /api/admin/simulation_runs.php\n";
$ep = $read(__DIR__ . '/../api/admin/simulation_runs.php');
$a('endpoint exists',                      $ep !== '');
$a('requires auth',                        str_contains($ep, 'api_require_auth'));
$a('GET-only',                             str_contains($ep, "api_method() !== 'GET'"));
$a('action=scenarios returns list',        str_contains($ep, "action === 'scenarios'") && str_contains($ep, 'simListScenarios'));
$a('action=discipline returns recent fires', str_contains($ep, "action === 'discipline'") && str_contains($ep, 'module_emission_discipline_log'));
$a('?id&detail returns run + assertions + replay',
    str_contains($ep, 'simulation_assertions') && str_contains($ep, 'replay_logs') && str_contains($ep, 'simulation_failures'));
$a('list returns KPI rollup',              str_contains($ep, 'AVG(duration_ms)'));
$a('graceful on migration_pending',        str_contains($ep, "'migration_pending' => true"));

echo "\nSPA dashboard wiring\n";
$page = $read(__DIR__ . '/../dashboard/src/pages/SimulationDashboard.jsx');
$a('SimulationDashboard page exists',      $page !== '');
$a('page reads simulation_runs endpoint',  str_contains($page, '/api/admin/simulation_runs.php'));
$a('page has runs/scenarios/discipline tabs',
    str_contains($page, 'sim-tab-runs') && str_contains($page, 'sim-tab-scenarios') && str_contains($page, 'sim-tab-discipline'));
$a('page has KPI strip',                   str_contains($page, 'sim-kpi-strip') && str_contains($page, 'sim-kpi-failed'));
$a('page has detail drawer',               str_contains($page, 'sim-detail-panel') && str_contains($page, 'sim-detail-assertions'));
$a('page has replay-copy button',          str_contains($page, 'sim-run-${r.id}-replay') || str_contains($page, '-replay`'));

$app = $read(__DIR__ . '/../dashboard/src/App.jsx');
$a('SPA imports SimulationDashboard',      str_contains($app, "import SimulationDashboard from './pages/SimulationDashboard'"));
$a('SPA routes /sim',                      str_contains($app, 'path="/sim"') && str_contains($app, '<SimulationDashboard'));

$dash = $read(__DIR__ . '/../dashboard/src/pages/DashboardOverview.jsx');
$a('Dashboard imports FlaskConical icon',  str_contains($dash, 'FlaskConical'));
$a('Dashboard surfaces sim harness card',  str_contains($dash, 'data-testid="dashboard-sim-harness"') && str_contains($dash, 'href="/sim"'));

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
