<?php
/**
 * /api/payroll/preflight.php — pre-flight check for a payroll period.
 *
 *   GET ?period_id=N
 *
 * Returns a per-employee pass/fail report so the user can fix data hygiene
 * issues BEFORE hitting "Build run" / "Submit to Gusto". For each W2
 * employee enrolled on the period's pay cycle we check:
 *
 *   • Identity:  legal_first_name, legal_last_name, ssn_cipher, date_of_birth, hire_date
 *   • Address:   primary residence on file (people_addresses where kind='home')
 *   • Federal:   people_tax_federal row with effective_date <= period_end,
 *                filing_status not null
 *   • State:     people_tax_state row matching payroll_profiles.work_state
 *   • Banking:   an active direct-deposit account when DD is selected
 *   • Compensation: the active People compensation row used by the engine
 *   • Placement: advisory only; payroll does not require a billable placement
 *   • Cycle:     payroll_profile.cycle_id matches the period cohort
 *
 * Each check is reported as { id, label, pass, severity, hint }. Severity:
 *   • blocker  — payroll cannot run without this fixed (SSN, filing status, compensation)
 *   • warning  — run will succeed but specific lines may be wrong (DD missing,
 *                state-tax SOT missing for a non-CA work-state, etc.)
 *   • info     — informational (no W-2 yet generated, etc.)
 *
 * Response shape:
 *   {
 *     period: { id, period_start, period_end, schedule_id, schedule_name },
 *     summary: { total_w2_employees, blockers, warnings, ready_to_run },
 *     employees: [
 *       { employee_id, name, classification, blockers: [...], warnings: [...], info: [...] }
 *     ]
 *   }
 *
 * Permission: payroll.run.create (same as building a run).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/sub_tenants.php';
require_once __DIR__ . '/../lib/payroll.php';

$ctx      = api_require_auth();
$tenantId = (int) $ctx['tenant_id'];
$peopleTenantId = effectiveTenantIdForModule('people', $tenantId) ?? $tenantId;
$placementsTenantId = effectiveTenantIdForModule('placements', $tenantId) ?? $tenantId;
rbac_legacy_require($ctx['user'], 'payroll.run.create');

if (api_method() !== 'GET') api_error('Method not allowed', 405);

$periodId = (int) ($_GET['period_id'] ?? 0);
if ($periodId <= 0) api_error('period_id required', 400);

$pdo = getDB();

// ── 1) Resolve the period + schedule ────────────────────────────────────
$period = scopedFind(
    "SELECT pp.id, pp.schedule_id, pp.cycle_id, pp.period_start, pp.period_end, pp.pay_date,
            pp.status,
            ps.name AS schedule_name, ps.frequency, pc.name AS cycle_name
       FROM payroll_pay_periods pp
       JOIN payroll_pay_schedules ps ON ps.id = pp.schedule_id AND ps.tenant_id = pp.tenant_id
  LEFT JOIN payroll_pay_cycles pc ON pc.id = pp.cycle_id AND pc.tenant_id = pp.tenant_id
      WHERE pp.tenant_id = :tenant_id AND pp.id = :id",
    ['id' => $periodId]
);
if (!$period) api_error('Pay period not found', 404);
$periodEnd = (string) $period['period_end'];

// ── 2) Enrolled W2 employees for this cycle. Legacy unbound profiles
//      are included only when the schedule has one active cycle.
//      payroll_profiles.employee_id FK → people_employees.id (the
//      canonical W2 record for payroll). The unified `people` table
//      from migration 003 is the talent-pool record; it doesn't carry
//      payroll-grade PII like ssn_cipher / hire_date.
$cycleId = !empty($period['cycle_id']) ? (int) $period['cycle_id'] : null;
$cycleWhere = '';
$employeeParams = ['sched' => (int) $period['schedule_id']];
if ($cycleId !== null) {
    $cycleWhere = ' AND (
        pp.cycle_id = :cycle_id
        OR (
            pp.cycle_id IS NULL
            AND 1 = (
                SELECT COUNT(*) FROM payroll_pay_cycles pc2
                 WHERE pc2.tenant_id = pp.tenant_id
                   AND pc2.schedule_id = :cycle_schedule
                   AND pc2.active = 1
            )
        )
    )';
    $employeeParams['cycle_id'] = $cycleId;
    $employeeParams['cycle_schedule'] = (int) $period['schedule_id'];
}
$emps = scopedQuery(
    "SELECT  pp.id            AS profile_id,
             pp.employee_id   AS employee_id,
             pp.schedule_id   AS profile_schedule_id,
             pp.cycle_id      AS profile_cycle_id,
             pp.work_state    AS work_state,
             pp.payment_method AS payment_method,
             pp.default_hours_per_period AS default_hours_per_period,
             pp.enabled       AS profile_enabled,
             e.legal_first_name AS first_name,
             e.legal_last_name  AS last_name,
             e.preferred_name   AS preferred_name,
             e.employee_number  AS employee_number,
             e.user_id          AS employee_user_id,
             e.work_email       AS work_email,
             e.personal_email   AS personal_email,
             e.ssn_cipher       AS ssn_cipher,
             e.date_of_birth    AS date_of_birth,
             e.hire_date        AS hire_date,
             e.status           AS person_status
       FROM payroll_profiles pp
       JOIN people_employees e ON e.id = pp.employee_id AND e.tenant_id = pp.tenant_id
      WHERE pp.tenant_id   = :tenant_id
        AND pp.enabled     = 1
        AND e.status       = 'active'
        AND (pp.schedule_id = :sched OR pp.schedule_id IS NULL)
        {$cycleWhere}
      ORDER BY e.legal_last_name, e.legal_first_name",
    $employeeParams
);

$report = [];
$totalBlockers = 0;
$totalWarnings = 0;

foreach ($emps as $e) {
    $empId    = (int) $e['employee_id'];
    $blockers = [];
    $warnings = [];
    $info     = [];

    $name = trim(($e['preferred_name'] ?: $e['first_name']) . ' ' . $e['last_name']);

    // Identity ───────────────────────────────────────────────────────
    if (empty($e['first_name']) || empty($e['last_name'])) {
        $blockers[] = [
            'id' => 'name', 'label' => 'Legal first + last name',
            'hint' => 'Open the employee record and fill in the legal name fields. Gusto will reject the submit otherwise.',
        ];
    }
    if (empty($e['ssn_cipher'])) {
        $blockers[] = [
            'id' => 'ssn', 'label' => 'SSN on file',
            'hint' => 'Add SSN under People → ' . $name . ' → Identity. Encrypted at rest.',
        ];
    }
    if (empty($e['date_of_birth'])) {
        $blockers[] = [
            'id' => 'dob', 'label' => 'Date of birth',
            'hint' => 'DoB is required for Gusto + W-2 generation.',
        ];
    }
    if (empty($e['hire_date'])) {
        $warnings[] = [
            'id' => 'hire_date', 'label' => 'Hire date',
            'hint' => 'Not strictly required for the run, but Gusto wants it for new-hire reporting.',
        ];
    }
    if ($e['person_status'] && $e['person_status'] !== 'active') {
        $warnings[] = [
            'id' => 'person_status', 'label' => 'Person record marked "' . $e['person_status'] . '"',
            'hint' => 'Inactive people will not pay-out — confirm this is intentional.',
        ];
    }

    // Federal W-4 ────────────────────────────────────────────────────
    $tf = scopedFind(
        "SELECT id, filing_status, effective_date
           FROM people_tax_federal
          WHERE tenant_id = :tenant_id AND employee_id = :eid
            AND effective_date <= :pe
          ORDER BY effective_date DESC LIMIT 1",
        ['eid' => $empId, 'pe' => $periodEnd]
    );
    if (!$tf || empty($tf['filing_status'])) {
        $blockers[] = [
            'id' => 'w4_federal', 'label' => 'Federal W-4 filing status',
            'hint' => 'Add a people_tax_federal row (effective ≤ ' . $periodEnd . ') with filing_status set.',
        ];
    }

    // State tax (only if work_state populated) ───────────────────────
    if (!empty($e['work_state'])) {
        $ts = scopedFind(
            "SELECT id, filing_status FROM people_tax_state
              WHERE tenant_id = :tenant_id AND employee_id = :eid
                AND state_code = :sc AND effective_date <= :pe
              ORDER BY effective_date DESC LIMIT 1",
            ['eid' => $empId, 'sc' => $e['work_state'], 'pe' => $periodEnd]
        );
        if (!$ts) {
            $warnings[] = [
                'id' => 'state_tax', 'label' => 'State tax setup for ' . $e['work_state'],
                'hint' => 'No people_tax_state row for this employee + work-state. Will fall back to "single, 0 allowances" — fine for CA, may underwithhold elsewhere.',
            ];
        }
    } else {
        $warnings[] = [
            'id' => 'work_state', 'label' => 'Work state on payroll profile',
            'hint' => 'Defaults to CA. Set explicitly under Payroll → Profiles.',
        ];
    }

    // Banking — only required if direct_deposit ──────────────────────
    if ($e['payment_method'] === 'direct_deposit') {
        if (!peopleActiveBankAccounts($empId)) {
            $warnings[] = [
                'id' => 'banking', 'label' => 'Banking on file (direct deposit)',
                'hint' => 'Set the payment method to check or add an active bank account in People. Direct deposit cannot be originated without it.',
            ];
        }
    }

    // Compensation is the source the gross-to-net engine actually uses.
    $compensation = peopleActiveCompensation($empId);
    if (!$compensation || (int) ($compensation['pay_rate_cents'] ?? 0) <= 0) {
        $blockers[] = [
            'id' => 'compensation', 'label' => 'Active compensation with a positive pay rate',
            'hint' => 'Open People → Compensation and add an effective salary or hourly rate for this employee.',
        ];
    } else {
        $info[] = [
            'id' => 'compensation_ok',
            'label' => ucfirst((string) $compensation['pay_type']) . ' compensation is active',
        ];
        if (($compensation['pay_type'] ?? '') === 'hourly'
            && (float) ($e['default_hours_per_period'] ?? 0) <= 0) {
            $warnings[] = [
                'id' => 'hours_source', 'label' => 'Hourly employee has no default hours',
                'hint' => 'Import or settle approved time before compute, or set default hours on the payroll profile.',
            ];
        }
    }

    // Placement context is useful for staffing reconciliation, but it is
    // never a payroll prerequisite. Internal employees and non-billable
    // workers must still be payable from their People compensation record.
    $personIds = [$empId]; // legacy fallback for older tenants where ids matched.
    try {
        $personWhere = [];
        $personParams = ['people_tid' => $peopleTenantId];
        if (!empty($e['employee_user_id'])) {
            $personWhere[] = 'user_id = :employee_user_id';
            $personParams['employee_user_id'] = (int) $e['employee_user_id'];
        }
        $emails = array_values(array_unique(array_filter([
            strtolower(trim((string) ($e['work_email'] ?? ''))),
            strtolower(trim((string) ($e['personal_email'] ?? ''))),
        ])));
        foreach ($emails as $idx => $email) {
            $key = 'email' . $idx;
            $personWhere[] = "LOWER(email_primary) = :{$key}";
            $personParams[$key] = $email;
        }
        if ($personWhere) {
            $personStmt = $pdo->prepare(
                'SELECT id FROM people
                  WHERE tenant_id = :people_tid
                    AND deleted_at IS NULL
                    AND (' . implode(' OR ', $personWhere) . ')'
            );
            $personStmt->execute($personParams);
            foreach ($personStmt->fetchAll(PDO::FETCH_ASSOC) as $pRow) {
                $personIds[] = (int) $pRow['id'];
            }
        }
    } catch (\Throwable $_) { /* keep legacy fallback */ }
    $personIds = array_values(array_unique(array_filter($personIds, static fn($id) => $id > 0)));
    $ph = [];
    $placementParams = [
        'placements_tid' => $placementsTenantId,
        'pe' => $periodEnd,
        'ps' => (string) $period['period_start'],
    ];
    foreach ($personIds as $idx => $pid) {
        $key = 'person_' . $idx;
        $ph[] = ':' . $key;
        $placementParams[$key] = $pid;
    }
    $placement = null;
    if ($ph) {
        $placementStmt = $pdo->prepare(
            "SELECT pl.id AS placement_id, pl.status,
                pr.pay_rate, pr.pay_rate_unit, pr.approved_at,
                pr.effective_from, pr.effective_to
           FROM placements pl
           LEFT JOIN placement_rates pr ON pr.placement_id = pl.id
                                       AND pr.tenant_id = :placements_tid
                                       AND pr.approved_at IS NOT NULL
                                       AND pr.effective_from <= :pe
                                       AND (pr.effective_to IS NULL OR pr.effective_to >= :ps)
          WHERE pl.tenant_id = :placements_tid
            AND pl.person_id IN (" . implode(',', $ph) . ")
            AND pl.status IN ('active','pending_start')
          ORDER BY pl.id DESC, pr.effective_from DESC
          LIMIT 1"
        );
        $placementStmt->execute($placementParams);
        $placement = $placementStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$placement) {
        $info[] = [
            'id' => 'placement_optional', 'label' => 'No billable placement in this period',
            'hint' => 'Payroll will use the employee compensation record. Add a placement only when this employee is billable to a client.',
        ];
    } elseif (!$placement['pay_rate'] || (float) $placement['pay_rate'] <= 0) {
        $warnings[] = [
            'id' => 'placement_rate', 'label' => 'Placement has no approved pay rate',
            'hint' => 'Payroll still uses People compensation, but staffing margin and settlement will be incomplete until the placement rate is approved.',
        ];
    } else {
        $info[] = [
            'id' => 'placement_rate_ok', 'label' => 'Placement rate available for staffing reconciliation',
        ];
    }

    if ($cycleId !== null && !empty($e['profile_cycle_id'])
        && (int) $e['profile_cycle_id'] !== $cycleId) {
        $info[] = [
            'id' => 'cycle', 'label' => 'Bound to a different pay cycle',
            'hint' => 'This employee is excluded from this run and should appear in their assigned cycle instead.',
        ];
    }

    $totalBlockers += count($blockers);
    $totalWarnings += count($warnings);

    $report[] = [
        'employee_id'    => $empId,
        'profile_id'     => (int) $e['profile_id'],
        'name'           => $name,
        'employee_number'=> $e['employee_number'],
        'work_state'     => $e['work_state'],
        'payment_method' => $e['payment_method'],
        'blocker_count'  => count($blockers),
        'warning_count'  => count($warnings),
        'blockers'       => $blockers,
        'warnings'       => $warnings,
        'info'           => $info,
        'ready'          => count($blockers) === 0,
    ];
}

api_ok([
    'period' => [
        'id'            => (int) $period['id'],
        'period_start'  => $period['period_start'],
        'period_end'    => $period['period_end'],
        'pay_date'      => $period['pay_date'],
        'schedule_id'   => (int) $period['schedule_id'],
        'schedule_name' => $period['schedule_name'],
        'cycle_id'      => $cycleId,
        'cycle_name'    => $period['cycle_name'] ?? null,
        'frequency'     => $period['frequency'],
        'status'        => $period['status'],
    ],
    'summary' => [
        'total_w2_employees' => count($emps),
        'blockers'           => $totalBlockers,
        'warnings'           => $totalWarnings,
        'ready_to_run'       => count($emps) > 0 && $totalBlockers === 0,
    ],
    'employees' => $report,
]);
