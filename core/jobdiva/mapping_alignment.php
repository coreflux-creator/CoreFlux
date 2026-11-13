<?php
/**
 * JobDiva integration-data alignment report.
 *
 * Purpose: make the integration graph explainable. Raw JobDiva mirrors,
 * field-map payloads, and canonical CoreFlux mappings are all useful, but
 * they are not the same thing. This service keeps those layers distinct and
 * checks whether downstream workflows can consume the mapped data.
 */
declare(strict_types=1);

require_once __DIR__ . '/sync.php';
require_once __DIR__ . '/canonical_graph.php';
require_once __DIR__ . '/placement_reconciliation.php';
require_once __DIR__ . '/../../modules/staffing/lib/clients.php';
require_once __DIR__ . '/../../modules/placements/lib/rate_approve.php';

function jobdivaMappingCanonicalObjectMap(): array
{
    $catalog = jobdivaCanonicalGraphCatalog();
    foreach ($catalog as $entityType => &$row) {
        $row['mapping_kind'] = 'canonical';
        $row['source_object'] = implode(' + ', $row['jobdiva_facets'] ?? []);
        $row['native_entity_types'] = jobdivaNativeEntityTypesForCanonical((string) $entityType);
    }
    unset($row);
    return $catalog;
}

require_once __DIR__.'/alignment_readiness.php';

function jobdivaMappingAlignmentReport(int $tenantId, array $opts = []): array
{
    try {
        return JobdivaStoredReviewTransaction::run(
            static fn(): array => jobdivaMappingAlignmentReportSnapshot($tenantId, $opts), true
        );
    } catch (Throwable $e) {
        error_log('[jobdiva alignment] Report unavailable: '.$e->getMessage());
        return ['ok'=>false, 'complete'=>false, 'issues'=>[
            ['severity'=>'critical', 'code'=>'alignment_unavailable', 'entity'=>'integration', 'count'=>null,
             'message'=>'The alignment check could not finish. No readiness result is available.',
             'action'=>'Retry the check. If it still fails, review database access, migrations and directory configuration.'],
        ], 'generated_at'=>gmdate('c')];
    }
}

