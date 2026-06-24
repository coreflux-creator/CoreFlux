<?php
/**
 * Pay-cycle engine: list, advance, and compute the next pay_period.
 *
 *   payrollCycleNextWindow(cycle)    — pure date math; no DB writes
 *   payrollCycleAdvance(cycleId)     — generate next period + draft run
 *
 * "Advancing" a cycle means:
 *   1. Compute the next period_start/end/pay_date based on the cycle's
 *      anchor + the parent schedule's frequency.
 *   2. INSERT a new payroll_pay_periods row tagged with cycle_id.
 *   3. INSERT a draft payroll_runs row.
 *   4. (Future) line-item population from time settlement happens via a
 *      separate call to keep the advance idempotent.
 *
 * Idempotent: two concurrent calls won't produce duplicate periods (uses
 * UNIQUE KEY uq_period_tenant_sched_num + last_advanced_at watermark).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../core/tenant_scope.php';
require_once __DIR__ . '/payroll.php';

class PayCycleException extends \RuntimeException {}

/** Pure: compute the next [start, end, pay_date] for a cycle row + schedule row. */
function payrollCycleNextWindow(array $cycle, array $schedule, ?string $asOf = null): array
{
    $asOf      = $asOf ?: date('Y-m-d');
    $periodNum = (int) ($cycle['next_period_number'] ?? 1) ?: 1;
    $anchor    = $cycle['anchor_date_override'] ?: $schedule['period_start_anchor'];
    $offset    = $cycle['pay_date_offset_days_override'] !== null && $cycle['pay_date_offset_days_override'] !== ''
                 ? (int) $cycle['pay_date_offset_days_override']
                 : (int) ($schedule['pay_date_offset_days'] ?? 5);
    $freq      = (string) $schedule['frequency'];

    $anchorTs = strtotime((string) $anchor);
    if ($anchorTs === false) throw new PayCycleException('Invalid cycle anchor date');

    switch ($freq) {
        case 'weekly':
            $start = strtotime("+" . (($periodNum - 1) * 7) . " days", $anchorTs);
            $end   = strtotime('+6 days', $start);
            break;
        case 'biweekly':
            $start = strtotime("+" . (($periodNum - 1) * 14) . " days", $anchorTs);
            $end   = strtotime('+13 days', $start);
            break;
        case 'semimonthly':
            $idx = $periodNum - 1;
            $monthOffset = intdiv($idx, 2);
            $half = $idx % 2;
            $base = strtotime("first day of +$monthOffset months", $anchorTs);
            if ($half === 0) {
                $start = $base;
                $end   = strtotime('+14 days', $base);
            } else {
                $start = strtotime('+15 days', $base);
                $end   = strtotime('last day of', $base);
            }
            break;
        case 'monthly':
            $start = strtotime("first day of +" . ($periodNum - 1) . " months", $anchorTs);
            $end   = strtotime('last day of', $start);
            break;
        default:
            throw new PayCycleException('Unsupported frequency: ' . $freq);
    }
    $payDate = strtotime("+$offset days", $end);

    return [
        'period_number' => $periodNum,
        'period_start'  => date('Y-m-d', $start),
        'period_end'    => date('Y-m-d', $end),
        'pay_date'      => date('Y-m-d', $payDate),
    ];
}

/**
 * Advance a cycle: insert the next pay period + a draft run, atomic.
 * Returns ['period_id' => ..., 'run_id' => ..., 'window' => [...]].
 */
function payrollCycleAdvance(int $cycleId, ?int $actorUserId = null): array
{
    $tenantId = currentTenantId();
    $pdo = getDB();
    $ownsTxn = cf_tx_begin($pdo);
    try {
        $cycle = scopedFind('SELECT * FROM payroll_pay_cycles WHERE tenant_id = :tenant_id AND id = :id', ['id' => $cycleId]);
        if (!$cycle)              throw new PayCycleException('Cycle not found');
        if (!$cycle['active'])    throw new PayCycleException('Cycle is inactive');
        $beforeCycle = $cycle;

        $schedule = scopedFind('SELECT * FROM payroll_pay_schedules WHERE tenant_id = :tenant_id AND id = :id',
                               ['id' => (int) $cycle['schedule_id']]);
        if (!$schedule) throw new PayCycleException('Cycle has no schedule (id=' . $cycle['schedule_id'] . ')');

        $win = payrollCycleNextWindow($cycle, $schedule);

        // Insert period (idempotent via uq_period_tenant_sched_num).
        $periodId = scopedInsert('payroll_pay_periods', [
            'schedule_id'   => (int) $schedule['id'],
            'cycle_id'      => $cycleId,
            'period_number' => $win['period_number'],
            'period_start'  => $win['period_start'],
            'period_end'    => $win['period_end'],
            'pay_date'      => $win['pay_date'],
            'status'        => 'open',
        ]);

        // Insert draft run.
        $runId = scopedInsert('payroll_runs', [
            'pay_period_id' => $periodId,
            'run_type'      => 'regular',
            'status'        => 'draft',
        ]);

        // Watermark + advance counter.
        $pdo->prepare(
            'UPDATE payroll_pay_cycles
             SET next_period_number = :n, last_advanced_at = NOW(), last_run_id = :r
             WHERE tenant_id = :t AND id = :id'
        )->execute([
            'n'  => $win['period_number'] + 1,
            'r'  => $runId,
            't'  => $tenantId,
            'id' => $cycleId,
        ]);

        cf_tx_commit($pdo, $ownsTxn);
    } catch (\Throwable $e) {
        cf_tx_rollback($pdo, $ownsTxn);
        throw $e;
    }

    payrollAuditLight('payroll.cycle.advanced', [
        'cycle_id' => $cycleId, 'period_id' => $periodId, 'run_id' => $runId,
        'window' => $win, 'actor_user_id' => $actorUserId,
    ], $cycleId, [
        'tenant_id' => $tenantId,
        'actor_user_id' => $actorUserId,
        'before' => ['cycle' => $beforeCycle],
        'after' => [
            'cycle' => payrollCycleAuditRow($tenantId, $cycleId),
            'period' => payrollPayPeriodAuditRow($tenantId, $periodId),
            'run' => payrollCycleRunAuditRow($tenantId, $runId),
        ],
    ]);

    return ['period_id' => $periodId, 'run_id' => $runId, 'window' => $win];
}

