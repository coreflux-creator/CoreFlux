<?php
/**
 * Treasury Sweep Engine — execution-time logic for tenant_sweep_rules.
 *
 * Concerns split into three layers so the worker (cron driver) stays
 * thin and every decision is independently testable:
 *
 *   1. Schedule decoder        — does `frequency` fire today?
 *   2. Amount computer         — given balance + floor, how much to sweep?
 *   3. Run orchestrator        — fetch balance, evaluate, audit, optionally
 *                                originate Mercury payment_instruction.
 *
 * Live execution is gated by env var TREASURY_SWEEP_LIVE=1. Default is
 * dry-run: every rule evaluation lands in `treasury_sweep_runs` with
 * `dry_run=1` and zero side-effects beyond the audit row + the rule's
 * `last_*` fields, so operators can tail the audit log to validate the
 * math before flipping the switch.
 *
 * See:
 *   /app/core/migrations/073_treasury_sweep_rules.sql
 *   /app/core/migrations/074_treasury_sweep_runs.sql
 *   /app/cron/treasury_sweep_worker.php
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mercury_adapter.php';

// ============================================================
// Layer 1: Schedule decoder
// ============================================================

/**
 * Decide if a sweep rule's frequency fires on a given calendar date.
 *
 * Frequencies (from migration 073):
 *   - daily
 *   - weekly_mon | weekly_tue | weekly_wed | weekly_thu | weekly_fri |
 *     weekly_sat | weekly_sun
 *   - monthly_1 | monthly_15 (additional day-of-month variants accepted
 *     as monthly_<1-28>)
 *
 * Pure function so unit tests can drive every weekday/day combo
 * deterministically.
 */
function treasurySweepFrequencyDueOn(string $frequency, \DateTimeImmutable $when): bool
{
    $frequency = strtolower(trim($frequency));
    if ($frequency === 'daily') return true;

    if (str_starts_with($frequency, 'weekly_')) {
        $target = substr($frequency, strlen('weekly_'));
        // PHP's lowercase short-day matches 'mon' .. 'sun' from format('D').
        $today = strtolower($when->format('D'));
        return $today === $target;
    }

    if (str_starts_with($frequency, 'monthly_')) {
        $dom = (int) substr($frequency, strlen('monthly_'));
        if ($dom < 1 || $dom > 28) return false; // 29-31 intentionally rejected — ambiguous in Feb
        return ((int) $when->format('j')) === $dom;
    }

    return false;
}

// ============================================================
// Layer 2: Amount computer
// ============================================================

/**
 * Given an account balance and a rule's floor configuration, compute
 * the sweep amount (in cents, always non-negative).
 *
 * Two floor models supported (mutually exclusive per rule):
 *   - target_min_balance_cents: keep AT LEAST this much, sweep the rest.
 *   - sweep_above_cents:        sweep everything above this threshold.
 *
 * Both end up with the same formula `max(0, balance - floor)` — the
 * naming separation in the schema is for operator clarity. If both are
 * set, target_min_balance_cents wins (more conservative).
 *
 * Returns 0 if balance is at or below the floor (caller should record
 * outcome='skipped_under_floor').
 */
function treasurySweepComputeAmount(int $balanceCents, ?int $targetMinCents, ?int $sweepAboveCents): int
{
    if ($balanceCents <= 0) return 0;
    $floor = $targetMinCents ?? $sweepAboveCents;
    if ($floor === null) return 0; // no floor configured → nothing to sweep (safety default)
    if ($floor < 0) $floor = 0;
    $delta = $balanceCents - $floor;
    return $delta > 0 ? (int) $delta : 0;
}

// ============================================================
// Layer 3: Run orchestrator
// ============================================================

/**
 * Check whether live execution is enabled via env.
 *
 * `TREASURY_SWEEP_LIVE=1` enables the actual Mercury transfer leg.
 * Anything else (including unset, 'false', '0', '') stays in dry-run.
 */
function treasurySweepLiveModeEnabled(): bool
{
    $v = strtolower((string) ($_ENV['TREASURY_SWEEP_LIVE'] ?? getenv('TREASURY_SWEEP_LIVE') ?? ''));
    return $v === '1' || $v === 'true' || $v === 'yes' || $v === 'on';
}

/**
 * Record a sweep run in the audit table + update the rule's last_*
 * snapshot. Idempotent at the rule level (rules can fire many times;
 * audit captures every fire), but caller should guard against
 * double-firing within the same scheduled tick.
 */