function jobdivaMappingAlignmentReportSnapshot(int $tenantId, array $opts = []): array
{
    $limit = max(1, min(100, (int) ($opts['sample_limit'] ?? 25)));
    $objectMap = jobdivaMappingCanonicalObjectMap();
    $issues = [];
    $relationships = [];
    $fieldCoverage = [];
    $mappingCounts = [];
    $syncConfig = [];
    $samples = [];

    $pdo = getDB();
    $scope = jobdivaAlignmentScope($pdo, $tenantId);
    $placementOwner = (int) $scope['placements'];
    $peopleOwner = (int) $scope['people'];
    $staffingOwner = (int) $scope['staffing'];
    $companyOwner = (int) $scope['companies'];
    // Missing schema is an unavailable check, never a healthy zero.
    foreach (['external_entity_mappings','placements','people','companies','company_contacts',
        'company_roles','staffing_clients','staffing_jobs','placement_rates','time_entries','integration_payload_field_index','jobdiva_connections'] as $table) {
        if (!_jobdivaMappingTableExists($pdo, $table)) throw new RuntimeException('Required alignment schema is unavailable.');
    }
    if (!_jobdivaMappingColumnExists($pdo, 'placement_rates', 'created_by_user_id')) {
        throw new RuntimeException('Rate ownership schema is unavailable.');
    }
    $syncConfig = jobdivaAlignmentSyncConfig($pdo, $tenantId);

    $mappingCounts = _jobdivaMappingCountsByType($pdo, $tenantId);
    $fieldCoverage = _jobdivaMappingFieldCoverage($pdo, $tenantId);
    $canonicalMappingCounts = _jobdivaMappingCanonicalCounts($mappingCounts);
    $canonicalFieldCoverage = _jobdivaMappingCanonicalCounts($fieldCoverage);
    $samples = _jobdivaMappingSampleRows($pdo, $tenantId, $limit);

    $canonicalTotal = 0;
    foreach (jobdivaCanonicalEntityTypes() as $entity) {
        $canonicalTotal += (int) ($canonicalMappingCounts[$entity] ?? 0);
    }
    $mirrorTotal = 0;
    foreach (['jobdiva_job', 'jobdiva_candidate', 'jobdiva_contact', 'jobdiva_assignment'] as $entity) {
        $mirrorTotal += (int) ($mappingCounts[$entity] ?? 0);
    }

    $relationships['mapping_layers'] = [
        'canonical_mappings' => $canonicalTotal,
        'native_payload_mirrors' => $mirrorTotal,
        'field_map_paths'  => array_sum(array_map('intval', $canonicalFieldCoverage)),
    ];
    $projectorContract = jobdivaProjectorContract();
    $projectorReadiness = jobdivaProjectorReadinessCounts($tenantId);
    $relationships['directory_policy'] = ['conflicts'=>jobdivaAlignmentDirectoryConflicts($pdo, $scope)];
    _jobdivaMappingAddIssue($issues, 'critical', 'assignment_directory_policy_conflict', 'integration', $projectorReadiness['directory_policy_conflicts'],
        'Current directory sharing does not support assignment projection.', 'Align People and the shared assignment consumer directories before importing assignments.');
    foreach (['missing_placement'=>'Some source links do not resolve to a placement in the current directory.',
        'missing_person'=>'Some mapped placements do not resolve to a person in the current People directory.'] as $key=>$message) {
        _jobdivaMappingAddIssue($issues, 'critical', 'placement_'.$key, 'placement', $projectorReadiness[$key], $message, 'Review sharing and the retained source links before continuing.');
    }
    $relationships['projector'] = [
        'contract_stages' => array_keys($projectorContract['stages'] ?? []),
        'workflow_readiness' => $projectorReadiness,
        'field_mapping_role' => 'enrichment_only_after_identity_resolution',
    ];

    $badStatuses = _jobdivaMappingScalar($pdo,
        "SELECT COUNT(*) FROM external_entity_mappings
          WHERE tenant_id = :t AND source_system = 'jobdiva' AND sync_status <> 'ok'",
        ['t' => $tenantId]
    );
    _jobdivaMappingAddIssue($issues, 'warn', 'non_ok_mapping_status', 'mapping', $badStatuses, 'Some JobDiva mappings are stale, errored, or deleted in source.', 'Open the recent sync audit and re-run the affected entity sync.');

    if (_jobdivaMappingTableExists($pdo, 'placements')) {
        $placementTotal = (int) ($mappingCounts['placement'] ?? 0);
        $relationships['placement_graph'] = [
            'mapped_placements' => $placementTotal,
        ];
        foreach ([
            'missing_staffing_job' => 'placement_missing_staffing_job',
            'missing_rate_row' => 'placement_missing_rate_row',
            'active_missing_approved_rate' => 'placement_active_missing_approved_rate',
        ] as $readinessKey => $issueCode) {
            $relationships['placement_graph'][$readinessKey] = (int) ($projectorReadiness[$readinessKey] ?? 0);
        }
        $invalidAssignmentSources = _jobdivaMappingInvalidPlacementSources($pdo, $tenantId, 0, $scope);
        $relationships['placement_graph']['locally_disqualified_assignment_sources'] = count($invalidAssignmentSources);
        if ($invalidAssignmentSources) {
            $samples['locally_disqualified_assignment_sources'] = array_slice(
                $invalidAssignmentSources,
                0,
                min(10, $limit)
            );
        }
        _jobdivaMappingAddIssue(
            $issues,
            'critical',
            'placement_non_assignment_source',
            'placement',
            count($invalidAssignmentSources),
            'CoreFlux placements were created from JobDiva rows that are not qualified Starts/Assignments.',
            'Run Repair alignment. Offer, canceled, candidate, job, and other pipeline rows will be removed from the live placements graph.'
        );
        _jobdivaMappingAddIssue(
            $issues,
            'critical',
            'placement_unverified_assignment_source',
            'placement',
            _jobdivaMappingScalar(
                $pdo,
                "SELECT COUNT(*)
                   FROM external_entity_mappings m
                   JOIN placements p
                     ON p.tenant_id = {$placementOwner}
                    AND p.id = m.internal_entity_id
                  WHERE m.tenant_id = :t
                    AND m.source_system = 'jobdiva'
                    AND m.internal_entity_type = 'placement'
                    AND m.sync_status = 'deleted_in_source'
                    AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')",
                ['t' => $tenantId]
            ),
            'CoreFlux placements are bound to JobDiva IDs that could not be verified as Starts/Assignments.',
            'Run Repair alignment. Unused shells will be archived; financially-used records remain quarantined for review.'
        );
        _jobdivaMappingAddIssue(
            $issues,
            'critical',
            'placement_missing_staffing_job',
            'placement',
            (int) ($projectorReadiness['missing_staffing_job'] ?? 0),
            'JobDiva-mapped placements are missing the canonical staffing job link.',
            'Re-run JobDiva placement projection with job mirrors so workflows can pull job details into placement, billing, payroll, and reporting.'
        );
        _jobdivaMappingAddIssue(
            $issues,
            'critical',
            'placement_missing_rate_row',
            'placement_rates',
            (int) ($projectorReadiness['missing_rate_row'] ?? 0),
            'JobDiva-mapped placements have no placement_rates row.',
            'Re-run JobDiva projection or repair source-rate drafts before activating placements or sending time through billing/payroll.'
        );
        _jobdivaMappingAddIssue(
            $issues,
            'critical',
            'placement_active_missing_approved_rate',
            'placement_rates',
            (int) ($projectorReadiness['active_missing_approved_rate'] ?? 0),
            'Active JobDiva placements do not have an approved rate covering the placement start date.',
            'Approve the draft rate or adjust its effective window before promotion, billing, payroll, or AP settlement.'
        );
        $unsafeAutoDraftRates = 0;
        if (_jobdivaMappingTableExists($pdo, 'placement_rates')
            && _jobdivaMappingColumnExists($pdo, 'placement_rates', 'created_by_user_id')) {
            $unsafeAutoDraftRates = _jobdivaMappingScalar($pdo,
                "SELECT COUNT(*)
                   FROM external_entity_mappings m
                   JOIN placements p ON p.id = m.internal_entity_id AND p.tenant_id = {$placementOwner}
                   JOIN placement_rates pr ON pr.tenant_id = p.tenant_id AND pr.placement_id = p.id
                  WHERE m.tenant_id = :t
                    AND m.source_system = 'jobdiva'
                    AND m.internal_entity_type = 'placement'
                    AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
                    AND pr.approved_at IS NULL
                    AND pr.created_by_user_id IS NULL
                    AND ABS(pr.pay_rate - pr.bill_rate) < 0.0001",
                ['t' => $tenantId]
            );
        }
        $relationships['placement_graph']['unsafe_auto_rate_bill_equals_pay'] = $unsafeAutoDraftRates;
        _jobdivaMappingAddIssue(
            $issues,
            'critical',
            'placement_auto_rate_bill_equals_pay',
            'placement_rates',
            $unsafeAutoDraftRates,
            'JobDiva auto-drafted rates have identical bill and pay values.',
            'Run Repair rates so CoreFlux can rebuild from a real JobDiva pay field or remove unsafe auto-drafts before approval.'
        );
        $duplicatePlacementGroups = _jobdivaMappingDuplicatePlacementGroups($pdo, $tenantId, 0, $scope);
        $relationships['placement_graph']['duplicate_jobdiva_external_id_groups'] = count($duplicatePlacementGroups);
        $relationships['placement_graph']['duplicate_legacy_spreadsheet_groups'] = count(array_filter(
            $duplicatePlacementGroups,
            static fn(array $group): bool => (string) ($group['duplicate_basis'] ?? '') === 'legacy_spreadsheet_identity'
        ));
        if ($duplicatePlacementGroups) {
            $samples['duplicate_placements'] = array_slice($duplicatePlacementGroups, 0, min(10, $limit));
        }
        _jobdivaMappingAddIssue(
            $issues,
            'critical',
            'duplicate_jobdiva_placement_rows',
            'placement',
            count($duplicatePlacementGroups),
            'Some JobDiva engagements resolve to more than one active CoreFlux placement row.',
            'Review duplicate placements, choose the current record, each field value and the future source; retain original history and provider links.'
        );

        $staleActiveRows = _jobdivaMappingStaleActivePlacementRows($pdo, $tenantId, 0, $scope);
        $relationships['placement_graph']['active_past_end_date'] = count(array_filter(
            $staleActiveRows,
            static fn($row) => (string) ($row['lifecycle_reason'] ?? '') === 'past_end_date'
        ));
        $relationships['placement_graph']['active_lifecycle_drift'] = count($staleActiveRows);
        if ($staleActiveRows) {
            $samples['active_lifecycle_drift'] = array_slice($staleActiveRows, 0, min(10, $limit));
        }
        _jobdivaMappingAddIssue(
            $issues,
            'critical',
            'placement_active_past_end_date',
            'placement',
            count($staleActiveRows),
            'Active CoreFlux placements disagree with the JobDiva lifecycle or have an end date in the past.',
            'Preview placement lifecycle repair, then apply the source-backed ended, cancelled, pending, or on-hold state.'
        );

        if (_jobdivaMappingColumnExists($pdo, 'placements', 'end_client_company_id')) {
            $missingEndClient = $projectorReadiness['missing_end_client_company'];
            $relationships['placement_graph']['missing_end_client_company'] = $missingEndClient;
            _jobdivaMappingAddIssue($issues, 'critical', 'placement_missing_end_client_company', 'placement', $missingEndClient, 'JobDiva-mapped placements are missing the canonical end-client company link.', 'Re-run JobDiva placement sync or repair client links so billing/AP/payroll have the same company identity.');
        }

        if (_jobdivaMappingColumnExists($pdo, 'placements', 'client_id')) {
            $missingClientId = $projectorReadiness['missing_staffing_client'];
            $relationships['placement_graph']['missing_staffing_client'] = $missingClientId;
            _jobdivaMappingAddIssue($issues, 'critical', 'placement_missing_staffing_client', 'placement', $missingClientId, 'JobDiva-mapped placements are missing placements.client_id.', 'Run Repair client links; billing and payroll readiness group by placements.client_id.');
        }

        if (_jobdivaMappingTableExists($pdo, 'staffing_clients') && _jobdivaMappingColumnExists($pdo, 'staffing_clients', 'company_id')
            && _jobdivaMappingColumnExists($pdo, 'placements', 'client_id') && _jobdivaMappingColumnExists($pdo, 'placements', 'end_client_company_id')) {
            $clientMismatch = $projectorReadiness['client_company_mismatch'];
            $relationships['placement_graph']['client_company_mismatch'] = $clientMismatch;
            _jobdivaMappingAddIssue($issues, 'critical', 'placement_client_company_mismatch', 'placement', $clientMismatch, 'Some JobDiva bill-to clients have no valid company in the selected directory.', 'Review the exact assignment billing identity; end clients may legitimately differ.');
        }

        if (_jobdivaMappingColumnExists($pdo, 'placements', 'person_id')) {
            $missingPersonMapping = _jobdivaMappingScalar($pdo,
                "SELECT COUNT(*)
                   FROM external_entity_mappings m
                   JOIN placements p ON p.id = m.internal_entity_id AND p.tenant_id = {$placementOwner}
              LEFT JOIN external_entity_mappings pm
                     ON pm.tenant_id = m.tenant_id
                    AND pm.source_system = 'jobdiva'
                    AND pm.internal_entity_type = 'person'
                    AND pm.internal_entity_id = p.person_id
                  WHERE m.tenant_id = :t
                    AND m.source_system = 'jobdiva'
                    AND m.internal_entity_type = 'placement'
                    AND p.person_id IS NOT NULL
                    AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
                    AND pm.id IS NULL",
                ['t' => $tenantId]
            );
            $relationships['placement_graph']['person_without_jobdiva_mapping'] = $missingPersonMapping;
            _jobdivaMappingAddIssue($issues, 'warn', 'placement_person_without_source_mapping', 'people', $missingPersonMapping, 'Some JobDiva placements reference people that do not have a JobDiva person mapping.', 'Re-run placement sync with candidate enrichment, or manually bind the candidate to the person record.');
        }
    }

    if (_jobdivaMappingTableExists($pdo, 'people')) {
        $staleSourcePeople = jobdivaAlignmentPeople($pdo, $tenantId, $scope);
        $relationships['people_graph'] = array_merge(
            (array) ($relationships['people_graph'] ?? []),
            ['jobdiva_people_without_live_placement' => count($staleSourcePeople)]
        );
        if ($staleSourcePeople) {
            $samples['jobdiva_people_without_live_placement'] = array_slice(
                $staleSourcePeople,
                0,
                min(10, $limit)
            );
        }
        _jobdivaMappingAddIssue(
            $issues,
            'warn',
            'jobdiva_person_without_live_placement',
            'people',
            count($staleSourcePeople),
            'Linked people have placement history and no current assignment; review their directory availability.',
            'Review each person and choose whether to keep their current availability, place them on the bench, or make them inactive. Employment and portal access are separate.'
        );
    }

    if (_jobdivaMappingTableExists($pdo, 'staffing_clients') && _jobdivaMappingColumnExists($pdo, 'staffing_clients', 'company_id')) {
        $relationships['company_graph'] = jobdivaAlignmentCompanies($pdo, $tenantId, $scope);
        $customersWithoutClient = $relationships['company_graph']['clients_without_staffing_client'];
        _jobdivaMappingAddIssue($issues, 'critical', 'company_source_link_unavailable', 'company', $relationships['company_graph']['missing_company'],
            'Some retained company links cannot resolve in the current directory.', 'Review company sharing and retained merge aliases.');
        $relationships['end_client_consumer_bridge'] = [
            'jobdiva_customers_without_staffing_client' => $customersWithoutClient,
        ];
        _jobdivaMappingAddIssue($issues, 'warn', 'jobdiva_customer_without_staffing_client', 'staffing', $customersWithoutClient, 'Some mapped companies with a client role have no staffing client record. Raw customer mirrors are not Core company identities.', 'Run Repair client links so staffing workflows consume the company graph instead of drifting.');
    }

    if (_jobdivaMappingTableExists($pdo, 'company_contacts') && _jobdivaMappingTableExists($pdo, 'companies')) {
        $contactsMissingCompany = _jobdivaMappingScalar($pdo,
            "SELECT COUNT(*)
               FROM external_entity_mappings m
          LEFT JOIN company_contacts cc ON cc.id = m.internal_entity_id AND cc.tenant_id = {$companyOwner}
          LEFT JOIN companies c ON c.id = cc.company_id AND c.tenant_id = {$companyOwner} AND c.deleted_at IS NULL
              WHERE m.tenant_id = :t
                AND m.source_system = 'jobdiva'
                AND m.internal_entity_type = 'contact'
                AND (cc.id IS NULL OR c.id IS NULL)",
            ['t' => $tenantId]
        );
        $relationships['contact_graph'] = [
            'contacts_missing_company' => $contactsMissingCompany,
        ];
        _jobdivaMappingAddIssue($issues, 'warn', 'contact_without_company', 'contacts', $contactsMissingCompany, 'Some JobDiva contact mappings no longer resolve to a live company contact/company.', 'Re-run company/contact sync or re-link the contact to the canonical company.');
    }

    if (_jobdivaMappingTableExists($pdo, 'time_entries') && _jobdivaMappingTableExists($pdo, 'placements')) {
        $timeWithoutPlacementMap = _jobdivaMappingScalar($pdo,
            "SELECT COUNT(*)
               FROM external_entity_mappings m
               LEFT JOIN time_entries te ON te.id = m.internal_entity_id AND te.tenant_id = m.tenant_id
          LEFT JOIN external_entity_mappings pm
                 ON pm.tenant_id = m.tenant_id
                AND pm.source_system = 'jobdiva'
                AND pm.internal_entity_type = 'placement'
                AND pm.internal_entity_id = te.placement_id
              WHERE m.tenant_id = :t
                AND m.source_system = 'jobdiva'
                AND m.internal_entity_type = 'time_entry'
                AND (te.id IS NULL OR pm.id IS NULL OR NOT EXISTS (SELECT 1 FROM placements tp WHERE tp.id=te.placement_id AND tp.tenant_id={$placementOwner}))",
            ['t' => $tenantId]
        );
        $relationships['time_graph'] = [
            'time_entries_without_placement_mapping' => $timeWithoutPlacementMap,
        ];
        _jobdivaMappingAddIssue($issues, 'critical', 'time_entry_without_placement_mapping', 'time', $timeWithoutPlacementMap, 'Some JobDiva time entries are linked to placements that do not have a JobDiva placement mapping.', 'Repair placement mappings before sending these hours through billing/AP/payroll.');
    }

    $joinedBuckets = ['person', 'company', 'contact', 'placement'];
    $missingBuckets = [];
    if ((int) ($mappingCounts['placement'] ?? 0) > 0) {
        foreach ($joinedBuckets as $bucket) {
            if ((int) ($canonicalFieldCoverage[$bucket] ?? 0) === 0) $missingBuckets[] = $bucket;
        }
    }
    if ($missingBuckets) {
        _jobdivaMappingAddIssue($issues, 'warn', 'canonical_payload_roots_missing', 'field_mapping', count($missingBuckets), 'Placement payloads exist, but some canonical mapping roots have no indexed JobDiva fields: ' . implode(', ', $missingBuckets) . '.', 'Run the JobDiva subpayload re-indexer, then open Field Mapping Studio.');
    }

    if ((int) ($mappingCounts['jobdiva_candidate'] ?? 0) > 0 && (int) ($mappingCounts['person'] ?? 0) === 0) {
        _jobdivaMappingAddIssue($issues, 'warn', 'candidate_mirror_without_people_mapping', 'people', (int) $mappingCounts['jobdiva_candidate'], 'JobDiva candidates are mirrored, but none are canonically mapped to people.', 'Run placement sync or bind candidate mirrors to the People graph before relying on downstream placement/person data.');
    }

    usort($issues, static function ($a, $b) {
        $rank = ['critical' => 0, 'warn' => 1, 'info' => 2];
        $ra = $rank[$a['severity']] ?? 9;
        $rb = $rank[$b['severity']] ?? 9;
        if ($ra !== $rb) return $ra <=> $rb;
        return ((int) $b['count']) <=> ((int) $a['count']);
    });

    return [
        'complete' => true,
        'directory_owners' => $scope,
        'ok' => count(array_filter($issues, static fn($i) => ($i['severity'] ?? '') === 'critical')) === 0,
        'object_map' => $objectMap,
        'sync_config' => $syncConfig,
        'mapping_counts' => $mappingCounts,
        'canonical_mapping_counts' => $canonicalMappingCounts,
        'field_coverage' => $fieldCoverage,
        'canonical_field_coverage' => $canonicalFieldCoverage,
        'relationships' => $relationships,
        'issues' => $issues,
        'samples' => $samples,
        'known_tensions' => [
            [
                'code' => 'native_facets_vs_canonical_roots',
                'summary' => 'JobDiva native facets are retained as evidence, but mappings and workflows should root in placement, person, company, contact, and time_entry.',
            ],
            [
                'code' => 'customer_id_semantics',
                'summary' => 'Raw customer mirrors retain provider IDs. Canonical company links resolve separately; bill-to clients and end-client work recipients may be different companies.',
            ],
            [
                'code' => 'staffing_consumes_company_graph',
                'summary' => 'staffing_clients is a consumer row keyed to companies.company_id. It should not become a competing client identity graph.',
            ],
        ],
        'generated_at' => gmdate('c'),
    ];
}

