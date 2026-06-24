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
require_once __DIR__ . '/../lib/workflow.php';

$ctx = api_require_auth();
$tid = (int) $ctx['tenant_id'];
rbac_legacy_require($ctx['user'], 'payroll.run.compute');
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
$summary = payrollImportRunCsv($pdo, $tid, $payPeriodId, $tmp, $runType);
$workflowInstanceId = null;
if (!empty($summary['run_id'])) {
    scopedUpdate('payroll_runs', (int) $summary['run_id'], [
        'created_by_user_id' => $ctx['user']['id'] ?? null,
        'computed_by_user_id' => $ctx['user']['id'] ?? null,
    ]);
    $workflowInstanceId = payrollRunWorkflowStart($tid, (int) $summary['run_id'], (int) ($ctx['user']['id'] ?? 0));
    if (!$workflowInstanceId) {
        api_error('Could not start payroll approval workflow for imported run', 503, [
            'run_id' => $summary['run_id'],
        ]);
    }
}

api_ok([
    'ok'             => $summary['run_id'] !== null,
    'run_id'         => $summary['run_id'],
    'workflow_instance_id' => $workflowInstanceId,
    'rows_seen'      => (int) $summary['rows_seen'],
    'rows_inserted'  => (int) $summary['rows_inserted'],
    'rows_skipped'   => (int) $summary['rows_skipped'],
    'totals'         => $summary['totals'],
    'errors'         => $summary['errors'],
]);
