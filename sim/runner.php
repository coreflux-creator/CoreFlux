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
require_once __DIR__ . '/lib/document_links.php';
require_once __DIR__ . '/lib/payment_links.php';
require_once __DIR__ . '/lib/bank_links.php';

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
    setRequestTenantId($tenantId);

    // Refuse to run against a non-sim tenant — hard safety guard.
    $st = $pdo->prepare('SELECT is_simulation FROM tenants WHERE id = :id');
    $st->execute(['id' => $tenantId]);
    $isSim = (int) $st->fetchColumn();
    if ($isSim !== 1) {
        fwrite(STDERR, "ERROR: tenant {$tenantId} is not flagged is_simulation=1. Refusing to run.\n");
        exit(4);
    }
    $entity = $pdo->prepare('SELECT id FROM accounting_entities WHERE tenant_id = :tenant_id AND code = "SIM" AND active = 1');
    $entity->execute(['tenant_id' => $tenantId]);
    $entityId = (int) $entity->fetchColumn();
    if ($entityId <= 0) {
        fwrite(STDERR, "ERROR: simulation entity is missing for tenant {$tenantId}. Seed this tenant first.\n");
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
    'entity_id' => $entityId ?? 0,
    'state'     => [],          // scenario labels mapped to tenant-local record ids
    'replay'    => [],          // append-order log of (event_type, payload_hash, je_hash)
    'metrics'   => ['events_emitted' => 0, 'je_posted' => 0, 'je_observed' => 0, 'steps_run' => 0],
    'step_failures' => [],
];

foreach ($scenario['steps'] as $i => $step) {
    $action = $step['action'] ?? '';
    if ($action === '') { fwrite(STDERR, "  · step #{$i} has no 'action', skipping\n"); continue; }
    $ctx['metrics']['steps_run']++;
    try {
        simExecuteStep($ctx, $action, $step);
    } catch (\Throwable $e) {
        echo "  ✗ step #{$i} ({$action}) threw: " . $e->getMessage() . "\n";
        $ctx['step_failures'][] = ['step_index' => $i, 'action' => $action, 'error' => $e->getMessage()];
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
            case 'subledger_balances_match_gl':
                $assertions[] = simInvariantCustomerBalanceMatchesGL($pdo, $tenantId); break;
            case 'business_graph_consistent':
                $assertions[] = simInvariantBusinessGraphConsistent($pdo, $tenantId); break;
            case 'bank_line_matched':
                $assertions[] = simInvariantBankLineMatched($pdo, $tenantId, $ctx['state']); break;
            case 'replay_reproducible':
                // Compares the in-memory $ctx['replay'] to any previously
                // persisted replay_logs for the same scenario+seed.
                $prevId = simFindPreviousRunForReplay($pdo, $tenantId, $scenario['name'], $seed, $runId);
                if ($prevId) $assertions[] = simInvariantReplayReproducible($pdo, $prevId, $ctx['replay']);
                else         $assertions[] = ['name' => 'replay_reproducible', 'ok' => true, 'severity' => 'info',
                                              'details' => ['baseline_run' => null]];
                break;
            default:
                $assertions[] = ['name' => $name, 'ok' => false, 'severity' => 'error',
                                 'details' => ['unknown_invariant' => $name]];
        }
    }

    $assertions[] = simInvariantPostedSourceLinks($pdo, $tenantId);

    $assertions[] = [
        'name' => 'scenario_steps_completed',
        'ok' => empty($ctx['step_failures']),
        'severity' => 'error',
        'details' => ['failure_count' => count($ctx['step_failures']), 'sample' => array_slice($ctx['step_failures'], 0, 10)],
    ];
    $metricMismatches = [];
    foreach (['events_emitted', 'je_posted', 'steps_run'] as $metric) {
        $actual = $metric === 'je_posted' ? $ctx['metrics']['je_observed'] : $ctx['metrics'][$metric];
        if (array_key_exists($metric, $scenario['expected'])
            && (int) $scenario['expected'][$metric] !== (int) $actual) {
            $metricMismatches[$metric] = [
                'expected' => (int) $scenario['expected'][$metric],
                'actual' => (int) $actual,
            ];
        }
    }
    $assertions[] = [
        'name' => 'scenario_expected_metrics',
        'ok' => empty($metricMismatches),
        'severity' => 'error',
        'details' => ['mismatches' => $metricMismatches],
    ];

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
    // tenant-leak-allow: defense-in-depth — caller scoped row by tenant_id before this id-only write
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
foreach ($failed as $a) echo "  ✗ " . $a['name'] . ': '
    . json_encode($a['details'] ?? null, JSON_UNESCAPED_SLASHES) . "\n";

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
        case 'create_ap_bill':
            simStepCreateApBill($ctx, $step);
            return;
        case 'settle_ap_bill':
            simStepSettleApBill($ctx, $step);
            return;
        case 'create_billing_invoice':
            simStepCreateBillingInvoice($ctx, $step);
            return;
        case 'create_bank_line':
            simStepCreateBankLine($ctx, $step);
            return;
        default:
            throw new \RuntimeException("Unknown sim action: {$action} (extend simExecuteStep in /app/sim/runner.php)");
    }
}

