<?php
/**
 * Payroll AI Cross-Checks — anomaly detection for a computed run.
 *
 *   payrollAnomaliesDetect(runId)         — runs deterministic checks, persists
 *                                            findings, optionally enriches with AI.
 *   payrollAnomaliesListByRun(runId)      — read findings (for UI / API).
 *   payrollAnomaliesListUnacked()         — tenant-wide unacknowledged feed for
 *                                            the dashboard alert badge.
 *   payrollAnomaliesAcknowledge(id, u)    — single-finding ack.
 *
 * Detection scope (deterministic, never invented by AI):
 *   1. hours_drift     — current run's hours vs employee's trailing-3 average.
 *                        Flags if delta >= 25% (warning) or >= 50% (critical).
 *   2. missing_time    — employee included in run with hours_regular=0 AND
 *                        prior runs had > 0 average hours.
 *   3. rate_change     — pay_rate_cents on current run differs from the most
 *                        recent prior run for the same employee.
 *
 * AI enrichment (optional): calls aiAsk() with the raw findings to produce a
 * one-paragraph narrative for the human reviewer. Failures are swallowed —
 * the deterministic findings are always persisted regardless.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../core/tenant_scope.php';
require_once __DIR__ . '/../../../core/ai_service.php';
require_once __DIR__ . '/payroll.php';

const PAYROLL_ANOMALY_DRIFT_WARN_PCT     = 25.0;
const PAYROLL_ANOMALY_DRIFT_CRITICAL_PCT = 50.0;
const PAYROLL_ANOMALY_HISTORY_RUNS       = 3;

/**
 * Run all anomaly checks for a payroll run. Idempotent: re-running on the
 * same run replaces prior findings for that run_id.
 *
 * Returns a summary array:
 *   [ 'total' => int, 'by_severity' => [...], 'by_code' => [...], 'ai' => ?envelope ]
 */
