<?php
/**
 * Payroll Phase A1 smoke — Gusto + audit CSV exports + AI anomaly flags.
 *
 * Static asserts only — no DB / network. Verifies that:
 *   - runs.php exposes ?action=export_gusto and ?action=export_run GET routes
 *   - export_gusto emits Gusto's hours-import column set
 *   - export_run emits the full pre-calc audit column set
 *   - both routes set Content-Type text/csv + Content-Disposition attachment
 *   - both routes call payrollAudit() with the right event names
 *   - lib/payroll.php declares payrollAudit()
 *   - manifest declares the two new audit events
 *   - ai_run_summary.php builds context.anomalies (new_hires / terminations /
 *     large_swings / missing_tax_setup) deterministically before calling aiAsk()
 *   - ai_run_summary system prompt instructs the model to surface anomalies
 *   - PayrollRunDetail.jsx surfaces both download buttons after compute
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

echo "Payroll runs.php — CSV export actions\n";
$runs = (string) file_get_contents(__DIR__ . '/../modules/payroll/api/runs.php');
$a('export_gusto action route',                strpos($runs, "'export_gusto'") !== false);
$a('export_run action route',                  strpos($runs, "'export_run'")   !== false);
$a('streams text/csv',                         strpos($runs, 'text/csv') !== false);
$a('emits Content-Disposition attachment',     strpos($runs, 'Content-Disposition: attachment') !== false);
$a('Gusto CSV first_name/last_name cols',      strpos($runs, "'first_name'") !== false && strpos($runs, "'last_name'") !== false);
$a('Gusto CSV regular/overtime hours cols',    strpos($runs, "'regular_hours'") !== false && strpos($runs, "'overtime_hours'") !== false);
$a('Gusto CSV bonus / commission / reimb cols',
    strpos($runs, "'bonus'") !== false &&
    strpos($runs, "'commission'") !== false &&
    strpos($runs, "'reimbursement'") !== false);
$a('Gusto CSV employee_id / hours columns aligned',
    strpos($runs, "'employee_id'") !== false &&
    strpos($runs, "'pto_hours'")  !== false &&
    strpos($runs, "'sick_hours'") !== false &&
    strpos($runs, "'holiday_hours'") !== false);
$a('Audit CSV gross / net / employer_taxes cols',
    strpos($runs, "'gross'")            !== false &&
    strpos($runs, "'net'")              !== false &&
    strpos($runs, "'employer_taxes'")   !== false);
$a('Audit CSV pretax/posttax/taxable',         strpos($runs, "'pretax_deductions'") !== false && strpos($runs, "'posttax_deductions'") !== false && strpos($runs, "'taxable'") !== false);
$a('Gusto export audits payroll.run.exported_gusto', strpos($runs, "payroll.run.exported_gusto") !== false);
$a('Audit CSV export audits payroll.run.exported_csv', strpos($runs, "payroll.run.exported_csv")   !== false);
$a('Run-not-found returns 404',                strpos($runs, "'Run not found', 404") !== false);
$a('id required guard',                        strpos($runs, "'id required', 400") !== false);
$a('Earnings classification covers bonus / commission / reimbursement',
    strpos($runs, "'bonus','spot_bonus','signing_bonus'") !== false &&
    strpos($runs, "'commission','referral'")              !== false &&
    strpos($runs, "'reimbursement','expense'")            !== false);

echo "\nPayroll lib — payrollAudit() helper\n";
$lib = (string) file_get_contents(__DIR__ . '/../modules/payroll/lib/payroll.php');
$a('payrollAudit declared',                    strpos($lib, 'function payrollAudit') !== false);
$a('payrollAudit writes to audit_log',         strpos($lib, 'INSERT INTO audit_log') !== false);
$a('payrollAudit never throws',                strpos($lib, "catch (\\Throwable") !== false);

echo "\nPayroll manifest — Phase A1 audit events\n";
$man = (string) file_get_contents(__DIR__ . '/../modules/payroll/manifest.php');
$a("declares 'payroll.run.exported_gusto'",    strpos($man, "'payroll.run.exported_gusto'") !== false);
$a("declares 'payroll.run.exported_csv'",      strpos($man, "'payroll.run.exported_csv'")   !== false);

echo "\nPayroll ai_run_summary.php — anomaly flags\n";
$ai = (string) file_get_contents(__DIR__ . '/../modules/payroll/api/ai_run_summary.php');
$a('builds anomalies block',                   strpos($ai, "\$anomalies = [") !== false);
$a('anomaly: new_hires key',                   strpos($ai, "'new_hires'") !== false);
$a('anomaly: terminations key',                strpos($ai, "'terminations'") !== false);
$a('anomaly: large_swings key',                strpos($ai, "'large_swings'") !== false);
$a('anomaly: missing_tax_setup key',           strpos($ai, "'missing_tax_setup'") !== false);
$a('large_swings threshold = 25%',             strpos($ai, "abs(\$deltaPct) >= 25.0") !== false);
$a('missing tax setup uses LEFT JOIN tax_federal', strpos($ai, 'LEFT JOIN people_tax_federal tf') !== false);
$a('context exposes anomalies key',            strpos($ai, "'anomalies' => \$anomalies") !== false);
$a('system prompt mentions anomalies',         stripos($ai, 'context.anomalies') !== false);
$a('uses aiAsk + feature_key payroll.run_summary',
    strpos($ai, "aiAsk(") !== false &&
    strpos($ai, "'payroll.run_summary'") !== false);
$a('rejects pre-compute runs',                 strpos($ai, "'Run not yet computed', 409") !== false);

echo "\nPayrollRunDetail.jsx — export buttons\n";
$rd = (string) file_get_contents(__DIR__ . '/../modules/payroll/ui/PayrollRunDetail.jsx');
$a('audit CSV download link',                  strpos($rd, 'payroll-run-export-csv')   !== false);
$a('Gusto CSV download link',                  strpos($rd, 'payroll-run-export-gusto') !== false);
$a('export hrefs use action=export_run',       strpos($rd, 'action=export_run')   !== false);
$a('export hrefs use action=export_gusto',     strpos($rd, 'action=export_gusto') !== false);
$a('exports hidden until compute finished',    strpos($rd, "run.status !== 'draft'") !== false);

echo "\nPayroll runs.php — Gusto sync API\n";
$a('mark_gusto_synced action',                 strpos($runs, "action === 'mark_gusto_synced'") !== false);
$a('mark_gusto_synced requires gusto_run_id',  strpos($runs, "['gusto_run_id']") !== false);
$a('mark_gusto_synced rejects non-http URL',   strpos($runs, "preg_match('#^https?://#i'") !== false);
$a('mark_gusto_synced blocks approver',        strpos($runs, 'Approver cannot submit the same payroll run to Gusto') !== false);
$a('mark_gusto_synced sets status submitted',  strpos($runs, "'gusto_status'      => 'submitted'") !== false);
$a('mark_gusto_synced audits',                 strpos($runs, 'payroll.run.gusto_synced') !== false);
$a('mark_gusto_paid action',                   strpos($runs, "action === 'mark_gusto_paid'") !== false);
$a('mark_gusto_paid requires linked run',      strpos($runs, "Run is not linked to Gusto', 409") !== false);
$a('mark_gusto_paid sets status=paid',         strpos($runs, "'gusto_status' => 'paid'") !== false);
$a('mark_gusto_paid mirrors local status to paid', strpos($runs, "if (\$run['status'] !== 'paid')") !== false);
$a('mark_gusto_paid audits',                   strpos($runs, 'payroll.run.gusto_marked_paid') !== false);
$a('unlink_gusto action',                      strpos($runs, "action === 'unlink_gusto'") !== false);
$a('unlink_gusto clears all gusto fields',
    strpos($runs, "'gusto_run_id'      => null") !== false &&
    strpos($runs, "'gusto_payroll_url' => null") !== false &&
    strpos($runs, "'gusto_status'      => null") !== false);
$a('unlink_gusto audits',                      strpos($runs, 'payroll.run.gusto_unlinked') !== false);

echo "\nPayroll lib — Gusto-managed helper\n";
$a('payrollRunIsGustoManaged declared',        strpos($lib, 'function payrollRunIsGustoManaged') !== false);

echo "\nPayroll migration 002_gusto_sync.sql\n";
$mig = __DIR__ . '/../modules/payroll/migrations/002_gusto_sync.sql';
$a('migration file exists', file_exists($mig));
$migC = (string) file_get_contents($mig);
$a('adds gusto_run_id column',                 strpos($migC, 'ADD COLUMN gusto_run_id') !== false);
$a('adds gusto_payroll_url column',            strpos($migC, 'ADD COLUMN gusto_payroll_url') !== false);
$a('adds gusto_status enum',                   strpos($migC, "ENUM('linked','submitted','paid','voided')") !== false);
$a('adds gusto_synced_at + gusto_synced_by',
    strpos($migC, 'ADD COLUMN gusto_synced_at') !== false &&
    strpos($migC, 'ADD COLUMN gusto_synced_by') !== false);
$a('adds gusto_paid_at column',                strpos($migC, 'ADD COLUMN gusto_paid_at') !== false);
$a('adds tenant + gusto_run_id index',         strpos($migC, 'idx_run_tenant_gusto') !== false);

echo "\nPayroll manifest — Gusto-sync audit events\n";
$a("declares 'payroll.run.gusto_synced'",       strpos($man, "'payroll.run.gusto_synced'") !== false);
$a("declares 'payroll.run.gusto_marked_paid'",  strpos($man, "'payroll.run.gusto_marked_paid'") !== false);
$a("declares 'payroll.run.gusto_unlinked'",     strpos($man, "'payroll.run.gusto_unlinked'") !== false);

echo "\nPayrollRunDetail.jsx — Gusto sync panel\n";
$a('GustoSyncPanel component',                 strpos($rd, 'function GustoSyncPanel') !== false);
$a('panel testid',                             strpos($rd, 'payroll-run-gusto-panel') !== false);
$a('Gusto run ID input',                       strpos($rd, 'payroll-run-gusto-id-input') !== false);
$a('Gusto URL input',                          strpos($rd, 'payroll-run-gusto-url-input') !== false);
$a('Mark synced button',                       strpos($rd, 'payroll-run-gusto-link-btn') !== false);
$a('Mark paid button',                         strpos($rd, 'payroll-run-gusto-mark-paid-btn') !== false);
$a('Unlink button',                            strpos($rd, 'payroll-run-gusto-unlink-btn') !== false);
$a('status pill submitted',                    strpos($rd, 'payroll-run-gusto-status-submitted') !== false);
$a('status pill paid',                         strpos($rd, 'payroll-run-gusto-status-paid') !== false);
$a('Mark paid action sent',                    strpos($rd, "post('mark_gusto_paid')") !== false);
$a('Unlink action sent',                       strpos($rd, "post('unlink_gusto')") !== false);
$a('Mark synced action sent',                  strpos($rd, "post('mark_gusto_synced'") !== false);
$a('confirms Mark paid suppresses duplicate post',
    strpos($rd, 'skip the duplicate cash-leg GL post') !== false);

echo PHP_EOL . "Total: $pass passed, $fail failed" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
