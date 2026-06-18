<?php
/**
 * Smoke — /api/admin/integration_triage.php aggregator.
 *
 * Locks:
 *   - endpoint exists, auth-gated
 *   - reads from all three sources (qbo_push_failures, qbo_sync_drift,
 *     payment_instructions Failed) with try/catch fallthrough so a
 *     missing source table doesn't break the response
 *   - merges into the unified row shape
 *   - applies severity-rank sort
 *   - synthesises playbook stubs for drift kinds
 *   - frontend page exists with required testids and route is wired
 *
 * Run: php -d zend.assertions=1 /app/tests/integration_triage_smoke.php
 */
declare(strict_types=1);

$passes = 0; $failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nIntegration triage smoke\n";
echo "========================\n\n";

// ────────────────────── 1. Endpoint shape ──
echo "── endpoint ──\n";
$path = '/app/api/admin/integration_triage.php';
check('endpoint exists', file_exists($path));
$src = (string) file_get_contents($path);
check('calls api_require_auth',            str_contains($src, 'api_require_auth()'));
check('enforces master_admin or tenant_admin',
    str_contains($src, "rbac_legacy_require_any") && str_contains($src, 'master_admin'));
check('imports both error playbooks',
    str_contains($src, 'qbo/error_playbook.php') && str_contains($src, 'mercury/error_playbook.php'));
check('reads qbo_push_failures (qbo-dlq)',     str_contains($src, 'FROM qbo_push_failures'));
check('reads qbo_sync_drift (qbo-drift)',      str_contains($src, 'FROM qbo_sync_drift'));
check('reads payment_instructions (mercury-failed)',
    str_contains($src, 'FROM payment_instructions'));
check('each source wrapped in try/catch (missing tables = soft-skip)',
    substr_count($src, "catch (\\Throwable \$_) { /* table missing") >= 2);
check('synthesises playbook stub for drift kinds',
    str_contains($src, '_triageDriftFix('));
check('row shape includes source + severity + summary + playbook + meta + actionable',
    str_contains($src, "'source'") && str_contains($src, "'severity'") &&
    str_contains($src, "'playbook'") && str_contains($src, "'meta'") &&
    str_contains($src, "'actionable'"));
check('severity-rank sort applied (critical > warn > info)',
    preg_match('/usort.*?critical.*?warn.*?info/s', $src) === 1);
check('counts payload includes by_source breakdown',
    str_contains($src, "'by_source'"));

// ────────────────────── 2. Drift fix table covers known kinds ──
echo "\n── drift fix coverage ──\n";
foreach (['paid_out_of_band','balance_changed','voided_in_qbo','amount_changed','qbo_only_orphan'] as $k) {
    check("_triageDriftFix has a stanza for '{$k}'", str_contains($src, "'{$k}'"));
}

// ────────────────────── 3. Frontend page ──
echo "\n── frontend page ──\n";
$jsxPath = '/app/dashboard/src/pages/IntegrationTriage.jsx';
check('IntegrationTriage.jsx exists', file_exists($jsxPath));
$jsx = (string) file_get_contents($jsxPath);
check('fetches /api/admin/integration_triage.php',
    str_contains($jsx, "api.get('/api/admin/integration_triage.php')"));
foreach ([
    'integration-triage-page', 'integration-triage-list',
    'integration-triage-counts', 'integration-triage-empty',
    'integration-triage-refresh',
    'integration-triage-filter-severity', 'integration-triage-filter-source',
] as $tid) {
    check("has data-testid='{$tid}'", str_contains($jsx, "data-testid=\"{$tid}\"") || str_contains($jsx, "'{$tid}'"));
}
// Per-severity count pills are templated (`integration-triage-count-${testid}`).
check("count pills use templated data-testid 'integration-triage-count-<sev>'",
    str_contains($jsx, "integration-triage-count-\${testid}"));
foreach (['critical', 'warn', 'info', 'total'] as $sev) {
    check("count pill instantiated for '{$sev}'",
        str_contains($jsx, "testid=\"{$sev}\""));
}
check('renders severity pills critical/warn/info',
    str_contains($jsx, 'critical:') && str_contains($jsx, 'warn:') && str_contains($jsx, 'info:'));
check('per-source action button calls correct POST endpoint',
    str_contains($jsx, '/api/admin/qbo/dead_letters.php') &&
    str_contains($jsx, '/api/admin/qbo/sync_drift.php') &&
    str_contains($jsx, '/api/admin/mercury/failed_payments.php'));
check('expanded row shows playbook suggested_fix',
    str_contains($jsx, 'playbook?.suggested_fix') || str_contains($jsx, 'playbook.suggested_fix'));
check('expanded row exposes raw vendor body when present',
    str_contains($jsx, 'vendor_raw'));

// ────────────────────── 4. Routing ──
echo "\n── routing wired in AdminModule.jsx ──\n";
$adm = (string) file_get_contents('/app/dashboard/src/pages/AdminModule.jsx');
check('imports IntegrationTriage', str_contains($adm, "import IntegrationTriage from './IntegrationTriage'"));
check('route /admin/integrations/triage is declared',
    str_contains($adm, "path=\"/integrations/triage\""));
check('sidebar nav has Integration triage entry',
    str_contains($adm, "label: 'Integration triage'"));

$total = $passes + count($failures);
echo "\n=========================================\n";
echo "integration_triage smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
echo "=========================================\n";
foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
exit($failures ? 1 : 0);