require_once __DIR__.'/client_link_repair.php';

require_once __DIR__.'/source_rate_repair.php';

require_once __DIR__.'/assignment_source_repair.php';

function jobdivaMappingRepairCanonicalProjection(int $tenantId, ?int $userId = null, int $limit = 5000): array
{
    $summary = [
        'checked' => 0,
        'projected' => 0,
        'skipped' => 0,
        'failed' => 0,
        'mapping_writes' => 0,
        'field_map_writes' => 0,
        'payloads_refreshed' => 0,
        'subpayload_indexes_refreshed' => 0,
        'jobs_joined' => 0,
        'candidates_joined' => 0,
        'contacts_joined' => 0,
        'assignments_joined' => 0,
        'projection_mode' => 'source_indexes_only',
        'errors' => [],
    ];
    $limit = max(1, min(5000, $limit));

    if (function_exists('jobdivaBackfillJoinedIndexes')) {
        $backfill = jobdivaBackfillJoinedIndexes($tenantId, $userId, $limit);
        $summary['failed'] = (int) ($backfill['failed'] ?? 0);
        $summary['payloads_refreshed'] = (int) ($backfill['snapshots_refreshed'] ?? 0);
        $summary['checked'] = (int) ($backfill['placements_walked'] ?? 0) + $summary['failed'];
        foreach ((array) ($backfill['sub_records_indexed'] ?? []) as $count) {
            $summary['subpayload_indexes_refreshed'] += (int) $count;
        }
        foreach ((array) ($backfill['enrichment_errors'] ?? []) as $err) {
            if (count($summary['errors']) < 10) {
                $summary['errors'][] = 'payload_refresh: ' . (string) $err;
            }
        }
    }
    // Repair alignment is structural maintenance, not a source replay.
    // Reprojecting every placement here used to overwrite valid CoreFlux
    // contract terms, classification, rates, and participant rules. A normal
    // JobDiva Sync applies current source values through ownership checks;
    // Repair only refreshes source snapshots/indexes and fixes graph links.
    $summary['skipped'] = $summary['checked'] - $summary['failed'];

    if (function_exists('jobdivaAudit')) {
        try {
            jobdivaAudit($tenantId, 'mapping_alignment_repair_canonical_projection', [
                'ok' => $summary['failed'] === 0,
                'direction' => 'pull',
                'actor_user_id' => $userId,
                'items_processed' => $summary['projected'],
                'items_skipped' => $summary['skipped'],
                'items_failed' => $summary['failed'],
                'detail' => $summary,
            ]);
        } catch (\Throwable $_) {}
    }

    return $summary;
}

