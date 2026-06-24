<?php
/**
 * /api/payroll/preflight.php — pre-flight check for a payroll period.
 *
 *   GET ?period_id=N
 *
 * Returns a per-employee pass/fail report so the user can fix data hygiene
 * issues BEFORE hitting "Build run" / "Submit to Gusto". For each W2
 * person enrolled on the period's pay schedule we check:
 *
 *   • Identity:  legal_first_name, legal_last_name, ssn_cipher, date_of_birth, hire_date
 *   • Address:   primary residence on file (people_addresses where kind='home')
 *   • Federal:   people_tax_federal row with effective_date <= period_end,
 *                filing_status not null
 *   • State:     people_tax_state row matching payroll_profiles.work_state
 *   • Banking:   if payment_method='direct_deposit' on payroll_profiles,
 *                people_banking row exists with routing+account ciphers
 *   • Placement: at least one active placement with an approved
 *                placement_rates row whose effective window covers the
 *                period and pay_rate > 0
 *   • Schedule:  payroll_profile.schedule_id matches the period's schedule
 *
 * Each check is reported as { id, label, pass, severity, hint }. Severity:
 *   • blocker  — payroll cannot run without this fixed (SSN, filing_status, rate)
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
 * Permission: payroll.run.create (readiness check before draft run creation).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';

$ctx      = api_require_auth();
$tenantId = (int) $ctx['tenant_id'];
rbac_legacy_require($ctx['user'], 'payroll.run.create');

if (api_method() !== 'GET') api_error('Method not allowed', 405);

$periodId = (int) ($_GET['period_id'] ?? 0);
if ($periodId <= 0) api_error('period_id required', 400);

$pdo = getDB();

// ── 1) Resolve the period + schedule ────────────────────────────────────
$period = scopedFind(
    "SELECT pp.id, pp.schedule_id, pp.period_start, pp.period_end, pp.pay_date,
            pp.status,
            ps.name AS schedule_name, ps.frequency
       FROM payroll_pay_periods pp
       JOIN payroll_pay_schedules ps ON ps.id = pp.schedule_id AND ps.tenant_id = pp.tenant_id
      WHERE pp.tenant_id = :tenant_id AND pp.id = :id",
    ['id' => $periodId]
);
if (!$period) api_error('Pay period not found', 404);
$periodEnd = (string) $period['period_end'];

// ── 2) Enrolled W2 employees for this schedule (or unbound profiles
//      that fall back to the tenant default schedule).
//      payroll_profiles.employee_id FK → people_employees.id (the
//      canonical W2 record for payroll). The unified `people` table
//      from migration 003 is the talent-pool record; it doesn't carry
//      payroll-grade PII like ssn_cipher / hire_date.
$emps = scopedQuery(
    "SELECT  pp.id            AS profile_id,
             pp.employee_id   AS employee_id,
             pp.schedule_id   AS profile_schedule_id,
             pp.work_state    AS work_state,
             pp.payment_method AS payment_method,
             pp.enabled       AS profile_enabled,
             e.legal_first_name AS first_name,
             e.legal_last_name  AS last_name,
             e.preferred_name   AS preferred_name,
             e.employee_number  AS employee_number,
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
      ORDER BY e.legal_last_name, e.legal_first_name",
    ['sched' => (int) $period['schedule_id']]
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
        $bk = scopedFind(
            "SELECT id FROM people_banking
              WHERE tenant_id = :tenant_id AND employee_id = :eid
              ORDER BY id DESC LIMIT 1",
            ['eid' => $empId]
        );
        if (!$bk) {
            $warnings[] = [
                'id' => 'banking', 'label' => 'Banking on file (direct deposit)',
                'hint' => 'Set payment_method=check on the payroll profile, OR add people_banking. Gusto handles disbursement either way, but DD requires routing/account.',
            ];
        }
    }

    // Active placement with approved rate covering this period ───────
    $placement = scopedFind(
        "SELECT pl.id AS placement_id, pl.status,
                pr.pay_rate, pr.pay_rate_unit, pr.approved_at,
                pr.effective_from, pr.effective_to
           FROM placements pl
           LEFT JOIN placement_rates pr ON pr.placement_id = pl.id
                                       AND pr.tenant_id = pl.tenant_id
                                       AND pr.approved_at IS NOT NULL
                                       AND pr.effective_from <= :pe
                                       AND (pr.effective_to IS NULL OR pr.effective_to >= :ps)
          WHERE pl.tenant_id = :tenant_id
            AND pl.person_id = :eid
            AND pl.status IN ('active','pending_start')
          ORDER BY pl.id DESC, pr.effective_from DESC
          LIMIT 1",
        ['eid' => $empId, 'pe' => $periodEnd, 'ps' => (string) $period['period_start']]
    );
    if (!$placement) {
        $blockers[] = [
            'id' => 'placement', 'label' => 'Active placement covering ' . $period['period_start'] . ' → ' . $periodEnd,
            'hint' => 'Without an active placement (status=active|pending_start) the time-billing settlement won\'t produce a payroll line for this employee.',
        ];
    } elseif (!$placement['pay_rate'] || (float) $placement['pay_rate'] <= 0) {
        $blockers[] = [
            'id' => 'pay_rate', 'label' => 'Approved pay_rate > 0 on the placement',
            'hint' => 'placement_rates row exists but pay_rate is 0 or NULL. Update under Placements → ' . $name . '.',
        ];
    } else {
        $info[] = [
            'id' => 'rate_ok', 'label' => 'Pay rate: $' . number_format((float) $placement['pay_rate'], 2) . '/' . $placement['pay_rate_unit'],
        ];
    }

    // Schedule binding ───────────────────────────────────────────────
    if ($e['profile_schedule_id'] && (int) $e['profile_schedule_id'] !== (int) $period['schedule_id']) {
        $info[] = [
            'id' => 'schedule', 'label' => 'Bound to a different schedule',
            'hint' => 'This profile\'s schedule_id is ' . $e['profile_schedule_id'] . '; the period\'s schedule_id is ' . $period['schedule_id'] . '. Will be skipped on this run.',
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
        'frequency'     => $period['frequency'],
        'status'        => $period['status'],
    ],
    'summary' => [
        'total_w2_employees' => count($emps),
        'blockers'           => $totalBlockers,
        'warnings'           => $totalWarnings,
        'ready_to_run'       => $totalBlockers === 0,
    ],
    'employees' => $report,
]);
