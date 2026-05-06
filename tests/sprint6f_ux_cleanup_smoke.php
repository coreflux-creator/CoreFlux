<?php
/**
 * Sprint 6f — Bank Rec UX cleanup + Reports resilience + sidebar/icon
 * variety + AccountsList close/reopen + duplicate Reports sidebar removal.
 *
 *   php -d zend.assertions=1 /app/tests/sprint6f_ux_cleanup_smoke.php
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $name, bool $cond, ?string $hint = null) use (&$pass, &$fail): void {
    if ($cond) { echo "  ✓ {$name}\n"; $pass++; }
    else       { echo "  ✗ {$name}" . ($hint ? "  ({$hint})" : '') . "\n"; $fail++; }
};
$lint = function (string $p): bool {
    $o = []; $rc = 0; @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
    return $rc === 0;
};
$ROOT = realpath(__DIR__ . '/..');

echo "Reports overview API — graceful failure\n";
$ov = (string) file_get_contents("{$ROOT}/modules/reports/api/overview.php");
$assert('overview.php parses',                            $lint("{$ROOT}/modules/reports/api/overview.php"));
$assert('wraps SQL in try/catch(\\Throwable)',            preg_match("#try\\s*\\{[^}]*staffingKpiTotals[^}]*staffingWeeklySeries[^}]*\\}\\s*catch\\s*\\(\\s*\\\\Throwable#s", $ov) === 1);
$assert('catch returns zeroed payload (no 500)',          stripos($ov, "'revenue'=>0") !== false && stripos($ov, "'cost'=>0") !== false);
$assert('catch builds friendly data_warning',             stripos($ov, "data_warning") !== false && stripos($ov, "Reports data view not yet built") !== false);
$assert('error_log captures original failure',            stripos($ov, "error_log('reports/overview SQL failed:") !== false);
$assert('api_ok envelope includes data_warning key',      stripos($ov, "'data_warning'   => \$dataError") !== false);

$so = (string) file_get_contents("{$ROOT}/modules/reports/ui/StaffingOverview.jsx");
$assert('StaffingOverview shows data_warning banner',     stripos($so, 'data-testid="reports-overview-warning"') !== false);
$assert('warning labelled "Data not ready yet"',          stripos($so, 'Data not ready yet') !== false);

echo "\nDuplicate Reports sidebar removed\n";
$rm = (string) file_get_contents("{$ROOT}/modules/reports/ui/ReportsModule.jsx");
$assert('ReportsModule no longer imports ReportsSidebar', stripos($rm, "import ReportsSidebar") === false);
$assert('ReportsModule no longer renders <ReportsSidebar/>',
    stripos($rm, '<ReportsSidebar') === false);
$assert('ReportsModule explanatory comment present',      stripos($rm, 'dropped the inner ReportsSidebar wrapper') !== false);

echo "\nGlobal Sidebar — expanded iconMap (per-route variety)\n";
$sb = (string) file_get_contents("{$ROOT}/dashboard/src/layout/Sidebar.jsx");
foreach ([
    'Gauge','BookOpen','FileText','Layers','BarChart3','Calendar','Tags','ClipboardCheck',
    'Banknote','Repeat','Building2','Network','Coins','Wallet','HandCoins','Activity','Wrench',
    'CreditCard','Receipt','BadgeDollarSign','Hourglass','CheckSquare','PieChart','FileSearch',
    'AlertTriangle','Folder','Clock','CalendarClock','Briefcase','UserCircle','Users','FilePlus2',
    'FileCheck2','TrendingUp','BarChart','ScrollText','Shield','Settings','Sparkles','FolderTree',
    'Mail','Inbox','ListChecks','Target',
] as $icon) {
    $assert("imports lucide icon: {$icon}",  stripos($sb, $icon) !== false);
}
foreach ([
    'staffing-overview','executive-snapshot','client-profitability','rate-spread','overtime-watch',
    'custom-reports','other-reports','dimensions','close','bank-rec','reconciliations','recurring',
    'audit-log','ai-accuracy','export-templates','deposit-accounts','liability-accounts',
    'placements','onboarding','mail','inbox','tasks','goals',
] as $route) {
    $assert("iconMap key registered: '{$route}'", stripos($sb, "'{$route}'") !== false);
}

echo "\nDashboard module cards — per-module icon + colour\n";
$ui = (string) file_get_contents("{$ROOT}/dashboard/src/components/UIComponents.jsx");
$assert('moduleVisuals map exists',                       stripos($ui, 'moduleVisuals = {') !== false);
$assert('per-module visual fallback to Building2',        stripos($ui, '{ Icon: Building2') !== false);
$assert('moduleVisual() lookup helper present',           stripos($ui, 'function moduleVisual(') !== false);
$assert('module-card-{id} testid for e2e',                stripos($ui, 'data-testid={`module-card-${mod.id}`}') !== false);
foreach (['accounting','ap','billing','people','hiring','payroll','time','treasury','reports','workflow','ai'] as $mod) {
    $assert("module visual: {$mod}",        stripos($ui, "{$mod}:") !== false);
}

echo "\nBank accounts API — close + reopen + filter\n";
$ba = (string) file_get_contents("{$ROOT}/modules/accounting/api/bank_accounts.php");
$assert('bank_accounts.php parses',                       $lint("{$ROOT}/modules/accounting/api/bank_accounts.php"));
$assert('accepts ?include_closed',                        stripos($ba, "\$_GET['include_closed']") !== false);
$assert('accepts ?status filter',                         stripos($ba, "\$_GET['status']") !== false);
$assert('default excludes status=closed',                 stripos($ba, "status <> 'closed'") !== false);
$assert('returns counts block',                           stripos($ba, "'counts' => \$counts") !== false);
$assert('exposes plaid_account_id in row',                stripos($ba, 'plaid_account_id') !== false);
$assert('?action=reopen flips back to active',            stripos($ba, "\$action === 'reopen'") !== false && stripos($ba, "['status' => 'active']") !== false);
$assert('reopen audited',                                 stripos($ba, 'accounting.bank_account.reopened') !== false);

echo "\nBankReconciliation UI — close/reopen + show-closed toggle\n";
$br = (string) file_get_contents("{$ROOT}/modules/accounting/ui/BankReconciliation.jsx");
$assert('AccountsList tracks showClosed state',           stripos($br, 'useState(false)') !== false && stripos($br, 'showClosed') !== false);
$assert('"Show closed" checkbox testid',                  stripos($br, 'data-testid="accounting-bank-accounts-show-closed"') !== false);
$assert('counts displayed in header',                     stripos($br, 'counts.active') !== false);
$assert('close button per row testid',                    stripos($br, 'data-testid={`accounting-bank-account-close-${a.id}`}') !== false);
$assert('reopen button per row testid',                   stripos($br, 'data-testid={`accounting-bank-account-reopen-${a.id}`}') !== false);
$assert('row dim when status === closed',                 stripos($br, "a.status === 'closed' ? 0.55 : 1") !== false);
$assert('status badge per row testid',                    stripos($br, 'data-testid={`accounting-bank-account-status-${a.id}`}') !== false);
$assert('confirm dialog before close',                    stripos($br, 'Close this bank account?') !== false);
$assert('feed shows plaid badge',                         stripos($br, "a.plaid_account_id ? ' · plaid' : ''") !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
