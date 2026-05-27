<?php
/**
 * Smoke — Phase 3 enhancement: Test Mappings panel on the Studio
 * + generalised dry-run evaluator wired through the existing
 * /api/admin/integrations/field_map_test.php endpoint.
 *
 * Asserts:
 *   1. integrationFieldMapTestPayloadGeneralised() exists with the
 *      correct shape (resolves source_path, falls back to legacy
 *      external_field, applies transform, surfaces target identity,
 *      tracks matched/unmatched totals).
 *   2. field_map_test.php endpoint attaches a `generalised` block
 *      alongside the legacy `resolved` shape.
 *   3. FieldMappingStudio.jsx exposes the new Test panel:
 *      toggle button, textarea, run/clear buttons, results table
 *      with per-row testid + data-matched attribute, totals header.
 *   4. PHP syntax stays clean.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/integrations/field_map_apply.php';

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

echo "\n1. integrationFieldMapTestPayloadGeneralised — shape + behaviour\n";
$a('function declared',
    function_exists('integrationFieldMapTestPayloadGeneralised'));

$apply = (string) file_get_contents('/app/core/integrations/field_map_apply.php');
$a('resolves source_path via integrationPayloadResolvePath',
    str_contains($apply, "integrationPayloadResolvePath(\$payload, (string) \$m['source_path'])"));
$a('falls back to legacy external_field via tenantIntegrationFieldMapPluckPath',
    str_contains($apply, "tenantIntegrationFieldMapPluckPath(\$payload, (string) \$m['external_field'])"));
$a('applies transform when matched + non-none',
    str_contains($apply, "tenantIntegrationFieldMapApplyTransform(\$val, (string) \$m['transform'])"));
$a('counts matched / unmatched in totals',
    str_contains($apply, "if (\$isMatched) \$matched++; else \$unmatched++;"));
$a('emits human-readable target including linked_entity',
    str_contains($apply, "'%s.%s.%s (linked=%s)'"));
$a('preserves enabled + resolved flags on each row',
    str_contains($apply, "'enabled'        => !empty(\$m['enabled'])")
    && str_contains($apply, "'resolved'       => !empty(\$m['resolved'])"));

echo "\n2. /api/admin/integrations/field_map_test.php — generalised block attached\n";
$api = (string) file_get_contents('/app/api/admin/integrations/field_map_test.php');
$a('requires field_map_apply.php',
    str_contains($api, "require_once __DIR__ . '/../../../core/integrations/field_map_apply.php';"));
$a('attaches generalised result alongside legacy resolved shape',
    str_contains($api, "\$result['generalised'] = integrationFieldMapTestPayloadGeneralised("));

echo "\n3. FieldMappingStudio.jsx — Test panel UI\n";
$ui = (string) file_get_contents('/app/dashboard/src/pages/FieldMappingStudio.jsx');
$a('test panel toggle button',
    str_contains($ui, 'data-testid="fms-test-toggle"'));
$a('test panel root testid + conditional render',
    str_contains($ui, 'data-testid="fms-test-pane"')
    && str_contains($ui, '{testOpen && ('));
$a('test panel JSON textarea + clear + run buttons',
    str_contains($ui, 'data-testid="fms-test-input"')
    && str_contains($ui, 'data-testid="fms-test-clear"')
    && str_contains($ui, 'data-testid="fms-test-run"'));
$a('handleTestRun posts to /api/admin/integrations/field_map_test.php',
    str_contains($ui, "api.post('/api/admin/integrations/field_map_test.php', {"));
$a('handleTestRun validates JSON before posting',
    str_contains($ui, 'payload = JSON.parse(testInput || \'{}\');')
    && str_contains($ui, "setError('Sample payload must be valid JSON: '"));
$a('test results render generalised.results with per-row testid + data-matched',
    str_contains($ui, '(testResult.generalised?.results || []).map')
    && str_contains($ui, 'data-testid={`fms-test-row-${r.mapping_id}`}')
    && str_contains($ui, 'data-matched={r.matched ? ' . "'yes' : 'no'}"));
$a('totals header reads from testResult.generalised.totals',
    str_contains($ui, 'testResult.generalised?.totals?.matched')
    && str_contains($ui, 'testResult.generalised?.totals?.total'));
$a('matched rows render "would write" badge, unmatched render "no value"',
    str_contains($ui, '✓ would write')
    && str_contains($ui, '✗ no value'));

echo "\n4. PHP syntax\n";
foreach ([
    '/app/core/integrations/field_map_apply.php',
    '/app/api/admin/integrations/field_map_test.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $a("php -l {$f}", $rc === 0, implode("\n", $out));
}

echo "\n=========================================\n";
echo "Field-mapping Phase 3 Test panel smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
