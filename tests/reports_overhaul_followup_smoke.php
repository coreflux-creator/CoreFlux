<?php
/**
 * reports_overhaul_followup_smoke.php
 *
 * Locks in the two Reports-Overhaul follow-up features:
 *
 *   1. Save Report Snapshot — `/api/admin/reports/save_snapshot.php`
 *      lands the current data envelope as an `evidence_attachments`
 *      row tagged `document_type='report_snapshot'`. ReportShell
 *      surfaces the "Save snapshot" button when `snapshotEnvelope` is
 *      passed. All 4 Tier-1 reports pass the envelope.
 *
 *   2. Drill-through audit trail — `/api/admin/reports/log_drilldown.php`
 *      logs every GlDetailDrilldown open into `report_drilldown_log`
 *      (migration 081). ReportShell exposes a "Recent drills" popover
 *      that lists the operator's last 10 distinct drills with
 *      one-click replay. GlDetailDrilldown fires the log POST silently.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ✓ {$name}\n"; }
    else     { $fail++; echo "  ✗ {$name}\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "Reports Overhaul Follow-up smoke\n";
echo "================================\n\n";

$ROOT = dirname(__DIR__);

// --- Migration 081 -------------------------------------------------
echo "Migration 081 — report_drilldown_log\n";
$mig = $read("{$ROOT}/core/migrations/081_report_drilldown_log.sql");
$a('creates report_drilldown_log',           str_contains($mig, 'CREATE TABLE IF NOT EXISTS report_drilldown_log'));
$a('tenant + user fk columns',               str_contains($mig, 'tenant_id   BIGINT UNSIGNED NOT NULL')
                                            && str_contains($mig, 'user_id     BIGINT UNSIGNED NOT NULL'));
$a('report_key VARCHAR(40)',                 str_contains($mig, 'report_key  VARCHAR(40)'));
$a('account_code NULLable (non-GL drills)',  str_contains($mig, 'account_code VARCHAR(40)    NULL'));
$a('opened_at default NOW',                  str_contains($mig, 'opened_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'));
$a('idx_rdl_recent composite',               str_contains($mig, 'idx_rdl_recent (tenant_id, user_id, report_key, opened_at DESC)'));
$a('idx_rdl_account_window for audit',       str_contains($mig, 'idx_rdl_account_window'));
$a('idempotent (CREATE TABLE IF NOT EXISTS)', str_contains($mig, 'IF NOT EXISTS'));

// --- log_drilldown.php endpoint ------------------------------------
echo "\nlog_drilldown.php endpoint\n";
$ep = $read("{$ROOT}/api/admin/reports/log_drilldown.php");
$a('endpoint exists', $ep !== '');
$a('bootstrap + RBAC require',               str_contains($ep, 'api_bootstrap.php') && str_contains($ep, 'RBAC.php'));
$a('gated by accounting.coa.view',           str_contains($ep, "rbac_legacy_require(\$user, 'accounting.coa.view')"));
$a('POST inserts a row',                     str_contains($ep, 'INSERT INTO report_drilldown_log'));
$a('POST returns 204 (fire-and-forget)',     str_contains($ep, 'http_response_code(204)'));
$a('POST validates report_key required',     str_contains($ep, "report_key required"));
$a('POST validates YYYY-MM-DD format',       str_contains($ep, "must be YYYY-MM-DD"));
$a('GET returns recent drills',              str_contains($ep, 'SELECT account_code, period_from, period_to, label,'));
$a('GET de-dups by scope (GROUP BY)',        str_contains($ep, 'GROUP BY account_code, period_from, period_to, label'));
$a('GET orders by MAX(opened_at) DESC',      str_contains($ep, 'ORDER BY MAX(opened_at) DESC'));
$a('GET caps limit to 50',                   str_contains($ep, "min(50, (int) (api_query('limit') ?? 10))"));
$a('write failures soft-fail with error_log', str_contains($ep, '[log_drilldown POST]'));

// --- save_snapshot.php endpoint ------------------------------------
echo "\nsave_snapshot.php endpoint\n";
$ep2 = $read("{$ROOT}/api/admin/reports/save_snapshot.php");
$a('endpoint exists', $ep2 !== '');
$a('reuses evidence_attachments pivot',      str_contains($ep2, "INSERT INTO evidence_attachments"));
$a('document_type=report_snapshot tag',      str_contains($ep2, "'report_snapshot'"));
$a('subject_type=tenant (polymorphic)',      str_contains($ep2, "'tenant'"));
$a('POST validates 256KB payload cap',       str_contains($ep2, 'snapshot payload exceeds 256KB'));
$a('GET ?id returns full envelope',          str_contains($ep2, "'envelope'    => \$decoded['envelope']"));
$a('GET list filters by report_key',         str_contains($ep2, '%"report_key":"'));
$a('GET list returns params (light)',        str_contains($ep2, "skip envelope to keep response light"));
$a('attached_by_user_id is current user',    str_contains($ep2, '$uid'));
$a('RBAC: accounting.coa.view',              str_contains($ep2, "rbac_legacy_require(\$user, 'accounting.coa.view')"));

// --- ReportShell wiring --------------------------------------------
echo "\nReportShell — Save Snapshot + Recent Drills surfaces\n";
$shell = $read("{$ROOT}/dashboard/src/components/ReportShell.jsx");
$a('SaveSnapshotButton component',           str_contains($shell, 'function SaveSnapshotButton'));
$a('RecentDrillsPicker component',           str_contains($shell, 'function RecentDrillsPicker'));
$a('Save Snapshot POSTs to endpoint',        str_contains($shell, "'/api/admin/reports/save_snapshot.php'"));
$a('Recent Drills GETs from endpoint',       str_contains($shell, "/api/admin/reports/log_drilldown.php?report_key="));
$a('Save button hidden when no envelope',    str_contains($shell, '{snapshotEnvelope && (')
                                            && str_contains($shell, '<SaveSnapshotButton'));
$a('Recent button hidden when no onReplay',  str_contains($shell, '{onReplayDrill && (')
                                            && str_contains($shell, '<RecentDrillsPicker'));
$a('Save button testid suffix -save-snapshot',
                                              str_contains($shell, '`${testIdPrefix}-save-snapshot`'));
$a('Recent button testid suffix -recent-drills',
                                              str_contains($shell, '`${testIdPrefix}-recent-drills`'));
$a('Recent popover dedup ×count badge',      str_contains($shell, 'it.open_count > 1'));
$a('Flash banner after save',                str_contains($shell, '`${testIdPrefix}-flash`'));
$a('Recent click fires onReplay callback',   str_contains($shell, 'onReplay({')
                                            && str_contains($shell, 'account_code: it.account_code'));

// --- GlDetailDrilldown wiring --------------------------------------
echo "\nGlDetailDrilldown — fire-and-forget log\n";
$drill = $read("{$ROOT}/dashboard/src/components/GlDetailDrilldown.jsx");
$a('accepts reportKey prop',                 str_contains($drill, 'reportKey   = null,'));
$a('POSTs to log_drilldown when reportKey set',
                                              str_contains($drill, "/api/admin/reports/log_drilldown.php"));
$a('log is fire-and-forget (.catch silent)', str_contains($drill, '.catch(() => {})'));
$a('log includes account_code + window',     str_contains($drill, 'account_code: accountCode || null,')
                                            && str_contains($drill, 'period_from:  start,')
                                            && str_contains($drill, 'period_to:    end,'));

// --- Tier-1 statements adoption ------------------------------------
echo "\nTier-1 statements wire snapshotEnvelope + onReplayDrill + reportKey\n";
foreach ([
    'IncomeStatement'   => 'rpt-pnl',
    'BalanceSheet'      => 'rpt-bs',
    'TrialBalance'      => 'rpt-tb',
    'CashFlowStatement' => 'rpt-cf',
] as $component => $key) {
    $code = $read("{$ROOT}/modules/accounting/ui/{$component}.jsx");
    $a("{$component}: passes snapshotEnvelope when loaded",
        str_contains($code, 'snapshotEnvelope={current ?'));
    $a("{$component}: passes onReplayDrill",
        str_contains($code, 'onReplayDrill={(d) => setDrill({'));
    $a("{$component}: GlDetailDrilldown carries reportKey=\"{$key}\"",
        str_contains($code, "reportKey=\"{$key}\""));
}

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
