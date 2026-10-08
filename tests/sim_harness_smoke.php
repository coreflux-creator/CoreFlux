<?php
/**
 * Simulation Harness — Phase H1 smoke (2026-02-XX).
 *
 * Validates the wind-tunnel foundation BEFORE Phase-2a starts using it:
 *   1. Migration 043 creates the 4 results tables + is_simulation flag.
 *   2. /app/sim/runner.php + lib/* exist with the right shape.
 *   3. Deterministic RNG: same seed → identical sequence.
 *   4. Scenario loader rejects malformed/missing scenarios.
 *   5. Runner CLI: --list, --help, --scenario, --dry-run honored.
 *   6. The three starter scenarios validate against the loader.
 *   7. Invariant library exports the expected functions.
 *   8. Runner safety: refuses non-sim tenant (read by inspecting source).
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "Migration 043 — simulation harness tables\n";
$mig = $read(__DIR__ . '/../core/migrations/043_simulation_harness.sql');
$a('migration file exists',                $mig !== '');
$a('guards tenants.is_simulation creation',
    str_contains(strtolower($mig), 'information_schema.columns')
    && str_contains($mig, 'ADD COLUMN is_simulation TINYINT'));
foreach (['simulation_runs', 'simulation_assertions', 'simulation_failures', 'replay_logs'] as $t) {
    $a("creates table {$t}",               str_contains($mig, "CREATE TABLE IF NOT EXISTS {$t}"));
}
$a('runs.status ENUM',                     str_contains($mig, "ENUM('running','passed','failed','aborted')"));
$a('assertions.severity ENUM',             str_contains($mig, "ENUM('info','warning','error')"));
$a('replay_logs unique (run, event_index)', str_contains($mig, 'uq_run_event_index'));
$a('replay_logs has payload_hash CHAR(64)', str_contains($mig, 'payload_hash CHAR(64)'));

echo "\nSeed / RNG / clock library\n";
require_once __DIR__ . '/../sim/lib/seed.php';
simSeed(42);
$first = [simRandInt(0, 1000000), simRandInt(0, 1000000), simRandInt(0, 1000000)];
simSeed(42);
$second = [simRandInt(0, 1000000), simRandInt(0, 1000000), simRandInt(0, 1000000)];
$a('simSeed gives deterministic ints',     $first === $second);
simSeed(42);
$a('simRandPick deterministic',            simRandPick(['a','b','c','d']) === simRandPick_at_seed(42, ['a','b','c','d']));
simSeed(42, '2026-03-15 00:00:00');
$a('simNow respects start date',           simNow('Y-m-d') === '2026-03-15');
simAdvance('+5 days');
$a('simAdvance moves clock forward',       simNow('Y-m-d') === '2026-03-20');
$h1 = simHash(['a' => 1, 'b' => 2]);
$h2 = simHash(['a' => 1, 'b' => 2]);
$a('simHash deterministic',                $h1 === $h2 && strlen($h1) === 64);

function simRandPick_at_seed(int $seed, array $opts) {
    simSeed($seed);
    return simRandPick($opts);
}

echo "\nScenario loader\n";
require_once __DIR__ . '/../sim/lib/scenario.php';
$scenarios = simListScenarios();
$names     = array_column($scenarios, 'name');
foreach (['ap_bill_happy_path', 'ar_invoice_happy_path', 'treasury_bank_feed_categorize'] as $sc) {
    $a("scenario listed: {$sc}",           in_array($sc, $names, true));
    $loaded = simLoadScenario($sc);
    $a("scenario loadable: {$sc}",         isset($loaded['name']) && $loaded['name'] === $sc);
    $a("scenario {$sc} has steps",         !empty($loaded['steps']));
    $a("scenario {$sc} declares invariants", !empty($loaded['invariants']));
}
$threw = false;
try { simLoadScenario('does_not_exist_anywhere'); } catch (\Throwable $e) { $threw = true; }
$a('loader throws on missing scenario',    $threw);

echo "\nInvariant library\n";
require_once __DIR__ . '/../sim/lib/invariants.php';
foreach ([
    'simInvariantDebitsEqualCredits',
    'simInvariantNoOrphanEvents',
    'simInvariantNoLegacyDirectGL',
    'simInvariantReplayReproducible',
    'simInvariantCustomerBalanceMatchesGL',
    'simInvariantPostedSourceLinks',
] as $fn) {
    $a("function exported: {$fn}",         function_exists($fn));
}

echo "\nRunner CLI shape + safety guards\n";
$runner = $read(__DIR__ . '/../sim/runner.php');
$documentLinks = $read(__DIR__ . '/../sim/lib/document_links.php');
$a('runner exists',                        $runner !== '');
$a('runner parses --scenario',             str_contains($runner, "'scenario:'"));
$a('runner parses --seed',                 str_contains($runner, "'seed::'"));
$a('runner parses --tenant',               str_contains($runner, "'tenant::'"));
$a('runner supports --dry-run',            str_contains($runner, "'dry-run::'"));
$a('runner supports --list',               str_contains($runner, "'list::'"));
$a('runner installs validated tenant context', str_contains($runner, 'setRequestTenantId($tenantId)'));
$a('runner refuses non-sim tenant',        str_contains($runner, 'is not flagged is_simulation=1. Refusing to run'));
$a('runner reuses accountingProcessEvent', str_contains($runner, 'accountingProcessEvent(') && str_contains($runner, 'posting_engine/process.php'));
$a('runner persists simulation_runs row',  str_contains($runner, 'INSERT INTO simulation_runs'));
$a('runner checks posted source links', str_contains($runner, 'simInvariantPostedSourceLinks($pdo, $tenantId)'));
$a('runner persists assertions',           str_contains($runner, 'INSERT INTO simulation_assertions'));
$a('runner persists replay_logs',          str_contains($runner, 'INSERT INTO replay_logs'));
$a('replays count unique observed JEs without claiming new postings',
    str_contains($runner, "'je_observed' => 0")
    && str_contains($runner, "\$ctx['state']['observed_je_ids'][\$jeId]")
    && str_contains($runner, "\$metric === 'je_posted' ? \$ctx['metrics']['je_observed']"));
$a('sim AP bills include line items',      str_contains($runner, 'INSERT INTO ap_bill_lines'));
$a('sim AR invoices include line items',   str_contains($runner, 'INSERT INTO billing_invoice_lines'));
$a('posted sim invoices and bills link to the journal entry',
    str_contains($runner, 'simLinkPostedDocument($ctx[\'tenant_id\'], $type, $payload, $jeId)')
    && str_contains($documentLinks, "'billing.invoice.sent' => ['billing_invoices', 'invoice_id']")
    && str_contains($documentLinks, "'ap.bill.approved' => ['ap_bills', 'bill_id']"));
$paymentLinks = $read(__DIR__ . '/../sim/lib/payment_links.php');
$a('cleared sim payments retain their bill allocation and posted JE',
    str_contains($runner, 'simLinkClearedPayment(')
    && str_contains($runner, 'INSERT INTO ap_payments')
    && str_contains($paymentLinks, 'journal_entry_id = :je_id')
    && str_contains($paymentLinks, 'INSERT INTO ap_payment_allocations'));
$a('scenario records use tenant-scoped generated IDs',
    str_contains($runner, "WHERE tenant_id = :tenant_id AND internal_ref = :internal_ref FOR UPDATE")
    && str_contains($runner, "WHERE tenant_id = :tenant_id AND invoice_number = :number FOR UPDATE")
    && str_contains($runner, 'simResolveDocumentId($ctx, \'bills\'')
    && !str_contains($runner, 'ON DUPLICATE KEY UPDATE total = VALUES(total)'));
$bankLinks = $read(__DIR__ . '/../sim/lib/bank_links.php');
$bankScenario = $read(__DIR__ . '/../sim/scenarios/treasury_bank_feed_categorize.json');
$a('treasury simulation creates and matches a real bank line',
    str_contains($runner, 'simStepCreateBankLine($ctx, $step)')
    && str_contains($runner, 'simLinkPostedBankLine($ctx[\'tenant_id\'], $lineId, $jeId)')
    && str_contains($bankLinks, 'matched_je_id = :je_id')
    && str_contains($bankScenario, '"bank_line_matched"'));
$seedScript = $read(__DIR__ . '/../scripts/ci_seed_sim_tenant.php');
$a('tenant-only seed skips schema and rejects non-simulation tenants',
    str_contains($seedScript, 'in_array(\'--tenant-only\', $argv, true)')
    && str_contains($seedScript, 'if (!$tenantOnly)')
    && str_contains($seedScript, '(int) $existingTenant[\'is_simulation\'] !== 1')
    && !str_contains($seedScript, 'ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id)'));
$peopleBase = strpos($seedScript, "'modules/people/migrations/001_init.sql'");
$sharedPersonBase = strpos($seedScript, "'modules/people/migrations/003_spec_alignment.sql'");
$apBase = strpos($seedScript, "'modules/ap/migrations/001_init.sql'");
$billingBase = strpos($seedScript, "'modules/billing/migrations/001_init.sql'");
$entityColumns = strpos($seedScript, "'modules/accounting/migrations/007_consolidation.sql'");
$invoiceJournalLink = strpos($seedScript, "'modules/accounting/migrations/008_consolidation_runs.sql'");
$paymentEntity = strpos($seedScript, "'modules/ap/migrations/020_payment_entity_scope.sql'");
$a('live simulation adds source entity and journal columns after their base tables',
    $peopleBase !== false && $sharedPersonBase !== false
    && $apBase !== false && $billingBase !== false
    && $entityColumns !== false && $invoiceJournalLink !== false && $paymentEntity !== false
    && $peopleBase < $sharedPersonBase && $sharedPersonBase < $entityColumns
    && $apBase < $entityColumns
    && $billingBase < $entityColumns && $entityColumns < $invoiceJournalLink
    && $entityColumns < $paymentEntity);
$duplicateScenario = json_decode($read(__DIR__ . '/../sim/scenarios/duplicate_webhook_idempotent.json'), true);
$firstEvent = $duplicateScenario['steps'][1] ?? [];
$replayedEvent = $duplicateScenario['steps'][2] ?? [];
$a('duplicate webhook scenario repeats the exact financial intent',
    ($firstEvent['event_type'] ?? null) === ($replayedEvent['event_type'] ?? null)
    && ($firstEvent['source_record_id'] ?? null) === ($replayedEvent['source_record_id'] ?? null)
    && ($firstEvent['payload'] ?? null) === ($replayedEvent['payload'] ?? null));
$a('runner exits non-zero on failure',     str_contains($runner, "exit(\$status === 'passed' ? 0 : 1)"));

echo "\nDry-run executes end-to-end without DB\n";
$out = shell_exec('php ' . escapeshellarg(__DIR__ . '/../sim/runner.php')
    . ' --scenario=ap_bill_happy_path --seed=42 --dry-run 2>&1');
$a('dry-run prints scenario header',       is_string($out) && str_contains($out, 'scenario=ap_bill_happy_path'));
$a('dry-run reports event count',          is_string($out) && (str_contains($out, '1 events') || str_contains($out, 'events')));
$a('dry-run exits passed',                 is_string($out) && str_contains($out, 'passed'));

echo "\nDry-run is deterministic (same seed → identical output)\n";
$out1 = shell_exec('php ' . escapeshellarg(__DIR__ . '/../sim/runner.php')
    . ' --scenario=ap_bill_happy_path --seed=99 --dry-run 2>&1');
$out2 = shell_exec('php ' . escapeshellarg(__DIR__ . '/../sim/runner.php')
    . ' --scenario=ap_bill_happy_path --seed=99 --dry-run 2>&1');
// Strip run_id line + duration_ms — those legitimately differ.
$norm = fn ($s) => preg_replace(['/run_id=\S+/', '/in \d+ms/'], ['run_id=X', 'in Xms'], (string) $s);
$a('dry-run deterministic per seed',       $norm($out1) === $norm($out2));

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
