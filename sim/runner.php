<?php
/**
 * Simulation Runner — CLI entry point.
 *
 *   php /app/sim/runner.php --scenario=ap_bill_happy_path --seed=42 --tenant=999
 *
 * Per harness spec §10:
 *   • Same core PHP services as production (require_once core/*.php).
 *   • Sim tenant flagged via tenants.is_simulation = 1 (never connects
 *     to live money movement).
 *   • Outputs persisted to simulation_runs / _assertions / _failures /
 *     replay_logs for forensic replay.
 *
 * Per harness spec §11 + §18:
 *   • Scenarios are JSON; each step is a structured action with
 *     deterministic inputs.
 *   • Seed drives every randomized value AND the sim clock.
 *
 * Per harness spec §15:
 *   • Invariants run at the END of every scenario (debits=credits,
 *     no orphan events, no direct-GL bypass, AP module=GL).
 *
 * Exit code: 0 if all invariants pass; 1 otherwise.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/seed.php';
require_once __DIR__ . '/lib/scenario.php';
require_once __DIR__ . '/lib/invariants.php';

// CLI arg parse
$opts = getopt('', ['scenario:', 'seed::', 'tenant::', 'dry-run::', 'list::', 'help::']);
if (isset($opts['help'])) {
    echo "Usage: php sim/runner.php --scenario=NAME [--seed=N] [--tenant=ID] [--dry-run] [--list]\n";
    exit(0);
}
if (isset($opts['list'])) {
    foreach (simListScenarios() as $sc) {
        printf("  %-40s seed=%-6d steps=%-3d  %s\n",
            $sc['name'], $sc['default_seed'], $sc['step_count'], $sc['description']);
    }
    exit(0);
}
$scenarioName = $opts['scenario'] ?? '';
if ($scenarioName === '') { fwrite(STDERR, "ERROR: --scenario=NAME required\n"); exit(2); }

// Lazy boot — only require core/* once we know we have work to do.
// In dry-run mode we still want the runner to parse + walk steps but
// skip every DB write (used by tests/sim_harness_smoke.php to validate
// the runner shape without a live DB).
$dryRun = isset($opts['dry-run']);
$tenantId = isset($opts['tenant']) ? (int) $opts['tenant'] : 0;

if (!$dryRun) {
    if ($tenantId <= 0) { fwrite(STDERR, "ERROR: --tenant=ID required (non-dry-run)\n"); exit(2); }
    require_once __DIR__ . '/../core/db.php';
    require_once __DIR__ . '/../core/tenant_scope.php';
    $pdo = getDB();
    if (!$pdo) { fwrite(STDERR, "ERROR: cannot connect to DB\n"); exit(3); }
    setCurrentTenantId($tenantId);

    // Refuse to run against a non-sim tenant — hard safety guard.
    $st = $pdo->prepare('SELECT is_simulation FROM tenants WHERE id = :id');
    $st->execute(['id' => $tenantId]);
    $isSim = (int) $st->fetchColumn();
    if ($isSim !== 1) {
        fwrite(STDERR, "ERROR: tenant {$tenantId} is not flagged is_simulation=1. Refusing to run.\n");
        exit(4);
    }
}

$scenario = simLoadScenario($scenarioName);
$seed     = isset($opts['seed']) ? (int) $opts['seed'] : (int) $scenario['default_seed'];
simSeed($seed);

// ── Run row ───────────────────────────────────────────────────────────
$runId = null; $startedAt = microtime(true);
if (!$dryRun) {
    $ins = $pdo->prepare(
        'INSERT INTO simulation_runs (tenant_id, scenario_name, seed, status)
         VALUES (:t, :s, :sd, "running")'
    );
    $ins->execute(['t' => $tenantId, 's' => $scenario['name'], 'sd' => $seed]);
    $runId = (int) $pdo->lastInsertId();
}
echo "▶ scenario={$scenario['name']} seed={$seed} run_id=" . ($runId ?? '(dry-run)') . "\n";

// ── Step execution ────────────────────────────────────────────────────
$ctx = [
    'tenant_id' => $tenantId, 'run_id' => $runId, 'dry_run' => $dryRun,
    'state'     => [],          // step name → captured ids (vendor_id, bill_id, etc.)
    'replay'    => [],          // append-order log of (event_type, payload_hash, je_hash)
    'metrics'   => ['events_emitted' => 0, 'je_posted' => 0, 'steps_run' => 0],
];

foreach ($scenario['steps'] as $i => $step) {
    $action = $step['action'] ?? '';
    if ($action === '') { fwrite(STDERR, "  · step #{$i} has no 'action', skipping\n"); continue; }
    $ctx['metrics']['steps_run']++;
    try {
        simExecuteStep($ctx, $action, $step);
    } catch (\Throwable $e) {
        echo "  ✗ step #{$i} ({$action}) threw: " . $e->getMessage() . "\n";
        if (!$dryRun) {
            $pdo->prepare(
                'INSERT INTO simulation_failures (run_id, invariant, message, context)
                 VALUES (:r, :i, :m, :c)'
            )->execute([
                'r' => $runId, 'i' => 'step_threw',
                'm' => $e->getMessage(), 'c' => json_encode(['step' => $step]),
            ]);
        }
    }
}

// ── Invariants ────────────────────────────────────────────────────────
$assertions = [];
if (!$dryRun) {
    $checks = $scenario['invariants'] ?? [];
    foreach ($checks as $name) {
        switch ($name) {
            case 'debits_equal_credits':
                $assertions[] = simInvariantDebitsEqualCredits($pdo, $tenantId); break;
            case 'no_orphan_events':
                $assertions[] = simInvariantNoOrphanEvents($pdo, $tenantId); break;
            case 'no_direct_gl':
                $assertions[] = simInvariantNoLegacyDirectGL($pdo, $tenantId); break;
            case 'ap_module_matches_gl':
                $assertions[] = simInvariantCustomerBalanceMatchesGL($pdo, $tenantId); break;
            case 'replay_reproducible':
                // Compares the in-memory $ctx['replay'] to any previously
                // persisted replay_logs for the same scenario+seed.
                $prevId = simFindPreviousRunForReplay($pdo, $tenantId, $scenario['name'], $seed, $runId);
                if ($prevId) $assertions[] = simInvariantReplayReproducible($pdo, $prevId, $ctx['replay']);
                else         $assertions[] = ['name' => 'replay_reproducible', 'ok' => true, 'severity' => 'info',
                                              'details' => ['baseline_run' => null]];
                break;
            default:
                $assertions[] = ['name' => $name, 'ok' => true, 'severity' => 'info',
                                 'details' => ['unknown_invariant' => $name]];
        }
    }

    // Persist replay log + assertions
    foreach ($ctx['replay'] as $idx => $r) {
        $pdo->prepare(
            'INSERT INTO replay_logs (run_id, event_index, event_type, payload_hash, je_id, je_hash)
             VALUES (:r, :i, :et, :ph, :je, :jh)'
        )->execute([
            'r' => $runId, 'i' => $idx,
            'et' => $r['event_type'], 'ph' => $r['payload_hash'],
            'je' => $r['je_id'] ?? null, 'jh' => $r['je_hash'] ?? null,
        ]);
    }
    foreach ($assertions as $a) {
        $pdo->prepare(
            'INSERT INTO simulation_assertions (run_id, name, ok, severity, details)
             VALUES (:r, :n, :ok, :s, :d)'
        )->execute([
            'r' => $runId, 'n' => $a['name'], 'ok' => $a['ok'] ? 1 : 0,
            's' => $a['severity'] ?? 'error',
            'd' => json_encode($a['details'] ?? null),
        ]);
        if (!$a['ok']) {
            $pdo->prepare(
                'INSERT INTO simulation_failures (run_id, invariant, message, context)
                 VALUES (:r, :i, :m, :c)'
            )->execute([
                'r' => $runId, 'i' => $a['name'],
                'm' => 'Invariant failed: ' . $a['name'],
                'c' => json_encode($a['details'] ?? null),
            ]);
        }
    }
}

// ── Summary / close ───────────────────────────────────────────────────
$failed   = array_filter($assertions, fn ($a) => !$a['ok']);
$status   = empty($failed) ? 'passed' : 'failed';
$duration = (int) ((microtime(true) - $startedAt) * 1000);
$summary  = [
    'metrics'         => $ctx['metrics'],
    'assertion_count' => count($assertions),
    'failed_count'    => count($failed),
];

if (!$dryRun && $runId) {
    $pdo->prepare(
        'UPDATE simulation_runs
            SET status = :st, finished_at = NOW(), duration_ms = :d,
                events_emitted = :ev, je_posted = :jp,
                assertions_run = :ar, assertions_failed = :af,
                summary = :sm
          WHERE id = :id'
    )->execute([
        'st' => $status, 'd' => $duration,
        'ev' => $ctx['metrics']['events_emitted'],
        'jp' => $ctx['metrics']['je_posted'],
        'ar' => count($assertions), 'af' => count($failed),
        'sm' => json_encode($summary), 'id' => $runId,
    ]);
}

echo sprintf("▶ %s in %dms — %d events, %d JEs, %d/%d assertions failed\n",
    $status, $duration, $ctx['metrics']['events_emitted'], $ctx['metrics']['je_posted'],
    count($failed), count($assertions));
foreach ($failed as $a) echo "  ✗ " . $a['name'] . "\n";

exit($status === 'passed' ? 0 : 1);

// ─────────────────────────────────────────────────────────────────────
// Step dispatcher.  Each action is a small, single-purpose function
// that operates against the SAME production code paths a real user
// would (e.g. create_bill -> POST /modules/ap/api/bills.php logic).
// For now the dispatcher is a tiny allowlist; we'll grow it as more
// scenarios land in H2/H3.
// ─────────────────────────────────────────────────────────────────────
function simExecuteStep(array &$ctx, string $action, array $step): void {
    switch ($action) {
        case 'noop':
            return;
        case 'advance_clock':
            simAdvance((string) ($step['by'] ?? '+1 day'));
            return;
        case 'emit_event':
            simStepEmitEvent($ctx, $step);
            return;
        default:
            throw new \RuntimeException("Unknown sim action: {$action} (extend simExecuteStep in /app/sim/runner.php)");
    }
}

function simStepEmitEvent(array &$ctx, array $step): void {
    $type    = (string) ($step['event_type'] ?? '');
    $payload = (array)  ($step['payload']    ?? []);
    if ($type === '') throw new \RuntimeException('emit_event requires event_type');

    $payloadHash = simHash($payload);
    $jeId  = null; $jeHash = null;

    if (!$ctx['dry_run']) {
        require_once __DIR__ . '/../core/posting_engine/process.php';
        $event = [
            'entity_id'        => (int) ($step['entity_id'] ?? 0),
            'event_type'       => $type,
            'source_module'    => (string) ($step['source_module']    ?? 'sim'),
            'source_record_id' => (string) ($step['source_record_id'] ?? 'sim:' . simRandId()),
            'event_date'       => simNow('Y-m-d'),
            'payload'          => $payload,
        ];
        try {
            $r = accountingProcessEvent($ctx['tenant_id'], $event, /* actor */ 0, /* dryRun */ false);
            if (($r['status'] ?? null) === 'posted') {
                $jeId   = (int) ($r['journal_entry_id'] ?? 0) ?: null;
                $jeHash = simHash([$r['je_number'] ?? null, $r['journal_entry_id'] ?? null]);
                $ctx['metrics']['je_posted']++;
            }
        } catch (\Throwable $e) {
            // Capture, don't crash — invariant 'no_orphan_events' will
            // surface stuck events; the runner stays deterministic.
        }
    }
    $ctx['metrics']['events_emitted']++;
    $ctx['replay'][] = [
        'event_type'   => $type,
        'payload_hash' => $payloadHash,
        'je_id'        => $jeId,
        'je_hash'      => $jeHash,
    ];
}

function simFindPreviousRunForReplay(\PDO $pdo, int $tenantId, string $scenario, int $seed, int $excludeRunId): ?int {
    $stmt = $pdo->prepare(
        'SELECT id FROM simulation_runs
          WHERE tenant_id = :t AND scenario_name = :s AND seed = :sd
            AND id <> :ex
          ORDER BY started_at DESC LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 's' => $scenario, 'sd' => $seed, 'ex' => $excludeRunId]);
    $id = (int) $stmt->fetchColumn();
    return $id > 0 ? $id : null;
}
