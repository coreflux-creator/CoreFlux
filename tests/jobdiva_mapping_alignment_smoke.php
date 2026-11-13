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
$reconcile = $read("$root/core/jobdiva/placement_reconciliation.php");
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
$a('sync bridges the resolved billing company to its staffing client',
    str_contains($sync, "staffingClientEnsureForCompany(\$tid, \$billingCompanyId, \$clientEvidence['name']"));
$a('update field set includes client_id',
    str_contains($sync, "'client_id'            => ['cli',   \$clientId]"));
$a('insert column list includes client_id next to end_client_company_id',
    str_contains($sync, 'end_client_name, end_client_company_id, client_id'));
$a('insert values bind :cli and preserve staffing job link slot',
    str_contains($sync, ':notes, :ecn, :ecc, :cli, :sji, :can, :cae'));

echo "\n2. Staffing client bridge is tenant-explicit\n";
$a('helper no longer scopedUpdate()s staffing_clients',
    !str_contains($clients, "scopedUpdate('staffing_clients'"));
$a('helper no longer scopedInsert()s staffing_clients',
    !str_contains($clients, "scopedInsert('staffing_clients'"));
$a('helper update is guarded by tenant_id and id',
    str_contains(file_get_contents(__DIR__.'/../modules/staffing/lib/client_source.php'), 'WHERE tenant_id=? AND id=?'));
$a('helper insert explicitly sets tenant_id from argument',
    str_contains($clients, 'staffingClientProjectSource($tenantId,$companyId,$name,$extra)')
    && str_contains(file_get_contents(__DIR__.'/../modules/staffing/lib/client_source.php'), "'tenant_id'=>$"."owner"));

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
$linkRepair = $read($root . '/core/jobdiva/client_link_repair.php');
$a('repair function exists and uses staffing bridge',
    str_contains($service, "'/client_link_repair.php'") && str_contains($linkRepair, 'function jobdivaMappingRepairStaffingClientLinks')
    && str_contains($linkRepair, 'staffingClientEnsureForCompany($workspace, $companyId'));
$a('repair selects source mappings in original workspace and locks the current placement owner',
    str_contains($linkRepair, '$q->execute([$tenantId])') && str_contains($linkRepair, '$q->execute([$owner, $id])'));
$a('repair rebuilds exact assignment billing evidence from locked stored mirrors',
    str_contains($linkRepair, "$"."mapping['payload_snapshot']")
    && str_contains($linkRepair, 'jobdivaPlacementPayloadWithMirrors($workspace, $payload, $stats, $external, true)')
    && str_contains($linkRepair, 'jobdivaProjectorAssignmentClientEvidence($payload)'));
$a('billing repair never rewrites the end-client fields',
    !str_contains($linkRepair, 'end_client_name =') && !str_contains($linkRepair, 'end_client_company_id ='));
$a('repair resolves billing company through exact evidence after native ownership',
    strpos($linkRepair, 'placementOwnershipFields(')<strpos($linkRepair, 'jobdivaProjectorResolveBillingClientCompany('));
$a('repair preserves manual choice and requires stable client source identity',
    str_contains($linkRepair, "'client_id', placementOwnershipFields(") && str_contains($linkRepair, 'exact_billing_identity_required'));
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
$a('duplicate detector reconciles the reviewed spreadsheet row conservatively',
    str_contains($service, 'function _jobdivaMappingLegacySpreadsheetDuplicateGroups')
    && str_contains($service, "'duplicate_basis' => 'legacy_spreadsheet_identity'")
    && str_contains($service, "'preferred_keeper_id' => \$preferredKeeper")
    && str_contains($service, 'approved_rate_count')
    && str_contains($reconcile, 'function jobdivaPlacementReconcilePair')
    && str_contains($reconcile, "'month_day_swap'")
    && str_contains($reconcile, "'name_prefix_alias'"));
