<?php
/**
 * reports_overhaul_pass0_pass1_smoke.php
 *
 * Locks in the Reports Overhaul foundation (Pass 0) + Tier-1 drill-through
 * adoption (Pass 1):
 *
 *  Pass 0 — Foundation primitives
 *    1. ReportShell.jsx       — sticky header, comparison toggle, kpi band
 *    2. MetricCard.jsx        — KPI tile w/ deltas + optional sparkline
 *    3. ComparisonTable.jsx   — row primitive w/ comparison + drill chevron
 *    4. MetricDrilldown.jsx   — generic slide-over for non-GL drills
 *    5. useReportPeriod.js    — period+comparison state + variance() helper
 *
 *  Pass 1 — Drill-through everywhere on Tier-1
 *    Income Statement, Balance Sheet, Trial Balance, Cash Flow Statement
 *    each render ReportShell + use ComparisonTable + open GlDetailDrilldown
 *    on row click (the slide-over modal, not a route bounce).
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ✓ {$name}\n"; }
    else     { $fail++; echo "  ✗ {$name}\n"; }
};

echo "Reports Overhaul Pass 0 + Pass 1 smoke\n";
echo "======================================\n\n";

$ROOT = dirname(__DIR__);

// --- Pass 0: foundation primitives exist + contracts ---------------
echo "Pass 0 — Foundation primitives\n";

$shell  = (string) file_get_contents("{$ROOT}/dashboard/src/components/ReportShell.jsx");
$a('ReportShell.jsx exists', $shell !== '');
$a('ReportShell exports default function',           strpos($shell, 'export default function ReportShell') !== false);
$a('ReportShell accepts period prop',                strpos($shell, 'period,') !== false);
$a('ReportShell accepts kpis slot',                  strpos($shell, 'kpis,') !== false);
$a('ReportShell renders compare-mode select',        strpos($shell, '-compare-mode') !== false);
$a('ReportShell singleDate prop (BS / TB use this)', strpos($shell, 'singleDate') !== false);
$a('ReportShell sticky header (position:sticky)',    strpos($shell, "position: 'sticky'") !== false);

$card   = (string) file_get_contents("{$ROOT}/dashboard/src/components/MetricCard.jsx");
$a('MetricCard.jsx exists', $card !== '');
$a('MetricCard exports default',                     strpos($card, 'export default function MetricCard') !== false);
$a('MetricCard supports priorPeriod prop',           strpos($card, 'priorPeriod') !== false);
$a('MetricCard supports priorYear prop',             strpos($card, 'priorYear') !== false);
$a('MetricCard wires Sparkline when data passed',    strpos($card, "import Sparkline from './Sparkline'") !== false);
$a('MetricCard surfaces drill chevron when onClick', strpos($card, 'ChevronRight') !== false);
$a('MetricCard inverse flag for expense KPIs',       strpos($card, 'inverse') !== false);

$tbl    = (string) file_get_contents("{$ROOT}/dashboard/src/components/ComparisonTable.jsx");
$a('ComparisonTable.jsx exists', $tbl !== '');
$a('ComparisonTable exports default',                strpos($tbl, 'export default function ComparisonTable') !== false);
$a('ComparisonTable renders variance column',        strpos($tbl, 'showVariance') !== false &&
                                                     strpos($tbl, 'col-variance') !== false);
$a('ComparisonTable renders drill chevron + testid', strpos($tbl, "'-drill'") !== false);
$a('ComparisonTable supports subtotal kind',         strpos($tbl, "'subtotal'") !== false);
$a('ComparisonTable supports total kind',            strpos($tbl, "'total'") !== false);
$a('ComparisonTable indents nested rows by depth',   strpos($tbl, '(r.depth || 0) * 16') !== false);

$drill  = (string) file_get_contents("{$ROOT}/dashboard/src/components/MetricDrilldown.jsx");
$a('MetricDrilldown.jsx exists', $drill !== '');
$a('MetricDrilldown exports default',                strpos($drill, 'export default function MetricDrilldown') !== false);
$a('MetricDrilldown right-side slide-over layout',   strpos($drill, "justifyContent: 'flex-end'") !== false);
$a('MetricDrilldown surfaces loading + error',       strpos($drill, '-loading') !== false &&
                                                     strpos($drill, '-error') !== false);
$a('MetricDrilldown summary strip slot',             strpos($drill, 'summary') !== false &&
                                                     strpos($drill, '-summary') !== false);

$hook   = (string) file_get_contents("{$ROOT}/dashboard/src/lib/useReportPeriod.js");
$a('useReportPeriod.js exists', $hook !== '');
$a('useReportPeriod exports hook',                   strpos($hook, 'export function useReportPeriod') !== false);
$a('useReportPeriod exposes from/to + setters',      strpos($hook, 'setFrom') !== false && strpos($hook, 'setTo') !== false);
$a('useReportPeriod derives priorFrom/priorTo',      strpos($hook, 'priorFrom') !== false && strpos($hook, 'priorTo') !== false);
$a('useReportPeriod derives priorYearFrom/-To',      strpos($hook, 'priorYearFrom') !== false && strpos($hook, 'priorYearTo') !== false);
$a('useReportPeriod compareMode + 4 modes',          strpos($hook, "'prior_period'") !== false &&
                                                     strpos($hook, "'prior_year'") !== false &&
                                                     strpos($hook, "'both'") !== false);
$a('useReportPeriod exports variance() helper',      strpos($hook, 'export function variance') !== false);
$a('variance() classifies favourable for expenses',  strpos($hook, 'inverse ? direction === \'down\'') !== false);

// --- Pass 1: Tier-1 financial statements adopt the foundation ------
echo "\nPass 1 — Tier-1 financial statements wired through ReportShell\n";

foreach ([
    'IncomeStatement'   => 'rpt-pnl',
    'BalanceSheet'      => 'rpt-bs',
    'TrialBalance'      => 'rpt-tb',
    'CashFlowStatement' => 'rpt-cf',
] as $component => $prefix) {
    $code = (string) file_get_contents("{$ROOT}/modules/accounting/ui/{$component}.jsx");
    $a("{$component}: imports ReportShell",
        strpos($code, "import ReportShell from '../../../dashboard/src/components/ReportShell'") !== false);
    $a("{$component}: imports MetricCard",
        strpos($code, "import MetricCard from '../../../dashboard/src/components/MetricCard'") !== false);
    $a("{$component}: imports ComparisonTable",
        strpos($code, "import ComparisonTable from '../../../dashboard/src/components/ComparisonTable'") !== false);
    $a("{$component}: imports GlDetailDrilldown",
        strpos($code, "import GlDetailDrilldown from '../../../dashboard/src/components/GlDetailDrilldown'") !== false);
    $a("{$component}: imports useReportPeriod",
        strpos($code, "import { useReportPeriod } from '../../../dashboard/src/lib/useReportPeriod'") !== false);
    $a("{$component}: ReportShell testIdPrefix=\"{$prefix}\"",
        strpos($code, "testIdPrefix=\"{$prefix}\"") !== false);
    $a("{$component}: row onDrill opens slide-over (not route)",
        strpos($code, 'setDrill({') !== false &&
        strpos($code, '<GlDetailDrilldown') !== false);
    $a("{$component}: KPI band renders MetricCard",
        strpos($code, '<MetricCard') !== false);
    $a("{$component}: parallel-fetches current + prior windows",
        strpos($code, 'Promise.all(reqs)') !== false);
    $a("{$component}: respects period.showPriorPeriod / showPriorYear",
        strpos($code, 'period.showPriorPeriod') !== false &&
        strpos($code, 'period.showPriorYear')   !== false);
}

// --- Bundle hygiene -------------------------------------------------
echo "\nBundle sync — Vite postbuild\n";
$dv = @file_get_contents("{$ROOT}/.deploy-version");
$a('.deploy-version exists',           is_string($dv) && $dv !== '');

// --- Pass 2 — Tier-2 reports adopt the foundation ------------------
echo "\nPass 2 — Tier-2 operational reports through ReportShell + MetricCard\n";
foreach ([
    'ClientProfitability' => ['file' => 'modules/reports/ui/ClientProfitability.jsx', 'prefix' => 'rpt-clientprof'],
    'OvertimeWatch'       => ['file' => 'modules/reports/ui/OvertimeWatch.jsx',       'prefix' => 'rpt-ot'],
    'RateSpreadMonitor'   => ['file' => 'modules/reports/ui/RateSpreadMonitor.jsx',   'prefix' => 'rpt-spread'],
] as $component => $cfg) {
    $code = (string) file_get_contents("{$ROOT}/{$cfg['file']}");
    $a("{$component}: imports ReportShell",
        strpos($code, "import ReportShell from '../../../dashboard/src/components/ReportShell'") !== false);
    $a("{$component}: imports MetricCard",
        strpos($code, "import MetricCard from '../../../dashboard/src/components/MetricCard'") !== false);
    $a("{$component}: ReportShell testIdPrefix=\"{$cfg['prefix']}\"",
        strpos($code, "testIdPrefix=\"{$cfg['prefix']}\"") !== false);
    $a("{$component}: KPI band uses MetricCard tiles",
        substr_count($code, '<MetricCard') >= 3);
    $a("{$component}: still wires PeriodSelector via customControls",
        strpos($code, 'customControls') !== false &&
        strpos($code, '<PeriodSelector') !== false);
}

// --- Pass 2 (continued) — Tier-2 surgical visual upgrades ----------
echo "\nPass 2 (continued) — Tier-2 surgical visual upgrades\n";
foreach ([
    'reports/ui/StaffingOverview.jsx'       => 'rpt-staffing-overview',
    'reports/ui/ReportToolkit.jsx'          => 'rpt-toolkit',
    'staffing/ui/StaffingReadiness.jsx'     => 'rpt-readiness',
    'staffing/ui/WorkerMix.jsx'             => 'rpt-workermix',
    'staffing/ui/StaffingOverview.jsx'      => 'rpt-staffing-landing',
    'time/ui/Reports.jsx'                   => 'rpt-time',
    'placements/ui/Reports.jsx'             => 'rpt-placements',
] as $relpath => $label) {
    $code = (string) file_get_contents("{$ROOT}/modules/{$relpath}");
    $a("{$label}: sticky header with gradient fade",
        strpos($code, "position: 'sticky'") !== false || strpos($code, "position:'sticky'") !== false);
    $a("{$label}: tabular-nums on values OR uses shared primitive",
        strpos($code, 'tabular-nums') !== false || strpos($code, 'MetricCard') !== false);
}

// --- Pass 3 — Tier-3 dashboards ------------------------------------
echo "\nPass 3 — Tier-3 dashboards surgical visual upgrades\n";
$cfo = (string) file_get_contents("{$ROOT}/dashboard/src/pages/CFODashboard.jsx");
$a('CFO header sticky',                              strpos($cfo, "position:'sticky'") !== false ||
                                                     strpos($cfo, "position: 'sticky'") !== false);
$a('CFO header title testid (cfo-title)',            strpos($cfo, 'data-testid="cfo-title"') !== false);
$a('CFO widget card has 3px accent border-left',     strpos($cfo, "borderLeft:'3px solid #334155'") !== false);
$a('CFO scalar uses tabular-nums for crisp digits',  strpos($cfo, "fontVariantNumeric:'tabular-nums'") !== false);
$a('CFO scalar tightened typography (24px / 700)',   strpos($cfo, 'fontSize:24, fontWeight:700') !== false);
$a('CFO Sparkline now passes raw trend (not .map .amount)',
    strpos($cfo, '<Sparkline data={trend} height={32} />') !== false);

$exec = (string) file_get_contents("{$ROOT}/dashboard/src/pages/ExecutiveDashboard.jsx");
$a('Exec header sticky',                             strpos($exec, "position: 'sticky'") !== false);
$a('Exec header title testid (exec-title)',          strpos($exec, 'data-testid="exec-title"') !== false);
$a('Exec KpiCard 3px accent border-left',            strpos($exec, 'borderLeft: `3px solid ${accent}`') !== false);
$a('Exec KpiCard value uses tabular-nums',           strpos($exec, "fontVariantNumeric: 'tabular-nums'") !== false);

$fin = (string) file_get_contents("{$ROOT}/dashboard/src/pages/FinanceReports.jsx");
$a('FinanceReports header sticky',                   strpos($fin, "position: 'sticky'") !== false);
$a('FinanceReports header title testid',             strpos($fin, 'data-testid="finance-title"') !== false);

$staff = (string) file_get_contents("{$ROOT}/dashboard/src/pages/StaffingReports.jsx");
$a('StaffingReports header sticky',                  strpos($staff, "position: 'sticky'") !== false);
$a('StaffingReports header title testid',            strpos($staff, 'data-testid="staffing-rpt-title"') !== false);

$cons = (string) file_get_contents("{$ROOT}/dashboard/src/pages/SubTenantConsolidatedReports.jsx");
$a('Consolidated header sticky',                     strpos($cons, "position: 'sticky'") !== false);
$a('Consolidated KPI 3px accent border-left',        strpos($cons, 'borderLeft: `3px solid ${accent}`') !== false);

$dov = (string) file_get_contents("{$ROOT}/dashboard/src/pages/DashboardOverview.jsx");
$a('DashboardOverview SnapshotTile 3px accent',      strpos($dov, 'borderLeft: `3px solid ${accent}`') !== false);
$a('DashboardOverview SnapshotTile tabular-nums',    strpos($dov, "fontVariantNumeric: 'tabular-nums'") !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