function treasurySweepRecordRun(
    int $tenantId,
    int $ruleId,
    ?int $balanceCents,
    int $sweepAmountCents,
    string $outcome,
    bool $dryRun,
    ?int $paymentInstructionId = null,
    ?string $errorMessage = null
): int {
    $pdo = getDB();
    $pdo->prepare(
        'INSERT INTO treasury_sweep_runs
            (tenant_id, rule_id, source_balance_cents, sweep_amount_cents,
             outcome, dry_run, payment_instruction_id, error_message)
         VALUES (:t, :r, :b, :a, :o, :d, :pi, :err)'
    )->execute([
        't'   => $tenantId,
        'r'   => $ruleId,
        'b'   => $balanceCents,
        'a'   => $sweepAmountCents,
        'o'   => $outcome,
        'd'   => $dryRun ? 1 : 0,
        'pi'  => $paymentInstructionId,
        'err' => $errorMessage !== null ? mb_substr($errorMessage, 0, 4000) : null,
    ]);
    $runId = (int) $pdo->lastInsertId();

    // Roll the rule's last_* fields forward so the UI shows the most
    // recent execution. We don't gate on rule.tenant_id here because
    // the worker has already pinned the tenant and this is in the same
    // logical transaction; the WHERE id+tenant_id is defense-in-depth.
    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    $pdo->prepare(
        'UPDATE tenant_sweep_rules
            SET last_run_at           = NOW(),
                last_outcome          = :o,
                last_run_amount_cents = :a
          WHERE id = :id AND tenant_id = :t'
    )->execute([
        'o'  => $outcome,
        'a'  => $sweepAmountCents,
        'id' => $ruleId,
        't'  => $tenantId,
    ]);

    return $runId;
}

/**
 * Execute a single sweep rule.
 *
 * Returns the outcome string (matches treasury_sweep_runs.outcome).
 * Never throws — every failure is caught and recorded as an audit row.
 */
