<?php
/**
 * Sprint 6g — Financial statements no longer 500 + DataWarning component +
 * smarter global exception handler.
 *
 *   php -d zend.assertions=1 /app/tests/sprint6g_financial_statements_smoke.php
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

echo "Reusable DataWarning component\n";
$dw = (string) file_get_contents("{$ROOT}/dashboard/src/components/DataWarning.jsx");
$assert('DataWarning component exists',                 strlen($dw) > 0);
$assert('exports default function',                     stripos($dw, 'export default function DataWarning') !== false);
$assert('renders nothing when text empty',              stripos($dw, 'if (!text) return null') !== false);
$assert('exposes data-warning testid',                  stripos($dw, 'data-testid="data-warning"') !== false);
$assert('uses amber palette (#fffbeb / #fde68a)',       stripos($dw, '#fffbeb') !== false && stripos($dw, '#fde68a') !== false);

echo "\nReports endpoint — graceful failure (BS / IS / TB / CF)\n";
$rp = (string) file_get_contents("{$ROOT}/modules/accounting/api/reports.php");
$assert('reports.php parses',                           $lint("{$ROOT}/modules/accounting/api/reports.php"));
$assert('introduces _safeReport wrapper',               stripos($rp, 'function _safeReport') !== false);
$assert('catches throwable in wrapper',                 stripos($rp, 'catch (\\Throwable $e)') !== false);
$assert('emits data_warning instead of throwing',       stripos($rp, "'data_warning'") !== false);
$assert('error_log captures original',                  stripos($rp, "error_log('accounting/reports failed:") !== false);
$assert('income_statement uses _safeReport',            preg_match("#income_statement.*_safeReport#s", $rp) === 1);
$assert('balance_sheet uses _safeReport',               preg_match("#balance_sheet.*_safeReport#s", $rp) === 1);
$assert('trial_balance uses _safeReport',               preg_match("#trial_balance.*_safeReport#s", $rp) === 1);
$assert('cash_flow_indirect uses _safeReport',          preg_match("#cash_flow_indirect.*_safeReport#s", $rp) === 1);

$je = (string) file_get_contents("{$ROOT}/modules/accounting/api/journal_entries.php");
$assert('journal_entries.php parses',                   $lint("{$ROOT}/modules/accounting/api/journal_entries.php"));
$assert('trial_balance action wrapped in try/catch',    preg_match("#action === 'trial_balance'.*?try\\s*\\{.*?catch\\s*\\(\\s*\\\\Throwable#s", $je) === 1);
$assert('trial_balance fallback emits data_warning',    preg_match("#action === 'trial_balance'.*?'data_warning'#s", $je) === 1);

echo "\nFinancial statement UIs — DataWarning wired\n";
foreach ([
    'BalanceSheet'        => 'rpt-bs',
    'IncomeStatement'     => 'rpt-pnl',
    'TrialBalance'        => 'rpt-tb',
    'CashFlowStatement'   => 'rpt-cf',
] as $component => $sectionTestid) {
    $code = (string) file_get_contents("{$ROOT}/modules/accounting/ui/{$component}.jsx");
    $assert("{$component}: imports DataWarning",
        stripos($code, "import DataWarning from '../../../dashboard/src/components/DataWarning'") !== false);
    $assert("{$component}: renders DataWarning when data_warning present",
        preg_match('#current\?.data_warning\s*&&\s*\(?\s*\n?\s*<DataWarning#s', $code) === 1);
    // Pass-2 overhaul: every report renders ReportShell with a stable
    // testIdPrefix so testids cascade under e.g. 'rpt-bs', 'rpt-pnl' etc.
    $assert("{$component}: ReportShell testIdPrefix='{$sectionTestid}'",
        stripos($code, "testIdPrefix=\"{$sectionTestid}\"") !== false);
}

$bs = (string) file_get_contents("{$ROOT}/modules/accounting/ui/BalanceSheet.jsx");
$ic = (string) file_get_contents("{$ROOT}/modules/accounting/ui/IncomeStatement.jsx");
$cf = (string) file_get_contents("{$ROOT}/modules/accounting/ui/CashFlowStatement.jsx");
$assert('BalanceSheet uses array-shape guard before render',
    stripos($bs, 'Array.isArray(current.assets)') !== false && stripos($bs, '{safe && (') !== false);
$assert('IncomeStatement uses array-shape guard',
    stripos($ic, 'Array.isArray(current.revenue)') !== false && stripos($ic, '{safe && (') !== false);
$assert('CashFlowStatement uses sections guard',
    stripos($cf, 'current.sections') !== false && stripos($cf, '{safe && (') !== false);

echo "\nGlobal exception handler — clearer 500 messages\n";
$ab = (string) file_get_contents("{$ROOT}/core/api_bootstrap.php");
$assert('api_bootstrap.php parses',                     $lint("{$ROOT}/core/api_bootstrap.php"));
$assert('detects "Unknown column" SQL error',           stripos($ab, "Unknown column '") !== false);
$assert('surfaces clean SQL error message (SQLSTATE)',  stripos($ab, 'SQLSTATE') !== false && stripos($ab, 'Database error') !== false);
$assert('redacts file paths from SQL message',          stripos($ab, "preg_replace('/in ") !== false);
$assert('replaces literal "Internal server error" w/ details',
                                                        stripos($ab, "'Server error: ' . \$msg") !== false);
$assert('still logs the underlying message + line',     stripos($ab, "error_log('[api] '") !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