$a('duplicate repair prefers the row carrying downstream workflow activity',
    str_contains($service, 'function _jobdivaMappingDuplicatePlacementChildCounts')
    && str_contains($service, 'multiple_rows_have_downstream_activity')
    && str_contains($service, '$keepId = count($rowsWithChildren) === 1'));
$a('legacy duplicate repair consolidates source metadata, rates, and configuration',
    str_contains($service, 'function _jobdivaMappingConsolidateLegacyPlacementPair')
    && str_contains($service, 'function _jobdivaMappingMergePlacementRates')
    && str_contains($service, 'function _jobdivaMappingMergePlacementConfiguration')
    && str_contains($service, 'placementEconomicsReconcile($tenantId, $keepId)')
    && str_contains($service, 'function _jobdivaMappingRateHasReferences')
    && str_contains($service, 'JOIN {$parentTable} parent ON parent.id = child.{$parentKey}'));
$a('duplicate repair blocks every settled downstream placement artifact',
    str_contains($service, "'time_downstream_feed'")
    && str_contains($service, "'placement_economic_obligations'")
    && str_contains($service, "'placement_economic_items'")
    && str_contains($service, "'ap_bills'"));
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
    && str_contains($service, "'/placement_lifecycle_repair.php'")
    && str_contains(file_get_contents($root.'/core/jobdiva/placement_lifecycle_repair.php'), 'function jobdivaMappingRepairStaleActivePlacements')
    && str_contains(file_get_contents($root.'/core/jobdiva/placement_lifecycle_repair.php'), 'placement.status_repaired_from_jobdiva_lifecycle')
    && str_contains(file_get_contents($root.'/core/jobdiva/placement_lifecycle_repair.php'), 'jobdivaAssignmentPlacementStatusForWrite'));
$a('source-linked People availability requires a separate review',
    str_contains($service, "'/people_lifecycle_review.php'")
    && str_contains($service, 'jobdivaMappingRepairSourcePeopleLifecycle($tenantId, $userId, $limit, true)')
    && !str_contains($service, "SET p.status = 'inactive'"));
$a('alignment report includes projector readiness drift',
    str_contains($service, 'jobdivaProjectorReadinessCounts($tenantId)')
    && str_contains($service, 'placement_missing_staffing_job')
    && str_contains($service, 'placement_missing_rate_row')
    && str_contains($service, 'placement_active_missing_approved_rate'));
$sourceRate=$read("$root/core/jobdiva/source_rate_repair.php");
$a('source rate draft repair delegates to the locked exact-source service',
    str_contains($service,"'/source_rate_repair.php'")&&str_contains($sourceRate,'function jobdivaMappingRepairSourceRateDrafts(')&&str_contains($sourceRate,'jobdivaRepairPlacementSourceRate('));
$a('missing source snapshots need exact saved assignment evidence',str_contains($sourceRate,'exact_assignment_evidence_required'));
$a('unsafe auto drafts require positive exact source rates before mutation',
    str_contains($service,'placement_auto_rate_bill_equals_pay')&&str_contains($sourceRate,'positive_source_rates_required')&&str_contains($sourceRate,"abs((float)\$rate['bill_rate']-(float)\$rate['pay_rate'])"));
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
    str_contains($sync, 'jobdivaAssignmentPlacementStatusForWrite(')
    && str_contains($sync, '$currentStatus'));
$a('projector rejects conflicting source names without retargeting provider IDs',
    str_contains($projector, 'jobdivaProjectorCompanyNameCompatible')
    && str_contains($projector, 'Review the existing identity.'));

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
    && str_contains($api, 'jobdivaPeopleLifecycleReview(')&&str_contains($api, 'jobdivaPeopleLifecycleApply('));
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
$a('settings opens explicit People availability review',
    str_contains($ui, 'data-testid="jobdiva-mapping-alignment-preview-stale-people"')
    && str_contains($ui, '<JobDivaPeopleLifecycleReview')
    && !str_contains($ui, 'data-testid="jobdiva-mapping-alignment-repair-stale-people"'));
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
