<?php
/**
 * Smoke: CoreStaffing Phase 2 Wave 2.
 *
 * Pins:
 *   • Per-client AR payment terms override.
 *   • Per-vendor AP payment terms override + companies.payment_terms_days col.
 *   • Payroll & Billing Readiness queues (API + UI).
 *   • AI weekly memo endpoint (uses aiAsk).
 *   • staffing.worker_hours.approved accounting event emission.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "Per-client AR payment terms\n";
$inv = $read(__DIR__ . '/../modules/billing/api/invoices.php');
$a('AR reads staffing_clients.payment_terms_days',  str_contains($inv, "FROM staffing_clients") && str_contains($inv, 'payment_terms_days IS NOT NULL'));
$a('AR overrides netDays per-client',               str_contains($inv, '$netDays = (int) $perClient'));
$a('AR tolerates missing staffing_clients table',   str_contains($inv, '/* staffing_clients may not exist'));

echo "\nPer-vendor AP payment terms\n";
$bill = $read(__DIR__ . '/../modules/ap/api/bills.php');
$a('AP reads companies.payment_terms_days',         str_contains($bill, "FROM companies WHERE tenant_id = :t AND id = :id"));
$a('AP overrides netDays per-vendor',               str_contains($bill, '$netDays = (int) $perVendor'));
$a('AP tries vendor_company_id first',              str_contains($bill, "WHERE tenant_id = :t AND id = :id AND payment_terms_days"));
$a('AP falls back to vendor name match',            str_contains($bill, "WHERE tenant_id = :t AND name = :n AND payment_terms_days"));
$ptmig = $read(__DIR__ . '/../modules/people/migrations/005_companies_payment_terms.sql');
$a('migration adds companies.payment_terms_days',   str_contains($ptmig, 'ADD COLUMN payment_terms_days INT NULL'));

echo "\nReadiness queues\n";
$ready = $read(__DIR__ . '/../modules/staffing/api/readiness.php');
$manifest = $read(__DIR__ . '/../modules/staffing/manifest.php');
$a('payroll action groups by person',               str_contains($ready, "action === 'payroll'") && str_contains($ready, '$byPerson'));
$a('billing action groups by client',               str_contains($ready, "action === 'billing'") && str_contains($ready, "GROUP BY pl.client_id"));
$a('billing tolerates missing v_timesheet_day_fin', str_contains($ready, "LEFT JOIN v_timesheet_day_fin v") && str_contains($ready, '$rows = scopedQuery'));
$a('readiness GETs gated by staffing permissions',  str_contains($ready, "staffing.payroll.view") && str_contains($ready, "staffing.billing.view"));
$a('readiness POSTs gated by manage permissions',  str_contains($ready, "staffing.payroll.manage") && str_contains($ready, "staffing.billing.manage"));
$a('mark_payroll_pushed flips status=payroll_ready',str_contains($ready, "mark_payroll_pushed") && str_contains($ready, "payroll_ready"));
$a('mark_billing_invoiced flips status=billing_ready', str_contains($ready, "mark_billing_invoiced") && str_contains($ready, "billing_ready"));
$a('readiness status flips audited',                str_contains($ready, 'staffingReadinessAudit(') && str_contains($ready, 'staffing.readiness.payroll_marked') && str_contains($ready, 'staffing.readiness.billing_marked'));
$a('manifest declares readiness audit events',      str_contains($manifest, 'staffing.readiness.payroll_marked') && str_contains($manifest, 'staffing.readiness.billing_marked'));
$readyUi = $read(__DIR__ . '/../modules/staffing/ui/StaffingReadiness.jsx');
$a('UI supports payroll mode',                      str_contains($readyUi, 'data-testid={`staffing-readiness-${mode}`}'));
$a('UI multi-select + bulk mark',                   str_contains($readyUi, 'data-testid={`staffing-readiness-${mode}-select-all`}'));

$sm = $read(__DIR__ . '/../modules/staffing/ui/StaffingModule.jsx');
$a('routes payroll-readiness',                      str_contains($sm, '<StaffingReadiness mode="payroll"'));
$a('routes billing-readiness',                      str_contains($sm, '<StaffingReadiness mode="billing"'));

echo "\nAI weekly memo\n";
$ai = $read(__DIR__ . '/../modules/staffing/api/ai_insights.php');
$a('uses aiAsk() from core',                        str_contains($ai, "require_once __DIR__ . '/../../../core/ai_service.php'") && str_contains($ai, 'aiAsk('));
$a('feature_class=narrative, feature_key=staffing.weekly_memo', str_contains($ai, "'feature_key'   => 'staffing.weekly_memo'"));
$a('builds context from v_timesheet_day_fin',       str_contains($ai, 'FROM v_timesheet_day_fin v'));
$a('5-bullet system prompt',                        str_contains($ai, 'Output exactly 5 bullets'));
$a('graceful AIDisabledException response',         str_contains($ai, 'AIDisabledException'));

$ov = $read(__DIR__ . '/../modules/staffing/ui/StaffingOverview.jsx');
$a('overview renders WeeklyMemoCard',               str_contains($ov, '<WeeklyMemoCard />'));
$a('generate button + content/error testids',       str_contains($ov, 'data-testid="staffing-memo-generate"') && str_contains($ov, 'data-testid="staffing-memo-content"'));

echo "\nAccounting event emission\n";
$lib = $read(__DIR__ . '/../modules/staffing/lib/timesheets.php');
$a('staffingEmitWorkerHoursApprovedEvent defined',  str_contains($lib, 'function staffingEmitWorkerHoursApprovedEvent'));
$a('called from staffingTimesheetApprove',          preg_match('/staffingEmitWorkerHoursApprovedEvent\(currentTenantId\(\), \$headerId\)/', $lib) === 1);
$a('uses accountingProcessEvent',                   str_contains($lib, 'accountingProcessEvent($tenantId,'));
$a('event_type = staffing.worker_hours.approved',   str_contains($lib, "'event_type'       => 'staffing.worker_hours.approved'"));
$a('payload includes revenue + cost + gp',          str_contains($lib, "'revenue'") && str_contains($lib, "'cost'") && str_contains($lib, "'gross_profit'"));
$a('failure is non-blocking (best-effort)',         str_contains($lib, 'error_log("[staffing] accounting event emit failed'));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
