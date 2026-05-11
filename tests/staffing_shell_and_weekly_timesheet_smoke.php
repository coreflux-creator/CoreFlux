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
$a('manifest id is staffing',                    ($m['id'] ?? null) === 'staffing');
$a('manifest name is Staffing',                  ($m['name'] ?? null) === 'Staffing');
$a('actions include Timesheets',                 in_array('Timesheets', array_column($m['actions'] ?? [], 'name'), true));
$a('actions include Placements',                 in_array('Placements', array_column($m['actions'] ?? [], 'name'), true));
$a('actions include Approvals',                  in_array('Approvals',  array_column($m['actions'] ?? [], 'name'), true));
$a('permissions list defines staffing.view',     isset($m['permissions']['staffing.view']));
$a('permissions list defines staffing.time.approve', isset($m['permissions']['staffing.time.approve']));

echo "\nMigration 001_timesheets.sql — header table contract\n";
$mig1 = $read(__DIR__ . '/../modules/staffing/migrations/001_timesheets.sql');
$a('creates timesheets table',                   str_contains($mig1, 'CREATE TABLE IF NOT EXISTS timesheets'));
$a('unique on (tenant_id, person_id, period_start)', str_contains($mig1, 'uq_ts_tenant_person_week (tenant_id, person_id, period_start)'));
$a('status enum includes all spec values',       str_contains($mig1, "ENUM('draft','submitted','approved','rejected','payroll_ready','billing_ready','locked')"));
$a('creates tenant_staffing_settings table',     str_contains($mig1, 'CREATE TABLE IF NOT EXISTS tenant_staffing_settings'));
$a('settings include week_starts_on default Mon',str_contains($mig1, 'week_starts_on TINYINT NOT NULL DEFAULT 1'));

echo "\nMigration 002_timesheet_id_on_entries.sql — FK + hour_type contract\n";
$mig2 = $read(__DIR__ . '/../modules/staffing/migrations/002_timesheet_id_on_entries.sql');
$a('adds timesheet_id column',                   str_contains($mig2, 'ADD COLUMN timesheet_id BIGINT UNSIGNED NULL'));
$a('adds hour_type enum',                        str_contains($mig2, "hour_type ENUM('regular','overtime','doubletime','holiday','pto','sick','bereavement','unpaid','nonbillable')"));
$a('adds billable + payable flags',              str_contains($mig2, 'ADD COLUMN billable TINYINT(1)') && str_contains($mig2, 'ADD COLUMN payable TINYINT(1)'));
$a('backfills hour_type from legacy category',   str_contains($mig2, "WHEN category = 'OT_billable' OR category = 'OT_nonbillable' THEN 'overtime'"));
$a('backfills timesheets headers from entries',  str_contains($mig2, 'INSERT IGNORE INTO timesheets'));
$a('uses information_schema gating throughout',  substr_count($mig2, 'FROM information_schema.') >= 4);
$a('uses one-statement-per-line PREPARE',        preg_match('/PREPARE stmt FROM @sql[^\n]*;[^\n]*EXECUTE/', $mig2) === 0);

echo "\nLib /modules/staffing/lib/timesheets.php\n";
$lib = $read(__DIR__ . '/../modules/staffing/lib/timesheets.php');
$a('STAFFING_HOUR_TYPES constant declared',      str_contains($lib, "const STAFFING_HOUR_TYPES"));
$a('STAFFING_HOUR_TYPE_TO_CATEGORY map present', str_contains($lib, 'STAFFING_HOUR_TYPE_TO_CATEGORY'));
$a('staffingTimesheetUpsert idempotent on (person, period_start)', str_contains($lib, 'staffingTimesheetUpsert') && str_contains($lib, 'period_start = :ps'));
$a('staffingTimesheetWeek joins placements + reads end_client_name', str_contains($lib, 'LEFT JOIN placements') && str_contains($lib, 'end_client_name'));
$a('staffingTimesheetBulkSave wraps in transaction', str_contains($lib, '$pdo->beginTransaction()'));
$a('zero hours → delete existing row',           str_contains($lib, '$hours <= 0 && !empty($r[\'id\'])') && str_contains($lib, "scopedDelete('time_entries'"));
$a('refuses edits when status approved/locked',  str_contains($lib, "in_array(\$header['status'] ?? 'draft', ['approved','locked','payroll_ready','billing_ready']"));
$a('submit() flips header + cascades rows',      str_contains($lib, "scopedUpdate('timesheets', \$headerId, [") && str_contains($lib, "'submitted'") && str_contains($lib, "status = 'pending_review'"));
$a('approve() guards two-eye control',           str_contains($lib, 'Two-eye control'));
$a('reject() requires reason on header',         str_contains($lib, "'rejection_reason'    => \$reason"));

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
$a('StaffingModule routes timesheets',           str_contains($sm, 'path="timesheets/*"'));
$a('StaffingModule routes approvals',            str_contains($sm, 'path="approvals/*"'));
$a('StaffingModule routes placements (umbrella)', str_contains($sm, 'path="placements/*"'));
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

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
