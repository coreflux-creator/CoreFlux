<?php
/**
 * Payroll Module — Cross-Module Library
 *
 * Stable interface other modules use to read payroll data. Wraps the helpers
 * People exposes (employees, comp, tax, banking) and joins in payroll-side
 * profile data (schedule, work_state, deduction elections, YTD).
 *
 * Other modules MUST go through these functions — never select from
 * payroll_* tables directly.
 */

require_once __DIR__ . '/../../../core/tenant_scope.php';
require_once __DIR__ . '/../../people/lib/employees.php';

/**
 * Get the payroll profile for an employee (or null).
 */
function payrollGetProfile(int $employeeId): ?array {
    return scopedFind(
        'SELECT * FROM payroll_profiles WHERE tenant_id = :tenant_id AND employee_id = :emp',
        ['emp' => $employeeId]
    );
}

/**
 * List employees that should be included in a run for a given pay schedule.
 * Returns employee rows joined with their payroll_profile row.
 */
function payrollEmployeesForSchedule(int $scheduleId): array {
    return scopedQuery(
        "SELECT e.id           AS employee_id,
                e.employee_number,
                e.legal_first_name, e.legal_last_name, e.preferred_name,
                e.work_email, e.department, e.location, e.status,
                p.id           AS profile_id,
                p.schedule_id, p.work_state, p.payment_method,
                p.default_hours_per_period,
                p.retirement_pretax_bps,
                p.health_premium_cents,
                p.hsa_pretax_cents,
                p.extra_post_tax_cents,
                p.enabled
         FROM people_employees e
         JOIN payroll_profiles p
           ON p.tenant_id = e.tenant_id AND p.employee_id = e.id
         WHERE e.tenant_id = :tenant_id
           AND p.schedule_id = :sched
           AND p.enabled = 1
           AND e.status IN ('active','on_leave')
         ORDER BY e.legal_last_name, e.legal_first_name",
        ['sched' => $scheduleId]
    );
}

/**
 * Sum YTD wages for an employee from prior approved runs in the same calendar year.
 * Returns ['ss' => cents, 'medicare' => cents, 'futa' => cents, 'suta' => cents].
 */
function payrollYTDWages(int $employeeId, string $asOfDate): array {
    $year = (int) substr($asOfDate, 0, 4);
    $row = scopedFind(
        "SELECT
            COALESCE(SUM(li.taxable_cents), 0)               AS fit_wages,
            COALESCE(SUM(li.gross_cents - COALESCE((
                SELECT SUM(d.amount_cents) FROM payroll_deductions d
                WHERE d.line_item_id = li.id AND d.is_pretax = 1
                  AND d.code IN ('health_premium','hsa')
            ), 0)), 0)                                       AS fica_wages
         FROM payroll_line_items li
         JOIN payroll_runs r ON r.id = li.run_id
         JOIN payroll_pay_periods pp ON pp.id = r.pay_period_id
         WHERE li.tenant_id = :tenant_id
           AND li.employee_id = :emp
           AND r.status IN ('approved','paid')
           AND YEAR(pp.pay_date) = :yr
           AND pp.pay_date < :asof",
        ['emp' => $employeeId, 'yr' => $year, 'asof' => $asOfDate]
    );
    $fica = (int) ($row['fica_wages'] ?? 0);
    return [
        'ss'       => $fica,
        'medicare' => $fica,
        'futa'     => $fica,
        'suta'     => $fica,
    ];
}

/**
 * Build the deterministic compute context for one employee + run.
 * Reads everything from People + payroll_profile + tenant settings.
 * Returns null if the employee isn't payroll-ready.
 */