function payrollCycleList(): array
{
    $stmt = getDB()->prepare(
        'SELECT c.*, s.name AS schedule_name, s.frequency, s.period_start_anchor
         FROM payroll_pay_cycles c
         JOIN payroll_pay_schedules s ON s.id = c.schedule_id AND s.tenant_id = c.tenant_id
         WHERE c.tenant_id = :tenant_id
         ORDER BY c.active DESC, c.name'
    );
    $stmt->execute(['tenant_id' => currentTenantId()]);
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
}

function payrollCycleAuditRow(int $tenantId, int $cycleId): ?array
{
    try {
        $stmt = getDB()->prepare('SELECT * FROM payroll_pay_cycles WHERE tenant_id = :t AND id = :id LIMIT 1');
        $stmt->execute(['t' => $tenantId, 'id' => $cycleId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (\Throwable $_) {
        return null;
    }
}

function payrollPayPeriodAuditRow(int $tenantId, int $periodId): ?array
{
    try {
        $stmt = getDB()->prepare('SELECT * FROM payroll_pay_periods WHERE tenant_id = :t AND id = :id LIMIT 1');
        $stmt->execute(['t' => $tenantId, 'id' => $periodId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (\Throwable $_) {
        return null;
    }
}

function payrollCycleRunAuditRow(int $tenantId, int $runId): ?array
{
    try {
        $stmt = getDB()->prepare('SELECT * FROM payroll_runs WHERE tenant_id = :t AND id = :id LIMIT 1');
        $stmt->execute(['t' => $tenantId, 'id' => $runId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (\Throwable $_) {
        return null;
    }
}

/**
 * Cron-style sweep: advance every active cycle whose latest period has
 * ended on/before $asOf. Run from update.php on each deploy + on a server
 * cron. Returns a per-cycle log [['cycle_id'=>1, 'status'=>'advanced', ...]].
 *
 * Idempotent: a cycle whose newest period still has period_end > today
 * is skipped with status='not_due'.
 */
function payrollCycleAutoAdvanceAll(?string $asOf = null): array
{
    $asOf = $asOf ?: date('Y-m-d');
    $pdo  = getDB();
    if (!$pdo) return [];

    // All-tenants sweep: walk distinct tenant_ids, switch tenant context for each.
    $tenants = $pdo->query('SELECT DISTINCT tenant_id FROM payroll_pay_cycles WHERE active = 1')->fetchAll();
    $log = [];
    foreach ($tenants as $tr) {
        $tid = (int) $tr['tenant_id'];
        $previous = $_SESSION['tenant_id'] ?? null;
        $_SESSION['tenant_id'] = $tid;
        try {
            $cycles = payrollCycleList();
            foreach ($cycles as $c) {
                if (empty($c['active'])) {
                    $log[] = ['tenant_id' => $tid, 'cycle_id' => (int) $c['id'], 'status' => 'inactive'];
                    continue;
                }
                // Find newest period for this cycle.
                $newest = scopedFind(
                    'SELECT period_end FROM payroll_pay_periods
                      WHERE tenant_id = :tenant_id AND cycle_id = :cid
                      ORDER BY period_number DESC LIMIT 1',
                    ['cid' => (int) $c['id']]
                );
                $needsAdvance = !$newest || (strtotime((string) $newest['period_end']) < strtotime($asOf));
                if (!$needsAdvance) {
                    $log[] = ['tenant_id' => $tid, 'cycle_id' => (int) $c['id'], 'status' => 'not_due',
                              'newest_period_end' => $newest['period_end']];
                    continue;
                }
                try {
                    $res = payrollCycleAdvance((int) $c['id']);
                    $log[] = ['tenant_id' => $tid, 'cycle_id' => (int) $c['id'], 'status' => 'advanced',
                              'period_id' => $res['period_id'], 'run_id' => $res['run_id'],
                              'window' => $res['window']];
                } catch (\Throwable $e) {
                    $log[] = ['tenant_id' => $tid, 'cycle_id' => (int) $c['id'], 'status' => 'error',
                              'error' => $e->getMessage()];
                }
            }
        } finally {
            if ($previous === null) unset($_SESSION['tenant_id']);
            else                    $_SESSION['tenant_id'] = $previous;
        }
    }
    return $log;
}

function payrollAuditLight(string $event, array $meta = [], ?int $targetId = null, array $opts = []): void
{
    try {
        if (!array_key_exists('tenant_id', $opts) && function_exists('currentTenantId')) {
            $opts['tenant_id'] = currentTenantId();
        }
        if (!array_key_exists('actor_user_id', $opts) && isset($meta['actor_user_id'])) {
            $opts['actor_user_id'] = $meta['actor_user_id'] !== null ? (int) $meta['actor_user_id'] : null;
        }
        payrollAudit($event, $meta, $targetId, $opts);
    } catch (\Throwable $e) { error_log('[payroll.audit] ' . $event . ' failed: ' . $e->getMessage()); }
}
