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
    setRequestTenantId($tenantId);

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

    $assertions[] = [
        'name' => 'scenario_steps_completed',
        'ok' => empty($ctx['step_failures']),
        'severity' => 'error',
        'details' => ['failure_count' => count($ctx['step_failures']), 'sample' => array_slice($ctx['step_failures'], 0, 10)],
    ];
    $metricMismatches = [];
    foreach (['events_emitted', 'je_posted', 'steps_run'] as $metric) {
        if (array_key_exists($metric, $scenario['expected'])
            && (int) $scenario['expected'][$metric] !== (int) $ctx['metrics'][$metric]) {
            $metricMismatches[$metric] = [
                'expected' => (int) $scenario['expected'][$metric],
                'actual' => (int) $ctx['metrics'][$metric],
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
        case 'create_ap_bill':
            simStepCreateApBill($ctx, $step);
            return;
        case 'settle_ap_bill':
            simStepSettleApBill($ctx, $step);
            return;
        case 'create_billing_invoice':
            simStepCreateBillingInvoice($ctx, $step);
            return;
        case 'record_billing_payment':
            simStepRecordBillingPayment($ctx, $step);
            return;
        case 'assert_document_balance':
            simStepAssertDocumentBalance($ctx, $step);
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
            'entity_id'        => (int) ($step['entity_id'] ?? 0),
            'event_type'       => $type,
            'source_module'    => (string) ($step['source_module']    ?? 'sim'),
            'source_record_id' => (string) ($step['source_record_id'] ?? 'sim:' . simRandId()),
            'event_date'       => simNow('Y-m-d'),
            'payload'          => $payload,
        ];
        try {
            $r = accountingProcessEvent($ctx['tenant_id'], $event, /* actor */ 0, /* dryRun */ false);
            if (($r['status'] ?? null) !== 'posted') {
                throw new \RuntimeException(
                    'Posting did not complete: ' . (string) ($r['error'] ?? $r['status'] ?? 'unknown result')
                );
            }
            $jeId = (int) ($r['journal_entry_id'] ?? 0) ?: null;
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
    $ctx['metrics']['events_emitted']++;
    $ctx['replay'][] = [
        'event_type'   => $type,
        'payload_hash' => $payloadHash,
        'je_id'        => $jeId,
        'je_hash'      => $jeHash,
    ];
}

function simStepCreateApBill(array &$ctx, array $step): void {
    if ($ctx['dry_run']) return;
    $id = (int) ($step['id'] ?? 0);
    $amount = round((float) ($step['amount'] ?? 0), 2);
    if ($id <= 0 || $amount <= 0) throw new \InvalidArgumentException('create_ap_bill requires positive id and amount');
    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO ap_bills
                (id, tenant_id, bill_number, internal_ref, vendor_name, vendor_type,
                 received_at, bill_date, due_date, currency, subtotal, tax_total,
                 total, amount_paid, amount_due, status, source)
             VALUES
                (:id, :tenant_id, :bill_number, :internal_ref, :vendor_name, "other",
                 :received_at, :bill_date, :due_date, "USD", :amount, 0,
                 :amount2, 0, :amount3, "approved", "manual")
             ON DUPLICATE KEY UPDATE total = VALUES(total), amount_due = VALUES(amount_due),
                 amount_paid = 0, status = "approved", updated_at = NOW()'
        )->execute([
            'id' => $id,
            'tenant_id' => $ctx['tenant_id'],
            'bill_number' => (string) ($step['bill_number'] ?? "SIM-{$id}"),
            'internal_ref' => (string) ($step['internal_ref'] ?? "SIM-{$id}"),
            'vendor_name' => (string) ($step['vendor_name'] ?? 'Simulation Vendor'),
            'received_at' => simNow('Y-m-d'),
            'bill_date' => simNow('Y-m-d'),
            'due_date' => simNow('Y-m-d'),
            'amount' => $amount,
            'amount2' => $amount,
            'amount3' => $amount,
        ]);
        $pdo->prepare('DELETE FROM ap_bill_lines WHERE bill_id = :bill_id')
            ->execute(['bill_id' => $id]);
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
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function simStepSettleApBill(array &$ctx, array $step): void {
    if ($ctx['dry_run']) return;
    $id = (int) ($step['id'] ?? 0);
    if ($id <= 0) throw new \InvalidArgumentException('settle_ap_bill requires id');
    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $billStmt = $pdo->prepare('SELECT * FROM ap_bills WHERE tenant_id = :tenant_id AND id = :id FOR UPDATE');
        $billStmt->execute(['tenant_id' => $ctx['tenant_id'], 'id' => $id]);
        $bill = $billStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$bill) throw new \RuntimeException("AP bill {$id} was not found");
        $amount = round((float) ($step['amount'] ?? $bill['amount_due']), 2);
        if ($amount <= 0 || $amount > (float) $bill['amount_due'] + 0.005) {
            throw new \RuntimeException('AP payment must be positive and no greater than the remaining bill balance');
        }
        $paymentId = (int) ($step['payment_id'] ?? (1000000 + $id));
        $pdo->prepare(
            'INSERT INTO ap_payments
                (id, tenant_id, vendor_name, pay_date, method, reference, amount, unallocated_amount, status)
             VALUES (:id, :tenant_id, :vendor_name, :pay_date, "ach", :reference, :amount, 0, "cleared")'
        )->execute([
            'id' => $paymentId,
            'tenant_id' => $ctx['tenant_id'],
            'vendor_name' => $bill['vendor_name'],
            'pay_date' => simNow('Y-m-d'),
            'reference' => 'SIM-PAY-' . $paymentId,
            'amount' => $amount,
        ]);
        $pdo->prepare(
            'INSERT INTO ap_payment_allocations (payment_id, bill_id, amount_applied)
             VALUES (:payment_id, :bill_id, :amount)'
        )->execute(['payment_id' => $paymentId, 'bill_id' => $id, 'amount' => $amount]);
        $paid = round((float) $bill['amount_paid'] + $amount, 2);
        $due = max(0, round((float) $bill['total'] - $paid, 2));
        $pdo->prepare(
            'UPDATE ap_bills SET amount_paid = :paid, amount_due = :due, status = :status, updated_at = NOW()
              WHERE tenant_id = :tenant_id AND id = :id'
        )->execute([
            'paid' => $paid, 'due' => $due,
            'status' => $due < 0.005 ? 'paid' : 'partially_paid',
            'tenant_id' => $ctx['tenant_id'], 'id' => $id,
        ]);
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function simStepRecordBillingPayment(array &$ctx, array $step): void {
    if ($ctx['dry_run']) return;
    $invoiceId = (int) ($step['invoice_id'] ?? 0);
    $paymentId = (int) ($step['payment_id'] ?? 0);
    $amount = round((float) ($step['amount'] ?? 0), 2);
    if ($invoiceId <= 0 || $paymentId <= 0 || $amount <= 0) {
        throw new \InvalidArgumentException('record_billing_payment requires invoice_id, payment_id, and positive amount');
    }
    $pdo = getDB();
    $invoiceStmt = $pdo->prepare('SELECT client_name FROM billing_invoices WHERE tenant_id = :tenant_id AND id = :id');
    $invoiceStmt->execute(['tenant_id' => $ctx['tenant_id'], 'id' => $invoiceId]);
    $clientName = $invoiceStmt->fetchColumn();
    if (!$clientName) throw new \RuntimeException("Invoice {$invoiceId} was not found");
    $pdo->prepare(
        'INSERT INTO billing_payments
            (id, tenant_id, client_name, received_at, method, reference, amount, unallocated_amount)
         VALUES (:id, :tenant_id, :client_name, :received_at, "ach", :reference, :amount, :unallocated)'
    )->execute([
        'id' => $paymentId, 'tenant_id' => $ctx['tenant_id'], 'client_name' => $clientName,
        'received_at' => simNow('Y-m-d'), 'reference' => 'SIM-REC-' . $paymentId,
        'amount' => $amount, 'unallocated' => $amount,
    ]);
    require_once __DIR__ . '/../modules/billing/lib/billing.php';
    billingAllocatePayment($paymentId, [
        'allocations' => [['invoice_id' => $invoiceId, 'amount' => $amount]],
    ]);
}

function simStepAssertDocumentBalance(array &$ctx, array $step): void {
    if ($ctx['dry_run']) return;
    $type = (string) ($step['type'] ?? '');
    $table = match ($type) {
        'invoice' => 'billing_invoices',
        'bill' => 'ap_bills',
        default => throw new \InvalidArgumentException('assert_document_balance type must be invoice or bill'),
    };
    $id = (int) ($step['id'] ?? 0);
    $stmt = getDB()->prepare("SELECT amount_paid, amount_due, status FROM {$table} WHERE tenant_id = :tenant_id AND id = :id");
    $stmt->execute(['tenant_id' => $ctx['tenant_id'], 'id' => $id]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row || abs((float) $row['amount_paid'] - (float) ($step['paid'] ?? -1)) >= 0.01
        || abs((float) $row['amount_due'] - (float) ($step['due'] ?? -1)) >= 0.01
        || $row['status'] !== (string) ($step['status'] ?? '')) {
        throw new \RuntimeException("{$type} #{$id} does not have the expected paid, due, and status values");
    }
}

function simStepCreateBillingInvoice(array &$ctx, array $step): void {
    if ($ctx['dry_run']) return;
    $id = (int) ($step['id'] ?? 0);
    $amount = round((float) ($step['amount'] ?? 0), 2);
    if ($id <= 0 || $amount <= 0) throw new \InvalidArgumentException('create_billing_invoice requires positive id and amount');
    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO billing_invoices
                (id, tenant_id, invoice_number, client_name, currency, issue_date, due_date,
                 subtotal, tax_total, total, amount_paid, amount_due, status, aggregation)
             VALUES
                (:id, :tenant_id, :invoice_number, :client_name, "USD", :issue_date, :due_date,
                 :amount, 0, :amount2, 0, :amount3, "sent", "per_client")
             ON DUPLICATE KEY UPDATE total = VALUES(total), amount_due = VALUES(amount_due),
                 amount_paid = 0, status = "sent", updated_at = NOW()'
        )->execute([
            'id' => $id,
            'tenant_id' => $ctx['tenant_id'],
            'invoice_number' => (string) ($step['invoice_number'] ?? "SIM-INV-{$id}"),
            'client_name' => (string) ($step['client_name'] ?? 'Simulation Client'),
            'issue_date' => simNow('Y-m-d'),
            'due_date' => simNow('Y-m-d'),
            'amount' => $amount,
            'amount2' => $amount,
            'amount3' => $amount,
        ]);
        $pdo->prepare('DELETE FROM billing_invoice_lines WHERE invoice_id = :invoice_id')
            ->execute(['invoice_id' => $id]);
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
        $pdo->commit();
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
