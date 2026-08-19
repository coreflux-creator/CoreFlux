<?php
/**
 * Smoke: JobDiva integration-data alignment.
 *
 * Locks the fix for the confusing JobDiva mapping split:
 * - canonical mappings vs mirror-only payloads are explicitly modeled;
 * - JobDiva placements write placements.client_id through the staffing
 *   consumer bridge, so billing/payroll readiness can group by client;
 * - operators have one alignment cockpit and an ordered repair workflow.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0; $failures = [];
$a = function (string $label, bool $cond) use (&$pass, &$fail, &$failures) {
    if ($cond) { $pass++; echo "  ok - {$label}\n"; }
    else { $fail++; $failures[] = $label; echo "  FAIL - {$label}\n"; }
};
$read = static fn(string $p): string => (string) file_get_contents($p);

echo "JobDiva mapping alignment smoke\n";
echo "===============================\n";

$sync = $read("$root/core/jobdiva/sync.php");
$projector = $read("$root/core/jobdiva/projector.php");
$clients = $read("$root/modules/staffing/lib/clients.php");
$servicePath = "$root/core/jobdiva/mapping_alignment.php";
$service = $read($servicePath);
$apiPath = "$root/api/admin/integrations/jobdiva_mapping_alignment.php";
$api = $read($apiPath);
$ui = $read("$root/dashboard/src/pages/JobDivaSettings.jsx");

echo "\n1. JobDiva placement sync writes the staffing client bridge\n";
$a('sync requires staffing client bridge helper',
    str_contains($sync, "/../../modules/staffing/lib/clients.php"));
$a('placement upsert accepts actor user for bridge creation',
    str_contains($sync, 'function jobdivaSyncUpsertPlacement(int $tid, int $personId, ?int $endClientCompanyId, array $jd, string $extId, ?int $userId = null)'));
$a('placement sync uses projector for canonical graph writes',
    str_contains($sync, 'jobdivaProjectorProjectPlacement($tid, $jd, $userId'));
$a('projector passes user id into canonical upsert',
    str_contains($projector, 'jobdivaSyncUpsertPlacement(')
    && str_contains($projector, '$userId'));
$a('sync calls staffingClientEnsureForCompany for JobDiva end-client',
    str_contains($sync, 'staffingClientEnsureForCompany($tid, $endClientCompanyId, $clientBridgeName'));
$a('update field set includes client_id',
    str_contains($sync, "'client_id'            => ['cli',   \$clientId]"));
$a('insert column list includes client_id next to end_client_company_id',
    str_contains($sync, 'end_client_name, end_client_company_id, client_id'));
$a('insert values bind :cli and preserve staffing job link slot',
    str_contains($sync, ':rp, :notes, :ecn, :ecc, :cli, :sji, :can'));

echo "\n2. Staffing client bridge is tenant-explicit\n";
$a('helper no longer scopedUpdate()s staffing_clients',
    !str_contains($clients, "scopedUpdate('staffing_clients'"));
$a('helper no longer scopedInsert()s staffing_clients',
    !str_contains($clients, "scopedInsert('staffing_clients'"));
$a('helper update is guarded by tenant_id and id',
    str_contains($clients, 'WHERE tenant_id = :tenant_id AND id = :id'));
$a('helper insert explicitly sets tenant_id from argument',
    str_contains($clients, "\$payload['tenant_id'] = \$tenantId"));

echo "\n3. Alignment service models canonical roots plus native mirrors\n";
$a('alignment service file exists', file_exists($servicePath));
$a('canonical object map function exists',
    str_contains($service, 'function jobdivaMappingCanonicalObjectMap(): array'));
$a('object map is sourced from canonical graph catalog',
    str_contains($service, 'jobdivaCanonicalGraphCatalog()')
    && str_contains($service, "\$row['mapping_kind'] = 'canonical'"));
$a('report keeps native mirrors as secondary diagnostics',
    str_contains($service, 'native_payload_mirrors')
    && str_contains($service, 'native_facets_vs_canonical_roots'));
$a('customer id semantic tension documented',
    str_contains($service, "'code' => 'customer_id_semantics'"));
$a('canonical mapping and field counts are exposed',
    str_contains($service, 'canonical_mapping_counts')
    && str_contains($service, 'canonical_field_coverage'));
$a('report flags placements missing staffing client',
    str_contains($service, 'placement_missing_staffing_client'));
$a('report flags placements missing canonical end-client company',
    str_contains($service, 'placement_missing_end_client_company'));
$a('repair function exists and uses staffing bridge',
    str_contains($service, 'function jobdivaMappingRepairStaffingClientLinks')
    && str_contains($service, 'staffingClientEnsureForCompany($tenantId, $companyId, $name'));
$a('repair selects rows with missing end_client_company_id, not only missing client_id',
    str_contains($service, 'OR p.end_client_company_id IS NULL')
    && str_contains($service, 'OR p.end_client_company_id = 0'));
$a('repair carries JobDiva payload snapshot for end-client fallback',
    str_contains($service, 'm.payload_snapshot')
    && str_contains($service, 'jobdivaPlacementPayloadWithMirrors(')
    && str_contains($service, "(string) (\$row['mapping_external_id'] ?? '')")
    && str_contains($service, 'jobdivaEndClientNameFromPayload($payload)'));
$a('repair treats JobDiva payload company name as authoritative over stale placement labels',
    str_contains($service, '$name = trim($payloadClientName);')
    && str_contains($service, 'jobdivaProjectorCompanyNameKey((string) ($row[\'end_client_name\'] ?? \'\'))')
    && str_contains($service, 'end_client_name = :end_client_name'));
$a('repair resolves canonical company through projector end-client rules',
    str_contains($service, 'jobdivaProjectorResolveEndClientCompany($tenantId, $payload, $userId)'));
$a('repair backfills from an existing staffing client company when present',
    str_contains($service, 'existing_client_company_id')
    && str_contains($service, "\$companyId === null && !empty(\$row['existing_client_company_id'])"));
$a('duplicate placement detector and repair function exist',
    str_contains($service, 'duplicate_jobdiva_placement_rows')
    && str_contains($service, 'function jobdivaMappingRepairDuplicatePlacements')
    && str_contains($service, '_jobdivaMappingDuplicatePlacementBlockingChildren'));
$a('duplicate detector catches legacy placeholder JobDiva placement copies',
    str_contains($service, 'function _jobdivaMappingPlacementStartIdFromRow')
    && str_contains($service, "str_starts_with(\$externalId, 'jd:') || isset(\$mapped[\$norm])")
    && str_contains($service, 'JobDiva\s+Placement\s+(\d+)')
    && str_contains($service, "'duplicate_basis' => 'jobdiva_start_id'")
    && str_contains($service, "'canonical_external_id' => 'jd:' . \$startId"));
$a('duplicate detector includes source bindings and title-only JobDiva shells',
    str_contains($service, '$mappedByInternalId')
    && str_contains($service, "p.title LIKE 'JobDiva Placement %'")
    && str_contains($service, 'm.id IS NOT NULL')
    && str_contains($service, '_jobdivaMappingPlacementStartIdFromRow($row, $mapped, $mappedByInternalId)'));
$a('duplicate repair prefers the row carrying downstream workflow activity',
    str_contains($service, 'function _jobdivaMappingDuplicatePlacementChildCounts')
    && str_contains($service, 'multiple_rows_have_downstream_activity')
    && str_contains($service, '$keepId = count($rowsWithChildren) === 1'));
$a('duplicate repair prefers fully projected rows over placeholder shells',
    str_contains($service, "!preg_match('/^JobDiva\\s+Placement\\s+\\d+$/i', \$title)")
    && str_contains($service, '$score += 40')
    && str_contains($service, '$score += 25'));
$a('duplicate repair rehomes mappings after archiving orphan shells',
    str_contains($service, 'function _jobdivaMappingRehomePlacementMapping')
    && str_contains($service, 'DELETE FROM external_entity_mappings')
    && str_contains($service, 'internal_entity_id <> :keep_id')
    && strpos($service, '_jobdivaMappingRehomePlacementMapping($pdo, $tenantId, $norm, $keepId, $duplicateIds)') >
       strpos($service, 'SET deleted_at = NOW(), updated_at = NOW()'));
$a('duplicate repair avoids repeated PDO placeholders when restoring external ids',
    str_contains($service, 'external_id = :ext_set')
    && str_contains($service, 'external_id <> :ext_filter')
    && str_contains($service, "'ext_set' => \$canonical")
    && str_contains($service, "'ext_filter' => \$canonical"));
$a('source-backed placement lifecycle repair is exposed from alignment service',
    str_contains($service, 'placement_active_past_end_date')
    && str_contains($service, 'function jobdivaMappingRepairStaleActivePlacements')
    && str_contains($service, 'mapping_alignment_repair_stale_active_placements')
    && str_contains($service, 'jobdivaAssignmentCanonicalPlacementStatus'));
$a('source-owned People lifecycle repair preserves history by inactivating rows',
    str_contains($service, 'function jobdivaMappingRepairSourcePeopleLifecycle')
    && str_contains($service, 'function _jobdivaMappingStaleSourcePeopleRows')
    && str_contains($service, "SET p.status = 'inactive'")
    && str_contains($service, "live.status IN ('draft', 'pending_start', 'active', 'on_hold')"));
$a('alignment report includes projector readiness drift',
    str_contains($service, 'jobdivaProjectorReadinessCounts($tenantId)')
    && str_contains($service, 'placement_missing_staffing_job')
    && str_contains($service, 'placement_missing_rate_row')
    && str_contains($service, 'placement_active_missing_approved_rate'));
$a('source rate draft repair is exposed from alignment service',
    str_contains($service, 'function jobdivaMappingRepairSourceRateDrafts(int $tenantId, array $user')
    && str_contains($service, 'placementsEnsureDraftRateFromSourcePayload($placementId, $user)')
    && str_contains($service, 'mapping_alignment_repair_source_rate_drafts'));
$a('source rate draft repair tries mapped placements even when snapshot must be rebuilt from mirrors',
    str_contains($service, "m.internal_entity_type = 'placement'")
    && !str_contains($service, "AND m.payload_snapshot IS NOT NULL\n            AND m.payload_snapshot <> ''\n            AND (p.deleted_at"));
$a('alignment detects and repairs unsafe JobDiva auto-drafted bill=pay rates',
    str_contains($service, 'placement_auto_rate_bill_equals_pay')
    && str_contains($service, 'unsafe_auto_rate_bill_equals_pay')
    && str_contains($service, 'ABS(pr.pay_rate - pr.bill_rate) < 0.0001')
    && str_contains($service, 'ABS(unsafe_pr.pay_rate - unsafe_pr.bill_rate) < 0.0001'));
$a('canonical projection repair is exposed from alignment service',
    str_contains($service, 'function jobdivaMappingRepairCanonicalProjection(int $tenantId')
    && str_contains($service, "'projection_mode' => 'source_indexes_only'")
    && !str_contains($service, 'jobdivaReprojectMirroredPlacementGraphs($tenantId, $userId, $limit)')
    && str_contains($service, 'mapping_alignment_repair_canonical_projection'));
$a('ordered repair workflow replays canonical projection before cleanup and rate drafting',
    str_contains($service, 'function jobdivaMappingRepairWorkflow(int $tenantId, array $user')
    && strpos($service, "jobdivaMappingRepairCanonicalProjection(\$tenantId, \$userId, \$limit)") !== false
    && strpos($service, "jobdivaMappingRepairDuplicatePlacements(\$tenantId, \$userId, min(500, \$limit), false)") !== false
    && strpos($service, "jobdivaMappingRepairSourceRateDrafts(\$tenantId, \$user, \$limit)") !== false
    && strpos($service, "jobdivaMappingRepairCanonicalProjection(\$tenantId, \$userId, \$limit)") <
       strpos($service, "jobdivaMappingRepairDuplicatePlacements(\$tenantId, \$userId, min(500, \$limit), false)")
    && strpos($service, "jobdivaMappingRepairDuplicatePlacements(\$tenantId, \$userId, min(500, \$limit), false)") <
       strpos($service, "jobdivaMappingRepairSourceRateDrafts(\$tenantId, \$user, \$limit)")
    && str_contains($service, 'mapping_alignment_repair_workflow'));
$a('JobDiva placement sync uses the shared source lifecycle normalizer',
    str_contains($sync, 'jobdivaAssignmentCanonicalPlacementStatus($statusRaw, $endDateNorm)'));
$a('projector distrusts external company mappings whose name conflicts with JobDiva payload',
    str_contains($projector, 'function jobdivaProjectorCompanyNameCompatible')
    && str_contains($projector, 'function jobdivaProjectorMappedCompanyIdIfNameMatches')
    && str_contains($projector, 'jobdivaProjectorMappedCompanyIdIfNameMatches($tenantId, \'company\', $companyExtId, $endClientName)')
    && !str_contains($projector, '$cm = mappingFindInternal($tenantId, \'jobdiva\', \'company\', $companyExtId);'));

echo "\n4. Alignment API is wired and gated\n";
$a('alignment API file exists', file_exists($apiPath));
$a('API requires alignment service',
    str_contains($api, 'core/jobdiva/mapping_alignment.php'));
$a('GET returns report',
    str_contains($api, "if (\$method === 'GET')")
    && str_contains($api, 'jobdivaMappingAlignmentReport($tid'));
$a('POST repair_client_links action is wired',
    str_contains($api, "repair_client_links")
    && str_contains($api, 'jobdivaMappingRepairStaffingClientLinks($tid'));
$a('POST repair_workflow action is wired',
    str_contains($api, "repair_workflow")
    && str_contains($api, 'jobdivaMappingRepairWorkflow($tid, $user, $limit)'));
$a('POST repair_duplicate_placements action is wired',
    str_contains($api, "repair_duplicate_placements")
    && str_contains($api, 'jobdivaMappingRepairDuplicatePlacements('));
$a('POST repair_source_rate_drafts action is wired',
    str_contains($api, "repair_source_rate_drafts")
    && str_contains($api, 'jobdivaMappingRepairSourceRateDrafts($tid, $user'));
$a('POST repair_stale_placements action is wired',
    str_contains($api, "repair_stale_placements")
    && str_contains($api, 'jobdivaMappingRepairStaleActivePlacements('));
$a('POST repair_stale_people action is wired',
    str_contains($api, "repair_stale_people")
    && str_contains($api, 'jobdivaMappingRepairSourcePeopleLifecycle('));
$a('API uses integration RBAC gates',
    str_contains($api, 'rbac_legacy_require_any')
    && str_contains($api, 'integrations.jobdiva.view')
    && str_contains($api, 'integrations.jobdiva.manage'));

echo "\n5. JobDiva settings exposes the cockpit\n";
$a('settings loads alignment endpoint',
    str_contains($ui, '/api/admin/integrations/jobdiva_mapping_alignment.php'));
$a('settings mounts mapping alignment card',
    str_contains($ui, 'data-testid="jobdiva-mapping-alignment-card"'));
$a('settings has repair client links button',
    str_contains($ui, 'data-testid="jobdiva-mapping-alignment-repair-client-links"'));
$a('settings has one-click ordered repair workflow',
    str_contains($ui, 'data-testid="jobdiva-mapping-alignment-repair-workflow"')
    && str_contains($ui, "repair_workflow")
    && str_contains($ui, 'canonical_projection')
    && str_contains($ui, 'data-testid="jobdiva-mapping-alignment-repair-workflow-result"')
    && str_contains($ui, 'data-testid="jobdiva-mapping-alignment-repair-workflow-errors"')
    && str_contains($ui, 'repairStepLabels'));
$a('settings has duplicate placement preview + repair buttons',
    str_contains($ui, 'data-testid="jobdiva-mapping-alignment-preview-duplicate-placements"')
    && str_contains($ui, 'data-testid="jobdiva-mapping-alignment-repair-duplicate-placements"'));
$a('settings has stale active preview + repair buttons',
    str_contains($ui, 'data-testid="jobdiva-mapping-alignment-preview-stale-placements"')
    && str_contains($ui, 'data-testid="jobdiva-mapping-alignment-repair-stale-placements"')
    && str_contains($ui, "repair_stale_placements"));
$a('settings has source-owned People lifecycle preview + repair buttons',
    str_contains($ui, 'data-testid="jobdiva-mapping-alignment-preview-stale-people"')
    && str_contains($ui, 'data-testid="jobdiva-mapping-alignment-repair-stale-people"')
    && str_contains($ui, "repair_stale_people"));
$a('settings has source rate draft repair button and issue action',
    str_contains($ui, 'data-testid="jobdiva-mapping-alignment-repair-rate-drafts"')
    && str_contains($ui, "repair_source_rate_drafts")
    && str_contains($ui, "code === 'placement_missing_rate_row'")
    && str_contains($ui, "code === 'placement_missing_rate_row' || code === 'placement_auto_rate_bill_equals_pay'")
    && str_contains($ui, 'Repair rates'));
$a('settings explains unsafe JobDiva auto-drafted bill=pay rates',
    str_contains($ui, 'placement_auto_rate_bill_equals_pay')
    && str_contains($ui, 'fake zero-margin contract'));
$a('settings renders canonical object map',
    str_contains($ui, 'data-testid="jobdiva-mapping-alignment-object-map"'));
$a('settings renders mirror-only section',
    str_contains($ui, 'data-testid="jobdiva-mapping-alignment-mirror-only"'));
$a('settings renders issue rows by code',
    str_contains($ui, 'jobdiva-mapping-alignment-issue-${issue.code}'));

echo "\n6. Syntax\n";
foreach ([$servicePath, $apiPath, "$root/core/jobdiva/sync.php", "$root/modules/staffing/lib/clients.php"] as $path) {
    $lint = shell_exec('php -l ' . escapeshellarg($path) . ' 2>&1');
    $a('php -l ' . basename($path), str_contains((string) $lint, 'No syntax errors detected'));
}

echo "\n===============================\n";
echo "JobDiva mapping alignment smoke: {$pass} ok / {$fail} fail\n";
if ($fail > 0) {
    foreach ($failures as $msg) echo " ! {$msg}\n";
    exit(1);
}
exit(0);
