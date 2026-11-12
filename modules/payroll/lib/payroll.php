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
require_once __DIR__ . '/../../../core/sub_tenants.php';
require_once __DIR__ . '/../../../core/audit.php';
require_once __DIR__ . '/../../people/lib/employees.php';

/**
 * Get the payroll profile for an employee (or null).
 */
function payrollGetProfile(int $employeeId): ?array {
    $stmt = getDB()->prepare('SELECT * FROM payroll_profiles WHERE tenant_id = ? AND employee_id = ?');
    $stmt->execute([(int)currentTenantId(),$employeeId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * List employees that should be included in a run for a pay schedule/cycle.
 * A cycle-specific run must never pull employees from a sibling cycle that
 * happens to use the same schedule. Legacy profiles without cycle_id are
 * included only when the schedule has a single active cycle.
 */
function payrollEmployeesForSchedule(int $scheduleId, ?int $cycleId = null): array {
    $tenantId = (int) currentTenantId();
    if ($tenantId <= 0) return [];
    $peopleTenantId = effectiveTenantIdForModule('people', $tenantId);
    if (!$peopleTenantId) throw new RuntimeException('Payroll People directory is unavailable.');
    $cycleWhere = '';
    $params = ['sched' => $scheduleId, 'people_owner' => $peopleTenantId, 'payroll_owner' => $tenantId];
    if ($cycleId !== null && $cycleId > 0) {
        $cycleWhere = ' AND (
            p.cycle_id = :cycle
            OR (
                p.cycle_id IS NULL
                AND p.schedule_id = :legacy_sched
                AND 1 = (
                    SELECT COUNT(*) FROM payroll_pay_cycles pc
                     WHERE pc.tenant_id = p.tenant_id
                       AND pc.schedule_id = :cycle_count_sched
                       AND pc.active = 1
                )
            )
        )';
        $params['cycle'] = $cycleId;
        $params['legacy_sched'] = $scheduleId;
        $params['cycle_count_sched'] = $scheduleId;
    }
    $stmt = getDB()->prepare(
        "SELECT e.id           AS employee_id,
                e.employee_number,
                e.legal_first_name, e.legal_last_name, e.preferred_name,
                e.work_email, e.department, e.location, e.status,
                p.id           AS profile_id,
                p.schedule_id, p.cycle_id, p.work_state, p.payment_method,
                p.default_hours_per_period,
                p.retirement_pretax_bps,
                p.health_premium_cents,
                p.hsa_pretax_cents,
                p.extra_post_tax_cents,
                p.enabled
         FROM people_employees e
         JOIN payroll_profiles p
           ON p.tenant_id = :payroll_owner AND p.employee_id = e.id
         WHERE e.tenant_id = :people_owner
           AND p.schedule_id = :sched
           AND p.enabled = 1
           AND e.status IN ('active','on_leave')
         {$cycleWhere}
         ORDER BY e.legal_last_name, e.legal_first_name"
    );
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** This native US payroll engine has no FX or foreign payroll calculation. */
function payrollAssertSourceCurrency(?string $currency, string $source): void {
    if (strtoupper(trim((string) $currency)) !== 'USD') {
        throw new RuntimeException($source . ' must have explicit USD currency for native payroll. Keep the original currency and review its payment route.');
    }
}

/** Native Time uses OT_billable/OT_nonbillable; older entries may use OT/overtime. */
function payrollSourceIsOvertime(string $category): bool {
    $category = strtolower($category);
    return str_starts_with($category,'ot_') || $category === 'ot' || str_contains($category,'overtime');
}

/** People reads use the directory owner; payroll profiles remain company-owned. */
function payrollReadPeople(callable $read): mixed {
    $prior = $GLOBALS['__cf_request_module_scope'] ?? null;
    setRequestModuleScope('people');
    try { return $read(); }
    finally {
        if ($prior === null) clearRequestModuleScope();
        else setRequestModuleScope($prior);
    }
}

/** Hours explicitly extracted from Time. Unresolved sources cannot silently disappear. */
function payrollRunExtractedHours(int $runId): array {
    require_once __DIR__ . '/../../placements/lib/economics.php';
    $ref = (string)$runId;
    $tenantId = currentTenantId();
    if (!$tenantId) return [];
    $placementOwner = effectiveTenantIdForModule('placements', $tenantId);
    $stmt = getDB()->prepare(
        'SELECT te.id,te.person_id,te.category,te.hours,te.status,r.currency,r.approved_at
           FROM time_entries te
      LEFT JOIN placement_rates r ON r.tenant_id=:placement_owner
            AND r.id=te.rate_snapshot_id AND r.placement_id=te.placement_id
          WHERE te.tenant_id = :time_tenant_id AND CAST(te.payroll_extracted_ref AS CHAR) IN (:ref,:legacy_ref)
          ORDER BY te.id'
    );
    $stmt->execute([
        'placement_owner' => $placementOwner,
        'time_tenant_id' => $tenantId,
        'ref' => $ref,
        'legacy_ref' => 'payroll:run#'.$runId,
    ]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    $result = []; $recipients = [];
    foreach ($rows as $row) {
        $label = 'Extracted time #' . $row['id'];
        if ($row['status'] !== 'approved' || empty($row['approved_at'])) throw new RuntimeException($label . ' no longer has approved time and rate evidence.');
        payrollAssertSourceCurrency($row['currency'], $label);
        $person = (int) $row['person_id'];
        if (!array_key_exists($person, $recipients)) $recipients[$person] = placementEconomicsPayrollEmployee($tenantId, ['person_id'=>$person]);
        $employee = $recipients[$person];
        if (!$employee) throw new RuntimeException($label . ' requires a reviewed person/employment link and an enabled payroll profile.');
        $id = (int) $employee['employee_id'];
        $result[$id] ??= ['hours_regular'=>0.0,'hours_overtime'=>0.0];
        $key = payrollSourceIsOvertime((string)$row['category']) ? 'hours_overtime' : 'hours_regular';
        $result[$id][$key] += (float) $row['hours'];
    }
    return $result;
}

/** Commission/referral obligations routed to employee earnings for this run. */
function payrollRunEconomicEarnings(int $runId): array {
    require_once __DIR__ . '/../../placements/lib/economics.php';
    $tenantId = currentTenantId();
    if (!$tenantId) return [];
    $stmt = getDB()->prepare(
        'SELECT o.*,CAST(ROUND(o.amount * 100) AS SIGNED) AS commission_cents
           FROM placement_economic_obligations o
          WHERE o.tenant_id = :obligation_tenant_id AND o.payroll_ref_id = :run_id
            AND o.status IN ("payroll","paid") ORDER BY o.id'
    );
    $stmt->execute([
        'obligation_tenant_id' => $tenantId,
        'run_id' => $runId,
    ]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    $result = [];
    foreach ($rows as $row) {
        $label = 'Payroll obligation #' . $row['id'];
        payrollAssertSourceCurrency($row['currency'], $label);
        $employee = placementPayrollReceiptEmployee($tenantId,$row,placementPayrollReceipt(getDB(),$tenantId,(int)$row['id']));
        $id = (int) $employee['employee_id'];
        $result[$id] = ($result[$id] ?? 0) + (int) $row['commission_cents'];
    }
    return $result;
}

/** Include every explicitly routed source, even if the default cycle differs. */
function payrollRunSourceEmployees(array $emps, array $settledHours, array $economicEarnings): array {
    $economicOnly = [];
    $scheduledEmployeeIds = array_fill_keys(array_map(static fn(array $row): int => (int) $row['employee_id'], $emps), true);
    // A placement can route hours to a specific cycle. Those explicitly extracted
    // sources must be included even when the employee's default profile differs.
    $sourceEmployeeIds = array_unique(array_merge(array_keys($settledHours),array_keys($economicEarnings)));
    foreach ($sourceEmployeeIds as $sourceEmployeeId) {
        if (isset($scheduledEmployeeIds[$sourceEmployeeId])) continue;
        $employee = payrollReadPeople(static fn() => peopleGetEmployee((int)$sourceEmployeeId));
        $profile = payrollGetProfile((int)$sourceEmployeeId);
        if (!$employee || !in_array($employee['status'],['active','on_leave'],true) || !$profile || empty($profile['enabled'])) {
            throw new RuntimeException('An extracted payroll source no longer has an enabled employment/profile record. Review it before computing.');
        }
        $emps[] = $employee + $profile + [
            'employee_id' => (int)$sourceEmployeeId,
            'payment_method' => $profile['payment_method'] ?? 'direct_deposit',
        ];
        if (!isset($settledHours[$sourceEmployeeId])) $economicOnly[(int)$sourceEmployeeId] = true;
    }
    return ['employees'=>$emps,'economic_only'=>$economicOnly];
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
    [$emp,$comp,$fed] = payrollReadPeople(static fn() => [
        peopleGetEmployee($employeeId),
        peopleActiveCompensation($employeeId, (string) $period['period_end']),
        peopleActiveFederalTax($employeeId, (string) $period['period_end']),
    ]);
    if (!$emp) return null;
    if (!$comp) return null;
    payrollAssertSourceCurrency($comp['currency'] ?? null, 'Employee compensation');
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
        'commission_cents' => (int) ($extras['commission_cents'] ?? 0),
        'suppress_regular_earnings' => !empty($extras['suppress_regular_earnings']),

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


/**
 * Append-only audit log writer for payroll events.
 * Mirrors apAudit() / billingAudit() — never throws.
 */
function payrollAudit(string $event, array $meta = [], ?int $targetId = null, array $opts = []): void
{
    try {
        $ctx  = function_exists('currentTenantContext') ? currentTenantContext() : null;
        $tenantId = isset($ctx['tenant_id']) ? (int) $ctx['tenant_id'] : (function_exists('currentTenantId') ? currentTenantId() : null);
        if ($tenantId === null && isset($meta['tenant_id'])) $tenantId = (int) $meta['tenant_id'];
        $actorUserId = isset($ctx['user']['id']) ? (int) $ctx['user']['id'] : null;
        if ($actorUserId === null && isset($meta['actor_user_id'])) $actorUserId = (int) $meta['actor_user_id'];
        platformAuditLogWrite($tenantId, $actorUserId, $event, $targetId, $meta, array_merge([
            'object_type' => payrollAuditObjectType($event),
            'source' => $meta['source'] ?? 'payroll',
        ], $opts));
    } catch (\Throwable $e) {
        error_log('[payroll.audit] ' . $event . ' write-failed: ' . $e->getMessage());
    }
}

function payrollAuditObjectType(string $event): string
{
    if (str_contains($event, '.run.')) return 'payroll_run';
    if (str_contains($event, '.period.')) return 'payroll_period';
    if (str_contains($event, '.profile.')) return 'payroll_profile';
    if (str_contains($event, '.settings.')) return 'payroll_settings';
    if (str_contains($event, '.schedule.')) return 'payroll_schedule';
    if (str_contains($event, '.cycle.')) return 'payroll_cycle';
    if (str_contains($event, '.tax.')) return 'payroll_tax_liability';
    if (str_contains($event, '.w2.')) return 'payroll_w2';
    if (str_contains($event, '.gusto.')) return 'payroll_gusto';
    if (str_contains($event, '.anomalies.')) return 'payroll_anomaly';
    if (str_contains($event, '.deduction.')) return 'payroll_deduction';
    return 'payroll';
}

/**
 * True iff this payroll run is being executed in Gusto (CSV uploaded, Gusto
 * run ID captured back). When this is the case, future post-to-GL code MUST
 * NOT post the cash-movement leg (DD payable, taxes payable disbursement,
 * etc.) because Gusto is doing that — we'd double-count.
 *
 * Wage-accrual posting (Dr Wages Expense / Cr Wages Payable) IS still ours
 * to do; only the disbursement leg is suppressed when gusto_managed.
 */
function payrollRunIsGustoManaged(array $run): bool
{
    return !empty($run['gusto_run_id']);
}
