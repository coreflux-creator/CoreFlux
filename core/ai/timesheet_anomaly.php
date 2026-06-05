<?php
/**
 * core/ai/timesheet_anomaly.php — Slice E rule-based timesheet anomaly
 * detector. Spec §11 ("Payroll Agent").
 *
 * Pure-read tool — never mutates state. Returns a structured list of
 * findings, each scored 0..1 with a short human-readable reason.
 *
 * Rules:
 *   R1. SPIKE          — person's hours this week > 1.5x their
 *                        4-week median AND ≥ 50 hours total.
 *   R2. ZERO_WEEK      — person had ≥ 1 hr/wk in the prior 4 weeks
 *                        and dropped to 0 this week (potential missed
 *                        time entry).
 *   R3. CATEGORY_DRIFT — share of hours in `regular_billable` dropped
 *                        > 30 percentage points vs. their 4-week avg
 *                        (often indicates miscategorization).
 *   R4. OVERLAP        — entries on the same (person, work_date) that
 *                        sum > 24 hours.
 *
 * Caller passes a (week_start, week_end) window. Returns the findings
 * AND the headline counts so the LLM / reviewer can prioritize.
 *
 * tenant-leak-allow: every query is parameterized on tenant_id.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

/**
 * Top-level entry point. Returns:
 *   {
 *     window: {week_start, week_end},
 *     scanned_people: int,
 *     findings: [{person_id, rule, severity (low|medium|high),
 *                 score (0..1), reason, current_value, baseline_value}],
 *     summary_by_rule: {rule_code: count, ...},
 *   }
 */