function payrollAnomaliesDetect(int $runId, bool $askAi = false): array
{
    $tenantId = currentTenantId();
    $pdo      = getDB();
    if (!$pdo) throw new RuntimeException('No database connection');

    $run = scopedFind(
        'SELECT r.*, pp.period_start, pp.period_end, pp.pay_date, pp.cycle_id
         FROM payroll_runs r
         JOIN payroll_pay_periods pp ON pp.id = r.pay_period_id AND pp.tenant_id = r.tenant_id
         WHERE r.tenant_id = :tenant_id AND r.id = :id',
        ['id' => $runId]
    );
    if (!$run) throw new RuntimeException('Run not found');
    if ($run['status'] === 'draft') throw new RuntimeException('Run not yet computed');

    // Wipe prior findings for this run (idempotent).
    $pdo->prepare(
        'DELETE FROM payroll_anomaly_findings WHERE tenant_id = :t AND run_id = :r'
    )->execute(['t' => $tenantId, 'r' => $runId]);

    // Pull this run's line items (with employee identity).
    $thisLines = scopedQuery(
        "SELECT li.id, li.employee_id, li.hours_regular, li.hours_overtime,
                li.pay_rate_cents, li.gross_cents,
                e.legal_first_name, e.legal_last_name, e.employee_number
         FROM payroll_line_items li
         JOIN people_employees e ON e.id = li.employee_id AND e.tenant_id = li.tenant_id
         WHERE li.tenant_id = :tenant_id AND li.run_id = :rid",
        ['rid' => $runId]
    );

    $findings = [];
    foreach ($thisLines as $row) {
        $empId = (int) $row['employee_id'];

        // Pull trailing N runs for this employee, excluding the current.
        $hist = scopedQuery(
            "SELECT li.hours_regular, li.hours_overtime, li.pay_rate_cents
             FROM payroll_line_items li
             JOIN payroll_runs r ON r.id = li.run_id AND r.tenant_id = li.tenant_id
             WHERE li.tenant_id = :tenant_id
               AND li.employee_id = :emp
               AND r.id <> :rid
               AND r.status IN ('computed','approved','paid')
             ORDER BY r.id DESC
             LIMIT " . PAYROLL_ANOMALY_HISTORY_RUNS,
            ['emp' => $empId, 'rid' => $runId]
        );
        if (!$hist) continue;  // nothing to compare against — first run for this employee

        $hoursNow = (float) $row['hours_regular'] + (float) $row['hours_overtime'];
        $hoursAvg = 0.0;
        foreach ($hist as $h) $hoursAvg += (float) $h['hours_regular'] + (float) $h['hours_overtime'];
        $hoursAvg = $hoursAvg / count($hist);

        $name = trim((string) $row['legal_first_name'] . ' ' . (string) $row['legal_last_name']);
        $emp  = (string) $row['employee_number'];

        // 1. hours_drift / missing_time
        if ($hoursAvg > 0) {
            $deltaPct = round((($hoursNow - $hoursAvg) / $hoursAvg) * 100, 1);
            if ($hoursNow == 0.0) {
                // missing_time wins over drift
                $findings[] = _payrollAnomalyRow($runId, (int) $run['cycle_id'], (int) $run['pay_period_id'], $empId, [
                    'severity'       => 'critical',
                    'code'           => 'missing_time',
                    'message'        => "$name (#$emp) has 0 hours this run but averaged "
                                       . number_format($hoursAvg, 1) . "h across the prior "
                                       . count($hist) . " run(s).",
                    'expected_value' => number_format($hoursAvg, 1) . 'h',
                    'actual_value'   => '0.0h',
                ]);
            } elseif (abs($deltaPct) >= PAYROLL_ANOMALY_DRIFT_WARN_PCT) {
                $sev = abs($deltaPct) >= PAYROLL_ANOMALY_DRIFT_CRITICAL_PCT ? 'critical' : 'warning';
                $dir = $deltaPct > 0 ? 'higher' : 'lower';
                $findings[] = _payrollAnomalyRow($runId, (int) $run['cycle_id'], (int) $run['pay_period_id'], $empId, [
                    'severity'       => $sev,
                    'code'           => 'hours_drift',
                    'message'        => "$name (#$emp) hours are " . number_format(abs($deltaPct), 1)
                                       . "% $dir than their trailing average ("
                                       . number_format($hoursAvg, 1) . "h → "
                                       . number_format($hoursNow, 1) . 'h).',
                    'expected_value' => number_format($hoursAvg, 1) . 'h',
                    'actual_value'   => number_format($hoursNow, 1) . 'h',
                ]);
            }
        }

        // 2. rate_change vs most-recent prior run
        $priorRate = (int) $hist[0]['pay_rate_cents'];
        $thisRate  = (int) $row['pay_rate_cents'];
        if ($priorRate > 0 && $thisRate !== $priorRate) {
            $deltaPct = round((($thisRate - $priorRate) / $priorRate) * 100, 1);
            $sev = abs($deltaPct) >= PAYROLL_ANOMALY_DRIFT_CRITICAL_PCT ? 'critical' : 'warning';
            $findings[] = _payrollAnomalyRow($runId, (int) $run['cycle_id'], (int) $run['pay_period_id'], $empId, [
                'severity'       => $sev,
                'code'           => 'rate_change',
                'message'        => "$name (#$emp) pay rate changed from "
                                   . number_format($priorRate / 100, 2) . ' to '
                                   . number_format($thisRate / 100, 2) . ' ('
                                   . ($deltaPct > 0 ? '+' : '') . $deltaPct . '%).',
                'expected_value' => '$' . number_format($priorRate / 100, 2),
                'actual_value'   => '$' . number_format($thisRate / 100, 2),
            ]);
        }
    }

    // Persist findings (single-pass).
    foreach ($findings as $f) scopedInsert('payroll_anomaly_findings', $f);

    // Summary aggregations
    $bySev = ['info' => 0, 'warning' => 0, 'critical' => 0];
    $byCode = [];
    foreach ($findings as $f) {
        $bySev[$f['severity']] = ($bySev[$f['severity']] ?? 0) + 1;
        $byCode[$f['code']]    = ($byCode[$f['code']]   ?? 0) + 1;
    }
    $summary = [
        'total'       => count($findings),
        'by_severity' => $bySev,
        'by_code'     => $byCode,
        'ai'          => null,
    ];

    payrollAudit('payroll.anomalies.detected',
        ['run_id' => $runId, 'count' => count($findings), 'by_severity' => $bySev], $runId);

    // Optional AI enrichment — best-effort narrative for reviewer.
    if ($askAi && $findings) {
        try {
            $envelope = aiAsk([
                'feature_class' => 'narrative',
                'kind'          => 'narrative',
                'feature_key'   => 'payroll.anomalies',
                'system'        => 'You write a brief reviewer note about pay-run anomalies. The findings '
                                  .'below are deterministic and final — do NOT restate raw numbers as '
                                  .'figures the system could parse, and do NOT invent additional findings. '
                                  .'Group them by severity and call out names + employee numbers.',
                'prompt'        => 'Summarize the following payroll anomalies in 1-2 short paragraphs and '
                                  .'end with one suggested next step the reviewer should take.',
                'context'       => [
                    'run_id'    => $runId,
                    'pay_date'  => $run['pay_date'],
                    'findings'  => $findings,
                    'summary'   => $summary,
                ],
                'max_output_tokens' => 500,
            ]);
            $summary['ai'] = $envelope;
        } catch (\Throwable $e) {
            error_log('[payroll.anomalies] ai enrichment skipped: ' . $e->getMessage());
        }
    }

    return $summary;
}

