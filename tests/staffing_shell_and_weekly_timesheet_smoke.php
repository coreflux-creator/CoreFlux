<?php
/**
 * Smoke: CoreStaffing module shell + weekly timesheet contracts.
 *
 * Pins the shape of:
 *   • /modules/staffing/manifest.php
 *   • /modules/staffing/migrations/001_timesheets.sql
 *   • /modules/staffing/migrations/002_timesheet_id_on_entries.sql
 *   • /modules/staffing/api/timesheets.php
 *   • /modules/staffing/lib/timesheets.php
 *   • App.jsx wiring + StaffingModule.jsx routes
 *   • TimesheetWeek.jsx (UX contract)
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "Manifest\n";
$m = require __DIR__ . '/../modules/staffing/manifest.php';

// Regression: must not collide with the legacy `timesheets` table from
// /app/sql/setup.sql (which has columns user_id/week_start/hours_worked and
// is still referenced by /app/timesheets/* and /app/people/* legacy code).
$libRaw = file_get_contents(__DIR__ . '/../modules/staffing/lib/timesheets.php');
$apiRaw = file_get_contents(__DIR__ . '/../modules/staffing/api/timesheets.php');
$mig1Raw = file_get_contents(__DIR__ . '/../modules/staffing/migrations/001_timesheets.sql');
$mig2Raw = file_get_contents(__DIR__ . '/../modules/staffing/migrations/002_timesheet_id_on_entries.sql');
$a('Regression: lib never references bare `timesheets` table',     preg_match('/\b(FROM|INTO|UPDATE|JOIN)\s+timesheets\b/i', $libRaw) === 0 && preg_match("/scopedInsert\('timesheets'/", $libRaw) === 0 && preg_match("/scopedUpdate\('timesheets'/", $libRaw) === 0);
$a('Regression: API never references bare `timesheets` table',     preg_match('/\b(FROM|INTO|UPDATE|JOIN)\s+timesheets\b/i', $apiRaw) === 0);
$a('Regression: migration 001 creates staffing_timesheets',        str_contains($mig1Raw, 'CREATE TABLE IF NOT EXISTS staffing_timesheets'));
$a('Regression: migration 002 references staffing_timesheets',     str_contains($mig2Raw, 'staffing_timesheets') && preg_match('/\b(FROM|INTO|UPDATE|JOIN)\s+timesheets\b/', $mig2Raw) === 0);

$a('manifest id is staffing',                    ($m['id'] ?? null) === 'staffing');
$a('manifest name is Staffing',                  ($m['name'] ?? null) === 'Staffing');
$a('actions include Timesheets',                 in_array('Timesheets', array_column($m['actions'] ?? [], 'name'), true));
$a('actions include Placements',                 in_array('Placements', array_column($m['actions'] ?? [], 'name'), true));
$a('actions include Approvals',                  in_array('Approvals',  array_column($m['actions'] ?? [], 'name'), true));
$actionPerms = array_column($m['actions'] ?? [], 'permission', 'name');
$a('source permission: Placements action',        ($actionPerms['Placements'] ?? null) === 'placements.view');
$a('source permission: Timesheets action',        ($actionPerms['Timesheets'] ?? null) === 'time.view');
$a('source permission: Approvals action',         ($actionPerms['Approvals'] ?? null) === 'time.approve');
$a('permissions list defines staffing.view',     isset($m['permissions']['staffing.view']));
$a('permissions list keeps legacy staffing.time.approve alias', isset($m['permissions']['staffing.time.approve']));

echo "\nMigration 001_timesheets.sql — header table contract\n";
$mig1 = $read(__DIR__ . '/../modules/staffing/migrations/001_timesheets.sql');
$a('creates staffing_timesheets table',          str_contains($mig1, 'CREATE TABLE IF NOT EXISTS staffing_timesheets'));
$a('unique on (tenant_id, person_id, period_start)', str_contains($mig1, 'uq_sts_tenant_person_week (tenant_id, person_id, period_start)'));
$a('status enum includes all spec values',       str_contains($mig1, "ENUM('draft','submitted','approved','rejected','payroll_ready','billing_ready','locked')"));
$a('creates tenant_staffing_settings table',     str_contains($mig1, 'CREATE TABLE IF NOT EXISTS tenant_staffing_settings'));
$a('settings include week_starts_on default Mon',str_contains($mig1, 'week_starts_on TINYINT NOT NULL DEFAULT 1'));

echo "\nMigration 002_timesheet_id_on_entries.sql — FK + hour_type contract\n";
$mig2 = $read(__DIR__ . '/../modules/staffing/migrations/002_timesheet_id_on_entries.sql');
$a('adds timesheet_id column',                   str_contains($mig2, 'ADD COLUMN timesheet_id BIGINT UNSIGNED NULL'));
$a('adds hour_type enum',                        str_contains($mig2, "hour_type ENUM('regular','overtime','doubletime','holiday','pto','sick','bereavement','unpaid','nonbillable')"));
$a('adds billable + payable flags',              str_contains($mig2, 'ADD COLUMN billable TINYINT(1)') && str_contains($mig2, 'ADD COLUMN payable TINYINT(1)'));
$a('backfills hour_type from legacy category',   str_contains($mig2, "WHEN category = 'OT_billable' OR category = 'OT_nonbillable' THEN 'overtime'"));
$a('backfills staffing_timesheets headers from entries',  str_contains($mig2, 'INSERT IGNORE INTO staffing_timesheets'));
$a('uses information_schema gating throughout',  substr_count($mig2, 'FROM information_schema.') >= 4);
$a('uses one-statement-per-line PREPARE',        preg_match('/PREPARE stmt FROM @sql[^\n]*;[^\n]*EXECUTE/', $mig2) === 0);

echo "\nLib /modules/staffing/lib/timesheets.php\n";
$lib = $read(__DIR__ . '/../modules/staffing/lib/timesheets.php');
$timeSync = $read(__DIR__ . '/../modules/time/lib/workflow_sync.php');
$a('STAFFING_HOUR_TYPES constant declared',      str_contains($lib, "const STAFFING_HOUR_TYPES"));
$a('STAFFING_HOUR_TYPE_TO_CATEGORY map present', str_contains($lib, 'STAFFING_HOUR_TYPE_TO_CATEGORY'));
$a('staffingTimesheetUpsert idempotent on (person, period_start)', str_contains($lib, 'staffingTimesheetUpsert') && str_contains($lib, 'period_start = :ps') && str_contains($lib, 'staffing_timesheets'));
$a('staffingTimesheetWeek joins placements + reads end_client_name', str_contains($lib, 'LEFT JOIN placements') && str_contains($lib, 'end_client_name'));
$a('staffingTimesheetBulkSave wraps in transaction', str_contains($lib, '$pdo->beginTransaction()'));
$a('zero hours → delete existing row',           str_contains($lib, '$hours <= 0 && !empty($r[\'id\'])') && str_contains($lib, "scopedDelete('time_entries'"));
$a('auto-reopens when status is submitted/approved/rejected/payroll_ready/billing_ready',
   str_contains($lib, "in_array(\$header['status'] ?? 'draft', ['submitted','approved','rejected','payroll_ready','billing_ready']"));
$a('still hard-blocks edits on truly locked sheets (downstream JEs)',
   str_contains($lib, "Timesheet is locked"));
$a('submit() flips header + cascades rows',      str_contains($lib, "scopedUpdate('staffing_timesheets', \$headerId, [") && str_contains($lib, "'submitted'") && str_contains($lib, "status = 'pending_review'"));
$a('approve() guards two-eye control',           str_contains($lib, 'Two-eye control'));
$a('workflow reject writes reason on header',    str_contains($timeSync, 'rejection_reason = :r'));

echo "\nAPI /modules/staffing/api/timesheets.php\n";
$api = $read(__DIR__ . '/../modules/staffing/api/timesheets.php');
$a('endpoint requires auth',                     str_contains($api, '$ctx    = api_require_auth()'));
$a('action=week returns timesheet + entries + settings', str_contains($api, "'timesheet' =>") && str_contains($api, "'entries'") && str_contains($api, "'settings'"));
$a('action=bulk_save dispatches to lib',         str_contains($api, "staffingTimesheetBulkSave((int) (\$user['id'] ?? 0), \$body)"));
$a('action=submit / approve / reject all handled', str_contains($api, "in_array(\$action, ['submit','approve','reject']"));
$a('action=settings supports GET + POST',        str_contains($api, "\$action === 'settings'") && str_contains($api, "if (\$method === 'GET')") && str_contains($api, "if (\$method === 'POST')"));
$a('settings upsert uses ON DUPLICATE KEY',      str_contains($api, 'ON DUPLICATE KEY UPDATE'));
$a('list endpoint joins people for worker name', str_contains($api, 'LEFT JOIN people p'));

echo "\nSPA wiring (App.jsx + StaffingModule.jsx)\n";
$app = $read(__DIR__ . '/../dashboard/src/App.jsx');
$a('App.jsx imports StaffingModule',             str_contains($app, "import StaffingModule from '../../modules/staffing/ui/StaffingModule'"));
$a('App.jsx mounts /modules/staffing/* route',   str_contains($app, '<Route path="/modules/staffing/*"'));
$a('App.jsx adds Staffing to DEMO_SESSION.modules', str_contains($app, "id: 'staffing'") && str_contains($app, "name: 'Staffing'"));
$a('App.jsx keeps /modules/time/* back-compat',  str_contains($app, '<Route path="/modules/time/*"'));
$a('App.jsx keeps /modules/placements/* back-compat', str_contains($app, '<Route path="/modules/placements/*"'));

$sm = $read(__DIR__ . '/../modules/staffing/ui/StaffingModule.jsx');
$overview = $read(__DIR__ . '/../modules/staffing/ui/StaffingOverview.jsx');
$profitability = $read(__DIR__ . '/../modules/staffing/ui/StaffingProfitability.jsx');
$a('StaffingModule routes timesheets (list/detail/week sub-routes — Batch 2 rebuild)',
    str_contains($sm, 'path="timesheets"')
    && str_contains($sm, 'path="timesheets/week"')
    && str_contains($sm, 'path="timesheets/:id"'));
$a('StaffingModule routes approvals',            str_contains($sm, 'path="approvals/*"'));
$a('StaffingModule routes placements workbench shortcut', str_contains($sm, 'path="placements/*"'));
$a('StaffingModule describes placements as source-module shortcut', str_contains($sm, 'source Placements module') && !str_contains($sm, 're-homing'));
$a('Staffing profitability describes Reports as source module', str_contains($profitability, 'Reports-module analytics') && !str_contains($profitability, 'under the Staffing umbrella'));
$a('Overview placements card links to canonical Placements', str_contains($overview, 'to="/modules/placements/list"'));
$a('StaffingModule has settings page',           str_contains($sm, 'path="settings"'));
$a('Overview default route',                     str_contains($sm, 'Navigate to="overview"'));

$tw = $read(__DIR__ . '/../modules/staffing/ui/TimesheetWeek.jsx');
$a('TimesheetWeek inline-editable cells',        str_contains($tw, 'CellEditor') && str_contains($tw, "type=\"number\""));
$a('TimesheetWeek hour-type split',              str_contains($tw, 'splitCell') && str_contains($tw, 'removeSplit'));
$a('TimesheetWeek debounced autosave',           str_contains($tw, 'saveTimer') && str_contains($tw, 'setTimeout(() => doSave'));
$a('TimesheetWeek Submit Week button',           str_contains($tw, 'data-testid="ts-submit-week"'));
$a('TimesheetWeek shows rejection banner',       str_contains($tw, 'data-testid="ts-rejection-banner"'));
$a('TimesheetWeek over-contracted warning',      str_contains($tw, 'over contracted'));
$a('TimesheetWeek week-start configurable',      str_contains($tw, 'weekStartsOn'));
$a('TimesheetWeek Copy-last-week button',         str_contains($tw, 'data-testid="ts-copy-last-week"') && str_contains($tw, 'copyLastWeek'));
$a('TimesheetWeek auto-prefill on empty week',    str_contains($tw, 'prefill_from_last_week') && str_contains($tw, 'prefillBanner'));
$a('TimesheetWeek prefill banner with clear btn', str_contains($tw, 'data-testid="ts-prefill-banner"') && str_contains($tw, 'data-testid="ts-prefill-clear"'));
$a('TimesheetWeek copy doesn\'t overwrite filled cells', str_contains($tw, "(c.hours || 0) > 0)) continue"));
$a('TimesheetWeek empty state links to canonical Placement create', str_contains($tw, 'href="/modules/placements/new"'));

$a('Lib has prior-week template builder',         str_contains($lib, 'staffingTimesheetPriorWeekTemplate'));
$a('Prior-week template shifts dates +7 days',    str_contains($lib, "strtotime(\$r['work_date'] . ' +7 day')"));
$a('Prior-week template filters out 0-hours rows', str_contains($lib, 'hours > 0'));

$a('API exposes action=prefill_from_last_week',   str_contains($api, "action === 'prefill_from_last_week'"));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