function jobdivaMappingRepairWorkflow(int $tenantId, array $user, int $limit = 5000): array
{
    $limit = max(1, min(5000, $limit));
    $userId = isset($user['id']) ? (int) $user['id'] : null;
    $startedAt = gmdate('c');
    $steps = [];

    // Identity is verified before replay. A candidate/job/contact payload can
    // enrich a placement, but it can never become one.
    $steps['assignment_sources'] = jobdivaMappingRepairAssignmentSources($tenantId, $userId, $limit);
    $steps['canonical_projection'] = jobdivaMappingRepairCanonicalProjection($tenantId, $userId, $limit);
    $steps['duplicate_placements'] = jobdivaMappingRepairDuplicatePlacements($tenantId, $userId, min(500, $limit), true);
    $steps['client_links'] = jobdivaMappingRepairStaffingClientLinks($tenantId, $userId, $limit);
    $steps['stale_active_placements'] = jobdivaMappingRepairStaleActivePlacements($tenantId, $userId, $limit, false);
    $steps['source_people_lifecycle'] = jobdivaMappingRepairSourcePeopleLifecycle($tenantId, $userId, $limit, true);
    $steps['source_rate_drafts'] = function_exists('rbac_legacy_can') && rbac_legacy_can($user, 'placements.financials.manage')
        ? jobdivaMappingRepairSourceRateDrafts($tenantId, $user, $limit)
        : ['checked'=>0,'drafted'=>0,'skipped'=>0,'failed'=>0,'reason'=>'financial_management_permission_required'];

    $failed = 0;
    $changed = 0;
    foreach ($steps as $step) {
        $failed += (int) ($step['failed'] ?? 0);
        $changed += (int) ($step['placements_archived'] ?? 0);
        $changed += (int) ($step['placements_restored'] ?? 0);
        $changed += (int) ($step['external_ids_restored'] ?? 0);
        $changed += (int) ($step['repaired'] ?? 0);
        $changed += (int) ($step['ended'] ?? 0);
        $changed += (int) ($step['cancelled'] ?? 0);
        $changed += (int) ($step['pending_start'] ?? 0);
        $changed += (int) ($step['on_hold'] ?? 0);
        $changed += (int) ($step['inactivated'] ?? 0);
        $changed += (int) ($step['drafted'] ?? 0);
        $changed += (int) ($step['mapping_writes'] ?? 0);
        $changed += (int) ($step['field_map_writes'] ?? 0);
        $changed += (int) ($step['projected'] ?? 0);
        $changed += (int) ($step['payloads_refreshed'] ?? 0);
        $changed += (int) ($step['subpayload_indexes_refreshed'] ?? 0);
    }

    $after = jobdivaMappingAlignmentReport($tenantId, ['sample_limit' => 10]);
    $remainingCritical = array_values(array_filter(
        (array) ($after['issues'] ?? []),
        static fn($issue) => (string) ($issue['severity'] ?? '') === 'critical'
    ));

    $summary = [
        'ok' => $failed === 0 && ($after['complete'] ?? false),
        'started_at' => $startedAt,
        'finished_at' => gmdate('c'),
        'steps' => $steps,
        'totals' => [
            'changed' => $changed,
            'failed' => $failed,
        ],
        'remaining' => [
            'complete' => $after['complete'] ?? false,
            'critical_issue_types' => count($remainingCritical),
            'critical_rows' => ($after['complete'] ?? false) ? array_reduce(
                $remainingCritical,
                static fn($sum, $issue) => $sum + (int) ($issue['count'] ?? 0),
                0
            ) : null,
            'issues' => $remainingCritical,
        ],
    ];

    if (function_exists('jobdivaAudit')) {
        try {
            jobdivaAudit($tenantId, 'mapping_alignment_repair_workflow', [
                'ok' => $summary['ok'],
                'direction' => 'pull',
                'actor_user_id' => $userId,
                'items_processed' => $changed,
                'items_failed' => $failed,
                'detail' => $summary,
            ]);
        } catch (\Throwable $_) {}
    }

    return $summary;
}

require_once __DIR__.'/placement_lifecycle_repair.php';

require_once __DIR__.'/people_lifecycle_review.php';

