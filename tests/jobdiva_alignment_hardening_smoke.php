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

echo "\n6. Repair alignment cannot overwrite live placement economics\n";
$a('force-projection override sentinel is removed',
    !str_contains($projector, '__cf_force_projection')
    && !str_contains($sync, "'force_projection' => true"));
$a('placement upsert always respects coreflux_overridden_fields',
    !str_contains($sync, '$forceProjection')
    && str_contains($sync, 'SELECT coreflux_overridden_fields FROM placements'));
$a('repair refreshes source indexes without replaying canonical records',
    str_contains($alignment, "'projection_mode' => 'source_indexes_only'")
    && !str_contains(
        substr(
            $alignment,
            strpos($alignment, 'function jobdivaMappingRepairCanonicalProjection('),
            strpos($alignment, 'function jobdivaMappingRepairWorkflow(')
                - strpos($alignment, 'function jobdivaMappingRepairCanonicalProjection(')
        ),
        'jobdivaReprojectMirroredPlacementGraphs('
    ));
$a('existing placement updates require current-payload field evidence',
    str_contains($sync, '$fieldEvidence = [')
    && str_contains($sync, "array_key_exists(\$col, \$fieldEvidence) && !\$fieldEvidence[\$col]"));
$a('missing classification cannot clear C2C details',
    str_contains($sync, "if (!empty(\$fieldEvidence['engagement_type']))")
    && str_contains($sync, 'jobdivaSyncUpsertPlacementCorpDetails('));
$a('normal sync reuses each exact Start snapshot before contextual lookup',
    str_contains($sync, "if (\$kind === 'start') {")
    && str_contains($sync, "'searchStart:placement_snapshot'")
    && str_contains($sync, 'jobdivaFetchExactAssignmentById($tid, (string) $id, $hint)')
    && str_contains($sync, "'exact searchStart placement snapshot -> supported jobId/candidateid lookup'"));
$a('canonical projection records recoverable before and after snapshots',
    str_contains($sync, 'function jobdivaPlacementProjectionAuditSnapshot(')
    && str_contains($sync, "jobdivaAudit(\$tenantId, 'projection_write'")
    && str_contains($sync, "'before' => \$before")
    && str_contains($sync, "'after' => \$after"));
$a('repair workflow defaults/caps to full placement-sized batches',
    str_contains($alignment, 'function jobdivaMappingRepairWorkflow(int $tenantId, array $user, int $limit = 5000)')
    && str_contains($alignment, 'min(5000, $limit)')
    && str_contains($alignmentApi, '$limit = isset($body[\'limit\']) ? (int) $body[\'limit\'] : 5000')
    && str_contains($settingsUi, "repair_workflow', { limit: 5000 }"));
$a('repair totals count refreshed source payloads and indexes',
    str_contains($alignment, "\$changed += (int) (\$step['payloads_refreshed'] ?? 0);")
    && str_contains($alignment, "\$changed += (int) (\$step['subpayload_indexes_refreshed'] ?? 0);"));

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