function simStepEmitEvent(array &$ctx, array $step): void {
    $type    = (string) ($step['event_type'] ?? '');
    $payload = (array)  ($step['payload']    ?? []);
    if ($type === '') throw new \RuntimeException('emit_event requires event_type');
    $jeId  = null; $jeHash = null;

    if (!$ctx['dry_run']) {
        require_once __DIR__ . '/../core/posting_engine/process.php';
        if (isset($payload['bill_id'])) {
            $payload['bill_id'] = simResolveDocumentId($ctx, 'bills', (int) $payload['bill_id']);
        }
        if (isset($payload['invoice_id'])) {
            $payload['invoice_id'] = simResolveDocumentId($ctx, 'invoices', (int) $payload['invoice_id']);
        }
        if (isset($payload['bank_txn_id'])) {
            $payload['bank_txn_id'] = simResolveDocumentId($ctx, 'bank_lines', (int) $payload['bank_txn_id']);
        }
        if (isset($payload['bank_account_id'])) {
            $payload['bank_account_id'] = simResolveDocumentId($ctx, 'bank_accounts', (int) $payload['bank_account_id']);
        }
        if ($type === 'ap.payment.cleared') {
            $logicalPaymentId = (int) ($payload['payment_id'] ?? 0);
            $payload['payment_id'] = simReserveApPayment($ctx, $payload);
            $ctx['state']['payments'][$logicalPaymentId] = $payload['payment_id'];
        }
        if (!empty($payload['bank_gl_account_code'])) {
            $account = getDB()->prepare(
                'SELECT id FROM accounting_accounts WHERE tenant_id = :tenant_id AND code = :code LIMIT 1'
            );
            $account->execute([
                'tenant_id' => $ctx['tenant_id'],
                'code' => (string) $payload['bank_gl_account_code'],
            ]);
            $accountId = (int) $account->fetchColumn();
            if ($accountId <= 0) throw new \RuntimeException('Bank GL account code was not found');
            $payload['bank_gl_account_id'] = $accountId;
        }
        $event = [
            'entity_id'        => $ctx['entity_id'],
            'event_type'       => $type,
            'source_module'    => (string) ($step['source_module']    ?? 'sim'),
            'source_record_id' => (string) ($step['source_record_id'] ?? 'sim:' . simRandId()),
            'event_date'       => simNow('Y-m-d'),
            'payload'          => $payload,
        ];
        $payloadHash = simHash($payload);
        try {
            $r = accountingProcessEvent($ctx['tenant_id'], $event, /* actor */ 0, /* dryRun */ false);
            if (($r['status'] ?? null) !== 'posted') {
                throw new \RuntimeException(
                    'Posting did not complete: ' . (string) ($r['error'] ?? $r['status'] ?? 'unknown result')
                );
            }
            $jeId = (int) ($r['journal_entry_id'] ?? 0) ?: null;
            if ($jeId !== null) {
                if (!isset($ctx['state']['observed_je_ids'][$jeId])) {
                    $ctx['state']['observed_je_ids'][$jeId] = true;
                    $ctx['metrics']['je_observed']++;
                }
                simLinkPostedDocument($ctx['tenant_id'], $type, $payload, $jeId);
                if ($type === 'treasury.bank_transaction.categorized') {
                    $lineId = (int) ($payload['bank_txn_id'] ?? 0);
                    simLinkPostedBankLine($ctx['tenant_id'], $lineId, $jeId);
                    $ctx['state']['posted_bank_lines'][$lineId] = $jeId;
                }
                if ($type === 'ap.payment.cleared' && (int) ($payload['payment_id'] ?? 0) > 0) {
                    $ctx['state']['posted_payments'][(int) $payload['payment_id']] = $jeId;
                }
            }
            $jeHash = $jeId ? simHash(['journal_entry_id' => $jeId]) : null;
            if (empty($r['idempotent_replay'])) {
                $ctx['metrics']['je_posted']++;
            }
        } catch (\Throwable $e) {
            $ctx['metrics']['events_emitted']++;
            $ctx['replay'][] = [
                'event_type'   => $type,
                'payload_hash' => $payloadHash,
                'je_id'        => null,
                'je_hash'      => null,
            ];
            throw new \RuntimeException("{$type} failed: {$e->getMessage()}", 0, $e);
        }
    }
    $payloadHash = $payloadHash ?? simHash($payload);
    $ctx['metrics']['events_emitted']++;
    $ctx['replay'][] = [
        'event_type'   => $type,
        'payload_hash' => $payloadHash,
        'je_id'        => $jeId,
        'je_hash'      => $jeHash,
    ];
}

