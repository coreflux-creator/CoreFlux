<?php
/**
 * Audit Snapshot smoke (2026-02).
 *
 *   1. /api/cfo_audit_snapshot.php endpoint shape, auth gate, date validation.
 *   2. AuditSnapshot.jsx page exists, gated, wired into /cfo/audit-snapshot.
 *   3. CFODashboard exposes the new toolbar link.
 *   4. /auditor.php now lands auditors at /cfo/audit-snapshot.
 *   5. CFOGuard + api_require_cfo() also permit role='auditor'.
 *
 *   php -d zend.assertions=1 /app/tests/audit_snapshot_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};

// 1. Endpoint -----------------------------------------------------------------
$ep = $ROOT . '/api/cfo_audit_snapshot.php';
$a('snapshot endpoint exists', file_exists($ep));
$src = (string) file_get_contents($ep);
$a('gated via api_require_cfo()',         str_contains($src, 'api_require_cfo()'));
$a('rejects invalid date input (422)',    (bool) preg_match('/api_error\("Invalid date for/', $src));
$a('returns tenant header (name + logo)', str_contains($src, "'logo_url'"));
$a('returns period.label',                str_contains($src, "'label'") && str_contains($src, "DateTimeImmutable"));
$a('returns totals with 6 KPIs',          str_contains($src, "'revenue_total'")
                                       && str_contains($src, "'collected_total'")
                                       && str_contains($src, "'ap_total'")
                                       && str_contains($src, "'ar_open'")
                                       && str_contains($src, "'ap_open'")
                                       && str_contains($src, "'net_margin_pct'"));
$a('returns auditor_scope (is_auditor/modules/expires_at)',
    str_contains($src, "'is_auditor'") && str_contains($src, "'modules'") && str_contains($src, "'expires_at'"));
$a('every $totals query is wrapped in try/catch',
    substr_count($src, 'try {') >= 5 && substr_count($src, "} catch (\\Throwable") >= 5);
$a('every $totals SQL filters by tenant_id',
    substr_count($src, 'tenant_id = :t') >= 5);

// 2. Frontend component -------------------------------------------------------
$ui = $ROOT . '/dashboard/src/pages/AuditSnapshot.jsx';
$a('AuditSnapshot.jsx exists', file_exists($ui));
$uiSrc = (string) file_get_contents($ui);
$a('component has data-testid="audit-snapshot"',          str_contains($uiSrc, 'data-testid="audit-snapshot"'));
$a('renders tenant name + logo',                          str_contains($uiSrc, 'audit-snapshot-tenant') && str_contains($uiSrc, 'tenant.logo_url'));
$a('renders the 6 KPI tiles',                             str_contains($uiSrc, 'kpi-revenue') && str_contains($uiSrc, 'kpi-margin'));
$a('Print button calls window.print()',                   str_contains($uiSrc, 'window.print()'));
$a('@media print stylesheet hides non-print chrome',      str_contains($uiSrc, '@media print') && str_contains($uiSrc, "visibility: hidden"));
$a('hides the global auditor banner on print',            str_contains($uiSrc, '[data-testid="auditor-banner"] { display: none'));
$a('shows auditor scope explainer block when is_auditor', str_contains($uiSrc, 'audit-snapshot-scope'));
$a('from/to date pickers re-fetch on change',             str_contains($uiSrc, "useEffect") && str_contains($uiSrc, '[from, to]'));
$a('lives behind CFOGuard on /cfo/audit-snapshot',        true /* checked in App.jsx assertion below */);

// 3. App.jsx wiring -----------------------------------------------------------
$app = (string) file_get_contents($ROOT . '/dashboard/src/App.jsx');
$a('App.jsx imports AuditSnapshot',
    str_contains($app, "import AuditSnapshot from './pages/AuditSnapshot'"));
$a('App.jsx /cfo/audit-snapshot is CFOGuard-wrapped',
    (bool) preg_match('#/cfo/audit-snapshot[^<]*<CFOGuard[^>]*>\s*<AuditSnapshot#', $app));

// 4. CFODashboard toolbar link ------------------------------------------------
$cfo = (string) file_get_contents($ROOT . '/dashboard/src/pages/CFODashboard.jsx');
$a('CFODashboard toolbar exposes "Audit snapshot" link',
    str_contains($cfo, 'cfo-audit-snapshot-btn') && str_contains($cfo, 'to="/cfo/audit-snapshot"'));

// 5. /auditor.php redirects to snapshot --------------------------------------
$entry = (string) file_get_contents($ROOT . '/auditor.php');
$a('/auditor.php default landing is the audit snapshot',
    str_contains($entry, '/spa.php#/cfo/audit-snapshot'));

// 6. Auditor role permitted by CFO gates --------------------------------------
$bootstrap = (string) file_get_contents($ROOT . '/core/api_bootstrap.php');
$a("api_require_cfo() permits role='auditor' / auditor_mode session",
    str_contains($bootstrap, "role === 'auditor'") && str_contains($bootstrap, "auditor_mode"));

$guard = (string) file_get_contents($ROOT . '/dashboard/src/pages/CFOGuard.jsx');
$a("CFOGuard.jsx permits role='auditor'",
    str_contains($guard, "'auditor'") && str_contains($guard, "tenant_admin"));

// 7. PHP syntax sanity --------------------------------------------------------
foreach (['api/cfo_audit_snapshot.php','auditor.php','core/api_bootstrap.php'] as $rel) {
    $rc = 0; $out = [];
    exec('php -l ' . escapeshellarg($ROOT . '/' . $rel) . ' 2>&1', $out, $rc);
    $a("php -l $rel", $rc === 0);
}

echo "\n=========================================\n";
echo "Audit Snapshot smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
