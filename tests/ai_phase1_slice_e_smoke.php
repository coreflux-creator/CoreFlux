<?php
/**
 * Smoke — Slice E: AP Invoice Review + Cash Forecast + Timesheet
 * Anomaly Detection + PayrollReviewPacket (2026-02).
 *
 * Locks the Phase 5 finish work from the AI-Native Extension spec
 * (and the final slice in the A→E user-committed sequence):
 *
 *   - Migration 108 — ap_invoice_extraction_runs + cash_forecast_runs
 *   - core/ai/ap_extraction.php     — extraction + dup-check + draft bill
 *   - core/ai/cash_forecast.php     — 13-week heuristic forecast
 *   - core/ai/timesheet_anomaly.php — rule-based weekly anomaly scan
 *   - core/ai/tool_gateway.php      — 5 new tools registered
 *   - /api/ai/forecasts.php         — list / detail / run endpoints
 *   - /api/ai/payroll_review.php    — weekly packet endpoint
 *   - dashboard CashForecastReview.jsx mounted at /modules/accounting/cash-forecast
 *   - dashboard PayrollReviewPacket.jsx mounted at /admin/ai/payroll-review
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ──────────────────────────────────────────────────────────────────────
// 1) Migration 108 — both new tables.
// ──────────────────────────────────────────────────────────────────────
echo "\n── Migration 108 ──\n";
$mig = (string) file_get_contents($ROOT . '/core/migrations/108_ap_invoice_extractions_and_cash_forecast.sql');
$a('migration file exists',                                   $mig !== '');
$a('CREATE ap_invoice_extraction_runs',                       $c($mig, 'CREATE TABLE IF NOT EXISTS ap_invoice_extraction_runs'));
$a('CREATE cash_forecast_runs',                               $c($mig, 'CREATE TABLE IF NOT EXISTS cash_forecast_runs'));
$a('ap_invoice status enum covers lifecycle',
    $c($mig, "ENUM('pending','extracted','duplicate','drafted','posted','failed')"));
$a('ap_invoice duplicate_check_status enum',
    $c($mig, "ENUM('not_run','no_match','match_exact','match_likely')"));
$a('ap_invoice links to artifact_objects (CHAR(36))',         $c($mig, 'source_artifact_id     CHAR(36) NULL'));
$a('ap_invoice links to ai_tool_invocations',                 $c($mig, 'ai_run_id              CHAR(36) NULL'));
$a('ap_invoice stores extracted_payload_json',                $c($mig, 'extracted_payload_json LONGTEXT NULL'));
$a('cash_forecast tracks weeks_count + starting_at',
    $c($mig, 'weeks_count') && $c($mig, 'starting_at'));
$a('cash_forecast stores starting/ending/min balances (cents)',
    $c($mig, 'starting_balance_cents') && $c($mig, 'ending_balance_cents')
    && $c($mig, 'min_week_balance_cents'));
$a('cash_forecast persists forecast_payload_json',            $c($mig, 'forecast_payload_json    LONGTEXT NOT NULL'));
$a('cash_forecast links to artifact_id',                      $c($mig, 'artifact_id              CHAR(36) NULL'));

// ──────────────────────────────────────────────────────────────────────
// 2) core/ai/ap_extraction.php
// ──────────────────────────────────────────────────────────────────────
echo "\n── core/ai/ap_extraction.php ──\n";
$ap = (string) file_get_contents($ROOT . '/core/ai/ap_extraction.php');
$a('apNormalizeVendorName defined',                           $c($ap, 'function apNormalizeVendorName(string $s): string'));
$a('apExtractionCreate defined',                              $c($ap, 'function apExtractionCreate(int $tenantId, array $opts): array'));
$a('apExtractionRecordPayload defined',                       $c($ap, 'function apExtractionRecordPayload(int $tenantId, int $runId, array $payload, ?float $confidence = null, ?string $aiRunId = null): array'));
$a('apExtractionCheckDuplicate defined',                      $c($ap, 'function apExtractionCheckDuplicate(int $tenantId, int $runId): array'));
$a('apExtractionDraftBill defined',                           $c($ap, 'function apExtractionDraftBill(int $tenantId, int $runId, ?int $actorUserId = null): array'));
$a('apExtractionGet defined',                                 $c($ap, 'function apExtractionGet(int $tenantId, int $runId): ?array'));
$a('apExtractionList defined',                                $c($ap, 'function apExtractionList(int $tenantId, array $filters = []): array'));
$a('duplicate check: exact match (vendor+bill_number)',       $c($ap, "= 'match_exact'") && $c($ap, 'TRIM(bill_number) = :b'));
$a('duplicate check: likely match (vendor+date+total)',       $c($ap, "= 'match_likely'") && $c($ap, 'ABS(total - :amt) < 0.01'));
$a('duplicate check updates run.duplicate_check_status',      $c($ap, 'duplicate_check_status = :ds'));
$a('draft bill refuses when run flagged as duplicate',        $c($ap, "is flagged duplicate of bill"));
$a('draft bill is idempotent on re-entry',                    $c($ap, 'idempotent_replay'));
$a('draft bill inserts ap_bills with status=inbox',           $c($ap, "status, source, source_ref_id") && $c($ap, '"inbox", "manual"'));

$lint = []; exec('php -l ' . escapeshellarg($ROOT . '/core/ai/ap_extraction.php') . ' 2>&1', $lint, $rc);
$a('ap_extraction.php passes php -l',                         $rc === 0);

// Pure-function probe.
require_once $ROOT . '/core/ai/ap_extraction.php';
$a('apNormalizeVendorName: "ACME Co." == "acme  co"',
    apNormalizeVendorName('ACME Co.') === apNormalizeVendorName('acme  co'));
$a('apNormalizeVendorName: trailing comma stripped',
    apNormalizeVendorName('ACME Co,') === 'ACME CO');

// ──────────────────────────────────────────────────────────────────────
// 3) core/ai/cash_forecast.php
// ──────────────────────────────────────────────────────────────────────
echo "\n── core/ai/cash_forecast.php ──\n";
$cf = (string) file_get_contents($ROOT . '/core/ai/cash_forecast.php');
$treasuryManifest = (string) file_get_contents($ROOT . '/modules/treasury/manifest.php');
$a('cashForecastRun defined',                                 $c($cf, 'function cashForecastRun(int $tenantId, array $opts = []): array'));
$a('cashForecastGet defined',                                 $c($cf, 'function cashForecastGet(int $tenantId, int $forecastId): ?array'));
$a('cashForecastList defined',                                $c($cf, 'function cashForecastList(int $tenantId, array $filters = []): array'));
$a('CASH_FORECAST_DEFAULT_WEEKS = 13',                        $c($cf, 'const CASH_FORECAST_DEFAULT_WEEKS = 13'));
$a('weeks_count clamped 1..52',                               $c($cf, 'max(1, min(52, (int) ($opts[\'weeks\'] ?? CASH_FORECAST_DEFAULT_WEEKS)))'));
$a('starting_at format validated',                            $c($cf, "starting_at must be YYYY-MM-DD"));
$a('forecast persists into cash_forecast_runs',               $c($cf, 'INSERT INTO cash_forecast_runs'));
$a('forecast creates first-class cash_forecast artifact',
    $c($cf, "artifactCreate(\$tenantId, 'cash_forecast'")
    && $c($cf, "source_record_type' => 'cash_forecast_runs'")
    && $c($cf, "initial_status' => 'review'"));
$a('forecast stamps cash_forecast_runs.artifact_id',
    $c($cf, 'UPDATE cash_forecast_runs')
    && $c($cf, 'SET artifact_id = :aid')
    && $c($cf, "'artifact_id'             => \$artifactId"));
$a('forecast run writes canonical treasury audit event',
    $c($cf, 'platformAuditLogWrite($tenantId, $actorUid, \'treasury.forecast.run\'')
    && $c($cf, "'object_type' => 'cash_forecast'")
    && $c($cf, "'artifact_id' => \$artifactId"));
$a('Treasury manifest declares forecast audit event',
    $c($treasuryManifest, "'treasury.forecast.run'"));
$a('forecast bucket carries shortfall note',                  $c($cf, "'NEGATIVE — shortfall flagged'"));
$a('forecast tracks min_week_balance for shortfall alerts',   $c($cf, 'minWeekCents'));
$a('opening cash from accounting_bank_accounts',              $c($cf, 'accounting_bank_accounts'));
$a('AP outflow query reads ap_bills by due_date',             $c($cf, 'FROM ap_bills') && $c($cf, 'due_date BETWEEN'));
$a('AR inflow query reads billing_invoices',                  $c($cf, 'FROM billing_invoices'));
$a('Payroll outflow query reads payroll_runs.pay_date',       $c($cf, 'FROM payroll_runs') && $c($cf, 'pay_date BETWEEN'));

$lint2 = []; exec('php -l ' . escapeshellarg($ROOT . '/core/ai/cash_forecast.php') . ' 2>&1', $lint2, $rc2);
$a('cash_forecast.php passes php -l',                         $rc2 === 0);

// ──────────────────────────────────────────────────────────────────────
// 4) core/ai/timesheet_anomaly.php
// ──────────────────────────────────────────────────────────────────────
echo "\n── core/ai/timesheet_anomaly.php ──\n";
$ta = (string) file_get_contents($ROOT . '/core/ai/timesheet_anomaly.php');
$a('detectTimesheetAnomalies defined',                        $c($ta, 'function detectTimesheetAnomalies(int $tenantId, array $opts = []): array'));
$a('timesheetFinding factory defined',                        $c($ta, 'function timesheetFinding('));
$a('week_start format validated',                             $c($ta, "week_start must be YYYY-MM-DD"));
$a('R1 SPIKE rule implemented (>1.5x baseline + ≥50hrs)',
    $c($ta, "'spike'") && $c($ta, '>= 50') && $c($ta, '1.5 * $base'));
$a('R2 ZERO_WEEK rule implemented',                           $c($ta, "'zero_week'"));
$a('R3 CATEGORY_DRIFT rule (billable share dropped > 30pp)',
    $c($ta, "'category_drift'") && $c($ta, '$drift > 0.30'));
$a('R4 OVERLAP rule (>24 hrs same person+day)',
    $c($ta, "'overlap'") && $c($ta, 'HAVING day_hours > 24'));
$a('returns summary_by_rule headline counts',                 $c($ta, "summary_by_rule"));
$a('returns scanned_people count',                            $c($ta, "scanned_people"));
$a('tolerant of missing schema (sandbox)',                    $c($ta, "'unable to scan: '"));

$lint3 = []; exec('php -l ' . escapeshellarg($ROOT . '/core/ai/timesheet_anomaly.php') . ' 2>&1', $lint3, $rc3);
$a('timesheet_anomaly.php passes php -l',                     $rc3 === 0);

// Pure-function probe — empty tenant returns a structured shell.
require_once $ROOT . '/core/ai/timesheet_anomaly.php';
try {
    $r = detectTimesheetAnomalies(999999, ['week_start' => '2026-02-02']);
    $a('detectTimesheetAnomalies returns array shape',        is_array($r) && isset($r['window'], $r['findings'], $r['summary_by_rule']));
    $a('window.week_start echoes input',                       $r['window']['week_start'] === '2026-02-02');
    $a('window.week_end = +6 days',                            $r['window']['week_end'] === '2026-02-08');
} catch (\Throwable $e) {
    $a('detectTimesheetAnomalies did NOT throw',               false);
}

// Bad date format throws InvalidArgumentException.
try {
    detectTimesheetAnomalies(1, ['week_start' => 'not-a-date']);
    $a('bad week_start throws',                                false);
} catch (\InvalidArgumentException $e) {
    $a('bad week_start throws InvalidArgumentException',       true);
}

// ──────────────────────────────────────────────────────────────────────
// 5) Tool registry — 5 new tools wired + handlers present.
// ──────────────────────────────────────────────────────────────────────
echo "\n── tool_gateway.php registry ──\n";
$gw = (string) file_get_contents($ROOT . '/core/ai/tool_gateway.php');
foreach ([
    'coreflux.check_duplicate_invoice'    => "'risk_level'  => 'read'",
    'coreflux.draft_bill'                 => "'risk_level'  => 'draft'",
    'coreflux.get_cash_position'          => "'risk_level'  => 'read'",
    'coreflux.run_cash_forecast'          => "'risk_level'  => 'draft'",
    'coreflux.detect_timesheet_anomalies' => "'risk_level'  => 'read'",
] as $tool => $riskHint) {
    $a("$tool registered",                                    $c($gw, "'$tool'"));
}
foreach ([
    'aiToolCheckDuplicateInvoiceHandler',
    'aiToolDraftBillHandler',
    'aiToolGetCashPositionHandler',
    'aiToolRunCashForecastHandler',
    'aiToolDetectTimesheetAnomaliesHandler',
] as $handler) {
    $a("$handler implemented",                                $c($gw, "function $handler("));
}
$a('draft_bill carries idempotency_args=[extraction_run_id]',
    $c($gw, "'idempotency_args' => ['extraction_run_id']"));
$a('run_cash_forecast carries idempotency_args=[starting_at, weeks]',
    $c($gw, "'idempotency_args' => ['starting_at', 'weeks']"));
$a('draft_bill handler reads _actor_user_id from threaded args',
    $c($gw, "\$args['_actor_user_id']"));

// ──────────────────────────────────────────────────────────────────────
// 6) /api/ai/forecasts.php + /api/ai/payroll_review.php
// ──────────────────────────────────────────────────────────────────────
echo "\n── /api/ai/forecasts.php ──\n";
$apiF = (string) file_get_contents($ROOT . '/api/ai/forecasts.php');
$a('strict_types',                                            $c($apiF, 'declare(strict_types=1)'));
$a('GET list returns cashForecastList',                       $c($apiF, 'cashForecastList('));
$a('GET detail returns cashForecastGet',                      $c($apiF, 'cashForecastGet('));
$a('POST run invokes cashForecastRun',                        $c($apiF, 'cashForecastRun('));
$a('forecast API returns artifact-aware forecast payloads',
    $c($apiF, "api_ok(['forecast' => \$row])")
    && $c($apiF, "api_ok(['forecast' => \$result])"));
$a('list+detail gated on accounting.read',                    $c($apiF, "rbac_legacy_can(\$user, 'accounting.read')"));
$a('run gated on accounting.write',                           $c($apiF, "rbac_legacy_can(\$user, 'accounting.write')"));

$lint4 = []; exec('php -l ' . escapeshellarg($ROOT . '/api/ai/forecasts.php') . ' 2>&1', $lint4, $rc4);
$a('forecasts.php passes php -l',                             $rc4 === 0);

echo "\n── /api/ai/payroll_review.php ──\n";
$apiP = (string) file_get_contents($ROOT . '/api/ai/payroll_review.php');
$a('strict_types',                                            $c($apiP, 'declare(strict_types=1)'));
$a('GET-only endpoint',                                       $c($apiP, "if (\$method !== 'GET') api_error('GET only', 405)"));
$a('Accepts ?week_start= or defaults to last Monday',         $c($apiP, "monday last week"));
$a('Calls detectTimesheetAnomalies',                          $c($apiP, 'detectTimesheetAnomalies'));
$a('Returns packet payload',                                  $c($apiP, "'packet' =>"));
$a('Gated on staffing.read OR accounting.read',
    $c($apiP, "rbac_legacy_can(\$user, 'staffing.read')")
    && $c($apiP, "rbac_legacy_can(\$user, 'accounting.read')"));

$lint5 = []; exec('php -l ' . escapeshellarg($ROOT . '/api/ai/payroll_review.php') . ' 2>&1', $lint5, $rc5);
$a('payroll_review.php passes php -l',                        $rc5 === 0);

// ──────────────────────────────────────────────────────────────────────
// 7) CashForecastReview.jsx
// ──────────────────────────────────────────────────────────────────────
echo "\n── CashForecastReview.jsx ──\n";
$cfui = (string) file_get_contents($ROOT . '/dashboard/src/pages/CashForecastReview.jsx');
$a('default export CashForecastReview',                       $c($cfui, 'export default function CashForecastReview()'));
$a('reads /api/ai/forecasts.php',                             $c($cfui, "/api/ai/forecasts.php"));
$a('POST run kicks new forecast',                             $c($cfui, "?action=run"));
$a('renders week buckets table',                              $c($cfui, 'cash-forecast-detail-weeks'));
$a('renders opening / closing / weekly-low Stat cards',
    $c($cfui, 'cash-forecast-detail-opening')
    && $c($cfui, 'cash-forecast-detail-closing')
    && $c($cfui, 'cash-forecast-detail-min'));
$a('two-column grid layout',                                  $c($cfui, "gridTemplateColumns: 'minmax(360px, 1fr) 2fr'"));
$a('shortfall flagged red in list',                           $c($cfui, '#dc2626'));

foreach ([
    'cash-forecast-page','cash-forecast-title','cash-forecast-weeks-input',
    'cash-forecast-run','cash-forecast-list-loading','cash-forecast-list-empty',
    'cash-forecast-list','cash-forecast-detail-placeholder','cash-forecast-detail-loading',
    'cash-forecast-detail-empty','cash-forecast-detail','cash-forecast-detail-weeks',
] as $tid) {
    $a("testid '$tid' present", $c($cfui, "data-testid=\"$tid\""));
}
$a("template testid 'cash-forecast-row-\${r.id}' present",    $c($cfui, 'cash-forecast-row-${r.id}'));
$a("template testid 'cash-forecast-week-\${w.week_no}' present", $c($cfui, 'cash-forecast-week-${w.week_no}'));

// ──────────────────────────────────────────────────────────────────────
// 8) PayrollReviewPacket.jsx
// ──────────────────────────────────────────────────────────────────────
echo "\n── PayrollReviewPacket.jsx ──\n";
$prui = (string) file_get_contents($ROOT . '/dashboard/src/pages/PayrollReviewPacket.jsx');
$a('default export PayrollReviewPacket',                      $c($prui, 'export default function PayrollReviewPacket()'));
$a('reads /api/ai/payroll_review.php',                        $c($prui, "/api/ai/payroll_review.php"));
$a('passes week_start query param',                           $c($prui, "?week_start="));
$a('summary bar covers all 4 rules + scanned + week',
    $c($prui, 'payroll-review-summary-spike')
    && $c($prui, 'payroll-review-summary-zero-week')
    && $c($prui, 'payroll-review-summary-category-drift')
    && $c($prui, 'payroll-review-summary-overlap')
    && $c($prui, 'payroll-review-summary-scanned')
    && $c($prui, 'payroll-review-summary-week'));
$a('renders empty state on no findings',                      $c($prui, "✓ No anomalies detected"));
$a('findings table renders per row',                          $c($prui, "payroll-review-findings"));
$a('RuleChip + SeverityChip subcomponents',
    $c($prui, 'function RuleChip(') && $c($prui, 'function SeverityChip('));

foreach ([
    'payroll-review-page','payroll-review-title','payroll-review-week-input',
    'payroll-review-refresh','payroll-review-loading','payroll-review-summary',
    'payroll-review-findings-empty','payroll-review-findings',
] as $tid) {
    $a("testid '$tid' present", $c($prui, "data-testid=\"$tid\""));
}
$a("template testid 'payroll-review-finding-\${i}' present",  $c($prui, 'payroll-review-finding-${i}'));
$a("template testid 'payroll-review-rule-\${rule}' present",  $c($prui, 'payroll-review-rule-${rule}'));
$a("template testid 'payroll-review-severity-\${severity}' present",
    $c($prui, 'payroll-review-severity-${severity}'));

// ──────────────────────────────────────────────────────────────────────
// 9) AccountingModule + AdminModule routing wire-in.
// ──────────────────────────────────────────────────────────────────────
echo "\n── AccountingModule.jsx + AdminModule.jsx routing ──\n";
$am  = (string) file_get_contents($ROOT . '/dashboard/src/modules/AccountingModule.jsx');
$adm = (string) file_get_contents($ROOT . '/dashboard/src/pages/AdminModule.jsx');

$a('AccountingModule imports CashForecastReview',             $c($am,  "import CashForecastReview from '../pages/CashForecastReview'"));
$a('AccountingModule routes /modules/accounting/cash-forecast', $c($am,  'path="cash-forecast"'));
$a('Cash forecast surfaced as ActionCard tile',               $c($am,  'href="/modules/accounting/cash-forecast"'));
$a('Cash forecast uses Banknote lucide icon',                 $c($am,  'Banknote'));

$a('AdminModule imports PayrollReviewPacket',                 $c($adm, "import PayrollReviewPacket from './PayrollReviewPacket'"));
$a('AdminModule routes /admin/ai/payroll-review',             $c($adm, 'path="/ai/payroll-review"'));
$a('Payroll review surfaced in sidebar nav',                  $c($adm, "to: '/admin/ai/payroll-review'"));
$a('Payroll review surfaced as AdminOverview tile',           $c($adm, 'href="/admin/ai/payroll-review"'));
$a('Payroll review uses UserCheck lucide icon',               $c($adm, 'UserCheck'));

// ──────────────────────────────────────────────────────────────────────
echo "\n=========================================\n";
echo "Slice E smoke: $pass ✓ / $fail ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
