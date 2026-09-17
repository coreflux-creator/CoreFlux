<?php
/**
 * /app/modules/payroll/api/import_csv.php
 *
 * Upload a payroll register CSV and create one `payroll_runs` row
 * with `payroll_line_items` for every employee row. Result is a
 * `status='computed'` run, ready for the existing approval flow
 * (no GL post, no payment dispatch — those stay on the existing
 * approve/pay rails).
 *
 *   POST /api/payroll/import_csv.php
 *     Content-Type: multipart/form-data
 *     pay_period_id (text, int)
 *     run_type      (text, optional — defaults to 'regular')
 *     file          (file)
 *
 *   → 200 { ok, run_id, rows_seen, rows_inserted, rows_skipped, totals, errors[] }
 *
 * RBAC: payroll.run.compute.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/csv_import.php';
require_once __DIR__ . '/../lib/payroll.php';
require_once __DIR__ . '/../lib/workflow.php';
require_once __DIR__ . '/../lib/anomalies.php';

$ctx = api_require_auth();
$tid = (int) $ctx['tenant_id'];
rbac_legacy_require($ctx['user'], 'payroll.run.compute');

if (api_method() === 'GET' && (string) (api_query('action') ?? '') === 'template') {
    $payPeriodId = (int) (api_query('pay_period_id') ?? 0);
    if ($payPeriodId <= 0) api_error('pay_period_id is required', 400);
    $pdo = getDB();
    $periodStmt = $pdo->prepare(
        'SELECT pp.*, ps.frequency
           FROM payroll_pay_periods pp
           JOIN payroll_pay_schedules ps ON ps.id = pp.schedule_id AND ps.tenant_id = pp.tenant_id
          WHERE pp.tenant_id = :tenant_id AND pp.id = :id LIMIT 1'
    );
    $periodStmt->execute(['tenant_id' => $tid, 'id' => $payPeriodId]);
    $period = $periodStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$period) api_error('Pay period not found', 404);

    $employeeStmt = $pdo->prepare(
        'SELECT e.id AS employee_id, e.employee_number, e.work_email,
                e.legal_first_name, e.legal_last_name,
                p.cycle_id, p.work_state, p.payment_method, p.default_hours_per_period
           FROM payroll_profiles p
           JOIN people_employees e ON e.id = p.employee_id AND e.tenant_id = p.tenant_id
          WHERE p.tenant_id = :tenant_id AND p.schedule_id = :schedule_id
            AND p.enabled = 1 AND e.status IN ("active", "on_leave", "terminated")
          ORDER BY e.legal_last_name, e.legal_first_name'
    );
    $employeeStmt->execute(['tenant_id' => $tid, 'schedule_id' => (int) $period['schedule_id']]);
    $employees = $employeeStmt->fetchAll(PDO::FETCH_ASSOC);
    $activeCycleCountStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM payroll_pay_cycles
          WHERE tenant_id = :tenant_id AND schedule_id = :schedule_id AND active = 1'
    );
    $activeCycleCountStmt->execute(['tenant_id' => $tid, 'schedule_id' => (int) $period['schedule_id']]);
    $activeCycleCount = (int) $activeCycleCountStmt->fetchColumn();

    $compStmt = $pdo->prepare(
        'SELECT pay_type, pay_rate_cents, pay_frequency
           FROM people_compensation
          WHERE tenant_id = :tenant_id AND employee_id = :employee_id
            AND effective_from <= :period_end
            AND (effective_to IS NULL OR effective_to >= :period_start)
          ORDER BY effective_from DESC, id DESC LIMIT 1'
    );
    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Cache-Control: no-store');
        header('Content-Disposition: attachment; filename="payroll-register-period-' . $payPeriodId . '.csv"');
    }
    $out = fopen('php://output', 'wb');
    fputcsv($out, [
        'employee_id', 'employee_number', 'employee_email', 'employee_name',
        'work_state', 'payment_method', 'pay_type', 'pay_rate', 'pay_frequency',
        'hours_regular', 'hours_overtime', 'gross_pay', 'employee_taxes',
        'pretax_deductions', 'posttax_deductions', 'net_pay', 'employer_taxes',
    ]);
    $periodCycleId = (int) ($period['cycle_id'] ?? 0);
    foreach ($employees as $employee) {
        $profileCycleId = (int) ($employee['cycle_id'] ?? 0);
        if ($periodCycleId > 0 && $profileCycleId !== $periodCycleId
            && !($profileCycleId === 0 && $activeCycleCount === 1)) continue;
        $compStmt->execute([
            'tenant_id' => $tid,
            'employee_id' => (int) $employee['employee_id'],
            'period_end' => (string) $period['period_end'],
            'period_start' => (string) $period['period_start'],
        ]);
        $comp = $compStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        fputcsv($out, [
            $employee['employee_id'],
            $employee['employee_number'],
            $employee['work_email'],
            trim((string) $employee['legal_first_name'] . ' ' . (string) $employee['legal_last_name']),
            $employee['work_state'],
            $employee['payment_method'],
            $comp['pay_type'] ?? '',
            isset($comp['pay_rate_cents']) ? number_format(((int) $comp['pay_rate_cents']) / 100, 2, '.', '') : '',
            $comp['pay_frequency'] ?? ($period['frequency'] ?? ''),
            $employee['default_hours_per_period'] ?? '',
            '', '', '', '', '', '', '',
        ]);
    }
    fclose($out);
    payrollAudit('payroll.run.exported_csv', [
        'source' => 'register_template',
        'pay_period_id' => $payPeriodId,
    ], $payPeriodId, ['object_type' => 'payroll_period']);
    exit;
}

if (api_method() !== 'POST') api_error('POST required', 405);

$payPeriodId = (int) ($_POST['pay_period_id'] ?? 0);
if ($payPeriodId <= 0) api_error('pay_period_id (int) is required', 400);

$runType = trim((string) ($_POST['run_type'] ?? 'regular'));
if (!in_array($runType, ['regular', 'off_cycle', 'correction', 'final'], true)) {
    api_error("run_type must be one of regular / off_cycle / correction / final", 400);
}

if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    api_error('csv file upload missing or failed (error code ' . (int) ($_FILES['file']['error'] ?? -1) . ')', 400);
}
$tmp = (string) ($_FILES['file']['tmp_name'] ?? '');
if ($tmp === '' || !is_readable($tmp)) api_error('uploaded csv not readable on server', 500);
$size = (int) ($_FILES['file']['size'] ?? 0);
if ($size > 25 * 1024 * 1024) api_error('csv too large — split into chunks under 25 MB', 413);

$pdo = getDB();
$actorUserId = (int) ($ctx['user']['id'] ?? 0) ?: null;
$summary = payrollImportRunCsv($pdo, $tid, $payPeriodId, $tmp, $runType, $actorUserId);
if ($summary['run_id'] === null) {
    $details = array_slice((array) ($summary['errors'] ?? []), 0, 3);
    api_error(
        'Payroll register was not imported. ' . ($details ? implode(' ', $details) : 'Review the file and try again.'),
        422,
        [
            'errors' => $summary['errors'],
            'warnings' => $summary['warnings'] ?? [],
            'rows_seen' => (int) $summary['rows_seen'],
            'rows_skipped' => (int) $summary['rows_skipped'],
        ]
    );
}

$runId = (int) $summary['run_id'];
$workflowInstanceId = payrollRunWorkflowStart($tid, $runId, $actorUserId);
if (!$workflowInstanceId) {
    $summary['warnings'][] = 'The run was imported, but the approval workflow could not be started yet. Opening Approve will retry it.';
}
try {
    payrollAnomaliesDetect($runId, false);
} catch (Throwable $e) {
    error_log('[payroll.csv_import] anomaly detection skipped: ' . $e->getMessage());
}
payrollAudit('payroll.run.built', [
    'run_id' => $runId,
    'pay_period_id' => $payPeriodId,
    'run_type' => $runType,
    'source' => 'csv_import',
    'rows' => (int) $summary['rows_inserted'],
    'reused_draft' => (bool) ($summary['reused_draft'] ?? false),
    'computed_by_user_id' => $actorUserId,
    'workflow_instance_id' => $workflowInstanceId,
], $runId);

api_ok([
    'ok'             => true,
    'run_id'         => $summary['run_id'],
    'rows_seen'      => (int) $summary['rows_seen'],
    'rows_inserted'  => (int) $summary['rows_inserted'],
    'rows_skipped'   => (int) $summary['rows_skipped'],
    'totals'         => $summary['totals'],
    'errors'         => $summary['errors'],
    'warnings'       => $summary['warnings'] ?? [],
    'reused_draft'   => (bool) ($summary['reused_draft'] ?? false),
    'workflow_instance_id' => $workflowInstanceId,
]);