function treasurySweepRunRule(
    int $tenantId,
    array $rule,
    ?string $apiToken,
    \DateTimeImmutable $now,
    bool $forceDryRun = false
): string {
    $ruleId   = (int) $rule['id'];
    $live     = !$forceDryRun && treasurySweepLiveModeEnabled();
    $dryRun   = !$live;

    if ((int) ($rule['enabled'] ?? 0) !== 1) {
        treasurySweepRecordRun($tenantId, $ruleId, null, 0, 'skipped_disabled', $dryRun);
        return 'skipped_disabled';
    }
    if (!treasurySweepFrequencyDueOn((string) $rule['frequency'], $now)) {
        treasurySweepRecordRun($tenantId, $ruleId, null, 0, 'skipped_not_due', $dryRun);
        return 'skipped_not_due';
    }
    if ($apiToken === null || $apiToken === '') {
        treasurySweepRecordRun($tenantId, $ruleId, null, 0, 'failed_no_connection', $dryRun,
            null, 'no active Mercury connection for tenant');
        return 'failed_no_connection';
    }

    // Layer 3a: fetch source-account balance.
    $balanceCents = null;
    try {
        $acct = mercuryGetAccount($apiToken, (string) $rule['source_account_id']);
        // Mercury returns currentBalance + availableBalance as floats in
        // dollars. Use availableBalance when present (it excludes pending
        // outflows, matching the "what can I actually move" intuition).
        $avail = $acct['availableBalance'] ?? $acct['currentBalance'] ?? null;
        if ($avail === null) {
            throw new \RuntimeException('Mercury response missing availableBalance/currentBalance');
        }
        $balanceCents = (int) round(((float) $avail) * 100);
    } catch (\Throwable $e) {
        treasurySweepRecordRun($tenantId, $ruleId, null, 0, 'failed_balance_fetch', $dryRun,
            null, $e->getMessage());
        return 'failed_balance_fetch';
    }

    // Layer 3b: compute sweep amount.
    $sweepCents = treasurySweepComputeAmount(
        $balanceCents,
        isset($rule['target_min_balance_cents']) ? (int) $rule['target_min_balance_cents'] : null,
        isset($rule['sweep_above_cents'])        ? (int) $rule['sweep_above_cents']        : null
    );
    if ($sweepCents <= 0) {
        treasurySweepRecordRun($tenantId, $ruleId, $balanceCents, 0, 'skipped_under_floor', $dryRun);
        return 'skipped_under_floor';
    }

    // Layer 3c: originate the transfer.
    //
    // Live execution wires the sweep into the existing
    // payment_instructions / mpAdvance pipeline by creating an
    // instruction whose recipient is a kind='sweep_destination' (added
    // by migration 075). The standard approval policy applies — if
    // require_approval_policy_id is set on the rule, the worker just
    // creates the instruction in Draft state and the approval workflow
    // runs the rest. When no approval policy is required, an
    // operator/cron can pick it up and advance through mpAdvance.
    //
    // The Mercury counterparty for the destination account must be
    // pre-pushed (mercuryRecipientPush) so the originate leg can
    // resolve the counterparty_id. Setup-time concern, not run-time.
    if ($dryRun) {
        treasurySweepRecordRun($tenantId, $ruleId, $balanceCents, $sweepCents, 'swept', true);
        return 'swept'; // outcome reflects the *intended* state; dry_run=1 column tells the truth
    }

    $destRecipientId = isset($rule['destination_recipient_id']) ? (int) $rule['destination_recipient_id'] : 0;
    if ($destRecipientId <= 0) {
        treasurySweepRecordRun($tenantId, $ruleId, $balanceCents, $sweepCents, 'failed_execute', false,
            null, 'rule has no destination_recipient_id — wire a kind=sweep_destination recipient first (migration 075)');
        return 'failed_execute';
    }

    require_once __DIR__ . '/mercury_payments.php';
    try {
        $pi = mpCreate($tenantId, [
            'recipient_id'    => $destRecipientId,
            'amount_cents'    => $sweepCents,
            'currency'        => 'USD',
            'source_module'   => 'treasury_sweep',
            'source_ref'      => (string) $ruleId,
            // Idempotency: one instruction per (rule, calendar-day). A
            // double-fired worker tick on the same day returns the
            // existing instruction without double-spending. We do NOT
            // include the amount in the key — if the rule re-evaluates
            // mid-day and balance shifted, the FIRST decision wins,
            // matching cron-safety expectations.
            'idempotency_key' => sprintf('sweep:%d:%s', $ruleId, $now->format('Y-m-d')),
            'description'     => sprintf('Sweep #%d: balance %s → destination', $ruleId, number_format($balanceCents / 100, 2)),
            'notes'           => 'Auto-originated by treasury_sweep_worker',
        ], null);
    } catch (\Throwable $e) {
        treasurySweepRecordRun($tenantId, $ruleId, $balanceCents, $sweepCents, 'failed_execute', false,
            null, 'mpCreate failed: ' . $e->getMessage());
        return 'failed_execute';
    }

    treasurySweepRecordRun(
        $tenantId, $ruleId, $balanceCents, $sweepCents, 'swept', false,
        (int) ($pi['id'] ?? 0), null
    );
    return 'swept';
}

/**
 * Walk every enabled sweep rule across every tenant. Cron-driver entry
 * point — the actual loop lives in /app/cron/treasury_sweep_worker.php
 * but it delegates here so the same orchestration is testable from a
 * smoke without invoking cron.
 *
 * Returns a summary: ['rules_seen' => N, 'by_outcome' => [outcome => count]].
 */
function treasurySweepRunAllTenants(\DateTimeImmutable $now): array
{
    $pdo = getDB();
    $rules = $pdo->query(
        'SELECT * FROM tenant_sweep_rules WHERE enabled = 1 ORDER BY tenant_id, sort_order, id'
    )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $summary = ['rules_seen' => count($rules), 'by_outcome' => []];
    // Cache Mercury connections per tenant — most tenants have one
    // active connection and we'd otherwise re-decrypt the token N times.
    $tokenCache = [];

    foreach ($rules as $rule) {
        $tid = (int) $rule['tenant_id'];
        if (!array_key_exists($tid, $tokenCache)) {
            $conn = function_exists('mercuryGetConnection') ? mercuryGetConnection($tid) : null;
            $tokenCache[$tid] = ($conn && ($conn['status'] ?? '') === 'active')
                ? (string) $conn['api_token']
                : null;
        }
        $outcome = treasurySweepRunRule($tid, $rule, $tokenCache[$tid], $now);
        $summary['by_outcome'][$outcome] = ($summary['by_outcome'][$outcome] ?? 0) + 1;
    }
    return $summary;
}
