<?php
/**
 * /modules/payroll/api/gusto_preview.php — dry-run preview of what
 * gusto_submit.php would PUT to Gusto, with a per-employee diff against
 * what's currently in the unprocessed Gusto payroll.
 *
 *   POST { run_id, gusto_payroll_uuid }
 *
 * Returns:
 *   {
 *     gusto_payroll: { uuid, period_start, period_end, status },
 *     employees: [
 *       {
 *         gusto_employee_number, name, matched: bool,
 *         current:  { regular_hours, overtime_hours, fixed: [...] },
 *         proposed: { regular_hours, overtime_hours, fixed: [...] },
 *         diff:     [ { field, from, to } ]
 *       }
 *     ],
 *     summary: { matched, unmatched, total_employees_in_gusto, total_lines_in_coreflux }
 *   }
 *
 * No mutations. Same RBAC as submit (payroll.run.disburse) since you'd
 * use this immediately before pulling the trigger.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/gusto_service.php';

$ctx = api_require_auth();
RBAC::requirePermission($ctx['user'], 'payroll.run.disburse');
if (api_method() !== 'POST') api_error('Method not allowed', 405);

$body = api_json_body();
api_require_fields($body, ['run_id', 'gusto_payroll_uuid']);
$runId       = (int) $body['run_id'];
$payrollUuid = trim((string) $body['gusto_payroll_uuid']);

$run = scopedFind(
    'SELECT r.*, pp.period_start, pp.period_end
       FROM payroll_runs r
       JOIN payroll_pay_periods pp ON pp.id = r.pay_period_id AND pp.tenant_id = r.tenant_id
      WHERE r.tenant_id = :tenant_id AND r.id = :id',
    ['id' => $runId]
);
if (!$run) api_error('Run not found', 404);

$conn = gustoActiveConnection((int) $ctx['tenant_id']);
if (!$conn) api_error('No active Gusto connection. Connect Gusto in Payroll Settings first.', 412);

// Pull our line items keyed by employee_number — same join as submit.
$lines = scopedQuery(
    "SELECT li.id, li.employee_id, li.pay_type, li.hours_regular, li.hours_overtime,
            li.gross_cents,
            e.employee_number, e.legal_first_name, e.legal_last_name
       FROM payroll_line_items li
       JOIN people_employees e ON e.id = li.employee_id AND e.tenant_id = li.tenant_id
      WHERE li.tenant_id = :tenant_id AND li.run_id = :rid",
    ['rid' => $runId]
);
if (!$lines) api_error('Run has no line items — compute first', 422);

$linesByEmpNum = [];
foreach ($lines as $l) {
    if (!empty($l['employee_number'])) $linesByEmpNum[(string) $l['employee_number']] = $l;
}

$earningsByLine = [];
$lineIds = array_map(fn ($l) => (int) $l['id'], $lines);
if ($lineIds) {
    $in = implode(',', array_map('intval', $lineIds));
    $pdo = getDB();
    foreach ($pdo->query("SELECT * FROM payroll_earnings WHERE line_item_id IN ($in)") as $r) {
        $earningsByLine[(int) $r['line_item_id']][] = $r;
    }
}

try {
    $gustoPayroll = gustoGetPayroll($conn, $payrollUuid);
} catch (GustoApiException $e) {
    api_error('Gusto fetch payroll failed: ' . $e->getMessage(), 502, [
        'error_key' => $e->errorKey, 'http_code' => $e->httpCode,
    ]);
}

$status = (string) ($gustoPayroll['processing_status'] ?? '');
$gustoComps = is_array($gustoPayroll['employee_compensations'] ?? null)
    ? $gustoPayroll['employee_compensations'] : [];

$report      = [];
$matched     = 0;
$unmatchedG  = [];
$unmatchedC  = [];

// ── Match each Gusto employee to a CoreFlux line and diff ─────────────
$consumedLineIds = [];
foreach ($gustoComps as $empComp) {
    $gustoEmpNum = (string) ($empComp['employee_number']
        ?? ($empComp['employee']['employee_number'] ?? ''));
    $gustoName   = trim(
        ($empComp['employee']['first_name'] ?? '') . ' '
        . ($empComp['employee']['last_name'] ?? '')
    );

    // Compute CURRENT (what's already on the Gusto payroll).
    $currentRegular = 0.0;
    $currentOT      = 0.0;
    $currentFixed   = [];
    $primaryJobId   = null;
    foreach (($empComp['hourly_compensations'] ?? []) as $hc) {
        if ($primaryJobId === null) {
            $primaryJobId = $hc['job_id'] ?? $hc['job_uuid'] ?? null;
        }
        $mult = (float) ($hc['compensation_multiplier'] ?? 1);
        $hrs  = (float) ($hc['hours'] ?? 0);
        if ($mult >= 1.49 && $mult <= 1.51) {
            $currentOT += $hrs;
        } else {
            $currentRegular += $hrs;
        }
    }
    foreach (($empComp['fixed_compensations'] ?? []) as $fc) {
        $currentFixed[] = [
            'name'   => (string) ($fc['name'] ?? ''),
            'amount' => (float) ($fc['amount'] ?? 0),
        ];
    }

    // Compute PROPOSED (what gusto_submit would PUT).
    $line = $linesByEmpNum[$gustoEmpNum] ?? null;
    if (!$line) {
        $unmatchedG[] = [
            'gusto_employee_number' => $gustoEmpNum,
            'name'                  => $gustoName,
            'reason'                => 'No CoreFlux line item with employee_number=' . $gustoEmpNum
                                     . '. Set the same employee_number on People → Identity, OR remove from this Gusto payroll.',
        ];
        $report[] = [
            'gusto_employee_number' => $gustoEmpNum,
            'name'                  => $gustoName,
            'matched'               => false,
            'reason'                => 'unmatched_in_coreflux',
            'current'               => [
                'regular_hours' => $currentRegular,
                'overtime_hours'=> $currentOT,
                'fixed'         => $currentFixed,
            ],
            'proposed' => null,
            'diff'     => [['field' => '_status', 'from' => 'present', 'to' => 'unchanged (no submit)']],
        ];
        continue;
    }

    $consumedLineIds[(int) $line['id']] = true;
    $matched++;

    $proposedRegular = (float) $line['hours_regular'];
    $proposedOT      = (float) $line['hours_overtime'];
    $proposedFixed   = [];
    if ($line['pay_type'] !== 'hourly') {
        $proposedRegular = 0;
        $proposedOT      = 0;
    }
    foreach (($earningsByLine[(int) $line['id']] ?? []) as $earn) {
        $kind  = strtolower((string) ($earn['kind'] ?? ''));
        $cents = (int) ($earn['amount_cents'] ?? 0);
        if ($cents <= 0) continue;
        $name = match (true) {
            in_array($kind, ['bonus', 'spot_bonus', 'signing_bonus'], true) => 'Bonus',
            in_array($kind, ['commission', 'referral'], true)               => 'Commission',
            in_array($kind, ['reimbursement', 'expense'], true)             => 'Reimbursement',
            default                                                          => null,
        };
        if (!$name) continue;
        $proposedFixed[] = ['name' => $name, 'amount' => round($cents / 100, 2)];
    }

    // Build a human-readable diff.
    $diff = [];
    if (abs($currentRegular - $proposedRegular) > 0.001) {
        $diff[] = ['field' => 'regular_hours', 'from' => $currentRegular, 'to' => $proposedRegular];
    }
    if (abs($currentOT - $proposedOT) > 0.001) {
        $diff[] = ['field' => 'overtime_hours', 'from' => $currentOT, 'to' => $proposedOT];
    }
    // Compare fixed comps as multiset by name.
    $curByName = []; foreach ($currentFixed  as $f) $curByName[$f['name']] = ($curByName[$f['name']] ?? 0) + $f['amount'];
    $propByName= []; foreach ($proposedFixed as $f) $propByName[$f['name']]= ($propByName[$f['name']] ?? 0) + $f['amount'];
    foreach (array_unique(array_merge(array_keys($curByName), array_keys($propByName))) as $n) {
        $cur = $curByName[$n]  ?? 0.0;
        $prp = $propByName[$n] ?? 0.0;
        if (abs($cur - $prp) > 0.001) {
            $diff[] = ['field' => "fixed.$n", 'from' => $cur, 'to' => $prp];
        }
    }

    $report[] = [
        'gusto_employee_number' => $gustoEmpNum,
        'gusto_employee_uuid'   => $empComp['employee_uuid'] ?? ($empComp['employee']['uuid'] ?? null),
        'name'                  => $gustoName,
        'matched'               => true,
        'pay_type'              => $line['pay_type'],
        'current'               => [
            'regular_hours' => $currentRegular,
            'overtime_hours'=> $currentOT,
            'fixed'         => $currentFixed,
        ],
        'proposed' => [
            'regular_hours' => $proposedRegular,
            'overtime_hours'=> $proposedOT,
            'fixed'         => $proposedFixed,
            'gross_cents'   => (int) $line['gross_cents'],
        ],
        'diff' => $diff,
    ];
}

// CoreFlux lines that didn't match any Gusto employee_compensation entry.
foreach ($lines as $l) {
    if (isset($consumedLineIds[(int) $l['id']])) continue;
    $unmatchedC[] = [
        'employee_number' => $l['employee_number'] ?? null,
        'name'            => trim(($l['legal_first_name'] ?? '') . ' ' . ($l['legal_last_name'] ?? '')),
        'reason'          => empty($l['employee_number'])
            ? 'CoreFlux employee has no employee_number. Set it under People → Identity, then have Gusto regenerate the payroll.'
            : 'No matching employee in Gusto payroll. Add this employee to the Gusto payroll, OR mark the CoreFlux line as off-cycle.',
    ];
}

api_ok([
    'gusto_payroll' => [
        'uuid'         => $payrollUuid,
        'period_start' => $gustoPayroll['period_start_date'] ?? null,
        'period_end'   => $gustoPayroll['period_end_date']   ?? null,
        'pay_date'     => $gustoPayroll['check_date']        ?? null,
        'status'       => $status,
    ],
    'employees' => $report,
    'unmatched_in_coreflux' => $unmatchedG,
    'unmatched_in_gusto'    => $unmatchedC,
    'summary' => [
        'matched'                  => $matched,
        'total_employees_in_gusto' => count($gustoComps),
        'total_lines_in_coreflux'  => count($lines),
        'safe_to_submit'           => count($unmatchedC) === 0
                                    && count($unmatchedG) === 0
                                    && $status === 'unprocessed',
    ],
]);