function detectTimesheetAnomalies(int $tenantId, array $opts = []): array
{
    if ($tenantId <= 0) throw new \InvalidArgumentException('tenantId required');
    $weekStart = (string) ($opts['week_start'] ?? date('Y-m-d', strtotime('monday last week')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart)) {
        throw new \InvalidArgumentException("week_start must be YYYY-MM-DD ('$weekStart')");
    }
    $weekEnd = date('Y-m-d', strtotime("$weekStart +6 days"));
    $baselineStart = date('Y-m-d', strtotime("$weekStart -28 days"));
    $baselineEnd   = date('Y-m-d', strtotime("$weekStart -1 day"));

    $findings = [];
    $people   = [];

    try {
        $pdo = getDB();
        // Per-person totals this week.
        $stmt = $pdo->prepare(
            "SELECT person_id,
                    SUM(hours) AS hours_total,
                    SUM(CASE WHEN category = 'regular_billable' THEN hours ELSE 0 END) AS hours_billable
               FROM time_entries
              WHERE tenant_id = :t
                AND work_date BETWEEN :a AND :b
              GROUP BY person_id"
        );
        $stmt->execute(['t' => $tenantId, 'a' => $weekStart, 'b' => $weekEnd]);
        $thisWeekRows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $thisWeek = [];
        foreach ($thisWeekRows as $r) {
            $thisWeek[(int) $r['person_id']] = [
                'hours_total'    => (float) $r['hours_total'],
                'hours_billable' => (float) $r['hours_billable'],
            ];
        }

        // Per-person 4-week baseline (totals + billable share).
        $stmt = $pdo->prepare(
            "SELECT person_id,
                    AVG(weekly_total) AS avg_total,
                    AVG(weekly_billable) AS avg_billable
               FROM (
                    SELECT person_id,
                           YEARWEEK(work_date, 1) AS wk,
                           SUM(hours) AS weekly_total,
                           SUM(CASE WHEN category = 'regular_billable' THEN hours ELSE 0 END) AS weekly_billable
                      FROM time_entries
                     WHERE tenant_id = :t
                       AND work_date BETWEEN :a AND :b
                     GROUP BY person_id, YEARWEEK(work_date, 1)
                   ) base
              GROUP BY person_id"
        );
        $stmt->execute(['t' => $tenantId, 'a' => $baselineStart, 'b' => $baselineEnd]);
        $baseline = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
            $baseline[(int) $r['person_id']] = [
                'avg_total'    => (float) $r['avg_total'],
                'avg_billable' => (float) $r['avg_billable'],
            ];
        }

        // Union of people with current OR historical activity.
        $personIds = array_unique(array_merge(array_keys($thisWeek), array_keys($baseline)));
        $people    = $personIds;
        foreach ($personIds as $pid) {
            $cur  = $thisWeek[$pid] ?? ['hours_total' => 0, 'hours_billable' => 0];
            $base = $baseline[$pid] ?? ['avg_total' => 0, 'avg_billable' => 0];

            // R1: SPIKE.
            if ($base['avg_total'] > 1 && $cur['hours_total'] >= 50
                && $cur['hours_total'] > 1.5 * $base['avg_total']) {
                $score = min(1.0, ($cur['hours_total'] / max(1, $base['avg_total']) - 1) / 2);
                $findings[] = timesheetFinding($pid, 'spike',
                    $score >= 0.5 ? 'high' : 'medium', $score,
                    sprintf('%.1f hrs this week vs %.1f hr baseline (>%d%% spike)',
                        $cur['hours_total'], $base['avg_total'],
                        (int) ((($cur['hours_total'] / max(1, $base['avg_total'])) - 1) * 100)),
                    $cur['hours_total'], $base['avg_total']);
            }

            // R2: ZERO_WEEK.
            if ($base['avg_total'] >= 1 && $cur['hours_total'] == 0) {
                $findings[] = timesheetFinding($pid, 'zero_week', 'medium', 0.6,
                    sprintf('No hours this week; baseline was %.1f hr/wk', $base['avg_total']),
                    0.0, $base['avg_total']);
            }

            // R3: CATEGORY_DRIFT (billable share).
            if ($cur['hours_total'] > 0 && $base['avg_total'] > 1) {
                $curBillShare  = $cur['hours_billable']  / max(1, $cur['hours_total']);
                $baseBillShare = $base['avg_billable']   / max(1, $base['avg_total']);
                $drift = $baseBillShare - $curBillShare;          // positive = dropped
                if ($drift > 0.30) {
                    $score = min(1.0, $drift * 1.5);
                    $findings[] = timesheetFinding($pid, 'category_drift',
                        $drift > 0.6 ? 'high' : 'medium', $score,
                        sprintf('Billable share dropped from %d%% to %d%% (-%d pp)',
                            (int) ($baseBillShare * 100),
                            (int) ($curBillShare * 100),
                            (int) ($drift * 100)),
                        $curBillShare, $baseBillShare);
                }
            }
        }

        // R4: OVERLAP — any (person, day) summing > 24 hrs.
        $stmt = $pdo->prepare(
            "SELECT person_id, work_date, SUM(hours) AS day_hours
               FROM time_entries
              WHERE tenant_id = :t
                AND work_date BETWEEN :a AND :b
              GROUP BY person_id, work_date
             HAVING day_hours > 24"
        );
        $stmt->execute(['t' => $tenantId, 'a' => $weekStart, 'b' => $weekEnd]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
            $hrs = (float) $r['day_hours'];
            $findings[] = timesheetFinding((int) $r['person_id'], 'overlap', 'high',
                min(1.0, ($hrs - 24) / 24 + 0.6),
                sprintf('%.1f hrs entered for %s (impossible >24h)', $hrs, $r['work_date']),
                $hrs, 24.0);
        }
    } catch (\Throwable $e) {
        // Schema missing in sandbox — return an empty-finding shell with the error.
        return [
            'window' => ['week_start' => $weekStart, 'week_end' => $weekEnd],
            'scanned_people' => 0,
            'findings' => [],
            'summary_by_rule' => [],
            'note' => 'unable to scan: ' . substr($e->getMessage(), 0, 200),
        ];
    }

    $summary = ['spike' => 0, 'zero_week' => 0, 'category_drift' => 0, 'overlap' => 0];
    foreach ($findings as $f) $summary[$f['rule']] = ($summary[$f['rule']] ?? 0) + 1;

    return [
        'window'          => ['week_start' => $weekStart, 'week_end' => $weekEnd],
        'scanned_people'  => count($people),
        'findings'        => $findings,
        'summary_by_rule' => $summary,
    ];
}

/** Internal — finding row factory. */
function timesheetFinding(int $personId, string $rule, string $severity, float $score,
                          string $reason, float $currentValue, float $baselineValue): array
{
    return [
        'person_id'      => $personId,
        'rule'           => $rule,
        'severity'       => $severity,
        'score'          => round($score, 3),
        'reason'         => $reason,
        'current_value'  => round($currentValue,  2),
        'baseline_value' => round($baselineValue, 2),
    ];
}