/** Compatibility preview only. Consolidation requires the exact reviewed merge service. */
function jobdivaMappingRepairDuplicatePlacements(int $tenantId, ?int $userId = null, int $limit = 100, bool $dryRun = false): array
{
    if(!$dryRun)throw new RuntimeException('Review the placements, choose the record and source to keep, then confirm the merge in Review duplicate placements.',409);
    return JobdivaStoredReviewTransaction::run(static function()use($tenantId,$limit):array{
        $pdo=getDB();$scope=jobdivaAlignmentScope($pdo,$tenantId);$limit=max(1,min(500,$limit));
        $groups=_jobdivaMappingDuplicatePlacementGroups($pdo,$tenantId,0,$scope);$total=count($groups);$groups=array_slice($groups,0,$limit);
        $q=$pdo->prepare('SELECT record_id,record_number FROM core_record_numbers WHERE tenant_id=? AND entity_type="placement"');$q->execute([$scope['placements']]);$numbers=$q->fetchAll(PDO::FETCH_KEY_PAIR);
        $candidates=[];
        foreach($groups as$group){
            $rows=[];
            foreach($group['rows']as$row)$rows[]=[
                'id'=>(int)$row['id'],'placement_number'=>$numbers[$row['id']]??null,'title'=>$row['title'],'person_id'=>(int)$row['person_id'],
                'person_name'=>trim(($row['person_first_name']??'').' '.($row['person_last_name']??'')),
                'status'=>$row['status'],'start_date'=>$row['start_date'],'end_date'=>$row['end_date'],'end_client_name'=>$row['end_client_name']??null,
            ];
            $people=array_unique(array_column($rows,'person_id'));
            $candidates[]=['basis'=>$group['duplicate_basis'],'source_external_id'=>$group['external_id'],'rows'=>$rows,
                'person_review_required'=>count($people)!==1||reset($people)<=0];
        }
        return ['dry_run'=>true,'groups_checked'=>count($groups),'total_groups'=>$total,'truncated'=>$total>count($groups),
            'review_required'=>$total,'groups_repaired'=>0,'placements_archived'=>0,'failed'=>0,'candidates'=>$candidates,
            'review_url'=>'/modules/placements/merge','reason'=>'explicit_survivor_fields_and_source_required'];
    },true);
}

