<?php
/**
 * Payroll — Submit a computed run to Gusto via OAuth API.
 *
 *   POST { run_id }                    → list_unprocessed: pull candidate Gusto payrolls
 *   POST { run_id, gusto_payroll_uuid }→ submit: PUT compensations → /calculate → /submit
 *
 * Replaces the manual CSV-paste loop for tenants that have connected via
 * OAuth. Uses CoreFlux's deterministic compute as the source of truth —
 * AI never participates in the calc path.
 *
 * Mapping rules (CoreFlux → Gusto employee_compensations):
 *   - Match employees by employee_number (Gusto stores it on the employee row)
 *   - Distribute hours_regular  → "Regular Hours" on the employee's primary job
 *   - Distribute hours_overtime → "Overtime"      on the employee's primary job
 *   - Bonus / commission / reimbursement earnings → fixed_compensations
 *   - Salaried employees: skip hourly_compensations (Gusto computes from base salary)
 *
 * Two-eye: requires payroll.run.disburse. Run must be approved + not yet
 * submitted to Gusto.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/gusto_service.php';
require_once __DIR__ . '/../lib/payroll.php';

$ctx = api_require_auth();
rbac_legacy_require($ctx['user'], 'payroll.run.disburse');
if (api_method() !== 'POST') api_error('Method not allowed', 405);

$body  = api_json_body();
api_require_fields($body, ['run_id']);
$runId = (int) $body['run_id'];

$run = scopedFind(
    'SELECT r.*, pp.period_start, pp.period_end, pp.pay_date, pp.cycle_id
     FROM payroll_runs r
     JOIN payroll_pay_periods pp ON pp.id = r.pay_period_id AND pp.tenant_id = r.tenant_id
     WHERE r.tenant_id = :tenant_id AND r.id = :id',
    ['id' => $runId]
);
if (!$run) api_error('Run not found', 404);
if (!in_array($run['status'], ['approved', 'paid'], true)) {
    api_error('Run must be approved before submitting to Gusto (current: ' . $run['status'] . ')', 409);
}

$conn = gustoActiveConnection((int) $ctx['tenant_id']);
if (!$conn) api_error('No active Gusto connection. Connect Gusto in Payroll Settings first.', 412);

// ---------------------------------------------------------------- Discovery action
$action = (string) ($body['action'] ?? 'submit');
if ($action === 'list_unprocessed') {
    try {
        $resp = gustoListUnprocessedPayrolls(
            $conn,
            (string) ($run['period_start'] ?? null) ?: null,
            (string) ($run['period_end']   ?? null) ?: null
        );
        api_ok([
            'payrolls' => array_map(fn($p) => [
                'uuid'         => $p['uuid']         ?? null,
                'period_start' => $p['period_start_date'] ?? null,
                'period_end'   => $p['period_end_date']   ?? null,
                'pay_date'     => $p['check_date']    ?? null,
                'status'       => $p['processing_status'] ?? 'unprocessed',
            ], is_array($resp) ? $resp : []),
        ]);
    } catch (GustoApiException $e) {
        api_error('Gusto list payrolls failed: ' . $e->getMessage(), 502, [
            'error_key'      => $e->errorKey,
            'error_category' => $e->errorCategory,
            'http_code'      => $e->httpCode,
        ]);
    }
}

// ---------------------------------------------------------------- Submit action
api_require_fields($body, ['gusto_payroll_uuid']);
$payrollUuid = trim((string) $body['gusto_payroll_uuid']);
if ($payrollUuid === '') api_error('gusto_payroll_uuid required', 422);
if (!empty($run['gusto_payroll_uuid']) && $run['gusto_payroll_uuid'] !== $payrollUuid) {
    api_error('Run is already linked to a different Gusto payroll: ' . $run['gusto_payroll_uuid'], 409);
}

// Pull our line items + employee numbers (the cross-system join key).
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

// Pre-fetch earnings for each line so we can route bonus/commission/reimbursement
// into Gusto fixed_compensations.
$earningsByLine = [];
$lineIds = array_map(fn($l) => (int) $l['id'], $lines);
if ($lineIds) {
    $in = implode(',', array_map('intval', $lineIds));
    $pdo = getDB();
    foreach ($pdo->query("SELECT * FROM payroll_earnings WHERE line_item_id IN ($in)") as $r) {
        $earningsByLine[(int) $r['line_item_id']][] = $r;
    }
}

try {
    $payroll = gustoGetPayroll($conn, $payrollUuid);
} catch (GustoApiException $e) {
    api_error('Gusto fetch payroll failed: ' . $e->getMessage(), 502, [
        'error_key' => $e->errorKey, 'http_code' => $e->httpCode,
    ]);
}

if (($payroll['processing_status'] ?? '') !== 'unprocessed') {
    api_error('Gusto payroll is not in unprocessed state (current: ' . ($payroll['processing_status'] ?? '?') . ')', 409);
}

// Build the employee_compensations payload by matching Gusto's employees
// (by employee_number) to our line items.
$compensations = [];
$matched = 0; $skipped = [];
foreach (($payroll['employee_compensations'] ?? []) as $empComp) {
    $gustoEmpNum = (string) ($empComp['employee_number'] ?? ($empComp['employee']['employee_number'] ?? ''));
    if ($gustoEmpNum === '' || !isset($linesByEmpNum[$gustoEmpNum])) {
        $skipped[] = ['gusto_employee_uuid' => $empComp['employee_uuid'] ?? null,
                      'gusto_employee_number' => $gustoEmpNum,
                      'reason' => 'no_matching_coreflux_employee'];
        continue;
    }
    $line = $linesByEmpNum[$gustoEmpNum];

    // Hourly compensations — find primary job and stamp regular + overtime hours.
    $hourly = [];
    $primaryJobId = null;
    foreach (($empComp['hourly_compensations'] ?? []) as $hc) {
        if ($primaryJobId === null) $primaryJobId = $hc['job_id'] ?? $hc['job_uuid'] ?? null;
    }
    if ($line['pay_type'] === 'hourly' && $primaryJobId !== null) {
        $hourly[] = [
            'job_uuid' => $primaryJobId,
            'compensation_multiplier' => 1,
            'hours' => number_format((float) $line['hours_regular'], 2, '.', ''),
        ];
        if ((float) $line['hours_overtime'] > 0) {
            $hourly[] = [
                'job_uuid' => $primaryJobId,
                'compensation_multiplier' => 1.5,
                'hours' => number_format((float) $line['hours_overtime'], 2, '.', ''),
            ];
        }
    }

    // Fixed comps — bonuses / commissions / reimbursements only.
    $fixed = [];
    foreach (($earningsByLine[(int) $line['id']] ?? []) as $earn) {
        $kind = strtolower((string) ($earn['kind'] ?? ''));
        $cents = (int) ($earn['amount_cents'] ?? 0);
        if ($cents <= 0) continue;
        $name = match (true) {
            in_array($kind, ['bonus','spot_bonus','signing_bonus'], true) => 'Bonus',
            in_array($kind, ['commission','referral'], true)              => 'Commission',
            in_array($kind, ['reimbursement','expense'], true)            => 'Reimbursement',
            default => null,
        };
        if (!$name) continue;
        $fixed[] = [
            'job_uuid' => $primaryJobId,
            'name'     => $name,
            'amount'   => number_format($cents / 100, 2, '.', ''),
        ];
    }

    $compensations[] = [
        'employee_uuid'         => $empComp['employee_uuid'],
        'hourly_compensations'  => $hourly,
        'fixed_compensations'   => $fixed,
        'paid_time_off'         => [],
    ];
    $matched++;
}

if (!$matched) {
    api_error('No employees matched between CoreFlux and Gusto. Verify employee_number values match in both systems.', 422,
        ['skipped' => $skipped]);
}

$version = (int) ($payroll['version'] ?? 0);
if ($version <= 0) api_error('Gusto payroll missing version field', 502);

// Mark the run as in-flight before the network calls.
scopedUpdate('payroll_runs', $runId, [
    'gusto_payroll_uuid'        => $payrollUuid,
    'gusto_submission_status'   => 'submitting',
    'gusto_submitted_at'        => date('Y-m-d H:i:s'),
    'gusto_submitted_by_user_id'=> (int) ($ctx['user']['id'] ?? 0),
    'gusto_submission_error'    => null,
]);

try {
    $updated = gustoUpdatePayrollCompensations($conn, $payrollUuid, $version, $compensations);
    $version = (int) ($updated['version'] ?? $version);

    $calced  = gustoCalculatePayroll($conn, $payrollUuid, $version);
    $version = (int) ($calced['version'] ?? $version);

    $submitted = gustoSubmitPayroll($conn, $payrollUuid, $version);

    $finalStatus = (string) ($submitted['processing_status'] ?? 'submitted');
    scopedUpdate('payroll_runs', $runId, [
        'gusto_run_id'             => (string) ($submitted['payroll_uuid'] ?? $payrollUuid),
        'gusto_payroll_uuid'       => $payrollUuid,
        'gusto_submission_status'  => $finalStatus,
        'gusto_status'             => 'submitted',
        'gusto_synced_at'          => date('Y-m-d H:i:s'),
        'gusto_synced_by'          => (int) ($ctx['user']['id'] ?? 0),
    ]);
    payrollAudit('payroll.gusto.run_submitted', [
        'run_id' => $runId, 'payroll_uuid' => $payrollUuid,
        'matched' => $matched, 'skipped_count' => count($skipped),
        'final_status' => $finalStatus,
    ], $runId);
    gustoAudit('payroll.gusto.run_submitted',
        ['run_id' => $runId, 'payroll_uuid' => $payrollUuid, 'final_status' => $finalStatus], $runId);

    // touch last_used_at
    getDB()->prepare('UPDATE tenant_gusto_connections SET last_used_at = NOW() WHERE id = :id')
        ->execute(['id' => (int) $conn['id']]);

    api_ok([
        'ok' => true,
        'gusto_payroll_uuid' => $payrollUuid,
        'submission_status' => $finalStatus,
        'matched_employees' => $matched,
        'skipped' => $skipped,
    ]);
} catch (GustoApiException $e) {
    scopedUpdate('payroll_runs', $runId, [
        'gusto_submission_status' => 'error',
        'gusto_submission_error'  => substr($e->getMessage(), 0, 480),
    ]);
    payrollAudit('payroll.gusto.run_submission_failed', [
        'run_id' => $runId, 'payroll_uuid' => $payrollUuid,
        'error_key' => $e->errorKey, 'error_category' => $e->errorCategory,
        'http_code' => $e->httpCode, 'message' => $e->getMessage(),
    ], $runId);
    api_error('Gusto submission failed: ' . $e->getMessage(), 502, [
        'error_key' => $e->errorKey, 'error_category' => $e->errorCategory,
        'http_code' => $e->httpCode, 'matched' => $matched, 'skipped' => $skipped,
    ]);
}