function simResolveDocumentId(array $ctx, string $kind, int $logicalId): int {
    $id = (int) ($ctx['state'][$kind][$logicalId] ?? 0);
    if ($logicalId <= 0 || $id <= 0) {
        throw new \RuntimeException("No {$kind} record was created for scenario id {$logicalId}");
    }
    return $id;
}

function simReserveApPayment(array $ctx, array $payload): int {
    $logicalId = (int) ($payload['payment_id'] ?? 0);
    $reference = trim((string) ($payload['payment_number'] ?? ''));
    $amount = round((float) ($payload['amount'] ?? 0), 2);
    $vendor = trim((string) ($payload['vendor_name'] ?? ''));
    if ($logicalId <= 0 || $reference === '' || $amount <= 0 || $vendor === '') {
        throw new \InvalidArgumentException('ap.payment.cleared requires a payment id, number, vendor, and positive amount');
    }

    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $find = $pdo->prepare(
            'SELECT id, entity_id, vendor_name, amount, status FROM ap_payments
              WHERE tenant_id = :tenant_id AND reference = :reference ORDER BY id LIMIT 2 FOR UPDATE'
        );
        $find->execute(['tenant_id' => $ctx['tenant_id'], 'reference' => $reference]);
        $matches = $find->fetchAll(\PDO::FETCH_ASSOC);
        if (count($matches) > 1) throw new \RuntimeException("Duplicate simulation payment reference {$reference}");
        if ($matches) {
            $existing = $matches[0];
            if ((int) ($existing['entity_id'] ?? 0) !== $ctx['entity_id']
                || (string) $existing['vendor_name'] !== $vendor
                || round((float) $existing['amount'], 2) !== $amount
                || !in_array($existing['status'], ['draft', 'cleared'], true)) {
                throw new \RuntimeException("Simulation payment {$reference} conflicts with an existing payment");
            }
            $id = (int) $existing['id'];
        } else {
            $pdo->prepare(
                'INSERT INTO ap_payments
                    (tenant_id, entity_id, vendor_name, pay_date, reference, amount, unallocated_amount, status)
                 VALUES (:tenant_id, :entity_id, :vendor_name, :pay_date, :reference, :amount, :amount2, "draft")'
            )->execute([
                'tenant_id' => $ctx['tenant_id'], 'entity_id' => $ctx['entity_id'],
                'vendor_name' => $vendor, 'pay_date' => simNow('Y-m-d'),
                'reference' => $reference, 'amount' => $amount, 'amount2' => $amount,
            ]);
            $id = (int) $pdo->lastInsertId();
        }
        $pdo->commit();
        return $id;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function simStepCreateBankLine(array &$ctx, array $step): void {
    if ($ctx['dry_run']) return;
    $logicalId = (int) ($step['id'] ?? 0);
    $accountCode = trim((string) ($step['bank_gl_account_code'] ?? ''));
    $fitid = trim((string) ($step['fitid'] ?? ''));
    $amount = round((float) ($step['amount'] ?? 0), 2);
    if ($logicalId <= 0 || $accountCode === '' || $fitid === '' || $amount === 0.0) {
        throw new \InvalidArgumentException('create_bank_line requires id, account code, FITID, and nonzero amount');
    }

    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $account = $pdo->prepare(
            'SELECT id, entity_id, status FROM accounting_bank_accounts
              WHERE tenant_id = :tenant_id AND gl_account_code = :code FOR UPDATE'
        );
        $account->execute(['tenant_id' => $ctx['tenant_id'], 'code' => $accountCode]);
        $existingAccount = $account->fetch(\PDO::FETCH_ASSOC);
        if ($existingAccount) {
            if ((int) ($existingAccount['entity_id'] ?? 0) !== $ctx['entity_id']
                || $existingAccount['status'] !== 'active') {
                throw new \RuntimeException("Simulation bank account {$accountCode} conflicts with an existing account");
            }
            $accountId = (int) $existingAccount['id'];
        } else {
            $pdo->prepare(
                'INSERT INTO accounting_bank_accounts
                    (tenant_id, entity_id, name, gl_account_code, currency, feed_provider, status)
                 VALUES (:tenant_id, :entity_id, "Simulation operating cash", :code, "USD", "manual_csv", "active")'
            )->execute([
                'tenant_id' => $ctx['tenant_id'], 'entity_id' => $ctx['entity_id'], 'code' => $accountCode,
            ]);
            $accountId = (int) $pdo->lastInsertId();
        }

        $line = $pdo->prepare(
            'SELECT id, amount, posted_date FROM accounting_bank_statement_lines
              WHERE tenant_id = :tenant_id AND bank_account_id = :account_id AND fitid = :fitid FOR UPDATE'
        );
        $line->execute(['tenant_id' => $ctx['tenant_id'], 'account_id' => $accountId, 'fitid' => $fitid]);
        $existingLine = $line->fetch(\PDO::FETCH_ASSOC);
        if ($existingLine) {
            if (round((float) $existingLine['amount'], 2) !== $amount
                || $existingLine['posted_date'] !== simNow('Y-m-d')) {
                throw new \RuntimeException("Simulation bank line {$fitid} conflicts with an existing line");
            }
            $lineId = (int) $existingLine['id'];
        } else {
            $pdo->prepare(
                'INSERT INTO accounting_bank_statement_lines
                    (tenant_id, bank_account_id, posted_date, description, amount, bank_reference, fitid, match_status)
                 VALUES (:tenant_id, :account_id, :posted_date, :description, :amount, :reference, :fitid, "unmatched")'
            )->execute([
                'tenant_id' => $ctx['tenant_id'], 'account_id' => $accountId,
                'posted_date' => simNow('Y-m-d'), 'description' => (string) ($step['description'] ?? 'Simulation bank line'),
                'amount' => $amount, 'reference' => $fitid, 'fitid' => $fitid,
            ]);
            $lineId = (int) $pdo->lastInsertId();
        }
        $pdo->commit();
        $ctx['state']['bank_accounts'][$logicalId] = $accountId;
        $ctx['state']['bank_lines'][$logicalId] = $lineId;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function simStepCreateApBill(array &$ctx, array $step): void {
    if ($ctx['dry_run']) return;
    $logicalId = (int) ($step['id'] ?? 0);
    $amount = round((float) ($step['amount'] ?? 0), 2);
    if ($logicalId <= 0 || $amount <= 0) throw new \InvalidArgumentException('create_ap_bill requires positive id and amount');
    $billNumber = (string) ($step['bill_number'] ?? "SIM-{$logicalId}");
    $internalRef = (string) ($step['internal_ref'] ?? "SIM-{$logicalId}");
    $vendor = (string) ($step['vendor_name'] ?? 'Simulation Vendor');
    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO ap_vendors_index (tenant_id, vendor_name, vendor_type, last_bill_at)
             VALUES (:tenant_id, :vendor_name, "other", :last_bill_at)
             ON DUPLICATE KEY UPDATE last_bill_at = COALESCE(last_bill_at, VALUES(last_bill_at))'
        )->execute([
            'tenant_id' => $ctx['tenant_id'], 'vendor_name' => $vendor,
            'last_bill_at' => simNow('Y-m-d H:i:s'),
        ]);
        $find = $pdo->prepare(
            'SELECT id, bill_number, vendor_name, total FROM ap_bills
              WHERE tenant_id = :tenant_id AND internal_ref = :internal_ref FOR UPDATE'
        );
        $find->execute(['tenant_id' => $ctx['tenant_id'], 'internal_ref' => $internalRef]);
        $existing = $find->fetch(\PDO::FETCH_ASSOC);
        if ($existing) {
            if ($existing['bill_number'] !== $billNumber || $existing['vendor_name'] !== $vendor
                || round((float) $existing['total'], 2) !== $amount) {
                throw new \RuntimeException("Simulation bill {$internalRef} conflicts with an existing bill");
            }
            $id = (int) $existing['id'];
        } else {
            $pdo->prepare(
                'INSERT INTO ap_bills
                    (tenant_id, bill_number, internal_ref, vendor_name, vendor_type,
                     received_at, bill_date, due_date, currency, subtotal, tax_total,
                     total, amount_paid, amount_due, status, source)
                 VALUES
                    (:tenant_id, :bill_number, :internal_ref, :vendor_name, "other",
                     :received_at, :bill_date, :due_date, "USD", :amount, 0,
                     :amount2, 0, :amount3, "approved", "manual")'
            )->execute([
                'tenant_id' => $ctx['tenant_id'],
                'bill_number' => $billNumber,
                'internal_ref' => $internalRef,
                'vendor_name' => $vendor,
                'received_at' => simNow('Y-m-d'),
                'bill_date' => simNow('Y-m-d'),
                'due_date' => simNow('Y-m-d'),
                'amount' => $amount,
                'amount2' => $amount,
                'amount3' => $amount,
            ]);
            $id = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'INSERT INTO ap_bill_lines
                    (bill_id, line_no, source_type, description, quantity, unit,
                     unit_price, subtotal, tax_rate_pct, tax_amount, total, gl_expense_account_code)
                 VALUES (:bill_id, 1, "manual", :description, 1, "item",
                         :amount, :amount2, 0, 0, :amount3, "6990")'
            )->execute([
                'bill_id' => $id,
                'description' => 'Simulation bill line',
                'amount' => $amount,
                'amount2' => $amount,
                'amount3' => $amount,
            ]);
        }
        $pdo->commit();
        $ctx['state']['bills'][$logicalId] = $id;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function simStepSettleApBill(array &$ctx, array $step): void {
    if ($ctx['dry_run']) return;
    $id = simResolveDocumentId($ctx, 'bills', (int) ($step['id'] ?? 0));
    $paymentId = simResolveDocumentId($ctx, 'payments', (int) ($step['payment_id'] ?? 0));
    $jeId = (int) ($ctx['state']['posted_payments'][$paymentId] ?? 0);
    if ($id <= 0 || $paymentId <= 0 || $jeId <= 0) {
        throw new \InvalidArgumentException('settle_ap_bill requires a bill id and a posted payment_id');
    }
    simLinkClearedPayment($ctx['tenant_id'], $id, $paymentId, $jeId, simNow('Y-m-d'));
}

function simStepCreateBillingInvoice(array &$ctx, array $step): void {
    if ($ctx['dry_run']) return;
    $logicalId = (int) ($step['id'] ?? 0);
    $amount = round((float) ($step['amount'] ?? 0), 2);
    if ($logicalId <= 0 || $amount <= 0) throw new \InvalidArgumentException('create_billing_invoice requires positive id and amount');
    $number = (string) ($step['invoice_number'] ?? "SIM-INV-{$logicalId}");
    $client = (string) ($step['client_name'] ?? 'Simulation Client');
    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $find = $pdo->prepare(
            'SELECT id, client_name, total FROM billing_invoices
              WHERE tenant_id = :tenant_id AND invoice_number = :number FOR UPDATE'
        );
        $find->execute(['tenant_id' => $ctx['tenant_id'], 'number' => $number]);
        $existing = $find->fetch(\PDO::FETCH_ASSOC);
        if ($existing) {
            if ($existing['client_name'] !== $client || round((float) $existing['total'], 2) !== $amount) {
                throw new \RuntimeException("Simulation invoice {$number} conflicts with an existing invoice");
            }
            $id = (int) $existing['id'];
        } else {
            $pdo->prepare(
                'INSERT INTO billing_invoices
                    (tenant_id, invoice_number, client_name, currency, issue_date, due_date,
                     subtotal, tax_total, total, amount_paid, amount_due, status, aggregation)
                 VALUES
                    (:tenant_id, :invoice_number, :client_name, "USD", :issue_date, :due_date,
                     :amount, 0, :amount2, 0, :amount3, "sent", "per_client")'
            )->execute([
                'tenant_id' => $ctx['tenant_id'],
                'invoice_number' => $number,
                'client_name' => $client,
                'issue_date' => simNow('Y-m-d'),
                'due_date' => simNow('Y-m-d'),
                'amount' => $amount,
                'amount2' => $amount,
                'amount3' => $amount,
            ]);
            $id = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'INSERT INTO billing_invoice_lines
                    (invoice_id, line_no, source_type, description, quantity, unit,
                     unit_price, subtotal, tax_rate_pct, tax_amount, total)
                 VALUES (:invoice_id, 1, "manual", :description, 1, "item",
                         :amount, :amount2, 0, 0, :amount3)'
            )->execute([
                'invoice_id' => $id,
                'description' => 'Simulation invoice line',
                'amount' => $amount,
                'amount2' => $amount,
                'amount3' => $amount,
            ]);
        }
        $pdo->commit();
        $ctx['state']['invoices'][$logicalId] = $id;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
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