function _jobdivaMappingInvalidPlacementSources(\PDO $pdo, int $tenantId, int $limit = 5000, ?array $scope = null): array
{
    $owner = (int) ($scope['placements'] ?? $tenantId);
    $limitSql = $scope !== null && $limit === 0 ? '' : ' LIMIT '.max(1, min(5000, $limit));
    $rows = _jobdivaMappingRows(
        $pdo,
        "SELECT m.internal_entity_id AS placement_id, m.external_id, m.payload_snapshot,
                p.title
           FROM external_entity_mappings m
           JOIN placements p
             ON p.tenant_id = {$owner}
            AND p.id = m.internal_entity_id
          WHERE m.tenant_id = :t
            AND m.source_system = 'jobdiva'
            AND m.internal_entity_type = 'placement'
            AND m.sync_status = 'ok'
            AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
          ORDER BY m.id ASC
          {$limitSql}",
        ['t' => $tenantId]
    );

    $invalid = [];
    foreach ($rows as $row) {
        $payload = json_decode((string) ($row['payload_snapshot'] ?? ''), true);
        if (!is_array($payload)) {
            $invalid[] = [
                'placement_id' => (int) ($row['placement_id'] ?? 0),
                'external_id' => (string) ($row['external_id'] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
                'reason' => 'invalid_payload_snapshot',
            ];
            continue;
        }

        // Only the root source row may establish placement eligibility.
        // Assignment mirrors are enrichment and may be stale or cross-linked.
        foreach (['_jd_start', 'assignment', 'start', 'Start', 'jobdiva_assignment'] as $key) {
            unset($payload[$key]);
        }
        $identity = jobdivaAssignmentValidate(
            $payload,
            (string) ($row['external_id'] ?? '')
        );
        if (!empty($identity['valid'])) continue;
        $invalid[] = [
            'placement_id' => (int) ($row['placement_id'] ?? 0),
            'external_id' => (string) ($row['external_id'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'reason' => (string) ($identity['reason'] ?? 'invalid_assignment_source'),
        ];
    }
    return $invalid;
}

function _jobdivaMappingCountsByType(\PDO $pdo, int $tenantId): array
{
    $rows = _jobdivaMappingRows($pdo,
        "SELECT internal_entity_type, COUNT(*) AS c
           FROM external_entity_mappings
          WHERE tenant_id = :t AND source_system = 'jobdiva'
       GROUP BY internal_entity_type",
        ['t' => $tenantId]
    );
    $out = [];
    foreach ($rows as $r) $out[(string) $r['internal_entity_type']] = (int) $r['c'];
    return $out;
}

function _jobdivaMappingFieldCoverage(\PDO $pdo, int $tenantId): array
{
    if (!_jobdivaMappingTableExists($pdo, 'integration_payload_field_index')) return [];
    $rows = _jobdivaMappingRows($pdo,
        "SELECT entity_type, COUNT(DISTINCT source_path) AS c
           FROM integration_payload_field_index
          WHERE tenant_id = :t AND integration = 'jobdiva'
       GROUP BY entity_type",
        ['t' => $tenantId]
    );
    $out = [];
    foreach ($rows as $r) $out[(string) $r['entity_type']] = (int) $r['c'];
    return $out;
}

function _jobdivaMappingSampleRows(\PDO $pdo, int $tenantId, int $limit): array
{
    return _jobdivaMappingRows($pdo,
        "SELECT id, internal_entity_type, external_id, internal_entity_id,
                sync_status, direction, last_error, last_seen_at, last_synced_at, updated_at
           FROM external_entity_mappings
          WHERE tenant_id = :t AND source_system = 'jobdiva'
       ORDER BY updated_at DESC
          LIMIT {$limit}",
        ['t' => $tenantId]
    );
}

function _jobdivaMappingCanonicalCounts(array $rawCounts): array
{
    $out = array_fill_keys(jobdivaCanonicalEntityTypes(), 0);
    foreach ($rawCounts as $entityType => $count) {
        $canonical = jobdivaCanonicalEntityType((string) $entityType);
        if (!array_key_exists($canonical, $out)) continue;
        $out[$canonical] += (int) $count;
    }
    return $out;
}

function _jobdivaMappingNormalisePlacementExternalId(?string $externalId): string
{
    $externalId = trim((string) $externalId);
    if ($externalId === '') return '';
    return str_starts_with($externalId, 'jd:') ? substr($externalId, 3) : $externalId;
}

function _jobdivaMappingDuplicatePlacementGroups(\PDO $pdo, int $tenantId, int $limit = 100, ?array $scope = null): array
{
    if (!_jobdivaMappingTableExists($pdo, 'placements')) {
        return [];
    }
    $all = $scope !== null && $limit === 0;
    $owner = (int) ($scope['placements'] ?? $tenantId);
    $peopleOwner = (int) ($scope['people'] ?? $tenantId);
    $limit = max(1, min(500, $limit));
    $mapped = [];
    $mappedByInternalId = [];
    if (_jobdivaMappingTableExists($pdo, 'external_entity_mappings')) {
        $mappingRows = _jobdivaMappingRows($pdo,
            "SELECT external_id, internal_entity_id
               FROM external_entity_mappings
              WHERE tenant_id = :t
                AND source_system = 'jobdiva'
                AND internal_entity_type = 'placement'",
            ['t' => $tenantId]
        );
        foreach ($mappingRows as $row) {
            $ext = _jobdivaMappingNormalisePlacementExternalId((string) ($row['external_id'] ?? ''));
            $iid = (int) ($row['internal_entity_id'] ?? 0);
            if ($ext === '') continue;
            $mapped[$ext] ??= ['internal_ids' => []];
            if ($iid > 0) $mapped[$ext]['internal_ids'][$iid] = true;
            if ($iid > 0) {
                $mappedByInternalId[$iid] ??= [];
                if (!in_array($ext, $mappedByInternalId[$iid], true)) {
                    $mappedByInternalId[$iid][] = $ext;
                }
            }
        }
    }

    $hasMappings = _jobdivaMappingTableExists($pdo, 'external_entity_mappings');
    $hasJobDivaJobId = _jobdivaMappingColumnExists($pdo, 'placements', 'jobdiva_job_id');
    $mappingJoin = $hasMappings
        ? "LEFT JOIN external_entity_mappings m
                 ON m.tenant_id = {$tenantId}
                AND m.internal_entity_type = 'placement'
                AND m.internal_entity_id = p.id
                AND m.source_system = 'jobdiva'"
        : '';
    $sourceWhere = [
        "(p.external_id IS NOT NULL AND p.external_id <> '')",
        "p.title LIKE 'JobDiva Placement %'",
    ];
    if ($hasMappings) $sourceWhere[] = 'm.id IS NOT NULL';
    if ($hasJobDivaJobId) $sourceWhere[] = "(p.jobdiva_job_id IS NOT NULL AND p.jobdiva_job_id <> '')";
    $jobIdSelect = $hasJobDivaJobId ? 'p.jobdiva_job_id' : 'NULL AS jobdiva_job_id';
    $jobIdGroup = $hasJobDivaJobId ? 'p.jobdiva_job_id' : 'NULL';
    $hasPeople = _jobdivaMappingTableExists($pdo, 'people');
    $peopleJoin = $hasPeople
        ? "LEFT JOIN people pe ON pe.tenant_id = {$peopleOwner} AND pe.id = p.person_id AND pe.deleted_at IS NULL"
        : '';
    $personSelect = $hasPeople
        ? 'pe.first_name AS person_first_name, pe.last_name AS person_last_name, pe.email_primary AS person_email'
        : "'' AS person_first_name, '' AS person_last_name, '' AS person_email";
    $personGroup = $hasPeople ? ', pe.first_name, pe.last_name, pe.email_primary' : '';

    $placementRows = _jobdivaMappingRows($pdo,
        "SELECT p.id, p.external_id, p.title, p.person_id, p.start_date, p.end_date,
                p.end_client_name, p.end_client_company_id, {$jobIdSelect}, p.status,
                p.created_at, p.updated_at, {$personSelect}
           FROM placements p
           {$mappingJoin}
           {$peopleJoin}
          WHERE p.tenant_id = {$owner}
            AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
            AND (" . implode(' OR ', $sourceWhere) . ")
       GROUP BY p.id, p.external_id, p.title, p.person_id, p.start_date, p.end_date,
                p.end_client_name, p.end_client_company_id, {$jobIdGroup}, p.status,
                p.created_at, p.updated_at{$personGroup}
       ORDER BY p.id ASC",
        []
    );
    if ($scope !== null) {
        $placementRows = jobdivaAlignmentExcludeOtherSources($pdo, $tenantId, $placementRows);
    }
    $groups = [];
    $rowsGroupedByStartId = [];
    $startIdByPlacementId = [];
    foreach ($placementRows as $row) {
        $id = (int) ($row['id'] ?? 0);
        $startId = _jobdivaMappingPlacementStartIdFromRow($row, $mapped, $mappedByInternalId);
        if ($startId === '') continue;
        $row['is_current_mapping'] = $id > 0 && !empty($mapped[$startId]['internal_ids'][$id]);
        $row['canonical_external_id'] = 'jd:' . $startId;
        $key = 'start:' . $startId;
        $groups[$key] ??= [
            'external_id' => $startId,
            'duplicate_basis' => 'jobdiva_start_id',
            'canonical_external_id' => 'jd:' . $startId,
            'count' => 0,
            'rows' => [],
        ];
        $groups[$key]['rows'][] = $row;
        $groups[$key]['count']++;
        if ($id > 0) {
            $rowsGroupedByStartId[$id] = true;
            $startIdByPlacementId[$id] = $startId;
        }
    }

    foreach (_jobdivaMappingLegacyPlacementBridgePairs($placementRows, $startIdByPlacementId) as $pair) {
        $startId = (string) ($pair['start_id'] ?? '');
        $canonicalId = (int) ($pair['canonical_id'] ?? 0);
        $legacyId = (int) ($pair['legacy_id'] ?? 0);
        $key = 'start:' . $startId;
        if ($startId === '' || $canonicalId <= 0 || $legacyId <= 0 || !isset($groups[$key])) continue;
        if ((int) ($groups[$key]['count'] ?? 0) !== 1) continue;
        $legacyRow = null;
        foreach ($placementRows as $candidateRow) {
            if ((int) ($candidateRow['id'] ?? 0) === $legacyId) {
                $legacyRow = $candidateRow;
                break;
            }
        }
        if (!is_array($legacyRow)) continue;
        $legacyRow['is_current_mapping'] = false;
        $legacyRow['canonical_external_id'] = 'jd:' . $startId;
        $legacyRow['legacy_import_bridge'] = true;
        $groups[$key]['duplicate_basis'] = 'jobdiva_start_legacy_import';
        $groups[$key]['rows'][] = $legacyRow;
        $groups[$key]['count']++;
        $rowsGroupedByStartId[$legacyId] = true;
    }

    foreach ($placementRows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0 && !empty($rowsGroupedByStartId[$id])) continue;
        $norm = _jobdivaMappingNormalisePlacementExternalId((string) ($row['external_id'] ?? ''));
        if ($norm === '') continue;
        $row['is_current_mapping'] = $id > 0 && !empty($mapped[$norm]['internal_ids'][$id]);
        $row['canonical_external_id'] = $norm;
        $key = 'external:' . $norm;
        $groups[$key] ??= [
            'external_id' => $norm,
            'duplicate_basis' => 'external_id',
            'canonical_external_id' => $norm,
            'count' => 0,
            'rows' => [],
        ];
        $groups[$key]['rows'][] = $row;
        $groups[$key]['count']++;
    }
    $out = array_values(array_filter($groups, static fn($group) => (int) ($group['count'] ?? 0) > 1));
    $alreadyGrouped = [];
    foreach ($out as $group) {
        foreach ((array) ($group['rows'] ?? []) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) $alreadyGrouped[$id] = true;
        }
    }
    $out = array_merge($out, _jobdivaMappingLegacySpreadsheetDuplicateGroups(
        $pdo,
        $tenantId,
        $mapped,
        $mappedByInternalId,
        $alreadyGrouped,
        $scope
    ));
    usort($out, static fn($a, $b) => ((int) ($b['count'] ?? 0) <=> (int) ($a['count'] ?? 0))
        ?: strcmp((string) ($a['external_id'] ?? ''), (string) ($b['external_id'] ?? '')));
    return $all ? $out : array_slice($out, 0, $limit);
}

/**
 * Pair the reviewed Placements.xlsx row with its JobDiva source row when the
 * identity differs only by a known import wrinkle. This is intentionally much
 * narrower than a general fuzzy match: same person and classification, a safe
 * client alias, and an exact/adjacent/month-day-swapped start date are all
 * required.
 */
function _jobdivaMappingLegacySpreadsheetDuplicateGroups(
    \PDO $pdo,
    int $tenantId,
    array $mapped,
    array $mappedByInternalId,
    array $excludedIds = [],
    ?array $scope = null
): array {
    $owner = (int) ($scope['placements'] ?? $tenantId);
    $limitSql = $scope === null ? ' LIMIT 5000' : '';
    $approvedRateSelect = _jobdivaMappingTableExists($pdo, 'placement_rates')
        ? '(SELECT COUNT(*) FROM placement_rates pr
              WHERE pr.tenant_id = p.tenant_id
                AND pr.placement_id = p.id
                AND pr.approved_at IS NOT NULL)'
        : '0';
    $rows = _jobdivaMappingRows($pdo,
        "SELECT p.*, {$approvedRateSelect} AS approved_rate_count
           FROM placements p
          WHERE p.tenant_id = {$owner}
            AND p.status IN ('pending_start', 'active', 'on_hold')
            AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
       ORDER BY p.person_id, p.id
          {$limitSql}",
        []
    );

    if ($scope !== null) $rows = jobdivaAlignmentExcludeOtherSources($pdo, $tenantId, $rows);
    $byPerson = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        $personId = (int) ($row['person_id'] ?? 0);
        if ($id <= 0 || $personId <= 0 || !empty($excludedIds[$id])) continue;
        $row['is_jobdiva_mapping'] = !empty($mappedByInternalId[$id]);
        $byPerson[$personId][] = $row;
    }

    $candidates = [];
    foreach ($byPerson as $personRows) {
        $sources = array_values(array_filter(
            $personRows,
            static fn(array $row): bool => !empty($row['is_jobdiva_mapping'])
        ));
        $legacy = array_values(array_filter(
            $personRows,
            static fn(array $row): bool => empty($row['is_jobdiva_mapping'])
                && jobdivaPlacementReconcileIsSpreadsheetImport($row)
        ));
        $candidates = array_merge(
            $candidates,
            jobdivaPlacementReconcileUniquePairs($sources, $legacy)
        );
    }

    usort($candidates, static fn(array $a, array $b): int =>
        ((int) ($b['score'] ?? 0) <=> (int) ($a['score'] ?? 0))
        ?: ((int) ($a['source']['id'] ?? 0) <=> (int) ($b['source']['id'] ?? 0))
        ?: ((int) ($a['legacy']['id'] ?? 0) <=> (int) ($b['legacy']['id'] ?? 0))
    );

    $used = [];
    $groups = [];
    foreach ($candidates as $candidate) {
        $source = (array) ($candidate['source'] ?? []);
        $legacy = (array) ($candidate['legacy'] ?? []);
        $sourceId = (int) ($source['id'] ?? 0);
        $legacyId = (int) ($legacy['id'] ?? 0);
        if ($sourceId <= 0 || $legacyId <= 0 || !empty($used[$sourceId]) || !empty($used[$legacyId])) continue;

        $startId = _jobdivaMappingPlacementStartIdFromRow($source, $mapped, $mappedByInternalId);
        if ($startId === '') continue;
        $canonical = 'jd:' . $startId;
        $source['is_current_mapping'] = !empty($mapped[$startId]['internal_ids'][$sourceId]);
        $source['canonical_external_id'] = $canonical;
        $legacy['is_current_mapping'] = false;
        $legacy['canonical_external_id'] = $canonical;
        $legacyApproved = (int) ($legacy['approved_rate_count'] ?? 0);
        $sourceApproved = (int) ($source['approved_rate_count'] ?? 0);
        $preferredKeeper = $legacyApproved > 0 || $sourceApproved === 0 ? $legacyId : $sourceId;
        $groups[] = [
            'external_id' => $startId,
            'duplicate_basis' => 'legacy_spreadsheet_identity',
            'canonical_external_id' => $canonical,
            'count' => 2,
            'preferred_keeper_id' => $preferredKeeper,
            'jobdiva_placement_id' => $sourceId,
            'legacy_spreadsheet_id' => $legacyId,
            'match_reason' => (array) ($candidate['match'] ?? []),
            'rows' => [$source, $legacy],
        ];
        $used[$sourceId] = true;
        $used[$legacyId] = true;
    }
    return $groups;
}

