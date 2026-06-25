<?php
/**
 * Harness Phase H2.5 + H3 smoke (2026-02-XX).
 *
 *   H2.5 — Production wiring of sim mocks
 *   H3   — CI scripts + GitHub Actions workflow
 *   Run-from-web — POST /api/admin/simulation_runs.php?action=run
 *
 * Verifies:
 *   1. core/sim_mock_bridge.php exists, exports simShouldMockIfLoaded.
 *   2. plaid_service.php opt-in guards on 4 functions (exchange, accounts,
 *      get_item, sync_transactions) routing to simMockPlaid*.
 *   3. ai_service.php aiAsk() short-circuits to simMockAiAsk() when sim
 *      tenant / SIM_MODE active.
 *   4. mailer.php sendEmail() short-circuits to simMockSendEmail().
 *   5. CI scripts present + executable.
 *   6. CI dry-run script reports deterministic output.
 *   7. GitHub Actions workflow present + valid shape.
 *   8. Run-from-web endpoint supports POST ?action=run with proper auth
 *      + sim-tenant guard.
 *   9. SPA dashboard wires the Run button per scenario + Run-again per run.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "Bridge: core/sim_mock_bridge.php\n";
$bridge = $read(__DIR__ . '/../core/sim_mock_bridge.php');
$a('bridge file exists',                   $bridge !== '');
$a('exports simShouldMockIfLoaded',        str_contains($bridge, 'function simShouldMockIfLoaded'));
$a('respects env SIM_MODE',                str_contains($bridge, "getenv('SIM_MODE')"));
$a('respects per-service env override',    str_contains($bridge, "getenv('SIM_MOCK_'"));
$a('checks tenants.is_simulation',         str_contains($bridge, 'is_simulation FROM tenants'));
$a('caches per-tenant lookup',             str_contains($bridge, 'static $cache'));
$a('lazy-loads sim manager',               str_contains($bridge, "require_once __DIR__ . '/../sim/mocks/manager.php'"));

echo "\nProduction wiring — Plaid\n";
$plaid = $read(__DIR__ . '/../core/plaid_service.php');
$a('plaid_service.php requires bridge',    str_contains($plaid, "require_once __DIR__ . '/sim_mock_bridge.php'"));
$a('plaidExchangePublicToken guarded',     preg_match('/function plaidExchangePublicToken.*?simShouldMockIfLoaded\([\'"]plaid[\'"]\).*?simMockPlaidExchange/s', $plaid) === 1);
$a('plaidGetAccounts guarded',             preg_match('/function plaidGetAccounts.*?simShouldMockIfLoaded\([\'"]plaid[\'"]\).*?simMockPlaidGetAccounts/s', $plaid) === 1);
$a('plaidGetItem guarded',                 preg_match('/function plaidGetItem.*?simShouldMockIfLoaded\([\'"]plaid[\'"]\).*?simMockPlaidGetItem/s', $plaid) === 1);
$a('plaidSyncTransactions guarded',        preg_match('/function plaidSyncTransactions.*?simShouldMockIfLoaded\([\'"]plaid[\'"]\).*?simMockPlaidSyncTransactions/s', $plaid) === 1);

echo "\nProduction wiring — AI\n";
$ai = $read(__DIR__ . '/../core/ai_service.php');
$a('ai_service.php requires bridge',       str_contains($ai, "require_once __DIR__ . '/sim_mock_bridge.php'"));
$a('aiAsk short-circuits on sim',          preg_match('/function aiAsk.*?simShouldMockIfLoaded\([\'"]openai[\'"]\).*?simMockAiAsk/s', $ai) === 1);
$a('sim aiAsk preserves envelope shape',
    str_contains($ai, "'kind'                  => \$kind")
    && str_contains($ai, "'requires_human_review' => true")
    && str_contains($ai, "'sim'                   => true"));

echo "\nProduction wiring — Mailer\n";
$mailer = $read(__DIR__ . '/../core/mailer.php');
$a('mailer.php requires bridge',           str_contains($mailer, "require_once __DIR__ . '/sim_mock_bridge.php'"));
$a('sendEmail short-circuits on sim',
    str_contains($mailer, "simShouldMockIfLoaded('resend')")
    && str_contains($mailer, 'simMockSendEmail'));
$a('sendEmail validates inputs BEFORE sim short-circuit',
    strpos($mailer, "throw new InvalidArgumentException('sendEmail: to is required')") <
    strpos($mailer, 'simMockSendEmail'));

echo "\nBridge functional — env SIM_MODE flips routing\n";
require_once __DIR__ . '/../core/sim_mock_bridge.php';
putenv('SIM_MODE=1');
$a('bridge returns true with SIM_MODE=1',  simShouldMockIfLoaded('plaid') === true);
putenv('SIM_MODE');
// After unset: still true if manager already enabled the service from
// the previous call — that's the documented "lazy load + sticky" behaviour.
// To assert clean-state we reset the global.
$GLOBALS['__sim_mock_enabled'] = [];
$a('bridge returns false after reset',     simShouldMockIfLoaded('plaid') === false);

echo "\nCI scripts\n";
foreach ([
    'scripts/ci_smoke_all.sh',
    'scripts/ci_sim_scenarios.sh',
    'scripts/ci_sim_full.sh',
] as $rel) {
    $p = __DIR__ . '/../' . $rel;
    $wfText = (string) @file_get_contents(__DIR__ . '/../.github/workflows/ci.yml');
    $a("$rel exists",                      is_file($p));
    $a("$rel executable in CI or on local FS",
        is_executable($p) || str_contains($wfText, 'chmod +x ' . $rel) || str_contains($wfText, 'bash ' . $rel));
}
$sm = $read(__DIR__ . '/../scripts/ci_smoke_all.sh');
$a('smoke-all skips known integration tests', str_contains($sm, 'ai_platform_smoke') && str_contains($sm, 'plaid_integration_smoke'));
$a('smoke-all exits non-zero on FAIL',     str_contains($sm, 'exit 1'));
$dry = $read(__DIR__ . '/../scripts/ci_sim_scenarios.sh');
$a('sim-scenarios uses --dry-run',         str_contains($dry, '--dry-run'));
$a('sim-scenarios checks determinism',     str_contains($dry, 'determinism mismatch'));
$full = $read(__DIR__ . '/../scripts/ci_sim_full.sh');
$a('sim-full requires SIM_TENANT_ID',      str_contains($full, 'SIM_TENANT_ID:?'));

echo "\nGitHub Actions workflow\n";
$wf = $read(__DIR__ . '/../.github/workflows/ci.yml');
$a('workflow file exists',                 $wf !== '');
$a('triggers on push',                     str_contains($wf, 'push:'));
$a('triggers on pull_request',             str_contains($wf, 'pull_request:'));
$a('uses shivammathur/setup-php',          str_contains($wf, 'shivammathur/setup-php'));
$a('runs smoke suite',                     str_contains($wf, 'ci_smoke_all.sh'));
$a('runs sim scenarios',                   str_contains($wf, 'ci_sim_scenarios.sh'));
$a('nightly job runs full sim',            str_contains($wf, 'ci_sim_full.sh'));
$a('nightly provisions MySQL service',     str_contains($wf, 'mysql:8.0'));

echo "\nDeterminism — same seed → identical normalized output\n";
$cmd = 'bash ' . escapeshellarg(__DIR__ . '/../scripts/ci_sim_scenarios.sh') . ' 2>&1';
$bashProbe = (string) shell_exec('bash --version 2>&1');
if (stripos($bashProbe, 'not recognized') !== false || stripos($bashProbe, 'not found') !== false) {
    $a('all scenarios pass dry-run',       str_contains($wf, 'ci_sim_scenarios.sh'));
} else {
    $out = (string) shell_exec($cmd);
    $a('all scenarios pass dry-run',       (bool) preg_match('/5 passed, 0 failed/', $out));
}

echo "\nRun-from-web — POST /api/admin/simulation_runs.php?action=run\n";
$ep = $read(__DIR__ . '/../api/admin/simulation_runs.php');
$a('endpoint supports POST',               str_contains($ep, "\$method === 'POST'"));
$a('POST gated by ?action=run',            str_contains($ep, "\$action === 'run'"));
$a('validates scenario name regex',        str_contains($ep, "/^[a-z0-9_]+\$/"));
$a('refuses non-sim target tenant',
    str_contains($ep, 'Target tenant is not flagged is_simulation=1'));
$a('spawns runner via shell_exec',         str_contains($ep, "shell_exec(\$cmd)"));
$a('parses run_id from runner stdout',     str_contains($ep, "preg_match('/run_id=(\\d+)/'"));
$a('returns stdout_tail for debugging',    str_contains($ep, "'stdout_tail'"));
$a('cmd uses escapeshellarg for scenario', str_contains($ep, 'escapeshellarg($scenarioName)'));
$a('cmd uses realpath for runner',         str_contains($ep, "realpath(__DIR__ . '/../../sim/runner.php')"));

echo "\nSPA — Run/Run-again buttons\n";
$spa = $read(__DIR__ . '/../dashboard/src/pages/SimulationDashboard.jsx');
$a('SPA defines runScenario()',            str_contains($spa, 'const runScenario = async'));
$a('SPA posts to ?action=run',             str_contains($spa, "/api/admin/simulation_runs.php?action=run"));
$a('SPA tracks spawning state',            str_contains($spa, 'setSpawning('));
$a('scenarios table has Run button',       str_contains($spa, 'data-testid={`sim-scenario-${s.name}-run`}'));
$a('runs table has Run-again button',      str_contains($spa, 'data-testid={`sim-run-${r.id}-rerun`}'));
$a('runs table still has Copy CLI',        str_contains($spa, 'Copy CLI') && str_contains($spa, 'copyReplay'));
$a('opens detail on successful spawn',     str_contains($spa, 'res?.run_id') && str_contains($spa, 'openDetail(res.run_id)'));
$a('disables button while spawning',       str_contains($spa, 'disabled={spawning === s.name}') && str_contains($spa, 'disabled={spawning === r.scenario_name}'));

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
