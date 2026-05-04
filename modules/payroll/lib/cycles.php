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

class PayCycleException extends \RuntimeException {}

/** Pure: compute the next [start, end, pay_date] for a cycle row + schedule row. */
function payrollCycleNextWindow(array $cycle, array $schedule, ?string $asOf = null): array
{
    $asOf      = $asOf ?: date('Y-m-d');
    $periodNum = (int) $cycle['next_period_number'] ?: 1;
    $anchor    = $cycle['anchor_date_override'] ?: $schedule['period_start_anchor'];
    $offset    = (int) ($cycle['pay_date_offset_days_override'] ?? $schedule['pay_date_offset_days'] ?? 5);
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
    $pdo->beginTransaction();
    try {
        $cycle = scopedFind('SELECT * FROM payroll_pay_cycles WHERE tenant_id = :tenant_id AND id = :id', ['id' => $cycleId]);
        if (!$cycle)              throw new PayCycleException('Cycle not found');
        if (!$cycle['active'])    throw new PayCycleException('Cycle is inactive');

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

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    payrollAuditLight('payroll.cycle.advanced', [
        'cycle_id' => $cycleId, 'period_id' => $periodId, 'run_id' => $runId,
        'window' => $win, 'actor_user_id' => $actorUserId,
    ], $cycleId);

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

function payrollAuditLight(string $event, array $meta = [], ?int $targetId = null): void
{
    try {
        $pdo = getDB();
        $ctx = function_exists('currentTenantContext') ? currentTenantContext() : null;
        $pdo->prepare(
            'INSERT INTO audit_log (tenant_id, actor_user_id, event, target_id, meta_json, ip_address, created_at)
             VALUES (:tenant_id, :actor, :event, :target_id, :meta_json, :ip, NOW())'
        )->execute([
            'tenant_id' => $ctx['tenant_id'] ?? null,
            'actor'     => $ctx['user']['id'] ?? null,
            'event'     => $event,
            'target_id' => $targetId,
            'meta_json' => json_encode($meta),
            'ip'        => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (\Throwable $e) { error_log('[payroll.audit] ' . $event . ' failed: ' . $e->getMessage()); }
}
