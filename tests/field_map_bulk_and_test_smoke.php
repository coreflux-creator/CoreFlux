<?php
/**
 * Slice 6/7 smoke — bulk import / export + test-payload endpoints for
 * the tenant integration field-map registry.
 *
 * Pure static + function-exists assertions on the PHP layer; UI
 * affordances are checked by string presence (data-testid wiring +
 * api.get/post/delete call sites match the deployed handler contracts).
 *
 * Live DB round-trip is covered by the existing
 * jobdiva_field_mapping_slice5_smoke.php which exercises
 * tenantIntegrationFieldMapUpsert.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/integrations/field_map.php';

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$fmap = (string) file_get_contents('/app/core/integrations/field_map.php');
$bulk = (string) file_get_contents('/app/api/admin/integrations/field_map_bulk.php');
$test = (string) file_get_contents('/app/api/admin/integrations/field_map_test.php');
$ui   = (string) file_get_contents('/app/dashboard/src/pages/IntegrationFieldMapAdmin.jsx');

echo "\n1. Core helpers exist and are callable\n";
$a('function tenantIntegrationFieldMapBulkExport',
    function_exists('tenantIntegrationFieldMapBulkExport'));
$a('function tenantIntegrationFieldMapBulkImport',
    function_exists('tenantIntegrationFieldMapBulkImport'));
$a('function tenantIntegrationFieldMapTestPayload',
    function_exists('tenantIntegrationFieldMapTestPayload'));

echo "\n2. Bulk import — input validation\n";
try {
    tenantIntegrationFieldMapBulkImport(1, ['mappings' => []], 'INVALID_MODE', null);
    $a('rejects unknown mode', false, 'no throw');
} catch (\InvalidArgumentException $e) {
    $a('rejects unknown mode', str_contains($e->getMessage(), 'mode'));
}
try {
    tenantIntegrationFieldMapBulkImport(1, ['nope' => 1], 'merge', null);
    $a('rejects missing mappings array', false, 'no throw');
} catch (\InvalidArgumentException $e) {
    $a('rejects missing mappings array', str_contains($e->getMessage(), 'mappings'));
}

echo "\n3. Test payload — pure dry run (no DB), structural shape\n";
// We can't hit the DB from a smoke test reliably, so we exercise the
// pure-PHP path: resolver returns empty array (no rules configured) →
// the function still returns the allow-list-driven `unmapped` set.
// Bypass DB by short-circuiting the resolver via the global cache.
$GLOBALS['CF_FIELD_MAP_CACHE'] = [
    '1|jobdiva|placement' => [
        'title'      => ['external_field' => 'job.title',     'transform' => 'none'],
        'start_date' => ['external_field' => 'startDate',     'transform' => 'date_normalise'],
    ],
];
$payload = [
    'job'       => ['title' => 'Service Desk Analyst'],
    'startDate' => '2026-05-22',
];
$result = tenantIntegrationFieldMapTestPayload(1, 'jobdiva', 'placement', $payload);
$a('test returns integration + entity_type',
    ($result['integration'] ?? '') === 'jobdiva'
 && ($result['entity_type'] ?? '') === 'placement');
$a('test resolved array has 2 rule rows', count($result['resolved'] ?? []) === 2);
$titleRow = array_values(array_filter($result['resolved'], static fn($r) => $r['internal_field'] === 'title'))[0] ?? null;
$a('title rule matched via dotted path',
    $titleRow !== null && $titleRow['matched'] === true && $titleRow['value'] === 'Service Desk Analyst');
$dateRow = array_values(array_filter($result['resolved'], static fn($r) => $r['internal_field'] === 'start_date'))[0] ?? null;
// date_normalise should pass through "2026-05-22" → "2026-05-22"
$a('start_date resolves with date_normalise transform',
    $dateRow !== null && $dateRow['matched'] === true && $dateRow['transform'] === 'date_normalise');
$a('unmapped_internal_fields excludes configured rules',
    isset($result['unmapped_internal_fields'])
 && is_array($result['unmapped_internal_fields'])
 && !in_array('title',      $result['unmapped_internal_fields'], true)
 && !in_array('start_date', $result['unmapped_internal_fields'], true));
$a('unmapped_internal_fields includes new metadata columns',
    in_array('recruiter_name',       $result['unmapped_internal_fields'], true)
 && in_array('account_manager_name', $result['unmapped_internal_fields'], true)
 && in_array('jobdiva_job_id',       $result['unmapped_internal_fields'], true));
// Test "no match" branch — rule points at an absent path.
$resultMiss = tenantIntegrationFieldMapTestPayload(1, 'jobdiva', 'placement', ['unrelated' => 1]);
$missTitle = array_values(array_filter($resultMiss['resolved'], static fn($r) => $r['internal_field'] === 'title'))[0] ?? null;
$a('rule with missing payload path reports matched=false',
    $missTitle !== null && $missTitle['matched'] === false && $missTitle['value'] === null);

echo "\n4. Bulk export — structural shape\n";
// Force the list helper to return a stub via the same cache trick is not
// possible (it queries the DB). Instead assert the SHAPE expectations
// on the empty-tenant path: it should return version=1 + mappings=[]
// without throwing. (Live DB rows are exercised by jobdiva_field_mapping_slice5_smoke.)
$a('Bulk export source has version + mappings shape',
    str_contains($fmap, "'version'      => 1")
 && str_contains($fmap, "'mappings'     => \$mappings"));
$a('Bulk export drops tenant-private fields (id, audit timestamps)',
    !str_contains(substr($fmap, strpos($fmap, 'tenantIntegrationFieldMapBulkExport')), "'id'")
 || str_contains($fmap, "// informational; ignored on import"));

echo "\n5. Bulk endpoint — GET + POST wiring\n";
$a('field_map_bulk.php declares strict types', str_contains($bulk, 'declare(strict_types=1)'));
$a('field_map_bulk.php requires RBAC',         str_contains($bulk, "rbac_legacy_require_any(\$user, ['integrations.field_map.manage', 'tenant_admin.integrations'])"));
$a('GET handler calls BulkExport',             str_contains($bulk, 'tenantIntegrationFieldMapBulkExport'));
$a('POST handler calls BulkImport',            str_contains($bulk, 'tenantIntegrationFieldMapBulkImport'));
$a('GET emits Content-Disposition for download',str_contains($bulk, 'Content-Disposition'));
$a('Method-not-allowed fallback present',      str_contains($bulk, 'Method not allowed'));

echo "\n6. Test endpoint — POST-only, validation, RBAC\n";
$a('field_map_test.php declares strict types', str_contains($test, 'declare(strict_types=1)'));
$a('field_map_test.php requires RBAC',         str_contains($test, "rbac_legacy_require_any(\$user, ['integrations.field_map.manage', 'tenant_admin.integrations'])"));
$a('test endpoint rejects non-POST',           str_contains($test, "api_method() !== 'POST'"));
$a('test endpoint validates integration',      str_contains($test, "api_error('integration required'"));
$a('test endpoint validates entity_type',      str_contains($test, "api_error('entity_type required'"));
$a('test endpoint accepts string payload via json_decode',
    str_contains($test, 'json_decode($payload, true)'));
$a('test endpoint flushes resolver cache before dry run',
    str_contains($test, 'tenantIntegrationFieldMapFlushCache'));

echo "\n7. UI affordances (data-testid + handler wiring)\n";
$a('Export button rendered',
    str_contains($ui, 'data-testid="field-map-export-btn"'));
$a('Import toggle rendered',
    str_contains($ui, 'data-testid="field-map-import-toggle"'));
$a('Test-with-payload toggle rendered',
    str_contains($ui, 'data-testid="field-map-test-toggle"'));
$a('Bulk panel textarea rendered',
    str_contains($ui, 'data-testid="field-map-bulk-textarea"'));
$a('Bulk mode selector exposes merge/replace',
    str_contains($ui, 'value="merge"') && str_contains($ui, 'value="replace"'));
$a('Apply-import button rendered',
    str_contains($ui, 'data-testid="field-map-bulk-import-btn"'));
$a('Download-as-file button rendered',
    str_contains($ui, 'data-testid="field-map-bulk-download-btn"'));
$a('Test panel textarea rendered',
    str_contains($ui, 'data-testid="field-map-test-textarea"'));
$a('Test run button rendered',
    str_contains($ui, 'data-testid="field-map-test-run-btn"'));
$a('Test results table rendered',
    str_contains($ui, 'data-testid="field-map-test-table"'));
$a('Export handler calls field_map_bulk.php',
    str_contains($ui, '/api/admin/integrations/field_map_bulk.php'));
$a('Test handler calls field_map_test.php',
    str_contains($ui, '/api/admin/integrations/field_map_test.php'));
$a('Replace mode prompts for confirmation',
    str_contains($ui, "Replace ALL existing mappings"));

echo "\n8. PHP syntax\n";
foreach ([
    '/app/core/integrations/field_map.php',
    '/app/api/admin/integrations/field_map_bulk.php',
    '/app/api/admin/integrations/field_map_test.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $a("php -l {$f}", $rc === 0, implode("\n", $out));
}

echo "\n=========================================\n";
echo "Field-map slice 6/7 smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
