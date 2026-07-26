<?php
/**
 * Smoke - JobDiva alignment hardening.
 *
 * Locks in the boundary fixes behind the operator-facing complaint:
 * mapping aliases must not abort projection, Mapping Studio writes must
 * coerce typed CoreFlux fields, and JobDiva must not seed stale W2 defaults.
 */
declare(strict_types=1);

$ROOT = realpath(__DIR__ . '/..');
$entityMappings = (string) file_get_contents("{$ROOT}/core/integrations/entity_mappings.php");
$fieldMap = (string) file_get_contents("{$ROOT}/core/integrations/field_map.php");
$apply = (string) file_get_contents("{$ROOT}/core/integrations/field_map_apply.php");
$sync = (string) file_get_contents("{$ROOT}/core/jobdiva/sync.php");
$placements = (string) file_get_contents("{$ROOT}/core/jobdiva/sync_placements.php");
$projector = (string) file_get_contents("{$ROOT}/core/jobdiva/projector.php");
$alignment = (string) file_get_contents("{$ROOT}/core/jobdiva/mapping_alignment.php");
$alignmentApi = (string) file_get_contents("{$ROOT}/api/admin/integrations/jobdiva_mapping_alignment.php");
$settingsUi = (string) file_get_contents("{$ROOT}/dashboard/src/pages/JobDivaSettings.jsx");

$pass = 0; $fail = 0;
$a = function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
    if ($ok) { echo "  OK {$label}\n"; $pass++; }
    else { echo "  FAIL {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n"; $fail++; }
};

echo "JobDiva alignment hardening smoke\n";
echo "=================================\n";

echo "\n1. Mapping aliases reconcile instead of aborting projection\n";
$a('mappingUpsert catches 23000 unique conflicts',
    str_contains($entityMappings, 'catch (\PDOException $e)')
    && str_contains($entityMappings, "(string) \$e->getCode() !== '23000'"));
$a('alias conflict updates the internal mapping row',
    str_contains($entityMappings, '$byInternal = mappingFindExternal')
    && str_contains($entityMappings, 'DELETE FROM external_entity_mappings')
    && str_contains($entityMappings, 'SET external_id        = :ext'));

echo "\n2. Mapping Studio writes are typed, not raw SQL strings\n";
foreach ([
    'integrationFieldMapCoerceTargetValue',
    'integrationFieldMapEngagementValue',
    'integrationFieldMapPersonClassificationValue',
    'integrationFieldMapDateValue',
    'integrationFieldMapPercentValue',
    'integrationFieldMapNumberValue',
] as $fn) {
    $a("{$fn} exists", str_contains($apply, "function {$fn}("));
}
$a('applyAll coerces target values before bucketing writes',
    str_contains($apply, '$val = integrationFieldMapCoerceTargetValue($val, $m);'));
$a('placement engagement and rate economics are explicitly coerced',
    str_contains($apply, "\$table === 'placements' && \$col === 'engagement_type'")
    && str_contains($apply, "'bill_rate', 'pay_rate', 'flat_amount'")
    && str_contains($apply, 'return integrationFieldMapNumberValue($val);'));

echo "\n3. Tenant mappings can explicitly win over built-in engagement scans\n";
$a('field map exposes an explicit internal-field presence helper',
    str_contains($fieldMap, 'function tenantIntegrationFieldMapHasInternal('));
$a('placement engagement checks explicit tenant mapping before source scan',
    str_contains($sync, 'tenantIntegrationFieldMapHasInternal($tid, \'jobdiva\', \'placement\', \'engagement_type\')')
    && str_contains($sync, '$hasMappedEngagement && $mappedEngagement !== \'\''));
$a('assignment/job engagement evidence is scanned before candidate classification',
    strpos($sync, "'_jd_start', 'assignment'") !== false
    && strpos($sync, "'_jd_candidate', 'person', 'candidate'") !== false
    && strpos($sync, "'_jd_start', 'assignment'") < strpos($sync, "'_jd_candidate', 'person', 'candidate'"));

echo "\n4. People graph no longer poisons placement workflow with W2 defaults\n";
$a('person classification derives from JobDiva evidence',
    str_contains($placements, 'function jobdivaPersonClassificationFromPlacementPayload(')
    && str_contains($placements, 'jobdivaInferPlacementEngagementTypeFromPayload($jd, \'\')'));
$a('unknown person classification defaults to candidate, not W2',
    str_contains($placements, "default => 'candidate'")
    && !str_contains($placements, "'cls' => 'w2'"));
$a('person insert uses derived classification',
    str_contains($placements, "'cls' => \$classification"));

echo "\n5. Economics source coverage is broader than the original BI subset\n";
$a('rate writer includes approved/candidate/employee/min pay aliases',
    str_contains($sync, "'approved pay rate'")
    && str_contains($sync, "'candidate pay rate'")
    && str_contains($sync, "'employee pay rate'")
    && str_contains($sync, "'PAYRATEMIN'"));
$a('vendor chain writer includes supplier/vendor discount aliases',
    str_contains($sync, "'vendor company'")
    && str_contains($sync, "'supplier company'")
    && str_contains($sync, "'vendor discount pct'")
    && str_contains($sync, "'discount amount'"));

echo "\n6. Repair alignment force-reprojects the live placement rows\n";
$a('projector accepts and strips force_projection sentinel',
    str_contains($projector, "\$writePayload['__cf_force_projection'] = true")
    && str_contains($projector, "unset(\$writePayload['__cf_force_projection']);"));
$a('placement upsert bypasses coreflux_overridden_fields only under forced repair',
    str_contains($sync, "\$forceProjection = !empty(\$jd['__cf_force_projection']);")
    && str_contains($sync, 'if (!$forceProjection) {')
    && str_contains($sync, 'SELECT coreflux_overridden_fields FROM placements'));
$a('mirror reproject path passes force_projection into projector',
    str_contains($sync, "'force_projection' => true"));
$a('repair workflow defaults/caps to full placement-sized batches',
    str_contains($alignment, 'function jobdivaMappingRepairWorkflow(int $tenantId, array $user, int $limit = 5000)')
    && str_contains($alignment, 'min(5000, $limit)')
    && str_contains($alignmentApi, '$limit = isset($body[\'limit\']) ? (int) $body[\'limit\'] : 5000')
    && str_contains($settingsUi, "repair_workflow', { limit: 5000 }"));
$a('repair totals count projected placements, not only mapping writes',
    str_contains($alignment, "\$changed += (int) (\$step['projected'] ?? 0);"));

echo "\n7. PHP syntax\n";
foreach ([
    "{$ROOT}/core/integrations/entity_mappings.php",
    "{$ROOT}/core/integrations/field_map.php",
    "{$ROOT}/core/integrations/field_map_apply.php",
    "{$ROOT}/core/jobdiva/sync.php",
    "{$ROOT}/core/jobdiva/sync_placements.php",
    "{$ROOT}/core/jobdiva/projector.php",
    "{$ROOT}/core/jobdiva/mapping_alignment.php",
    "{$ROOT}/api/admin/integrations/jobdiva_mapping_alignment.php",
] as $file) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($file) . ' 2>&1', $out, $rc);
    $a('php -l ' . basename($file), $rc === 0, implode("\n", $out));
}

echo "\nJobDiva alignment hardening smoke: {$pass} OK / {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
