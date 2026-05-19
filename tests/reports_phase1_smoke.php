<?php
/**
 * Reports Module Phase 1 — static contract smoke test.
 *
 *   php -d zend.assertions=1 /app/tests/reports_phase1_smoke.php
 *
 * Verifies:
 *   1. Manifest registers + permissions + audit events declared.
 *   2. Migration creates the v_timesheet_day_fin view with the contracted columns.
 *   3. Period resolver math (4w default, custom range, all preset codes).
 *   4. All 5 API endpoints exist + parse + reference required helpers.
 *   5. UI components exist, parse JSX (Vite build verifies real parse), and
 *      surface the spec-required testids and KPI tiles.
 *   6. App.jsx + core/modules.php wired to the new module.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/ModuleRegistry.php';
require_once __DIR__ . '/../modules/reports/lib/periods.php';

$pass = 0; $fail = 0;
$assert = function (string $name, bool $cond, ?string $hint = null) use (&$pass, &$fail): void {
    if ($cond) { echo "  ✓ {$name}\n"; $pass++; }
    else       { echo "  ✗ {$name}" . ($hint ? "  ({$hint})" : '') . "\n"; $fail++; }
};

echo "Manifest\n";
$reg = ModuleRegistry::reset(__DIR__ . '/../modules');
$m = $reg->getModule('reports');
$assert('module registered',                        $m !== null);
$assert('id = reports',                             ($m['id'] ?? '') === 'reports');
$assert('depends_on people + placements + time',
    in_array('people', $m['depends_on'] ?? [], true)
    && in_array('placements', $m['depends_on'] ?? [], true)
    && in_array('time', $m['depends_on'] ?? [], true));
foreach (['reports.view','reports.export','reports.custom.build','reports.custom.share'] as $p) {
    $assert("perm: {$p}", in_array($p, array_keys($m['permissions'] ?? []), true));
}
foreach (['reports.dashboard.viewed','reports.exported','reports.custom.created','reports.custom.updated','reports.custom.deleted'] as $ev) {
    $assert("event: {$ev}", in_array($ev, $m['audit_events'] ?? [], true));
}
foreach (['overview','executive_snapshot','client_profitability','rate_spread','overtime_watch'] as $rt) {
    $hit = false;
    foreach ($m['actions'] ?? [] as $a) { if (($a['route'] ?? '') === $rt) { $hit = true; break; } }
    $assert("action route: {$rt}", $hit);
}

echo "\nMigration SQL\n";
$sql = (string) file_get_contents(__DIR__ . '/../modules/reports/migrations/001_init.sql');
$assert('file exists',                            strlen($sql) > 0);
$assert('CREATE VIEW v_timesheet_day_fin',        stripos($sql, 'CREATE VIEW v_timesheet_day_fin') !== false);
$assert('DROP VIEW IF EXISTS guard',              stripos($sql, 'DROP VIEW IF EXISTS v_timesheet_day_fin') !== false);
foreach ([
    'tenant_id','employee_id','placement_id','timesheet_id','work_date','week_start','week_end',
    'hour_type','hours','bill_rate','pay_rate','multiplier','revenue','cost','gross_profit',
    'is_overtime','is_billable',
] as $col) {
    $assert("view exposes column: {$col}", stripos($sql, $col) !== false);
}
$assert('joins placement_rates by rate_snapshot_id',
    stripos($sql, 'placement_rates pr ON pr.id = te.rate_snapshot_id') !== false);
$assert('excludes superseded entries',
    stripos($sql, "te.status <> 'superseded'") !== false || stripos($sql, "te.status != 'superseded'") !== false);

echo "\nPeriod resolver\n";
$p4w = reportsResolvePeriod('4w');
$assert('4w default code',                      $p4w['code'] === '4w');
$assert('4w from < to',                         $p4w['from'] < $p4w['to']);
$assert('4w produces ≥ 4 weekly buckets',       count($p4w['weeks']) >= 4);
$assert('weeks are Mon-based',                  ($p4w['weeks'][0]['start'] ?? '') !== '' && (int) date('N', strtotime($p4w['weeks'][0]['start'])) === 1);
foreach (['1w','2w','4w','8w','12w','mtd','last_month','qtd','last_quarter','ytd','last_12m','last_year'] as $code) {
    $r = reportsResolvePeriod($code);
    $assert("period code resolves: {$code}", $r['code'] === $code && $r['from'] !== '' && $r['to'] !== '' && $r['from'] <= $r['to']);
}
$rCustom = reportsResolvePeriod('whatever', '2026-01-01', '2026-01-31');
$assert('custom range overrides code',          $rCustom['code'] === 'custom' && $rCustom['from'] === '2026-01-01' && $rCustom['to'] === '2026-01-31');
$opts = reportsPeriodOptions();
$assert('reportsPeriodOptions returns 12 codes', is_array($opts) && count($opts) === 12);
$badCode = reportsResolvePeriod('not-a-code');
$assert('unknown code falls back to 4w',        $badCode['code'] === '4w');

echo "\nAPI files\n";
foreach (['overview.php','executive_snapshot.php','client_profitability.php','rate_spread.php','overtime_watch.php'] as $f) {
    $p = __DIR__ . "/../modules/reports/api/{$f}";
    $assert("api/{$f} exists", is_file($p));
    if (is_file($p)) {
        $o = []; $rc = 0; @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
        $assert("api/{$f} parses", $rc === 0, $rc !== 0 ? trim(implode("\n", $o)) : null);
        $src = (string) file_get_contents($p);
        $assert("api/{$f} guards reports.view", stripos($src, "rbac_legacy_require(\$user, 'reports.view')") !== false);
        $assert("api/{$f} accepts ?period",     stripos($src, "api_query('period')") !== false);
        $assert("api/{$f} restricts to GET",    stripos($src, "api_method() !== 'GET'") !== false);
    }
}

echo "\nLib files\n";
foreach (['periods.php','staffing_metrics.php'] as $f) {
    $p = __DIR__ . "/../modules/reports/lib/{$f}";
    $assert("lib/{$f} exists", is_file($p));
    if (is_file($p)) {
        $o = []; $rc = 0; @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
        $assert("lib/{$f} parses", $rc === 0);
    }
}
$metricsSrc = (string) file_get_contents(__DIR__ . '/../modules/reports/lib/staffing_metrics.php');
foreach (['staffingKpiTotals','staffingWeeklySeries','staffingHeadcount','staffingTimesheetHealth','staffingRunRate'] as $fn) {
    $assert("staffing_metrics exports: {$fn}", stripos($metricsSrc, "function {$fn}(") !== false);
}
$assert('KPIs query v_timesheet_day_fin', stripos($metricsSrc, 'v_timesheet_day_fin') !== false);

echo "\nUI components\n";
$uiDir = __DIR__ . '/../modules/reports/ui';
foreach (['ReportsModule.jsx','ReportsSidebar.jsx','PeriodSelector.jsx','StaffingOverview.jsx',
          'ExecutiveSnapshot.jsx','ClientProfitability.jsx','RateSpreadMonitor.jsx','OvertimeWatch.jsx'] as $f) {
    $assert("ui/{$f}", is_file("{$uiDir}/{$f}"));
}
$mod = (string) file_get_contents("{$uiDir}/ReportsModule.jsx");
foreach (['overview','executive_snapshot','client_profitability','rate_spread','overtime_watch'] as $rt) {
    $assert("ReportsModule routes: {$rt}", stripos($mod, "path=\"{$rt}\"") !== false);
}
$assert('ReportsModule has reports-module testid', stripos($mod, 'data-testid="reports-module"') !== false);

$ov = (string) file_get_contents("{$uiDir}/StaffingOverview.jsx");
foreach (['kpi-revenue','kpi-gp','kpi-gp-pct','kpi-hours','kpi-ot-pct','kpi-spread'] as $tid) {
    $assert("StaffingOverview tile: {$tid}", stripos($ov, "testid=\"{$tid}\"") !== false);
}
$assert('Overview reads /api/overview.php',  stripos($ov, '/modules/reports/api/overview.php') !== false);
$assert('Overview default period = 4w',       stripos($ov, "useState('4w')") !== false);
$assert('Overview renders weekly trend',      stripos($ov, 'reports-overview-weekly') !== false);
$assert('Overview renders headcount',         stripos($ov, 'reports-overview-headcount') !== false);
$assert('Overview renders run rate',          stripos($ov, 'reports-overview-runrate') !== false);
$assert('Overview renders timesheet health',  stripos($ov, 'reports-overview-health') !== false);

$exec = (string) file_get_contents("{$uiDir}/ExecutiveSnapshot.jsx");
$assert('ExecutiveSnapshot has print button', stripos($exec, 'reports-exec-print') !== false);
foreach (['exec-revenue','exec-gp','exec-headcount','exec-rev-runrate'] as $tid) {
    $assert("ExecutiveSnapshot tile: {$tid}", stripos($exec, "testid=\"{$tid}\"") !== false);
}

$cp = (string) file_get_contents("{$uiDir}/ClientProfitability.jsx");
$assert('ClientProfitability table testid',   stripos($cp, 'reports-client-table') !== false);
$assert('ClientProfitability surfaces alerts', stripos($cp, 'reports-client-alerts') !== false);

$rs = (string) file_get_contents("{$uiDir}/RateSpreadMonitor.jsx");
$assert('RateSpreadMonitor table testid', stripos($rs, 'reports-rate-spread-table') !== false);
$assert('RateSpreadMonitor flags negative_spread', stripos($rs, 'negative_spread') !== false);

$ow = (string) file_get_contents("{$uiDir}/OvertimeWatch.jsx");
foreach (['ot-hours','ot-revenue','ot-cost','ot-margin','reports-overtime-employees-table','reports-overtime-clients-table'] as $tid) {
    $assert("OvertimeWatch surface: {$tid}", stripos($ow, $tid) !== false);
}

echo "\nApp wiring\n";
$app = (string) file_get_contents(__DIR__ . '/../dashboard/src/App.jsx');
$assert('App.jsx imports new ReportsModule',
    stripos($app, "from '../../modules/reports/ui/ReportsModule'") !== false);
$assert('App.jsx route /modules/reports/* present',
    stripos($app, '/modules/reports/*') !== false);

$mods = (string) file_get_contents(__DIR__ . '/../core/modules.php');
foreach (['overview','executive_snapshot','client_profitability','rate_spread','overtime_watch'] as $rt) {
    $assert("core/modules.php exposes route: {$rt}", stripos($mods, "'route' => '{$rt}'") !== false);
}

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