function _jobdivaMappingPlacementStartIdFromRow(array $row, array $mapped = [], array $mappedByInternalId = []): string
{
    $id = (int) ($row['id'] ?? 0);
    if ($id > 0 && !empty($mappedByInternalId[$id]) && is_array($mappedByInternalId[$id])) {
        foreach ($mappedByInternalId[$id] as $mappedExt) {
            $mappedExt = _jobdivaMappingNormalisePlacementExternalId((string) $mappedExt);
            if ($mappedExt !== '' && preg_match('/^\d+$/', $mappedExt)) {
                return $mappedExt;
            }
        }
    }
    $externalId = trim((string) ($row['external_id'] ?? ''));
    $norm = _jobdivaMappingNormalisePlacementExternalId($externalId);
    if ($norm !== ''
        && preg_match('/^\d+$/', $norm)
        && (str_starts_with($externalId, 'jd:') || isset($mapped[$norm]))) {
        return $norm;
    }
    $title = trim((string) ($row['title'] ?? ''));
    if ($title !== '' && preg_match('/^JobDiva\s+Placement\s+(\d+)$/i', $title, $m)) {
        return (string) $m[1];
    }
    return '';
}

/**
 * Pair a canonical JobDiva Start with one legacy spreadsheet placement. Every
 * match must be one-to-one in both directions; ambiguous rows are left for
 * manual review.
 */
function _jobdivaMappingLegacyPlacementBridgePairs(array $rows, array $startIdByPlacementId): array
{
    $liveStatuses = ['draft', 'pending_start', 'active', 'on_hold'];
    $canonicalRows = [];
    $legacyRows = [];
    $startCounts = [];
    foreach ($startIdByPlacementId as $placementId => $startId) {
        $startId = trim((string) $startId);
        if ($startId !== '') $startCounts[$startId] = (int) ($startCounts[$startId] ?? 0) + 1;
    }
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0 || !in_array((string) ($row['status'] ?? ''), $liveStatuses, true)) continue;
        $externalId = trim((string) ($row['external_id'] ?? ''));
        $startId = (string) ($startIdByPlacementId[$id] ?? '');
        if ($startId !== '' && (int) ($startCounts[$startId] ?? 0) === 1) {
            $canonicalRows[$id] = $row + ['__bridge_start_id' => $startId];
            continue;
        }
        if ($startId === '' && preg_match('/^placements-xlsx-/i', $externalId)) {
            $legacyRows[$id] = $row;
        }
    }

    $normalise = static fn(string $value): string => preg_replace(
        '/[^a-z0-9]+/',
        '',
        strtolower(trim($value))
    ) ?: '';
    $usableEmail = static function (string $value): string {
        $value = strtolower(trim($value));
        return $value !== '' && !str_ends_with($value, '@no-email.invalid') ? $value : '';
    };
    $nameKey = static function (array $row) use ($normalise): string {
        return $normalise(trim(
            (string) ($row['person_first_name'] ?? '') . ' ' . (string) ($row['person_last_name'] ?? '')
        ));
    };

    $byCanonical = [];
    $byLegacy = [];
    foreach ($canonicalRows as $canonicalId => $canonical) {
        $canonicalStart = jobdivaNormaliseDate((string) ($canonical['start_date'] ?? ''));
        if ($canonicalStart === null) continue;
        foreach ($legacyRows as $legacyId => $legacy) {
            $legacyStart = jobdivaNormaliseDate((string) ($legacy['start_date'] ?? ''));
            if ($legacyStart === null) continue;

            $samePerson = (int) ($canonical['person_id'] ?? 0) > 0
                && (int) ($canonical['person_id'] ?? 0) === (int) ($legacy['person_id'] ?? 0);
            $canonicalEmail = $usableEmail((string) ($canonical['person_email'] ?? ''));
            $legacyEmail = $usableEmail((string) ($legacy['person_email'] ?? ''));
            $sameEmail = $canonicalEmail !== '' && $canonicalEmail === $legacyEmail;
            $canonicalName = $nameKey($canonical);
            $legacyName = $nameKey($legacy);
            $sameName = $canonicalName !== '' && $canonicalName === $legacyName;
            if (!$samePerson && !$sameEmail && !$sameName) continue;

            $sameCompany = (int) ($canonical['end_client_company_id'] ?? 0) > 0
                && (int) ($canonical['end_client_company_id'] ?? 0) === (int) ($legacy['end_client_company_id'] ?? 0);
            $canonicalClient = $normalise((string) ($canonical['end_client_name'] ?? ''));
            $legacyClient = $normalise((string) ($legacy['end_client_name'] ?? ''));
            $sameClient = $sameCompany
                || ($canonicalClient !== '' && $canonicalClient === $legacyClient);
            $distance = abs((int) floor((strtotime($canonicalStart) - strtotime($legacyStart)) / 86400));
            if ($distance !== 0 && !($distance <= 31 && $sameClient)) continue;
            if (!$samePerson && !$sameEmail && !$sameClient) continue;

            $byCanonical[$canonicalId] ??= [];
            $byCanonical[$canonicalId][$legacyId] = true;
            $byLegacy[$legacyId] ??= [];
            $byLegacy[$legacyId][$canonicalId] = true;
        }
    }

    $pairs = [];
    foreach ($byCanonical as $canonicalId => $legacyCandidates) {
        if (count($legacyCandidates) !== 1) continue;
        $legacyId = (int) array_key_first($legacyCandidates);
        if (count($byLegacy[$legacyId] ?? []) !== 1) continue;
        $pairs[] = [
            'start_id' => (string) ($canonicalRows[$canonicalId]['__bridge_start_id'] ?? ''),
            'canonical_id' => (int) $canonicalId,
            'legacy_id' => $legacyId,
        ];
    }
    return $pairs;
}

