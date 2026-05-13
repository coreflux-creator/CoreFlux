<?php
/**
 * Smoke: CFO Dashboard (Phase 1 — 2026-02).
 *
 * Pins:
 *   • /api/exec_dashboard.php extended with DSO, DPO, unapplied cash,
 *     upcoming starts/terminations, prior_period comparison (not just YoY).
 *   • Saved views support a widget_config_json column for visibility/order.
 *   • New endpoints: cfo_notes, cfo_annotate, cfo_send_report, cfo_formulas.
 *   • Migration 035_cfo_dashboard_extras.sql creates the supporting tables.
 *   • SPA CFODashboard.jsx wired into App.jsx + Header.jsx nav.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "exec_dashboard CFO extras\n";
$exec = $read(__DIR__ . '/../api/exec_dashboard.php');
$a('compare=prior_period accepted',           str_contains($exec, "in_array(\$compare, ['prior_year', 'prior_period']"));
$a('prior_period window = preceding same-length',
    str_contains($exec, "// prior_period (or default)") || str_contains($exec, "immediately preceding"));
$a('finance.dso scalar present',              str_contains($exec, "'dso'            => null"));
$a('finance.dpo scalar present',              str_contains($exec, "'dpo'            => null"));
$a('finance.unapplied_cash present',          str_contains($exec, "'unapplied_cash' => 0"));
$a('DSO computation references revenue last 90', str_contains($exec, 'DSO — AR balance'));
$a('DPO computation references bills  last 90', str_contains($exec, 'DPO — AP balance'));
$a('unapplied cash sums billing_payments',    str_contains($exec, 'billing_payments'));
$a('upcoming_starts (next 30 days)',          str_contains($exec, 'upcoming_starts') && str_contains($exec, "+30 days"));
$a('upcoming_terminations (next 30 days)',    str_contains($exec, "upcoming_terminations") && str_contains($exec, "termination_date BETWEEN"));
$a('compare.scalars block in api_ok',         str_contains($exec, "'scalars'   => \$prevScalars"));
$a('prevScalars include revenue + payroll',   str_contains($exec, "'revenue'") && str_contains($exec, "'payroll'") && str_contains($exec, "'window_from'"));

echo "\nSaved views widget_config\n";
$views = $read(__DIR__ . '/../api/exec_dashboard_views.php');
$a('widget_config in POST insert',            str_contains($views, 'widget_config_json'));
$a('widget_config in PATCH update',           str_contains($views, "array_key_exists('widget_config', \$body)"));
$a('widget_config serialized in response',    str_contains($views, "'widget_config' => is_array(\$widgets)"));
$a('widget_config size capped (32 KB)',       str_contains($views, '32768'));

echo "\nCFO endpoints\n";
foreach (['cfo_notes.php','cfo_annotate.php','cfo_send_report.php','cfo_formulas.php'] as $f) {
    $a("endpoint exists: api/{$f}",            is_file(__DIR__ . '/../api/' . $f));
}
$notes = $read(__DIR__ . '/../api/cfo_notes.php');
$a('cfo_notes CRUD has POST/DELETE',           str_contains($notes, "'POST'") && str_contains($notes, "'DELETE'"));
$a('cfo_notes scopes by tenant + user',        str_contains($notes, 'tenant_id = :t') && str_contains($notes, 'user_id = :u'));

$ann = $read(__DIR__ . '/../api/cfo_annotate.php');
$a('cfo_annotate calls aiAsk()',               str_contains($ann, 'aiAsk('));
$a('cfo_annotate gracefully handles AIDisabled',str_contains($ann, 'AIDisabledException'));
$a('cfo_annotate per-widget system prompts',   str_contains($ann, "finance.dso") && str_contains($ann, "finance.dpo") && str_contains($ann, "finance.unapplied_cash"));
$a('cfo_annotate forbids raw number restatement', str_contains($ann, 'Do NOT restate raw numbers'));

$send = $read(__DIR__ . '/../api/cfo_send_report.php');
$a('send_report validates recipients (email + count)',
    str_contains($send, 'FILTER_VALIDATE_EMAIL') && str_contains($send, 'Too many recipients'));
$a('send_report uses mailerSend when available', str_contains($send, "function_exists('mailerSend')"));
$a('send_report returns preview_html for QA',   str_contains($send, "'preview_html'"));
$a('send_report audit-logs cfo.report_sent',    str_contains($send, "'cfo.report_sent'"));
$a('send_report includes annotation + note in body', str_contains($send, "'annotation'") && str_contains($send, "'note'"));

$formulas = $read(__DIR__ . '/../api/cfo_formulas.php');
$a('cfo_formulas whitelist of keys',           str_contains($formulas, 'CFO_FORMULA_KEYS'));
$a('cfo_formulas whitelist of operators',      str_contains($formulas, "['+','-','*','/','pct_of']"));
$a('cfo_formulas resolver has no eval()',      !preg_match('/\beval\s*\(/', $formulas) && str_contains($formulas, '_cfoFormulaResolve'));
$a('cfo_formulas evaluate endpoint',           str_contains($formulas, "\$action === 'evaluate'"));
$a('cfo_formulas divide-by-zero guard',        str_contains($formulas, '$b == 0.0 ? null'));

echo "\nMigration + SPA wiring\n";
$mig = $read(__DIR__ . '/../core/migrations/035_cfo_dashboard_extras.sql');
$a('migration adds widget_config_json',        str_contains($mig, 'ADD COLUMN IF NOT EXISTS widget_config_json'));
$a('migration creates cfo_section_notes',      str_contains($mig, 'CREATE TABLE IF NOT EXISTS cfo_section_notes'));
$a('migration creates cfo_custom_formulas',    str_contains($mig, 'CREATE TABLE IF NOT EXISTS cfo_custom_formulas'));

$spa = $read(__DIR__ . '/../dashboard/src/App.jsx');
$a('SPA imports CFODashboard',                 str_contains($spa, "import CFODashboard from './pages/CFODashboard'"));
$a('SPA routes /cfo to CFODashboard',          str_contains($spa, 'path="/cfo"') && str_contains($spa, '<CFODashboard'));

$hdr = $read(__DIR__ . '/../dashboard/src/layout/Header.jsx');
$a('Header surfaces CFO link',                 str_contains($hdr, 'data-testid="header-cfo-link"') && str_contains($hdr, 'to="/cfo"'));

$ui = $read(__DIR__ . '/../dashboard/src/pages/CFODashboard.jsx');
foreach (['finance.dso','finance.dpo','finance.unapplied_cash','staffing.upcoming','staffing.headcount'] as $key) {
    $a("widget registered: {$key}",            str_contains($ui, "key: '{$key}'"));
}
$a('toolbar: window picker',                   str_contains($ui, 'data-testid="cfo-window-picker"'));
$a('toolbar: compare picker',                  str_contains($ui, 'data-testid="cfo-compare-picker"'));
$a('toolbar: view picker',                     str_contains($ui, 'data-testid="cfo-view-picker"'));
$a('toolbar: edit toggle',                     str_contains($ui, 'data-testid="cfo-edit-toggle"'));
$a('toolbar: save view',                       str_contains($ui, 'data-testid="cfo-save-view"'));
$a('toolbar: send report',                     str_contains($ui, 'data-testid="cfo-send-report"'));
$a('toolbar: custom KPI',                      str_contains($ui, 'data-testid="cfo-formulas-btn"'));
$a('Save View modal name+default+shared',      str_contains($ui, 'cfo-save-name') && str_contains($ui, 'cfo-save-default') && str_contains($ui, 'cfo-save-shared'));
$a('Send Report modal recipients+subject+intro', str_contains($ui, 'cfo-send-recipients') && str_contains($ui, 'cfo-send-subject') && str_contains($ui, 'cfo-send-intro'));
$a('Formula builder modal A op B + format',    str_contains($ui, 'cfo-formula-a') && str_contains($ui, 'cfo-formula-op') && str_contains($ui, 'cfo-formula-b') && str_contains($ui, 'cfo-formula-format'));
$a('Per-widget annotation button',             str_contains($ui, 'data-testid={`cfo-annotate-btn-${widgetKey}`}'));
$a('Per-widget note add/save/cancel',          str_contains($ui, 'cfo-note-add-') && str_contains($ui, 'cfo-note-save-') && str_contains($ui, 'cfo-note-cancel-'));
$a('DeltaBadge surfaces period-over-period',   str_contains($ui, "data-testid=\"cfo-delta-badge\""));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