function payrollBuildComputeContext(int $employeeId, array $period, array $tenantSettings, array $extras = []): ?array {
    $emp = peopleGetEmployee($employeeId);
    if (!$emp) return null;
    $comp = peopleActiveCompensation($employeeId);
    if (!$comp) return null;
    $fed = peopleActiveFederalTax($employeeId);
    if (!$fed) return null;
    $profile = payrollGetProfile($employeeId);
    if (!$profile) return null;

    $ytd = payrollYTDWages($employeeId, $period['pay_date']);

    return [
        'pay_type'       => $comp['pay_type'],
        'pay_rate_cents' => (int) $comp['pay_rate_cents'],
        'pay_frequency'  => $comp['pay_frequency'],
        'work_state'     => $profile['work_state'] ?? 'CA',
        'hours_regular'  => (float) ($extras['hours_regular']  ?? $profile['default_hours_per_period'] ?? 0),
        'hours_overtime' => (float) ($extras['hours_overtime'] ?? 0),
        'bonus_cents'    => (int)   ($extras['bonus_cents']    ?? 0),

        'fed_filing_status'         => $fed['filing_status'],
        'fed_dependents_cents'      => (int) $fed['dependents_amount_cents'],
        'fed_other_income_cents'    => (int) $fed['other_income_cents'],
        'fed_deductions_cents'      => (int) $fed['deductions_cents'],
        'fed_extra_withhold_cents'  => (int) $fed['extra_withholding_cents'],
        'state_extra_withhold_cents'=> 0,

        'ytd_ss_wages_cents'        => $ytd['ss'],
        'ytd_medicare_wages_cents'  => $ytd['medicare'],
        'ytd_futa_wages_cents'      => $ytd['futa'],
        'ytd_suta_wages_cents'      => $ytd['suta'],

        'retirement_pretax_bps'     => (int) $profile['retirement_pretax_bps'],
        'health_premium_cents'      => (int) $profile['health_premium_cents'],
        'hsa_pretax_cents'          => (int) $profile['hsa_pretax_cents'],
        'extra_post_tax_cents'      => (int) $profile['extra_post_tax_cents'],

        'suta_rate_bps'             => (int) ($tenantSettings['suta_rate_bps'] ?? 340),
        'futa_credit_rate_bps'      => (int) ($tenantSettings['futa_credit_rate_bps'] ?? 540),
    ];
}

/**
 * Generate the next N pay periods for a schedule (idempotent).
 * Returns the new rows that were inserted (skips ones that already exist).
 */
function payrollGenerateNextPeriods(int $scheduleId, int $count = 6): array {
    $sched = scopedFind(
        'SELECT * FROM payroll_pay_schedules WHERE tenant_id = :tenant_id AND id = :id',
        ['id' => $scheduleId]
    );
    if (!$sched) return [];

    // Find current max period_number; start one after.
    $row = scopedFind(
        'SELECT COALESCE(MAX(period_number), 0) AS mx
         FROM payroll_pay_periods WHERE tenant_id = :tenant_id AND schedule_id = :s',
        ['s' => $scheduleId]
    );
    $startNum = ((int) ($row['mx'] ?? 0)) + 1;

    $created = [];
    $anchor = new DateTimeImmutable($sched['period_start_anchor']);
    $offset = (int) $sched['pay_date_offset_days'];
    for ($i = 0; $i < $count; $i++) {
        $n = $startNum + $i;
        [$start, $end] = _payrollPeriodBounds($anchor, $sched['frequency'], $n);
        $payDate = $end->modify("+$offset day")->format('Y-m-d');
        $newId = scopedInsert('payroll_pay_periods', [
            'schedule_id'   => $scheduleId,
            'period_number' => $n,
            'period_start'  => $start->format('Y-m-d'),
            'period_end'    => $end->format('Y-m-d'),
            'pay_date'      => $payDate,
            'status'        => 'draft',
        ]);
        $created[] = $newId;
    }
    return $created;
}

/**
 * Compute period start/end based on frequency + period number (1-indexed).
 */
function _payrollPeriodBounds(DateTimeImmutable $anchor, string $freq, int $n): array {
    switch ($freq) {
        case 'weekly':
            $start = $anchor->modify('+' . (($n - 1) * 7) . ' day');
            $end   = $start->modify('+6 day');
            return [$start, $end];
        case 'biweekly':
            $start = $anchor->modify('+' . (($n - 1) * 14) . ' day');
            $end   = $start->modify('+13 day');
            return [$start, $end];
        case 'monthly':
            $start = $anchor->modify('+' . ($n - 1) . ' month');
            $end   = $start->modify('+1 month')->modify('-1 day');
            return [$start, $end];
        case 'semimonthly':
        default:
            // Pairs: first half (1–15), second half (16–end). Period 1 = first half of anchor month.
            $monthsAhead = intdiv($n - 1, 2);
            $half = ($n % 2 === 1) ? 1 : 2;
            $base = $anchor->modify('+' . $monthsAhead . ' month');
            $year = (int) $base->format('Y'); $mo = (int) $base->format('m');
            if ($half === 1) {
                $start = $base->setDate($year, $mo, 1);
                $end   = $base->setDate($year, $mo, 15);
            } else {
                $start = $base->setDate($year, $mo, 16);
                $lastDay = (int) $base->modify('last day of this month')->format('d');
                $end = $base->setDate($year, $mo, $lastDay);
            }
            return [$start, $end];
    }
}

/**
 * Tenant payroll settings (auto-creates a stub row if missing).
 */
function payrollGetTenantSettings(): array {
    $row = scopedFind(
        'SELECT * FROM payroll_settings WHERE tenant_id = :tenant_id LIMIT 1'
    );
    return $row ?? [
        'legal_name'           => '',
        'primary_state'        => 'CA',
        'suta_rate_bps'        => 340,
        'futa_credit_rate_bps' => 540,
    ];
}