function _jobdivaMappingStaleActivePlacementRows(\PDO $pdo, int $tenantId, int $limit = 500, ?array $scope = null): array
{
    if (!_jobdivaMappingTableExists($pdo, 'placements')) return [];
    $all = $scope !== null && $limit === 0;
    $owner = (int) ($scope['placements'] ?? $tenantId);
    $limit = max(1, min(5000, $limit));
    $limitSql = $all ? '' : ' LIMIT 5000';
    $hasMappings = _jobdivaMappingTableExists($pdo, 'external_entity_mappings');
    $mappingJoin = $hasMappings
        ? "LEFT JOIN external_entity_mappings m
                 ON m.id = (
                    SELECT MAX(m2.id)
                      FROM external_entity_mappings m2
                     WHERE m2.tenant_id = {$tenantId}
                       AND m2.internal_entity_type = 'placement'
                       AND m2.internal_entity_id = p.id
                       AND m2.source_system = 'jobdiva'
                 )"
        : '';
    $sourceWhere = $hasMappings
        ? "(p.external_id LIKE 'jd:%' OR p.title LIKE 'JobDiva Placement %' OR m.id IS NOT NULL)"
        : "(p.external_id LIKE 'jd:%' OR p.title LIKE 'JobDiva Placement %')";
    $mappingFields = $hasMappings
        ? 'm.external_id AS mapping_external_id, m.payload_snapshot, m.sync_status AS mapping_sync_status'
        : 'NULL AS mapping_external_id, NULL AS payload_snapshot, NULL AS mapping_sync_status';
    $rows = _jobdivaMappingRows($pdo,
        "SELECT p.id, p.external_id, p.title, p.person_id, p.start_date, p.end_date, p.status, p.updated_at,
                p.deleted_at, p.actual_end_date, p.coreflux_overridden_fields,
                {$mappingFields}
           FROM placements p
           {$mappingJoin}
          WHERE p.tenant_id = {$owner}
            AND p.status = 'active'
            AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
            AND {$sourceWhere}
       ORDER BY p.id ASC
          {$limitSql}",
        []
    );

    if ($scope !== null) $rows = jobdivaAlignmentExcludeOtherSources($pdo, $tenantId, $rows);
    $out = [];
    foreach ($rows as $row) {
        if ($scope !== null && !empty($row['mapping_external_id'])) {
            $root = json_decode((string)$row['payload_snapshot'], true);
            // Invalid roots are counted by source checks; they cannot propose lifecycle changes.
            if (!is_array($root)) continue;
            $plan = jobdivaPlacementLifecycleRepairPlan([
                'placement'=>$row,
                'mapping'=>['sync_status'=>$row['mapping_sync_status'], 'payload_snapshot'=>$row['payload_snapshot']],
                'external'=>jobdivaAssignmentIdentityNormaliseId($row['mapping_external_id']),
                'owned_fields'=>placementOwnershipFields($row['coreflux_overridden_fields']),
            ]);
            if ($plan['status'] !== 'updated') continue;
            unset($row['payload_snapshot'], $row['coreflux_overridden_fields']);
            $out[] = array_replace($row, ['desired_status'=>$plan['desired_status'], 'lifecycle_reason'=>$plan['reason'], 'source_status'=>$plan['source_status']]);
            continue;
        }
        $payload = json_decode((string) ($row['payload_snapshot'] ?? ''), true);
        if (!is_array($payload)) $payload = [];
        if ($payload && function_exists('jobdivaAssignmentStripDerivedFacets')) {
            $payload = jobdivaAssignmentStripDerivedFacets($payload);
        }
        $sourceStatus = $payload
            ? jobdivaAssignmentIdentityPluck($payload, [
                'status', 'startStatus', 'start_status', 'start status',
                'placementStatus', 'placement_status',
                'assignmentStatus', 'assignment_status',
                'employeeStatus', 'employee_status',
            ])
            : '';
        $lifecycle = jobdivaAssignmentPlacementStatusForWrite(
            $sourceStatus,
            (string) ($row['start_date'] ?? ''),
            (string) ($row['end_date'] ?? ''),
            (string) ($row['status'] ?? '')
        );
        $desired = (string) ($lifecycle['status'] ?? 'active');
        if ($desired === 'active') continue;
        $row['desired_status'] = $desired;
        $row['lifecycle_reason'] = (string) ($lifecycle['reason'] ?? 'source_lifecycle');
        $row['source_status'] = (string) ($lifecycle['source_status'] ?? '');
        unset($row['payload_snapshot']);
        $out[] = $row;
        if (!$all && count($out) >= $limit) break;
    }
    return $out;
}

function _jobdivaMappingInClause(string $column, array $values): array
{
    $params = [];
    $parts = [];
    foreach (array_values($values) as $idx => $value) {
        $key = 'in_' . $idx;
        $parts[] = ':' . $key;
        $params[$key] = (int) $value;
    }
    $safeColumn = preg_replace('/[^A-Za-z0-9_]/', '', $column);
    return [$safeColumn . ' IN (' . implode(', ', $parts) . ')', $params];
}

function _jobdivaMappingStringInClause(string $column, array $values): array
{
    $params = [];
    $parts = [];
    foreach (array_values($values) as $idx => $value) {
        $key = 'sin_' . $idx;
        $parts[] = ':' . $key;
        $params[$key] = (string) $value;
    }
    $safeColumn = preg_replace('/[^A-Za-z0-9_]/', '', $column);
    return [$safeColumn . ' IN (' . implode(', ', $parts) . ')', $params];
}

function _jobdivaMappingAddIssue(array &$issues, string $severity, string $code, string $area, int $count, string $summary, string $action): void
{
    if ($count <= 0) return;
    $issues[] = [
        'severity' => $severity,
        'code' => $code,
        'area' => $area,
        'count' => $count,
        'summary' => $summary,
        'action' => $action,
    ];
}

function _jobdivaMappingScalar(\PDO $pdo, string $sql, array $params = []): int
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    } catch (\Throwable $e) {
        error_log('[jobdivaMappingAlignment] scalar failed: ' . $e->getMessage());
        throw $e;
    }
}

function _jobdivaMappingRows(\PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        error_log('[jobdivaMappingAlignment] rows failed: ' . $e->getMessage());
        throw $e;
    }
}

function _jobdivaMappingTableExists(\PDO $pdo, string $table): bool
{
    try {
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :n LIMIT 1");
            $stmt->execute(['n' => $table]);
            return $stmt->fetchColumn() !== false;
        }
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :n'
        );
        $stmt->execute(['n' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (\Throwable $e) {
        throw $e;
    }
}

function _jobdivaMappingColumnExists(\PDO $pdo, string $table, string $column): bool
{
    try {
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $table);
            $cols = $pdo->query("PRAGMA table_info({$safeTable})")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($cols as $c) if (($c['name'] ?? '') === $column) return true;
            return false;
        }
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c'
        );
        $stmt->execute(['t' => $table, 'c' => $column]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (\Throwable $e) {
        throw $e;
    }
}
