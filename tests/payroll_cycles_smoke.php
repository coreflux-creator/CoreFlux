<?php
/**
 * Payroll cycles + AI anomaly cross-checks smoke test.
 *
 * Static asserts only — no DB / network. Verifies that:
 *   - Migration 003 declares payroll_pay_cycles, anomaly findings, cycle_id
 *     columns, and the cycle-scoped uniqueness key.
 *   - lib/cycles.php exposes window math, advance, list, auto-advance helpers.
 *   - lib/anomalies.php exposes detect/list/ack helpers + the three checks
 *     (hours_drift, missing_time, rate_change).
 *   - api/cycles.php wires CRUD + advance + auto_advance routes.
 *   - api/anomalies.php wires GET + POST + PATCH ack routes + dashboard feed.
 *   - runs.php compute hook runs anomaly detection + requires the new lib.
 *   - update.php auto-advances cycles each deploy.
 *   - manifest declares new audit events + permissions.
 *   - PayCyclesPanel + PayrollAnomalies UI exist with required testids.
 *   - PayrollOverview surfaces the alert badge.
 *   - PayrollRunDetail shows the anomaly panel + ack buttons.
 *   - PaySchedules embeds PayCyclesPanel.
 *   - PayrollModule routes /cycles + /anomalies.
 *   - Date math for biweekly window matches a known anchor.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

echo "Migration 003 — pay_cycles schema\n";
$mig = (string) file_get_contents(__DIR__ . '/../modules/payroll/migrations/003_pay_cycles.sql');
$a('payroll_pay_cycles table',                strpos($mig, 'CREATE TABLE IF NOT EXISTS payroll_pay_cycles') !== false);
$a('payroll_pay_cycles uses utf8mb4_unicode_ci', strpos($mig, 'utf8mb4_unicode_ci') !== false);
$a('cohort_filter_json column',               strpos($mig, 'cohort_filter_json') !== false);
$a('next_period_number watermark',            strpos($mig, 'next_period_number') !== false);
$a('last_advanced_at watermark',              strpos($mig, 'last_advanced_at') !== false);
$a('schedule_id link',                        strpos($mig, 'schedule_id') !== false);
$a('payroll_pay_periods.cycle_id (idempotent)', strpos($mig, "TABLE_NAME='payroll_pay_periods' AND COLUMN_NAME='cycle_id'") !== false);
$a('payroll_profiles.cycle_id (idempotent)',    strpos($mig, "TABLE_NAME='payroll_profiles' AND COLUMN_NAME='cycle_id'") !== false);
$a('auto-backfill default cycle per schedule', strpos($mig, "INSERT IGNORE INTO payroll_pay_cycles") !== false);
$a('drops legacy uq_period_tenant_sched_num', strpos($mig, 'uq_period_tenant_sched_num') !== false);
$a('adds cycle-scoped uq_period_tenant_cycle_num', strpos($mig, 'uq_period_tenant_cycle_num') !== false);
$a('payroll_anomaly_findings table',          strpos($mig, 'CREATE TABLE IF NOT EXISTS payroll_anomaly_findings') !== false);
$a('anomaly severity enum',                   strpos($mig, "ENUM('info','warning','critical')") !== false);
$a('anomaly code column',                     strpos($mig, "code            VARCHAR(60)") !== false);
$a('anomaly acknowledged_at column',          strpos($mig, "acknowledged_at") !== false);

echo "\nlib/cycles.php — engine\n";
$cy = (string) file_get_contents(__DIR__ . '/../modules/payroll/lib/cycles.php');
$a('payrollCycleNextWindow function',         strpos($cy, 'function payrollCycleNextWindow') !== false);
$a('payrollCycleAdvance function',            strpos($cy, 'function payrollCycleAdvance')    !== false);
$a('payrollCycleList function',               strpos($cy, 'function payrollCycleList')       !== false);
$a('payrollCycleAutoAdvanceAll function',     strpos($cy, 'function payrollCycleAutoAdvanceAll') !== false);
$a('handles weekly frequency',                strpos($cy, "case 'weekly':")     !== false);
$a('handles biweekly frequency',              strpos($cy, "case 'biweekly':")   !== false);
$a('handles semimonthly frequency',           strpos($cy, "case 'semimonthly':") !== false);
$a('handles monthly frequency',               strpos($cy, "case 'monthly':")    !== false);
$a('audit emits payroll.cycle.advanced',      strpos($cy, "'payroll.cycle.advanced'") !== false);
$a('advance is transactional',                (strpos($cy, 'beginTransaction') !== false || strpos($cy, 'cf_tx_begin') !== false) && (strpos($cy, 'rollBack') !== false || strpos($cy, 'cf_tx_rollback') !== false));
$a('PayCycleException thrown on missing cycle', strpos($cy, "throw new PayCycleException('Cycle not found')") !== false);

echo "\nlib/cycles.php — pure window math (biweekly anchor 2026-01-05, period 1)\n";
require_once __DIR__ . '/../core/db.php';                    // for type-only includes — no real PDO needed below
$_SERVER['REQUEST_METHOD'] = 'CLI';                          // avoid session warnings
require_once __DIR__ . '/../modules/payroll/lib/cycles.php';
$cycle    = ['next_period_number' => 1, 'anchor_date_override' => null,
             'pay_date_offset_days_override' => null];
$schedule = ['frequency' => 'biweekly', 'period_start_anchor' => '2026-01-05',
             'pay_date_offset_days' => 5];
$win = payrollCycleNextWindow($cycle, $schedule);
$a('biweekly period 1 start = 2026-01-05',     $win['period_start'] === '2026-01-05');
$a('biweekly period 1 end   = 2026-01-18',     $win['period_end']   === '2026-01-18');
$a('biweekly period 1 pay   = 2026-01-23',     $win['pay_date']     === '2026-01-23');
$cycle['next_period_number'] = 2;
$win2 = payrollCycleNextWindow($cycle, $schedule);
$a('biweekly period 2 start = 2026-01-19',     $win2['period_start'] === '2026-01-19');
$a('biweekly period 2 end   = 2026-02-01',     $win2['period_end']   === '2026-02-01');
// override path
$cycle['next_period_number'] = 1;
$cycle['anchor_date_override']          = '2026-03-02';
$cycle['pay_date_offset_days_override'] = 3;
$winO = payrollCycleNextWindow($cycle, $schedule);
$a('override anchor used',                     $winO['period_start'] === '2026-03-02');
$a('override pay-date offset used (3 days)',   $winO['pay_date']     === '2026-03-18');
$a('throws on bad anchor',                     (function() use ($schedule) {
    try { payrollCycleNextWindow(['next_period_number'=>1,'anchor_date_override'=>'not-a-date',
        'pay_date_offset_days_override'=>null], $schedule); return false;
    } catch (PayCycleException $e) { return true; }
})());
$a('throws on unsupported frequency',          (function() use ($cycle) {
    try { payrollCycleNextWindow($cycle, ['frequency'=>'fortnightly','period_start_anchor'=>'2026-01-05','pay_date_offset_days'=>5]); return false;
    } catch (PayCycleException $e) { return true; }
})());

echo "\nlib/anomalies.php — detector\n";
$an = (string) file_get_contents(__DIR__ . '/../modules/payroll/lib/anomalies.php');
$a('payrollAnomaliesDetect',                   strpos($an, 'function payrollAnomaliesDetect') !== false);
$a('payrollAnomaliesListByRun',                strpos($an, 'function payrollAnomaliesListByRun') !== false);
$a('payrollAnomaliesListUnacked',              strpos($an, 'function payrollAnomaliesListUnacked') !== false);
$a('payrollAnomaliesAcknowledge',              strpos($an, 'function payrollAnomaliesAcknowledge') !== false);
$a('hours_drift code',                         strpos($an, "'hours_drift'") !== false);
$a('missing_time code',                        strpos($an, "'missing_time'") !== false);
$a('rate_change code',                         strpos($an, "'rate_change'") !== false);
$a('warn threshold = 25%',                     strpos($an, 'PAYROLL_ANOMALY_DRIFT_WARN_PCT     = 25.0') !== false);
$a('critical threshold = 50%',                 strpos($an, 'PAYROLL_ANOMALY_DRIFT_CRITICAL_PCT = 50.0') !== false);
$a('idempotent — wipes prior findings on rerun', strpos($an, 'DELETE FROM payroll_anomaly_findings WHERE tenant_id') !== false);
$a('uses scopedQuery for history',             strpos($an, "FROM payroll_line_items li") !== false);
$a('falls back gracefully when AI fails',      strpos($an, 'ai enrichment skipped') !== false);
$a('audits payroll.anomalies.detected',        strpos($an, "'payroll.anomalies.detected'") !== false);

echo "\napi/cycles.php — endpoints\n";
$apiC = (string) file_get_contents(__DIR__ . '/../modules/payroll/api/cycles.php');
$a('GET list/detail',                          strpos($apiC, "case 'GET':") !== false);
$a('POST create',                              strpos($apiC, "api_require_fields(\$body, ['name', 'schedule_id'])") !== false);
$a('POST advance action',                      strpos($apiC, "if (\$action === 'advance')") !== false);
$a('POST auto_advance action',                 strpos($apiC, "if (\$action === 'auto_advance')") !== false);
$a('PUT update',                               strpos($apiC, "case 'PUT':") !== false);
$a('DELETE soft-disable',                      strpos($apiC, "scopedUpdate('payroll_pay_cycles', \$id, ['active' => 0])") !== false);
$a('emits payroll.cycle.created audit',        strpos($apiC, "'payroll.cycle.created'") !== false);
$a('rejects oversized cohort filter',          strpos($apiC, 'cohort_filter_json too long') !== false);

echo "\napi/anomalies.php — endpoints\n";
$apiA = (string) file_get_contents(__DIR__ . '/../modules/payroll/api/anomalies.php');
$a('GET findings by run_id',                   strpos($apiA, "api_query('run_id')") !== false);
$a('GET dashboard feed',                       strpos($apiA, "api_query('dashboard')") !== false);
$a('POST detect with AI flag',                 strpos($apiA, '$ai    = !empty($body[\'ai\'])') !== false);
$a('PATCH ack route',                          strpos($apiA, "case 'PATCH':") !== false);

echo "\napi/runs.php — compute hook + library\n";
$apiR = (string) file_get_contents(__DIR__ . '/../modules/payroll/api/runs.php');
$a('runs.php requires anomalies lib',          strpos($apiR, "require_once __DIR__ . '/../lib/anomalies.php'") !== false);
$a('compute action triggers detector',         strpos($apiR, 'payrollAnomaliesDetect($runId, false)') !== false);
$a('detector failure does not block compute',  strpos($apiR, 'anomaly detect skipped') !== false);

echo "\nupdate.php — deploy-time auto-advance\n";
$up = (string) file_get_contents(__DIR__ . '/../update.php');
$a('require cycles.php',                       strpos($up, '/modules/payroll/lib/cycles.php') !== false);
$a('calls payrollCycleAutoAdvanceAll',         strpos($up, 'payrollCycleAutoAdvanceAll()') !== false);
$a('logs payroll cycles auto-advance step',    strpos($up, 'payroll cycles auto-advance') !== false);
$a('soft-fails (never blocks deploy)',         strpos($up, 'soft-skip — ') !== false);

echo "\nmanifest.php — permissions + audit events\n";
$man = (string) file_get_contents(__DIR__ . '/../modules/payroll/manifest.php');
$a("'payroll.cycles.manage' permission",       strpos($man, "'payroll.cycles.manage'") !== false);
$a("'payroll.anomalies.view' permission",      strpos($man, "'payroll.anomalies.view'") !== false);
$a("'payroll.anomalies.acknowledge' permission", strpos($man, "'payroll.anomalies.acknowledge'") !== false);
$a("'payroll.cycle.advanced' audit event",     strpos($man, "'payroll.cycle.advanced'") !== false);
$a("'payroll.cycle.auto_advanced' audit event", strpos($man, "'payroll.cycle.auto_advanced'") !== false);
$a("'payroll.anomalies.detected' audit event", strpos($man, "'payroll.anomalies.detected'") !== false);
$a("'payroll.anomalies.acknowledged' audit event", strpos($man, "'payroll.anomalies.acknowledged'") !== false);
$a("Cycles + Anomalies actions registered",    strpos($man, "'route' => 'cycles'") !== false && strpos($man, "'route' => 'anomalies'") !== false);

echo "\nUI — PayCyclesPanel.jsx\n";
$pc = (string) file_get_contents(__DIR__ . '/../modules/payroll/ui/PayCyclesPanel.jsx');
$a('panel data-testid',                        strpos($pc, "data-testid=\"payroll-cycles\"")        !== false);
$a('new-cycle button testid',                  strpos($pc, 'payroll-cycles-new-btn')                !== false);
$a('auto-advance button testid',               strpos($pc, 'payroll-cycles-auto-advance-btn')       !== false);
$a('cycles table testid',                      strpos($pc, 'payroll-cycles-table')                  !== false);
$a('per-row advance testid',                   strpos($pc, 'payroll-cycle-advance-')                !== false);
$a('per-row toggle testid',                    strpos($pc, 'payroll-cycle-toggle-')                 !== false);
$a('uses /modules/payroll/api/cycles.php',     strpos($pc, '/modules/payroll/api/cycles.php')       !== false);

echo "\nUI — PayrollAnomalies.jsx\n";
$pa = (string) file_get_contents(__DIR__ . '/../modules/payroll/ui/PayrollAnomalies.jsx');
$a('page testid',                              strpos($pa, 'payroll-anomalies-page')                !== false);
$a('table testid',                             strpos($pa, 'payroll-anomalies-table')               !== false);
$a('per-row ack testid',                       strpos($pa, 'payroll-anomalies-ack-')                !== false);
$a('open-run link testid',                     strpos($pa, 'payroll-anomalies-open-run-')           !== false);
$a('uses dashboard feed endpoint',             strpos($pa, 'anomalies.php?dashboard=1')             !== false);

echo "\nUI — PayrollOverview alert badge\n";
$ov = (string) file_get_contents(__DIR__ . '/../modules/payroll/ui/PayrollOverview.jsx');
$a('badge testid',                             strpos($ov, 'payroll-overview-anomalies-badge')      !== false);
$a('badge count testid',                       strpos($ov, 'payroll-overview-anomalies-count')      !== false);
$a('critical count badge testid',              strpos($ov, 'payroll-overview-anomalies-critical')   !== false);
$a('latest anomalies panel',                   strpos($ov, 'payroll-overview-anomalies-panel')      !== false);
$a('badge links to ../anomalies',              strpos($ov, 'to="../anomalies"')                    !== false);

echo "\nUI — PayrollRunDetail anomaly panel\n";
$rd = (string) file_get_contents(__DIR__ . '/../modules/payroll/ui/PayrollRunDetail.jsx');
$a('anomaly section testid',                   strpos($rd, 'payroll-run-anomalies')                !== false);
$a('rerun button testid',                      strpos($rd, 'payroll-run-anomalies-rerun')          !== false);
$a('rerun w/ AI testid',                       strpos($rd, 'payroll-run-anomalies-rerun-ai')       !== false);
$a('per-row testid',                           strpos($rd, 'payroll-run-anomaly-${a.id}')           !== false);
$a('ack button testid',                        strpos($rd, 'payroll-run-anomaly-ack-')             !== false);
$a('count badge testid',                       strpos($rd, 'payroll-run-anomalies-count')          !== false);
$a('compute reload also reloads anomalies',    strpos($rd, 'await loadAnomalies();') !== false);

echo "\nUI — PaySchedules embeds cycles panel\n";
$ps = (string) file_get_contents(__DIR__ . '/../modules/payroll/ui/PaySchedules.jsx');
$a('imports PayCyclesPanel',                   strpos($ps, "import PayCyclesPanel from './PayCyclesPanel'") !== false);
$a('renders <PayCyclesPanel />',               strpos($ps, '<PayCyclesPanel />')                    !== false);

echo "\nUI — PayrollModule routing\n";
$pm = (string) file_get_contents(__DIR__ . '/../modules/payroll/ui/PayrollModule.jsx');
$a('cycles route',                             strpos($pm, '<Route path="cycles"') !== false);
$a('anomalies route',                          strpos($pm, '<Route path="anomalies"') !== false);
$a('cycles legacy slug redirect',              strpos($pm, '<Route path="pay_cycles"') !== false);

echo PHP_EOL . "Total: $pass passed, $fail failed" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
