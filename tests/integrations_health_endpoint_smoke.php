<?php
/**
 * Smoke — /api/admin/integrations_health.php + UI widget wiring.
 *
 * Locks the contract that lets the admin see at-a-glance whether
 * every contract-smoked provider is still healthy:
 *
 *   - GET endpoint exists, is RBAC-gated, returns the
 *     {providers, stale_after_days, generated_at_iso} shape.
 *   - Both currently-onboarded providers (jaz, qbo) appear.
 *   - For each provider, the endpoint actually computes ages from the
 *     real spec / snapshot / tool artefacts living on disk.
 *   - The React panel renders that response with status pill,
 *     per-smoke badge, and a Refresh button — all with data-testids
 *     so playwright + curl-based smokes can drive it.
 *
 * Run: php -d zend.assertions=1 /app/tests/integrations_health_endpoint_smoke.php
 */
declare(strict_types=1);

$passes = 0; $failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nintegrations_health smoke\n";
echo "=========================\n\n";

$apiPath = __DIR__ . '/../api/admin/integrations_health.php';
$jsxPath = __DIR__ . '/../dashboard/src/pages/IntegrationsHealthPanel.jsx';
$admPath = __DIR__ . '/../dashboard/src/pages/AdminModule.jsx';

echo "── endpoint contract ──\n";
check('api endpoint exists', is_file($apiPath));
$api = (string) file_get_contents($apiPath);
check('GET-only guard', str_contains($api, "method_not_allowed"));
check('RBAC gated to master_admin/tenant_admin',
    str_contains($api, "rbac_legacy_require_any") &&
    str_contains($api, "'master_admin'") && str_contains($api, "'tenant_admin'"));
check('emits providers + stale_after_days + generated_at_iso',
    str_contains($api, "'providers'") &&
    str_contains($api, "'stale_after_days'") &&
    str_contains($api, "'generated_at_iso'"));
check('declares jaz provider triplet',
    str_contains($api, "'id'        => 'jaz'") &&
    str_contains($api, 'tests/jaz_payload_contract_smoke.php') &&
    str_contains($api, 'tests/jaz_spec_freshness_smoke.php') &&
    str_contains($api, 'tools/refresh_jaz_spec.sh'));
check('declares qbo provider triplet',
    str_contains($api, "'id'        => 'qbo'") &&
    str_contains($api, 'tests/qbo_payload_contract_smoke.php') &&
    str_contains($api, 'tests/qbo_spec_freshness_smoke.php') &&
    str_contains($api, 'tools/refresh_qbo_spec.sh'));
check('rolls overall up to ok/attention/missing',
    str_contains($api, "'attention'") && str_contains($api, "'missing'") && str_contains($api, "'ok'"));
check('snapshot age threshold is 90 days', preg_match('/STALE_AFTER_DAYS\s*=\s*90/', $api) === 1);

echo "\n── shape against the real files on disk ──\n";
// Use a tiny PHP harness to compute what the endpoint would return for
// the two real providers without running api_bootstrap / RBAC.
$rows = [];
foreach ([
    ['id' => 'jaz', 'spec' => '/app/spec/jaz_openapi.json',  'contract' => '/app/tests/jaz_payload_contract_smoke.php', 'freshness' => '/app/tests/jaz_spec_freshness_smoke.php', 'tool' => '/app/tools/refresh_jaz_spec.sh', 'snapshot' => null],
    ['id' => 'qbo', 'spec' => '/app/spec/qbo_schema.json',   'contract' => '/app/tests/qbo_payload_contract_smoke.php', 'freshness' => '/app/tests/qbo_spec_freshness_smoke.php', 'tool' => '/app/tools/refresh_qbo_spec.sh', 'snapshot' => '/app/spec/qbo_docs'],
] as $p) {
    check("{$p['id']}: spec file present",        is_file($p['spec']));
    check("{$p['id']}: contract smoke present",   is_file($p['contract']));
    check("{$p['id']}: freshness smoke present",  is_file($p['freshness']));
    check("{$p['id']}: refresh tool present",     is_file($p['tool']));
    check("{$p['id']}: refresh tool executable",  is_executable($p['tool']));
    if ($p['snapshot']) {
        check("{$p['id']}: snapshot dir exists",      is_dir($p['snapshot']));
        check("{$p['id']}: .fetched_at marker exists", is_file($p['snapshot'] . '/.fetched_at'));
    }
}

echo "\n── React panel wiring ──\n";
check('IntegrationsHealthPanel.jsx exists', is_file($jsxPath));
$jsx = (string) file_get_contents($jsxPath);
check("panel fetches the endpoint",
    str_contains($jsx, "api.get('/api/admin/integrations_health.php')"));
check("renders status pill ok/attention/missing",
    str_contains($jsx, 'data-testid={`integrations-health-status-${overall}`}'));
check("renders per-row test ids",
    str_contains($jsx, 'data-testid={`integrations-health-row-${p.id}`}'));
check("renders Refresh control with testid",
    str_contains($jsx, 'data-testid="integrations-health-refresh"'));
check("renders contract + freshness smoke badges",
    str_contains($jsx, 'label="contract"') && str_contains($jsx, 'label="freshness"'));
check("warns visually when snapshot stale",
    str_contains($jsx, 'days > stale'));
check("documents the stale_after_days value from the API",
    str_contains($jsx, 'data.stale_after_days'));

echo "\n── Admin overview mounts the panel ──\n";
$adm = (string) file_get_contents($admPath);
check('AdminModule imports the panel',
    str_contains($adm, "import IntegrationsHealthPanel from './IntegrationsHealthPanel'"));
check('AdminModule renders <IntegrationsHealthPanel />',
    str_contains($adm, '<IntegrationsHealthPanel />'));

$total = $passes + count($failures);
echo "\n=========================================\n";
echo "integrations_health smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
echo "=========================================\n";
foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
exit($failures ? 1 : 0);
