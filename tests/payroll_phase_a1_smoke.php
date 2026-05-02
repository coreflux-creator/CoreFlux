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

echo PHP_EOL . "Total: $pass passed, $fail failed" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
