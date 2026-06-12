<?php
/**
 * Smoke: CoreStaffing Phase 2 — Clients entity + Profitability mirror +
 * weekly timesheet economics.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "Clients migration\n";
$mig = $read(__DIR__ . '/../modules/staffing/migrations/003_clients.sql');
$a('creates staffing_clients table',          str_contains($mig, 'CREATE TABLE IF NOT EXISTS staffing_clients'));
$a('unique on (tenant_id, name)',             str_contains($mig, 'uq_sc_tenant_name (tenant_id, name)'));
$a('has billing + msa fields',                str_contains($mig, 'billing_postal_code') && str_contains($mig, 'msa_status'));
$a('adds placements.client_id FK + index',    str_contains($mig, 'ADD COLUMN client_id BIGINT') && str_contains($mig, 'idx_pl_client (client_id)'));
$a('backfills clients from end_client_name',  str_contains($mig, 'INSERT IGNORE INTO staffing_clients') && str_contains($mig, 'GROUP BY tenant_id, end_client_name'));
$a('links every placement to its client',     str_contains($mig, 'JOIN staffing_clients c') && str_contains($mig, 'SET p.client_id = c.id'));
$a('uses DO 0 fallback (no result leak)',     substr_count($mig, "'DO 0'") >= 3);
$a('one statement per line for PREPARE',      preg_match('/PREPARE s FROM @sql[^\n]*;[^\n]*EXECUTE/', $mig) === 0);

echo "\nClients API\n";
$api = $read(__DIR__ . '/../modules/staffing/api/clients.php');
$a('list action with q + status + active_placements join', str_contains($api, "action === 'list'") && str_contains($api, 'active_placements'));
$a('create action validates unique name',     str_contains($api, "Client '{\$name}' already exists"));
$a('update action allow-list of fields',      str_contains($api, "'billing_city','billing_state'"));
$a('delete action is soft (status=closed)',   str_contains($api, "'status' => 'closed'"));
$a('stats action queries v_timesheet_day_fin',str_contains($api, 'FROM v_timesheet_day_fin') && str_contains($api, 'mtd_revenue'));
$a('stats tolerates missing view',            str_contains($api, "\$stats['mtd_revenue'] = null"));

echo "\nClients CSV export\n";
$csv = $read(__DIR__ . '/../modules/staffing/api/csv_export.php');
$a('client CSV uses governed dataset',        str_contains($csv, 'exportTemplateStreamDatasetCsv')
                                             && str_contains($csv, 'staffing_clients')
                                             && str_contains($csv, 'exportDatasetFetchStaffingClients'));
$a('client CSV gates on export permission',   str_contains($csv, "'staffing.export.run'"));
$a('client CSV audits raw dataset event',     str_contains($csv, 'staffing.clients.exported') && str_contains($csv, "mode' => 'raw'"));

echo "\nClients UI\n";
$ui = $read(__DIR__ . '/../modules/staffing/ui/Clients.jsx');
$a('renders clients table + new button',      str_contains($ui, 'data-testid="staffing-clients-new"') && str_contains($ui, 'data-testid="staffing-clients-table"'));
$a('search + status filter',                  str_contains($ui, 'staffing-clients-search') && str_contains($ui, 'staffing-clients-status-filter'));
$a('export template picker wired',            str_contains($ui, 'ExportTemplatePicker') && str_contains($ui, 'dataset="staffing_clients"'));
$a('ClientDrawer for new / edit',             str_contains($ui, 'ClientDrawer'));
$a('soft-delete confirmation',                str_contains($ui, 'Close client'));

echo "\nProfitability mirror\n";
$prof = $read(__DIR__ . '/../modules/staffing/ui/StaffingProfitability.jsx');
$a('5 sub-tabs',                              str_contains($prof, "overview") && str_contains($prof, 'executive_snapshot') && str_contains($prof, 'client_profitability') && str_contains($prof, 'rate_spread') && str_contains($prof, 'overtime_watch'));
$a('reuses Reports module pages',             str_contains($prof, "from '../../reports/ui/"));
$sm = $read(__DIR__ . '/../modules/staffing/ui/StaffingModule.jsx');
$a('StaffingModule routes profitability/*',   str_contains($sm, 'path="profitability/*"'));
$a('StaffingModule routes clients',           str_contains($sm, 'path="clients"') && str_contains($sm, 'element={<Clients'));

echo "\nWeekly timesheet economics\n";
$apit = $read(__DIR__ . '/../modules/staffing/api/timesheets.php');
$a('API exposes action=week_economics',       str_contains($apit, "action === 'week_economics'"));
$a('reads from v_timesheet_day_fin',          str_contains($apit, 'FROM v_timesheet_day_fin v'));
$a('returns revenue/cost/gp/hours totals',    str_contains($apit, "'revenue' => 0.0") && str_contains($apit, "'gp_pct'"));
$a('tolerates view missing',                  str_contains($apit, '$rows = [];'));

$tw = $read(__DIR__ . '/../modules/staffing/ui/TimesheetWeek.jsx');
$a('TimesheetWeek fetches econPath',          str_contains($tw, 'week_economics'));
$a('renders econ totals row',                 str_contains($tw, 'data-testid="ts-week-economics"'));
$a('econ cells: revenue / cost / gp / gp%',   str_contains($tw, 'ts-econ-revenue') && str_contains($tw, 'ts-econ-cost') && str_contains($tw, 'ts-econ-gp') && str_contains($tw, 'ts-econ-gp-pct'));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