/** Pure helper that pre-fills tenant + run row context for a finding. */
function _payrollAnomalyRow(int $runId, int $cycleId, int $periodId, int $empId, array $extra): array
{
    return array_merge([
        'run_id'      => $runId,
        'cycle_id'    => $cycleId ?: null,
        'period_id'   => $periodId,
        'employee_id' => $empId,
        'ai_used'     => 0,
    ], $extra);
}

/** Pull all findings for a run (for the run-detail panel). */
function payrollAnomaliesListByRun(int $runId): array
{
    return scopedQuery(
        "SELECT f.*, e.legal_first_name, e.legal_last_name, e.employee_number
         FROM payroll_anomaly_findings f
         JOIN people_employees e ON e.id = f.employee_id AND e.tenant_id = f.tenant_id
         WHERE f.tenant_id = :tenant_id AND f.run_id = :rid
         ORDER BY FIELD(f.severity,'critical','warning','info'), f.id",
        ['rid' => $runId]
    );
}

/** Tenant-wide unacknowledged findings (for the dashboard alert badge). */
function payrollAnomaliesListUnacked(int $limit = 25): array
{
    $limit = max(1, min(200, $limit));
    return scopedQuery(
        "SELECT f.*, e.legal_first_name, e.legal_last_name, e.employee_number,
                pp.pay_date
         FROM payroll_anomaly_findings f
         JOIN people_employees e ON e.id = f.employee_id AND e.tenant_id = f.tenant_id
         LEFT JOIN payroll_pay_periods pp ON pp.id = f.period_id AND pp.tenant_id = f.tenant_id
         WHERE f.tenant_id = :tenant_id AND f.acknowledged_at IS NULL
         ORDER BY FIELD(f.severity,'critical','warning','info'), f.id DESC
         LIMIT $limit"
    );
}

/** Mark a finding acknowledged. */
function payrollAnomaliesAcknowledge(int $findingId, ?int $userId): int
{
    $rows = scopedUpdate('payroll_anomaly_findings', $findingId, [
        'acknowledged_at'         => date('Y-m-d H:i:s'),
        'acknowledged_by_user_id' => $userId,
    ]);
    if ($rows > 0) {
        payrollAudit('payroll.anomalies.acknowledged',
            ['finding_id' => $findingId, 'actor_user_id' => $userId], $findingId);
    }
    return $rows;
}
