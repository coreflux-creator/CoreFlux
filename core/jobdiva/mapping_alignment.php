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

function jobdivaMappingAlignmentReport(int $tenantId, array $opts = []): array
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
    if (!$pdo) {
        _jobdivaMappingAddIssue($issues, 'critical', 'no_database', 'database', 1, 'No database connection is available.', 'Restore database connectivity before checking JobDiva alignment.');
        return [
            'ok' => false,
            'object_map' => $objectMap,
            'sync_config' => $syncConfig,
            'mapping_counts' => $mappingCounts,
            'field_coverage' => $fieldCoverage,
            'relationships' => $relationships,
            'issues' => $issues,
            'samples' => $samples,
            'generated_at' => gmdate('c'),
        ];
    }

    if (!_jobdivaMappingTableExists($pdo, 'external_entity_mappings')) {
        _jobdivaMappingAddIssue($issues, 'critical', 'missing_external_mapping_table', 'mapping', 1, 'external_entity_mappings is missing.', 'Run core migrations before attempting integration sync.');
        return [
            'ok' => false,
            'object_map' => $objectMap,
            'sync_config' => $syncConfig,
            'mapping_counts' => $mappingCounts,
            'field_coverage' => $fieldCoverage,
            'relationships' => $relationships,
            'issues' => $issues,
            'samples' => $samples,
            'generated_at' => gmdate('c'),
        ];
    }

    try {
        $syncConfig = function_exists('jobdivaSyncConfigRead') ? jobdivaSyncConfigRead($tenantId) : [];
    } catch (\Throwable $_) {
        $syncConfig = [];
    }

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
    $projectorContract = function_exists('jobdivaProjectorContract') ? jobdivaProjectorContract() : [];
    $projectorReadiness = function_exists('jobdivaProjectorReadinessCounts') ? jobdivaProjectorReadinessCounts($tenantId) : [];
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
        $invalidAssignmentSources = _jobdivaMappingInvalidPlacementSources($pdo, $tenantId, 5000);
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
                     ON p.tenant_id = m.tenant_id
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
                   JOIN placements p ON p.id = m.internal_entity_id AND p.tenant_id = m.tenant_id
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
        $duplicatePlacementGroups = _jobdivaMappingDuplicatePlacementGroups($pdo, $tenantId, $limit);
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
            'Preview duplicates, then consolidate spreadsheet and JobDiva rows that have no conflicting downstream activity.'
        );

        $staleActiveRows = _jobdivaMappingStaleActivePlacementRows($pdo, $tenantId, 5000);
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
            $missingEndClient = _jobdivaMappingScalar($pdo,
                "SELECT COUNT(*)
                   FROM external_entity_mappings m
                   JOIN placements p ON p.id = m.internal_entity_id AND p.tenant_id = m.tenant_id
                  WHERE m.tenant_id = :t
                    AND m.source_system = 'jobdiva'
                    AND m.internal_entity_type = 'placement'
                    AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
                    AND (p.end_client_company_id IS NULL OR p.end_client_company_id = 0)",
                ['t' => $tenantId]
            );
            $relationships['placement_graph']['missing_end_client_company'] = $missingEndClient;
            _jobdivaMappingAddIssue($issues, 'critical', 'placement_missing_end_client_company', 'placement', $missingEndClient, 'JobDiva-mapped placements are missing the canonical end-client company link.', 'Re-run JobDiva placement sync or repair client links so billing/AP/payroll have the same company identity.');
        }

        if (_jobdivaMappingColumnExists($pdo, 'placements', 'client_id')) {
            $missingClientId = _jobdivaMappingScalar($pdo,
                "SELECT COUNT(*)
                   FROM external_entity_mappings m
                   JOIN placements p ON p.id = m.internal_entity_id AND p.tenant_id = m.tenant_id
                  WHERE m.tenant_id = :t
                    AND m.source_system = 'jobdiva'
                    AND m.internal_entity_type = 'placement'
                    AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
                    AND (p.client_id IS NULL OR p.client_id = 0)",
                ['t' => $tenantId]
            );
            $relationships['placement_graph']['missing_staffing_client'] = $missingClientId;
            _jobdivaMappingAddIssue($issues, 'critical', 'placement_missing_staffing_client', 'placement', $missingClientId, 'JobDiva-mapped placements are missing placements.client_id.', 'Run Repair client links; billing and payroll readiness group by placements.client_id.');
        }

        if (_jobdivaMappingTableExists($pdo, 'staffing_clients') && _jobdivaMappingColumnExists($pdo, 'staffing_clients', 'company_id')
            && _jobdivaMappingColumnExists($pdo, 'placements', 'client_id') && _jobdivaMappingColumnExists($pdo, 'placements', 'end_client_company_id')) {
            $clientMismatch = _jobdivaMappingScalar($pdo,
                "SELECT COUNT(*)
                   FROM external_entity_mappings m
                   JOIN placements p ON p.id = m.internal_entity_id AND p.tenant_id = m.tenant_id
                   JOIN staffing_clients sc ON sc.id = p.client_id AND sc.tenant_id = p.tenant_id
                  WHERE m.tenant_id = :t
                    AND m.source_system = 'jobdiva'
                    AND m.internal_entity_type = 'placement'
                    AND p.end_client_company_id IS NOT NULL
                    AND sc.company_id IS NOT NULL
                    AND sc.company_id <> p.end_client_company_id",
                ['t' => $tenantId]
            );
            $relationships['placement_graph']['client_company_mismatch'] = $clientMismatch;
            _jobdivaMappingAddIssue($issues, 'critical', 'placement_client_company_mismatch', 'placement', $clientMismatch, 'Some JobDiva placements point at a staffing client whose company does not match the placement end-client company.', 'Repair client links, then inspect any remaining mismatches for duplicate company/client records.');
        }

        if (_jobdivaMappingColumnExists($pdo, 'placements', 'person_id')) {
            $missingPersonMapping = _jobdivaMappingScalar($pdo,
                "SELECT COUNT(*)
                   FROM external_entity_mappings m
                   JOIN placements p ON p.id = m.internal_entity_id AND p.tenant_id = m.tenant_id
              LEFT JOIN external_entity_mappings pm
                     ON pm.tenant_id = p.tenant_id
                    AND pm.source_system = 'jobdiva'
                    AND pm.internal_entity_type = 'person'
                    AND pm.internal_entity_id = p.person_id
                  WHERE m.tenant_id = :t
                    AND m.source_system = 'jobdiva'
                    AND m.internal_entity_type = 'placement'
                    AND p.person_id IS NOT NULL
                    AND pm.id IS NULL",
                ['t' => $tenantId]
            );
            $relationships['placement_graph']['person_without_jobdiva_mapping'] = $missingPersonMapping;
            _jobdivaMappingAddIssue($issues, 'warn', 'placement_person_without_source_mapping', 'people', $missingPersonMapping, 'Some JobDiva placements reference people that do not have a JobDiva person mapping.', 'Re-run placement sync with candidate enrichment, or manually bind the candidate to the person record.');
        }
    }

    if (_jobdivaMappingTableExists($pdo, 'people')) {
        $staleSourcePeople = _jobdivaMappingStaleSourcePeopleRows($pdo, $tenantId, 5000);
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
            'Former JobDiva workers are active even though their placement history has no live assignment.',
            'Run People lifecycle repair. History is retained; candidate-only and manually maintained People records are never changed.'
        );
    }

    if (_jobdivaMappingTableExists($pdo, 'staffing_clients') && _jobdivaMappingColumnExists($pdo, 'staffing_clients', 'company_id')) {
        $customersWithoutClient = _jobdivaMappingScalar($pdo,
            "SELECT COUNT(*)
               FROM external_entity_mappings m
          LEFT JOIN staffing_clients sc
                 ON sc.tenant_id = m.tenant_id
                AND sc.company_id = m.internal_entity_id
              WHERE m.tenant_id = :t
                AND m.source_system = 'jobdiva'
                AND m.internal_entity_type = 'jobdiva_customer'
                AND sc.id IS NULL",
            ['t' => $tenantId]
        );
        $relationships['end_client_consumer_bridge'] = [
            'jobdiva_customers_without_staffing_client' => $customersWithoutClient,
        ];
        _jobdivaMappingAddIssue($issues, 'warn', 'jobdiva_customer_without_staffing_client', 'staffing', $customersWithoutClient, 'Some JobDiva end-client/customer company mappings do not have a staffing_clients consumer row.', 'Run Repair client links so staffing workflows consume the company graph instead of drifting.');
    }

    if (_jobdivaMappingTableExists($pdo, 'company_contacts') && _jobdivaMappingTableExists($pdo, 'companies')) {
        $contactsMissingCompany = _jobdivaMappingScalar($pdo,
            "SELECT COUNT(*)
               FROM external_entity_mappings m
          LEFT JOIN company_contacts cc ON cc.id = m.internal_entity_id AND cc.tenant_id = m.tenant_id
          LEFT JOIN companies c ON c.id = cc.company_id AND c.tenant_id = m.tenant_id AND c.deleted_at IS NULL
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
               JOIN time_entries te ON te.id = m.internal_entity_id AND te.tenant_id = m.tenant_id
          LEFT JOIN external_entity_mappings pm
                 ON pm.tenant_id = te.tenant_id
                AND pm.source_system = 'jobdiva'
                AND pm.internal_entity_type = 'placement'
                AND pm.internal_entity_id = te.placement_id
              WHERE m.tenant_id = :t
                AND m.source_system = 'jobdiva'
                AND m.internal_entity_type = 'time_entry'
                AND pm.id IS NULL",
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
                'summary' => 'JobDiva customer/customerId fields are normalized into the company/end-client bridge; native jobdiva_customer rows may remain only to avoid source-id collisions.',
            ],
            [
                'code' => 'staffing_consumes_company_graph',
                'summary' => 'staffing_clients is a consumer row keyed to companies.company_id. It should not become a competing client identity graph.',
            ],
        ],
        'generated_at' => gmdate('c'),
    ];
}

function jobdivaMappingRepairStaffingClientLinks(int $tenantId, ?int $userId = null, int $limit = 500): array
{
    $summary = ['checked' => 0, 'repaired' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
    $limit = max(1, min(5000, $limit));
    $pdo = getDB();
    if (!$pdo) {
        $summary['failed']++;
        $summary['errors'][] = 'No database connection';
        return $summary;
    }
    foreach (['external_entity_mappings', 'placements', 'staffing_clients', 'companies'] as $table) {
        if (!_jobdivaMappingTableExists($pdo, $table)) {
            $summary['failed']++;
            $summary['errors'][] = "Missing table: {$table}";
            return $summary;
        }
    }
    foreach ([['placements', 'client_id'], ['placements', 'end_client_company_id'], ['staffing_clients', 'company_id']] as [$table, $column]) {
        if (!_jobdivaMappingColumnExists($pdo, $table, $column)) {
            $summary['failed']++;
            $summary['errors'][] = "Missing column: {$table}.{$column}";
            return $summary;
        }
    }

    $stmt = $pdo->prepare(
        "SELECT p.id, p.client_id, p.end_client_company_id, p.end_client_name, c.name AS company_name,
                sc.id AS existing_client_id, sc.company_id AS existing_client_company_id,
                sc.name AS existing_client_name,
                m.external_id AS mapping_external_id,
                m.payload_snapshot
           FROM external_entity_mappings m
           JOIN placements p ON p.id = m.internal_entity_id AND p.tenant_id = m.tenant_id
      LEFT JOIN companies c ON c.id = p.end_client_company_id AND c.tenant_id = p.tenant_id AND c.deleted_at IS NULL
      LEFT JOIN staffing_clients sc ON sc.id = p.client_id AND sc.tenant_id = p.tenant_id
          WHERE m.tenant_id = :t
            AND m.source_system = 'jobdiva'
            AND m.internal_entity_type = 'placement'
            AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
            AND (
                 m.payload_snapshot IS NOT NULL
              OR p.client_id IS NULL
              OR p.end_client_company_id IS NULL
              OR p.end_client_company_id = 0
              OR sc.id IS NULL
              OR (p.end_client_company_id IS NOT NULL AND p.end_client_company_id <> 0 AND (sc.company_id IS NULL OR sc.company_id = 0))
              OR (p.end_client_company_id IS NOT NULL AND sc.company_id IS NOT NULL AND sc.company_id <> p.end_client_company_id)
            )
       ORDER BY p.updated_at DESC
          LIMIT {$limit}"
    );
    $stmt->execute(['t' => $tenantId]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $row) {
        $summary['checked']++;
        $placementId = (int) $row['id'];
        $companyId = !empty($row['end_client_company_id']) ? (int) $row['end_client_company_id'] : null;
        if ($companyId === null && !empty($row['existing_client_company_id'])) {
            $companyId = (int) $row['existing_client_company_id'];
        }

        $payload = [];
        if (is_string($row['payload_snapshot'] ?? null) && trim((string) $row['payload_snapshot']) !== '') {
            $decoded = json_decode((string) $row['payload_snapshot'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
                try {
                    if (function_exists('jobdivaPlacementPayloadWithMirrors')) {
                        $mirrorStats = [];
                        $payload = jobdivaPlacementPayloadWithMirrors(
                            $tenantId,
                            $payload,
                            $mirrorStats,
                            (string) ($row['mapping_external_id'] ?? '')
                        );
                    }
                } catch (\Throwable $e) {
                    error_log('[jobdiva mapping repair] payload mirror enrichment failed: ' . $e->getMessage());
                }
            }
        }

        $evidence = $payload && function_exists('jobdivaProjectorAssignmentClientEvidence')
            ? jobdivaProjectorAssignmentClientEvidence($payload)
            : ['authoritative' => false, 'name' => ''];
        $name = trim((string) ($evidence['name'] ?? ''));

        if (!empty($evidence['authoritative']) && function_exists('jobdivaProjectorResolveEndClientCompany')) {
            try {
                $resolvedCompanyId = jobdivaProjectorResolveEndClientCompany($tenantId, $payload, $userId);
                if ($resolvedCompanyId !== null && $resolvedCompanyId > 0) {
                    $companyId = (int) $resolvedCompanyId;
                }
            } catch (\Throwable $e) {
                error_log('[jobdiva mapping repair] end-client resolve failed: ' . $e->getMessage());
            }
        }

        // Without exact assignment billing evidence, repair may only re-link
        // an already-established company/client pair. It must never create a
        // client from legacy free text, a contact name, or a requisition name.
        $existingCanonicalClient = $companyId !== null
            ? staffingClientFindForCompany($tenantId, $companyId)
            : null;
        if (empty($evidence['authoritative']) && !$existingCanonicalClient) {
            $summary['skipped']++;
            continue;
        }
        if ($name === '' && $existingCanonicalClient) {
            $name = trim((string) ($existingCanonicalClient['name'] ?? ''));
        }
        try {
            $clientRef = !empty($evidence['authoritative'])
                ? staffingClientEnsureForCompany($tenantId, $companyId, $name, [
                    'created_by_user_id' => $userId,
                    'status' => 'active',
                    'source_system' => 'jobdiva',
                ])
                : [
                    'client_id' => (int) $existingCanonicalClient['id'],
                    'company_id' => (int) $existingCanonicalClient['company_id'],
                    'name' => (string) $existingCanonicalClient['name'],
                ];
            $clientId = (int) ($clientRef['client_id'] ?? 0);
            if ($clientId <= 0) {
                $summary['skipped']++;
                continue;
            }
            $patch = [
                'client_id' => $clientId,
                'updated_at' => date('Y-m-d H:i:s'),
                'tenant_id' => $tenantId,
                'id' => $placementId,
            ];
            $sets = ['client_id = :client_id', 'updated_at = :updated_at'];
            if (!empty($clientRef['company_id'])) {
                $sets[] = 'end_client_company_id = :end_client_company_id';
                $patch['end_client_company_id'] = (int) $clientRef['company_id'];
            }
            if (!empty($clientRef['name'])
                && jobdivaProjectorCompanyNameKey((string) ($row['end_client_name'] ?? '')) !== jobdivaProjectorCompanyNameKey((string) $clientRef['name'])) {
                $sets[] = 'end_client_name = :end_client_name';
                $patch['end_client_name'] = (string) $clientRef['name'];
            }
            $pdo->prepare(
                'UPDATE placements SET ' . implode(', ', $sets) . ' WHERE tenant_id = :tenant_id AND id = :id'
            )->execute($patch);
            $summary['repaired']++;
        } catch (\Throwable $e) {
            $summary['failed']++;
            if (count($summary['errors']) < 10) {
                $summary['errors'][] = "placement {$placementId}: " . $e->getMessage();
            }
        }
    }

    if (function_exists('jobdivaAudit')) {
        try {
            jobdivaAudit($tenantId, 'mapping_alignment_repair_client_links', [
                'ok' => $summary['failed'] === 0,
                'direction' => 'pull',
                'actor_user_id' => $userId,
                'items_processed' => $summary['repaired'],
                'items_skipped' => $summary['skipped'],
                'items_failed' => $summary['failed'],
                'detail' => $summary,
            ]);
        } catch (\Throwable $_) {}
    }

    return $summary;
}

function jobdivaMappingRepairSourceRateDrafts(int $tenantId, array $user, int $limit = 500): array
{
    $summary = ['checked' => 0, 'drafted' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
    $limit = max(1, min(1000, $limit));
    $pdo = getDB();
    if (!$pdo) {
        $summary['failed']++;
        $summary['errors'][] = 'No database connection';
        return $summary;
    }
    foreach (['external_entity_mappings', 'placements', 'placement_rates'] as $table) {
        if (!_jobdivaMappingTableExists($pdo, $table)) {
            $summary['failed']++;
            $summary['errors'][] = "Missing table: {$table}";
            return $summary;
        }
    }
    foreach ([['placements', 'start_date'], ['placements', 'status'], ['placement_rates', 'approved_at'], ['placement_rates', 'effective_from'], ['placement_rates', 'effective_to'], ['placement_rates', 'created_by_user_id']] as [$table, $column]) {
        if (!_jobdivaMappingColumnExists($pdo, $table, $column)) {
            $summary['failed']++;
            $summary['errors'][] = "Missing column: {$table}.{$column}";
            return $summary;
        }
    }

    $stmt = $pdo->prepare(
        "SELECT p.id
           FROM external_entity_mappings m
           JOIN placements p ON p.id = m.internal_entity_id AND p.tenant_id = m.tenant_id
          WHERE m.tenant_id = :t
            AND m.source_system = 'jobdiva'
            AND m.internal_entity_type = 'placement'
            AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
            AND (
                 NOT EXISTS (
                     SELECT 1
                       FROM placement_rates any_pr
                      WHERE any_pr.tenant_id = p.tenant_id
                        AND any_pr.placement_id = p.id
                 )
              OR (
                    p.status = 'active'
                AND NOT EXISTS (
                     SELECT 1
                       FROM placement_rates approved_pr
                      WHERE approved_pr.tenant_id = p.tenant_id
                        AND approved_pr.placement_id = p.id
                        AND approved_pr.approved_at IS NOT NULL
                        AND approved_pr.effective_from <= COALESCE(NULLIF(p.start_date, ''), CURDATE())
                        AND (approved_pr.effective_to IS NULL OR approved_pr.effective_to >= COALESCE(NULLIF(p.start_date, ''), CURDATE()))
                )
              )
              OR EXISTS (
                    SELECT 1
                      FROM placement_rates unsafe_pr
                     WHERE unsafe_pr.tenant_id = p.tenant_id
                       AND unsafe_pr.placement_id = p.id
                       AND unsafe_pr.approved_at IS NULL
                       AND unsafe_pr.created_by_user_id IS NULL
                       AND ABS(unsafe_pr.pay_rate - unsafe_pr.bill_rate) < 0.0001
              )
            )
       GROUP BY p.id
       ORDER BY MAX(p.updated_at) DESC
          LIMIT {$limit}"
    );
    $stmt->execute(['t' => $tenantId]);
    $placementIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);

    foreach ($placementIds as $placementId) {
        if ($placementId <= 0) continue;
        $summary['checked']++;
        try {
            if (placementsEnsureDraftRateFromSourcePayload($placementId, $user)) {
                $summary['drafted']++;
            } else {
                $summary['skipped']++;
            }
        } catch (\Throwable $e) {
            $summary['failed']++;
            if (count($summary['errors']) < 10) {
                $summary['errors'][] = "placement {$placementId}: " . $e->getMessage();
            }
        }
    }

    if (function_exists('jobdivaAudit')) {
        try {
            jobdivaAudit($tenantId, 'mapping_alignment_repair_source_rate_drafts', [
                'ok' => $summary['failed'] === 0,
                'direction' => 'pull',
                'actor_user_id' => isset($user['id']) ? (int) $user['id'] : null,
                'items_processed' => $summary['drafted'],
                'items_skipped' => $summary['skipped'],
                'items_failed' => $summary['failed'],
                'detail' => $summary,
            ]);
        } catch (\Throwable $_) {}
    }

    return $summary;
}

function jobdivaMappingRepairAssignmentSources(int $tenantId, ?int $userId = null, int $limit = 5000): array
{
    $summary = [
        'checked' => 0,
        'verified' => 0,
        'stored_assignments_trusted' => 0,
        'non_assignments' => 0,
        'not_found' => 0,
        'placements_restored' => 0,
        'placements_archived' => 0,
        'archived_retained' => 0,
        'quarantined' => 0,
        'review_required' => 0,
        'api_errors' => 0,
        'failed' => 0,
        'errors' => [],
    ];
    $limit = max(1, min(5000, $limit));
    $pdo = getDB();
    if (!$pdo) {
        $summary['failed']++;
        $summary['errors'][] = 'No database connection';
        return $summary;
    }

    try {
        $st = $pdo->prepare(
            "SELECT m.id AS mapping_id, m.external_id, m.internal_entity_id AS placement_id,
                    m.payload_snapshot, m.last_seen_at AS mapping_last_seen_at,
                    p.deleted_at AS placement_deleted_at,
                    c.last_sync_at AS connection_last_sync_at
               FROM external_entity_mappings m
               JOIN placements p
                 ON p.tenant_id = m.tenant_id
                AND p.id = m.internal_entity_id
               LEFT JOIN jobdiva_connections c
                 ON c.tenant_id = m.tenant_id
              WHERE m.tenant_id = :t
                AND m.source_system = 'jobdiva'
                AND m.internal_entity_type = 'placement'
              ORDER BY m.id ASC
              LIMIT {$limit}"
        );
        $st->execute(['t' => $tenantId]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        $summary['failed']++;
        $summary['errors'][] = 'query_failed: ' . $e->getMessage();
        return $summary;
    }

    foreach ($rows as $row) {
        $summary['checked']++;
        $mappingId = (int) ($row['mapping_id'] ?? 0);
        $placementId = (int) ($row['placement_id'] ?? 0);
        $externalId = jobdivaAssignmentIdentityNormaliseId((string) ($row['external_id'] ?? ''));
        if ($mappingId <= 0 || $placementId <= 0 || $externalId === '') {
            $summary['failed']++;
            if (count($summary['errors']) < 20) {
                $summary['errors'][] = "placement {$placementId}: invalid mapping identity";
            }
            continue;
        }

        $stored = json_decode((string) ($row['payload_snapshot'] ?? ''), true);
        if (!is_array($stored)) $stored = [];
        $stored = jobdivaAssignmentSanitisePayload($stored, $externalId);
        $storedValidation = jobdivaAssignmentValidate($stored, $externalId);
        $storedObservedInLatestSync = jobdivaAssignmentObservedInLatestSync(
            $storedValidation,
            (string) ($row['mapping_last_seen_at'] ?? ''),
            (string) ($row['connection_last_sync_at'] ?? '')
        );
        $wasArchived = !empty($row['placement_deleted_at'])
            && (string) $row['placement_deleted_at'] !== '0000-00-00 00:00:00';

        $exact = jobdivaFetchExactAssignmentById($tenantId, $externalId, $stored);
        $status = (string) ($exact['status'] ?? 'error');
        $decision = jobdivaAssignmentSourceDecision(
            $exact,
            $storedValidation,
            $externalId,
            $storedObservedInLatestSync
        );
        $action = (string) ($decision['action'] ?? 'review');
        if ($action === 'remote_verified' || $action === 'trusted_stored') {
            $trustedStoredFacet = jobdivaAssignmentFindExactFacet($stored, $externalId);
            $trustedMirrorFacet = null;
            if (function_exists('jobdivaMirrorPayloadByExternalId')) {
                $mirror = jobdivaMirrorPayloadByExternalId(
                    $tenantId,
                    'jobdiva_assignment',
                    $externalId
                );
                if (is_array($mirror)) {
                    $mirrorValidation = jobdivaAssignmentValidate($mirror, $externalId);
                    if (!empty($mirrorValidation['valid'])) {
                        $trustedMirrorFacet = $mirror;
                    }
                }
            }
            $stored = jobdivaAssignmentStripDerivedFacets($stored);
            $payload = $stored;
            $channel = (string) (($storedValidation['channel'] ?? '') ?: 'repair:stored_assignment_evidence');
            if ($action === 'remote_verified' && is_array($exact['row'] ?? null)) {
                // The exact Start owns identity and lifecycle. Rich assignment
                // detail is a separate exact-id facet: retain it when verified
                // because it carries classification, rates, and user fields
                // that sparse searchStart responses omit.
                $payload = $exact['row'];
                $channel = (string) (($exact['identity']['channel'] ?? '') ?: 'repair:exact_assignment');
            }
            $trustedFacet = $trustedMirrorFacet ?? $trustedStoredFacet;
            if ($trustedFacet !== null) {
                $payload['_jd_start'] = $trustedFacet;
                $payload['assignment'] = [$trustedFacet];
            }
            $payload = jobdivaAssignmentSanitisePayload($payload, $externalId);
            $payload = jobdivaAssignmentMarkVerified($payload, $externalId, $channel);
            try {
                $pdo->beginTransaction();
                $snapshotPayload = jobdivaAssignmentCompactSnapshotPayload($payload, $externalId);
                mappingUpsert(
                    $tenantId,
                    'jobdiva',
                    'placement',
                    $externalId,
                    $placementId,
                    $snapshotPayload,
                    'pull',
                    $userId
                );
                $pdo->prepare(
                    "UPDATE external_entity_mappings
                        SET sync_status = 'ok', last_error = NULL, last_seen_at = NOW()
                      WHERE tenant_id = :t AND id = :id"
                )->execute(['t' => $tenantId, 'id' => $mappingId]);
                $pdo->prepare(
                    "UPDATE external_entity_mappings
                        SET sync_status = 'ok', last_error = NULL, last_seen_at = NOW()
                      WHERE tenant_id = :t
                        AND source_system = 'jobdiva'
                        AND internal_entity_type = 'jobdiva_assignment'
                        AND external_id = :eid"
                )->execute(['t' => $tenantId, 'eid' => $externalId]);
                if ($wasArchived) {
                    $pdo->prepare(
                        "UPDATE placements
                            SET deleted_at = NULL, updated_at = NOW()
                          WHERE tenant_id = :t AND id = :id"
                    )->execute(['t' => $tenantId, 'id' => $placementId]);
                    $summary['placements_restored']++;
                }
                $pdo->commit();
                if ($action === 'remote_verified') {
                    $summary['verified']++;
                } else {
                    $summary['stored_assignments_trusted']++;
                }
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $summary['failed']++;
                if (count($summary['errors']) < 20) {
                    $summary['errors'][] = "placement {$placementId}: " . $e->getMessage();
                }
            }
            continue;
        }

        $error = substr((string) ($exact['error'] ?? 'JobDiva assignment verification failed'), 0, 500);
        if ($status === 'not_assignment') $summary['non_assignments']++;
        if ($status === 'not_found') $summary['not_found']++;
        if ($action !== 'terminal') {
            $pdo->prepare(
                "UPDATE external_entity_mappings
                    SET sync_status = 'error', last_error = :err
                  WHERE tenant_id = :t AND id = :id"
            )->execute(['err' => $error, 't' => $tenantId, 'id' => $mappingId]);
            if ($status === 'error') {
                $summary['api_errors']++;
                $summary['failed']++;
            }
            $summary['review_required']++;
            if (!$wasArchived) $summary['quarantined']++;
            if (count($summary['errors']) < 20) {
                $summary['errors'][] = "placement {$placementId}: {$error}";
            }
            continue;
        }

        if ($wasArchived) {
            $summary['archived_retained']++;
            continue;
        }

        $blocking = _jobdivaMappingDuplicatePlacementBlockingChildren($pdo, $tenantId, [$placementId]);
        try {
            $pdo->beginTransaction();
            $pdo->prepare(
                "UPDATE external_entity_mappings
                    SET sync_status = 'deleted_in_source', last_error = :err
                  WHERE tenant_id = :t AND id = :id"
            )->execute(['err' => $error, 't' => $tenantId, 'id' => $mappingId]);
            $pdo->prepare(
                "UPDATE external_entity_mappings
                    SET sync_status = 'deleted_in_source', last_error = :err
                  WHERE tenant_id = :t
                    AND source_system = 'jobdiva'
                    AND internal_entity_type = 'jobdiva_assignment'
                    AND external_id = :eid"
            )->execute(['err' => $error, 't' => $tenantId, 'eid' => $externalId]);
            if (!$blocking) {
                $pdo->prepare(
                    "UPDATE placements
                        SET deleted_at = NOW(), updated_at = NOW()
                      WHERE tenant_id = :t AND id = :id
                        AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')"
                )->execute(['t' => $tenantId, 'id' => $placementId]);
                $summary['placements_archived']++;
            } else {
                $summary['quarantined']++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $summary['failed']++;
            if (count($summary['errors']) < 20) {
                $summary['errors'][] = "placement {$placementId}: " . $e->getMessage();
            }
        }
    }

    if (function_exists('jobdivaAudit')) {
        try {
            jobdivaAudit($tenantId, 'mapping_alignment_repair_assignment_sources', [
                'ok' => $summary['failed'] === 0,
                'direction' => 'pull',
                'actor_user_id' => $userId,
                'items_processed' => $summary['verified']
                    + $summary['stored_assignments_trusted']
                    + $summary['placements_archived'],
                'items_skipped' => $summary['review_required'],
                'items_failed' => $summary['failed'],
                'detail' => $summary,
            ]);
        } catch (\Throwable $_) {}
    }
    return $summary;
}

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
        $backfill = jobdivaBackfillJoinedIndexes($tenantId);
        $summary['payloads_refreshed'] = (int) ($backfill['enrichment_ran_for'] ?? 0);
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
    $summary['checked'] = $summary['payloads_refreshed'];
    $summary['skipped'] = $summary['checked'];

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
    $steps['duplicate_placements'] = jobdivaMappingRepairDuplicatePlacements($tenantId, $userId, min(500, $limit), false);
    $steps['client_links'] = jobdivaMappingRepairStaffingClientLinks($tenantId, $userId, $limit);
    $steps['stale_active_placements'] = jobdivaMappingRepairStaleActivePlacements($tenantId, $userId, $limit, false);
    $steps['source_people_lifecycle'] = jobdivaMappingRepairSourcePeopleLifecycle($tenantId, $userId, $limit, false);
    $steps['source_rate_drafts'] = jobdivaMappingRepairSourceRateDrafts($tenantId, $user, $limit);

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
        'ok' => $failed === 0,
        'started_at' => $startedAt,
        'finished_at' => gmdate('c'),
        'steps' => $steps,
        'totals' => [
            'changed' => $changed,
            'failed' => $failed,
        ],
        'remaining' => [
            'critical_issue_types' => count($remainingCritical),
            'critical_rows' => array_reduce(
                $remainingCritical,
                static fn($sum, $issue) => $sum + (int) ($issue['count'] ?? 0),
                0
            ),
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

function jobdivaMappingRepairStaleActivePlacements(int $tenantId, ?int $userId = null, int $limit = 500, bool $dryRun = true): array
{
    $summary = [
        'dry_run' => $dryRun,
        'checked' => 0,
        'updated' => 0,
        'ended' => 0,
        'cancelled' => 0,
        'pending_start' => 0,
        'on_hold' => 0,
        'skipped' => 0,
        'failed' => 0,
        'errors' => [],
    ];
    $limit = max(1, min(5000, $limit));
    $pdo = getDB();
    if (!$pdo) {
        $summary['failed']++;
        $summary['errors'][] = 'No database connection';
        return $summary;
    }
    if (!_jobdivaMappingTableExists($pdo, 'placements')) {
        $summary['failed']++;
        $summary['errors'][] = 'Missing table: placements';
        return $summary;
    }

    $rows = _jobdivaMappingStaleActivePlacementRows($pdo, $tenantId, $limit);
    foreach ($rows as $row) {
        $summary['checked']++;
        $placementId = (int) ($row['id'] ?? 0);
        if ($placementId <= 0) {
            $summary['skipped']++;
            continue;
        }
        $desiredStatus = (string) ($row['desired_status'] ?? 'ended');
        if (!in_array($desiredStatus, ['ended', 'cancelled', 'pending_start', 'on_hold'], true)) {
            $summary['skipped']++;
            continue;
        }
        if ($dryRun) {
            $summary['updated']++;
            $summary[$desiredStatus]++;
            continue;
        }
        try {
            $stmt = $pdo->prepare(
                "UPDATE placements
                    SET status = :status, updated_at = NOW()
                  WHERE tenant_id = :t
                    AND id = :id
                    AND status = 'active'"
            );
            $stmt->execute(['status' => $desiredStatus, 't' => $tenantId, 'id' => $placementId]);
            if ($stmt->rowCount() > 0) {
                $summary['updated']++;
                $summary[$desiredStatus]++;
                if (function_exists('placementsAudit')) {
                    placementsAudit('placement.status_repaired_from_jobdiva_lifecycle', [
                        'placement_id' => $placementId,
                        'prior_status' => 'active',
                        'status' => $desiredStatus,
                        'end_date' => (string) ($row['end_date'] ?? ''),
                        'source_status' => (string) ($row['source_status'] ?? ''),
                        'reason' => (string) ($row['lifecycle_reason'] ?? ''),
                        'source' => 'jobdiva_mapping_alignment',
                    ], $placementId);
                }
            } else {
                $summary['skipped']++;
            }
        } catch (\Throwable $e) {
            $summary['failed']++;
            if (count($summary['errors']) < 10) {
                $summary['errors'][] = "placement {$placementId}: " . $e->getMessage();
            }
        }
    }

    if (function_exists('jobdivaAudit')) {
        try {
            jobdivaAudit($tenantId, 'mapping_alignment_repair_stale_active_placements', [
                'ok' => $summary['failed'] === 0,
                'direction' => 'pull',
                'actor_user_id' => $userId,
                'items_processed' => $summary['updated'],
                'items_skipped' => $summary['skipped'],
                'items_failed' => $summary['failed'],
                'detail' => $summary,
            ]);
        } catch (\Throwable $_) {}
    }

    return $summary;
}

function jobdivaMappingRepairSourcePeopleLifecycle(
    int $tenantId,
    ?int $userId = null,
    int $limit = 500,
    bool $dryRun = true
): array {
    $summary = ['dry_run' => $dryRun, 'checked' => 0, 'inactivated' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
    $limit = max(1, min(5000, $limit));
    $pdo = getDB();
    if (!$pdo) {
        $summary['failed']++;
        $summary['errors'][] = 'No database connection';
        return $summary;
    }
    if (!_jobdivaMappingTableExists($pdo, 'people') || !_jobdivaMappingTableExists($pdo, 'placements')) {
        $summary['failed']++;
        $summary['errors'][] = 'Missing people or placements table';
        return $summary;
    }

    $rows = _jobdivaMappingStaleSourcePeopleRows($pdo, $tenantId, $limit);
    foreach ($rows as $row) {
        $summary['checked']++;
        $personId = (int) ($row['id'] ?? 0);
        if ($personId <= 0) {
            $summary['skipped']++;
            continue;
        }
        if ($dryRun) {
            $summary['inactivated']++;
            continue;
        }
        try {
            $stmt = $pdo->prepare(
                "UPDATE people p
                    SET p.status = 'inactive', p.updated_at = NOW()
                  WHERE p.tenant_id = :t
                    AND p.id = :id
                    AND p.status IN ('active', 'bench')
                    AND p.deleted_at IS NULL
                    AND NOT EXISTS (
                        SELECT 1
                          FROM placements live
                         WHERE live.tenant_id = p.tenant_id
                           AND live.person_id = p.id
                           AND (live.deleted_at IS NULL OR live.deleted_at = '0000-00-00 00:00:00')
                           AND live.status IN ('draft', 'pending_start', 'active', 'on_hold')
                           AND (live.end_date IS NULL OR live.end_date = '' OR live.end_date >= :today)
                    )"
            );
            $stmt->execute(['t' => $tenantId, 'id' => $personId, 'today' => date('Y-m-d')]);
            if ($stmt->rowCount() > 0) {
                $summary['inactivated']++;
            } else {
                $summary['skipped']++;
            }
        } catch (\Throwable $e) {
            $summary['failed']++;
            if (count($summary['errors']) < 10) {
                $summary['errors'][] = "person {$personId}: " . $e->getMessage();
            }
        }
    }

    if (function_exists('jobdivaAudit')) {
        try {
            jobdivaAudit($tenantId, 'mapping_alignment_repair_source_people_lifecycle', [
                'ok' => $summary['failed'] === 0,
                'direction' => 'pull',
                'actor_user_id' => $userId,
                'items_processed' => $summary['inactivated'],
                'items_skipped' => $summary['skipped'],
                'items_failed' => $summary['failed'],
                'detail' => $summary,
            ]);
        } catch (\Throwable $_) {}
    }

    return $summary;
}

function jobdivaMappingRepairDuplicatePlacements(int $tenantId, ?int $userId = null, int $limit = 100, bool $dryRun = false): array
{
    $summary = [
        'dry_run' => $dryRun,
        'groups_checked' => 0,
        'groups_repaired' => 0,
        'placements_archived' => 0,
        'external_ids_restored' => 0,
        'person_mappings_rehomed' => 0,
        'people_inactivated' => 0,
        'legacy_groups_repaired' => 0,
        'source_fields_merged' => 0,
        'rates_rehomed' => 0,
        'draft_rates_removed' => 0,
        'config_rows_rehomed' => 0,
        'skipped' => 0,
        'failed' => 0,
        'errors' => [],
        'skipped_groups' => [],
    ];
    $limit = max(1, min(500, $limit));
    $pdo = getDB();
    if (!$pdo) {
        $summary['failed']++;
        $summary['errors'][] = 'No database connection';
        return $summary;
    }
    foreach (['external_entity_mappings', 'placements'] as $table) {
        if (!_jobdivaMappingTableExists($pdo, $table)) {
            $summary['failed']++;
            $summary['errors'][] = "Missing table: {$table}";
            return $summary;
        }
    }

    $groups = _jobdivaMappingDuplicatePlacementGroups($pdo, $tenantId, $limit);
    foreach ($groups as $group) {
        $summary['groups_checked']++;
        $norm = (string) ($group['external_id'] ?? '');
        $rows = is_array($group['rows'] ?? null) ? $group['rows'] : [];
        if ($norm === '' || count($rows) < 2) {
            $summary['skipped']++;
            continue;
        }
        $rowIds = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) $rowIds[] = $id;
        }
        $childCounts = _jobdivaMappingDuplicatePlacementChildCounts($pdo, $tenantId, $rowIds);
        $rowsWithChildren = array_values(array_filter(array_keys($childCounts), static fn($id) => (int) ($childCounts[$id] ?? 0) > 0));
        if (count($rowsWithChildren) > 1) {
            $summary['skipped']++;
            $summary['skipped_groups'][] = [
                'external_id' => $norm,
                'reason' => 'multiple_rows_have_downstream_activity',
                'child_counts' => $childCounts,
            ];
            continue;
        }
        $preferredKeeper = (int) ($group['preferred_keeper_id'] ?? 0);
        $keepId = count($rowsWithChildren) === 1
            ? (int) $rowsWithChildren[0]
            : ($preferredKeeper > 0 && in_array($preferredKeeper, $rowIds, true)
                ? $preferredKeeper
                : _jobdivaMappingChooseDuplicatePlacementKeeper($group));
        if ($keepId <= 0) {
            $summary['skipped']++;
            $summary['skipped_groups'][] = ['external_id' => $norm, 'reason' => 'no_keep_candidate'];
            continue;
        }
        $duplicateIds = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0 && $id !== $keepId) $duplicateIds[] = $id;
        }
        if (!$duplicateIds) {
            $summary['skipped']++;
            continue;
        }
        if ((string) ($group['duplicate_basis'] ?? '') === 'legacy_spreadsheet_identity') {
            $keepApproved = 0;
            $duplicateApproved = 0;
            foreach ($rows as $row) {
                if ((int) ($row['id'] ?? 0) === $keepId) {
                    $keepApproved += (int) ($row['approved_rate_count'] ?? 0);
                } else {
                    $duplicateApproved += (int) ($row['approved_rate_count'] ?? 0);
                }
            }
            if ($keepApproved === 0 && $duplicateApproved > 0) {
                $summary['skipped']++;
                $summary['skipped_groups'][] = [
                    'external_id' => $norm,
                    'keep_id' => $keepId,
                    'duplicate_ids' => $duplicateIds,
                    'reason' => 'approved_rate_snapshot_belongs_to_duplicate',
                ];
                continue;
            }
        }
        $blocking = _jobdivaMappingDuplicatePlacementBlockingChildren($pdo, $tenantId, $duplicateIds);
        if ($blocking) {
            $summary['skipped']++;
            $summary['skipped_groups'][] = [
                'external_id' => $norm,
                'keep_id' => $keepId,
                'duplicate_ids' => $duplicateIds,
                'blocking_children' => $blocking,
            ];
            continue;
        }
        if ($dryRun) {
            $summary['groups_repaired']++;
            $summary['placements_archived'] += count($duplicateIds);
            if ((string) ($group['duplicate_basis'] ?? '') === 'legacy_spreadsheet_identity') {
                $summary['legacy_groups_repaired']++;
            }
            $canonicalPreview = (string) ($group['canonical_external_id'] ?? '');
            foreach ($rows as $row) {
                if ((int) ($row['id'] ?? 0) === $keepId && $canonicalPreview !== '' && (string) ($row['external_id'] ?? '') !== $canonicalPreview) {
                    $summary['external_ids_restored']++;
                    break;
                }
            }
            if ((string) ($group['duplicate_basis'] ?? '') === 'jobdiva_start_legacy_import') {
                $keepPersonId = 0;
                foreach ($rows as $row) {
                    if ((int) ($row['id'] ?? 0) === $keepId) {
                        $keepPersonId = (int) ($row['person_id'] ?? 0);
                        break;
                    }
                }
                $duplicatePersonIds = [];
                foreach ($rows as $row) {
                    $rowId = (int) ($row['id'] ?? 0);
                    $personId = (int) ($row['person_id'] ?? 0);
                    if ($rowId !== $keepId && $personId > 0 && $personId !== $keepPersonId) {
                        $duplicatePersonIds[$personId] = true;
                    }
                }
                $summary['person_mappings_rehomed'] += count($duplicatePersonIds);
            }
            continue;
        }

        try {
            $pdo->beginTransaction();
            $canonical = (string) ($group['canonical_external_id'] ?? '');
            $merge = [];
            if ((string) ($group['duplicate_basis'] ?? '') === 'legacy_spreadsheet_identity') {
                $merge = _jobdivaMappingConsolidateLegacyPlacementPair(
                    $pdo,
                    $tenantId,
                    $group,
                    $keepId,
                    $duplicateIds
                );
            }
            [$inSql, $params] = _jobdivaMappingInClause('id', $duplicateIds);
            $params['t'] = $tenantId;
            $pdo->prepare(
                "UPDATE placements
                    SET deleted_at = NOW(), updated_at = NOW()
                  WHERE tenant_id = :t AND {$inSql}"
            )->execute($params);

            _jobdivaMappingRehomePlacementMapping($pdo, $tenantId, $norm, $keepId, $duplicateIds);

            if ((string) ($group['duplicate_basis'] ?? '') === 'jobdiva_start_legacy_import') {
                $personRepair = _jobdivaMappingRehomeLegacyBridgePeople(
                    $pdo,
                    $tenantId,
                    $keepId,
                    $duplicateIds,
                    $rows
                );
                $summary['person_mappings_rehomed'] += (int) ($personRepair['mappings_rehomed'] ?? 0);
                $summary['people_inactivated'] += (int) ($personRepair['people_inactivated'] ?? 0);
            }

            if ($canonical !== '') {
                $st = $pdo->prepare(
                    'UPDATE placements
                        SET external_id = :ext_set, updated_at = NOW()
                      WHERE tenant_id = :t AND id = :id AND external_id <> :ext_filter'
                );
                $st->execute(['ext_set' => $canonical, 'ext_filter' => $canonical, 't' => $tenantId, 'id' => $keepId]);
                if ($st->rowCount() > 0) $summary['external_ids_restored']++;
            }

            if ((string) ($group['duplicate_basis'] ?? '') === 'legacy_spreadsheet_identity'
                && function_exists('placementEconomicsReconcile')) {
                $economics = placementEconomicsReconcile($tenantId, $keepId);
                if (empty($economics['available']) && !empty($economics['errors'])) {
                    throw new \RuntimeException('Economics reconciliation failed: ' . implode('; ', (array) $economics['errors']));
                }
            }

            $pdo->commit();
            $summary['groups_repaired']++;
            $summary['placements_archived'] += count($duplicateIds);
            if ((string) ($group['duplicate_basis'] ?? '') === 'legacy_spreadsheet_identity') {
                $summary['legacy_groups_repaired']++;
                foreach (['source_fields_merged', 'rates_rehomed', 'draft_rates_removed', 'config_rows_rehomed'] as $key) {
                    $summary[$key] += (int) ($merge[$key] ?? 0);
                }
            }
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $summary['failed']++;
            if (count($summary['errors']) < 10) {
                $summary['errors'][] = "external_id {$norm}: " . $e->getMessage();
            }
        }
    }

    if (function_exists('jobdivaAudit')) {
        try {
            jobdivaAudit($tenantId, 'mapping_alignment_repair_duplicate_placements', [
                'ok' => $summary['failed'] === 0,
                'direction' => 'pull',
                'actor_user_id' => $userId,
                'items_processed' => $summary['placements_archived'],
                'items_skipped' => $summary['skipped'],
                'items_failed' => $summary['failed'],
                'detail' => $summary,
            ]);
        } catch (\Throwable $_) {}
    }

    return $summary;
}

function _jobdivaMappingConsolidateLegacyPlacementPair(
    \PDO $pdo,
    int $tenantId,
    array $group,
    int $keepId,
    array $duplicateIds
): array {
    $summary = [
        'source_fields_merged' => 0,
        'rates_rehomed' => 0,
        'draft_rates_removed' => 0,
        'config_rows_rehomed' => 0,
    ];
    $legacyId = (int) ($group['legacy_spreadsheet_id'] ?? 0);
    $sourceId = (int) ($group['jobdiva_placement_id'] ?? 0);
    if ($legacyId <= 0 || $sourceId <= 0 || !in_array($keepId, [$legacyId, $sourceId], true)) {
        throw new \RuntimeException('Invalid legacy placement consolidation identity');
    }

    $summary['source_fields_merged'] = _jobdivaMappingMergePlacementCoreFields(
        $pdo,
        $tenantId,
        $keepId,
        $legacyId,
        $sourceId
    );
    $rateSummary = _jobdivaMappingMergePlacementRates($pdo, $tenantId, $keepId, $duplicateIds);
    $summary['rates_rehomed'] = (int) ($rateSummary['rates_rehomed'] ?? 0);
    $summary['draft_rates_removed'] = (int) ($rateSummary['draft_rates_removed'] ?? 0);
    $summary['config_rows_rehomed'] = _jobdivaMappingMergePlacementConfiguration(
        $pdo,
        $tenantId,
        $keepId,
        $legacyId,
        $duplicateIds
    );
    return $summary;
}

function _jobdivaMappingMergePlacementCoreFields(
    \PDO $pdo,
    int $tenantId,
    int $keepId,
    int $legacyId,
    int $sourceId
): int {
    [$inSql, $params] = _jobdivaMappingInClause('id', [$keepId, $legacyId, $sourceId]);
    $params['t'] = $tenantId;
    $rows = _jobdivaMappingRows(
        $pdo,
        "SELECT * FROM placements WHERE tenant_id = :t AND {$inSql}",
        $params
    );
    $byId = [];
    foreach ($rows as $row) $byId[(int) ($row['id'] ?? 0)] = $row;
    if (empty($byId[$keepId]) || empty($byId[$legacyId]) || empty($byId[$sourceId])) {
        throw new \RuntimeException('Placement consolidation rows could not be loaded');
    }

    $keeper = $byId[$keepId];
    $legacy = $byId[$legacyId];
    $source = $byId[$sourceId];
    $updates = [];
    $overrides = json_decode((string) ($legacy['coreflux_overridden_fields'] ?? ''), true);
    $overrides = is_array($overrides) ? array_fill_keys(array_map('strval', $overrides), true) : [];

    if ($keepId === $legacyId) {
        $sourceFields = [
            'title', 'status', 'start_date', 'end_date', 'actual_end_date', 'due_date',
            'engagement_type', 'worksite_state', 'worksite_country', 'remote_policy',
            'branch', 'service_line', 'workers_comp_class', 'department', 'cost_center', 'accounting_entity_id',
            'end_client_name', 'end_client_company_id', 'client_id', 'staffing_job_id',
            'jobdiva_job_id', 'recruiter_name', 'recruiter_email',
            'account_manager_name', 'account_manager_email',
        ];
        foreach ($sourceFields as $column) {
            if (!array_key_exists($column, $keeper) || !array_key_exists($column, $source) || !empty($overrides[$column])) continue;
            $value = $source[$column];
            if ($value === null || (is_string($value) && trim($value) === '')) continue;
            if ($column === 'title') {
                $currentTitle = strtolower(trim((string) ($keeper[$column] ?? '')));
                $genericTitle = $currentTitle === ''
                    || in_array($currentTitle, ['consultant', 'contractor', 'employee', 'placement'], true)
                    || (bool) preg_match('/^jobdiva\s+placement\s+\d+$/i', $currentTitle);
                if (!$genericTitle) continue;
            }
            if ((string) ($keeper[$column] ?? '') !== (string) $value) $updates[$column] = $value;
        }
        foreach (['client_approver_name', 'client_approver_email'] as $column) {
            if (!array_key_exists($column, $keeper) || !array_key_exists($column, $source)) continue;
            if (trim((string) ($keeper[$column] ?? '')) !== '') continue;
            if (trim((string) ($source[$column] ?? '')) !== '') $updates[$column] = $source[$column];
        }
    } else {
        $commercialFields = [
            'billing_cycle_id', 'ap_cycle_id', 'payroll_cycle_id',
            'billing_operating_cycle_id', 'ap_operating_cycle_id', 'payroll_operating_cycle_id',
            'client_bill_cycle_anchor', 'vendor_pay_cycle_anchor',
            'client_payment_terms_override', 'vendor_payment_terms_override', 'vendor_pwp_enabled',
            'client_approver_name', 'client_approver_email',
            'tokenized_email_approval_enabled', 'bulk_uploads_can_be_pre_approved',
        ];
        foreach ($commercialFields as $column) {
            if (!array_key_exists($column, $keeper) || !array_key_exists($column, $legacy)) continue;
            $value = $legacy[$column];
            if ($value === null || (is_string($value) && trim($value) === '')) continue;
            if ((string) ($keeper[$column] ?? '') !== (string) $value) $updates[$column] = $value;
        }
        $legacyNotes = trim((string) ($legacy['notes'] ?? ''));
        $keeperNotes = trim((string) ($keeper['notes'] ?? ''));
        if ($legacyNotes !== '' && !str_contains($keeperNotes, $legacyNotes)) {
            $updates['notes'] = trim($keeperNotes . ($keeperNotes !== '' ? "\n" : '') . $legacyNotes);
        }
    }

    if (array_key_exists('coreflux_overridden_fields', $keeper)) {
        $keeperOverrides = json_decode((string) ($keeper['coreflux_overridden_fields'] ?? ''), true);
        $legacyOverrides = json_decode((string) ($legacy['coreflux_overridden_fields'] ?? ''), true);
        $mergedOverrides = array_values(array_unique(array_merge(
            is_array($keeperOverrides) ? array_map('strval', $keeperOverrides) : [],
            is_array($legacyOverrides) ? array_map('strval', $legacyOverrides) : []
        )));
        sort($mergedOverrides);
        if ($mergedOverrides) $updates['coreflux_overridden_fields'] = json_encode($mergedOverrides);
    }

    if (!$updates) return 0;
    $sets = [];
    $bind = ['t' => $tenantId, 'id' => $keepId];
    foreach ($updates as $column => $value) {
        $key = 'merge_' . preg_replace('/[^A-Za-z0-9_]/', '', $column);
        $sets[] = "`{$column}` = :{$key}";
        $bind[$key] = $value;
    }
    $sets[] = 'updated_at = NOW()';
    $pdo->prepare(
        'UPDATE placements SET ' . implode(', ', $sets) . ' WHERE tenant_id = :t AND id = :id'
    )->execute($bind);
    return count($updates);
}

function _jobdivaMappingMergePlacementRates(
    \PDO $pdo,
    int $tenantId,
    int $keepId,
    array $duplicateIds
): array {
    $summary = ['rates_rehomed' => 0, 'draft_rates_removed' => 0];
    if (!_jobdivaMappingTableExists($pdo, 'placement_rates')) return $summary;
    $placementIds = array_values(array_unique(array_merge([$keepId], array_map('intval', $duplicateIds))));
    [$inSql, $params] = _jobdivaMappingInClause('placement_id', $placementIds);
    $params['t'] = $tenantId;
    $rates = _jobdivaMappingRows(
        $pdo,
        "SELECT * FROM placement_rates WHERE tenant_id = :t AND {$inSql} ORDER BY id",
        $params
    );

    $keeperApproved = array_values(array_filter($rates, static fn(array $rate): bool =>
        (int) ($rate['placement_id'] ?? 0) === $keepId && !empty($rate['approved_at'])
    ));
    $duplicateApproved = array_values(array_filter($rates, static fn(array $rate): bool =>
        (int) ($rate['placement_id'] ?? 0) !== $keepId && !empty($rate['approved_at'])
    ));
    if (!$keeperApproved && $duplicateApproved) {
        // Approved snapshots embed economic-party IDs from their original
        // placement. The detector should select the row with the locked rate;
        // never silently move that immutable snapshot to another contract.
        throw new \RuntimeException('Preferred placement has no approved rate while the duplicate does');
    }
    if (!$keeperApproved) {
        foreach ($rates as $rate) {
            if ((int) ($rate['placement_id'] ?? 0) === $keepId) continue;
            $pdo->prepare(
                'UPDATE placement_rates SET placement_id = :keep WHERE tenant_id = :t AND id = :id'
            )->execute(['keep' => $keepId, 't' => $tenantId, 'id' => (int) $rate['id']]);
            $summary['rates_rehomed']++;
        }
    }

    $allRates = _jobdivaMappingRows(
        $pdo,
        'SELECT * FROM placement_rates WHERE tenant_id = :t AND placement_id = :p ORDER BY id',
        ['t' => $tenantId, 'p' => $keepId]
    );
    $approved = array_values(array_filter($allRates, static fn(array $rate): bool => !empty($rate['approved_at'])));
    foreach ($allRates as $draft) {
        if (!empty($draft['approved_at']) || !array_key_exists('created_by_user_id', $draft) || !empty($draft['created_by_user_id'])) continue;
        $redundant = false;
        foreach ($approved as $locked) {
            if (_jobdivaMappingRatesEquivalent($draft, $locked)
                && _jobdivaMappingDateRangesOverlap(
                    (string) ($draft['effective_from'] ?? ''),
                    (string) ($draft['effective_to'] ?? ''),
                    (string) ($locked['effective_from'] ?? ''),
                    (string) ($locked['effective_to'] ?? '')
                )) {
                $redundant = true;
                break;
            }
        }
        if (!$redundant || _jobdivaMappingRateHasReferences($pdo, $tenantId, (int) $draft['id'])) continue;
        $pdo->prepare('DELETE FROM placement_rates WHERE tenant_id = :t AND id = :id AND approved_at IS NULL')
            ->execute(['t' => $tenantId, 'id' => (int) $draft['id']]);
        $summary['draft_rates_removed']++;
    }
    return $summary;
}

function _jobdivaMappingRatesEquivalent(array $left, array $right): bool
{
    foreach (['bill_rate', 'pay_rate', 'ot_multiplier', 'dt_multiplier'] as $column) {
        if (abs((float) ($left[$column] ?? 0) - (float) ($right[$column] ?? 0)) >= 0.0001) return false;
    }
    foreach (['bill_rate_unit', 'pay_rate_unit', 'currency'] as $column) {
        if (strtolower(trim((string) ($left[$column] ?? ''))) !== strtolower(trim((string) ($right[$column] ?? '')))) return false;
    }
    return true;
}

function _jobdivaMappingDateRangesOverlap(string $startA, string $endA, string $startB, string $endB): bool
{
    if ($startA === '' || $startB === '') return false;
    $endA = $endA !== '' ? $endA : '9999-12-31';
    $endB = $endB !== '' ? $endB : '9999-12-31';
    return $startA <= $endB && $startB <= $endA;
}

function _jobdivaMappingRateHasReferences(\PDO $pdo, int $tenantId, int $rateId): bool
{
    $sources = [
        ['table' => 'time_entries'],
        ['table' => 'time_daily_finance'],
        ['table' => 'time_downstream_feed'],
        [
            'table' => 'billing_invoice_lines',
            'parent_table' => 'billing_invoices',
            'parent_key' => 'invoice_id',
        ],
        [
            'table' => 'ap_bill_lines',
            'parent_table' => 'ap_bills',
            'parent_key' => 'bill_id',
        ],
    ];
    foreach ($sources as $source) {
        $table = (string) $source['table'];
        if (!_jobdivaMappingTableExists($pdo, $table)
            || !_jobdivaMappingColumnExists($pdo, $table, 'rate_snapshot_id')) {
            continue;
        }
        $parentTable = (string) ($source['parent_table'] ?? '');
        if ($parentTable !== '') {
            if (!_jobdivaMappingTableExists($pdo, $parentTable)) continue;
            $parentKey = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($source['parent_key'] ?? ''));
            if ($parentKey === '') continue;
            $sql = "SELECT COUNT(*)
                      FROM {$table} child
                      JOIN {$parentTable} parent ON parent.id = child.{$parentKey}
                     WHERE parent.tenant_id = :t AND child.rate_snapshot_id = :r";
        } else {
            if (!_jobdivaMappingColumnExists($pdo, $table, 'tenant_id')) continue;
            $sql = "SELECT COUNT(*) FROM {$table} WHERE tenant_id = :t AND rate_snapshot_id = :r";
        }
        if (_jobdivaMappingScalar($pdo, $sql, ['t' => $tenantId, 'r' => $rateId]) > 0) return true;
    }
    return false;
}

function _jobdivaMappingMergePlacementConfiguration(
    \PDO $pdo,
    int $tenantId,
    int $keepId,
    int $legacyId,
    array $duplicateIds
): int {
    $moved = 0;
    foreach (['placement_commissions', 'placement_referrals', 'placement_documents'] as $table) {
        if (!_jobdivaMappingTableExists($pdo, $table) || !_jobdivaMappingColumnExists($pdo, $table, 'placement_id')) continue;
        [$inSql, $params] = _jobdivaMappingInClause('placement_id', $duplicateIds);
        $params['t'] = $tenantId;
        $params['keep'] = $keepId;
        $st = $pdo->prepare("UPDATE {$table} SET placement_id = :keep WHERE tenant_id = :t AND {$inSql}");
        $st->execute($params);
        $moved += $st->rowCount();
    }

    if (_jobdivaMappingTableExists($pdo, 'placement_client_chain')) {
        [$inSql, $params] = _jobdivaMappingInClause('placement_id', $duplicateIds);
        $params['t'] = $tenantId;
        $chain = _jobdivaMappingRows(
            $pdo,
            "SELECT id, position FROM placement_client_chain WHERE tenant_id = :t AND {$inSql} ORDER BY id",
            $params
        );
        foreach ($chain as $row) {
            $exists = _jobdivaMappingScalar(
                $pdo,
                'SELECT COUNT(*) FROM placement_client_chain WHERE tenant_id = :t AND placement_id = :p AND position = :pos',
                ['t' => $tenantId, 'p' => $keepId, 'pos' => (int) $row['position']]
            );
            if ($exists > 0) continue;
            $st = $pdo->prepare('UPDATE placement_client_chain SET placement_id = :p WHERE tenant_id = :t AND id = :id');
            $st->execute(['p' => $keepId, 't' => $tenantId, 'id' => (int) $row['id']]);
            $moved += $st->rowCount();
        }
    }

    if (_jobdivaMappingTableExists($pdo, 'placement_corp_details')) {
        $keeperCorp = _jobdivaMappingScalar(
            $pdo,
            'SELECT COUNT(*) FROM placement_corp_details WHERE tenant_id = :t AND placement_id = :p',
            ['t' => $tenantId, 'p' => $keepId]
        );
        if ($keeperCorp === 0) {
            [$inSql, $params] = _jobdivaMappingInClause('placement_id', $duplicateIds);
            $params['t'] = $tenantId;
            $params['keep'] = $keepId;
            $st = $pdo->prepare("UPDATE placement_corp_details SET placement_id = :keep WHERE tenant_id = :t AND {$inSql}");
            $st->execute($params);
            $moved += $st->rowCount();
        }
    }

    if (_jobdivaMappingTableExists($pdo, 'placement_economic_parties')) {
        [$inSql, $params] = _jobdivaMappingInClause('placement_id', $duplicateIds);
        $params['t'] = $tenantId;
        $manual = _jobdivaMappingRows(
            $pdo,
            "SELECT id, source_ref FROM placement_economic_parties
              WHERE tenant_id = :t AND source_type = 'manual' AND {$inSql}",
            $params
        );
        foreach ($manual as $row) {
            $exists = _jobdivaMappingScalar(
                $pdo,
                'SELECT COUNT(*) FROM placement_economic_parties WHERE tenant_id = :t AND placement_id = :p AND source_ref = :ref',
                ['t' => $tenantId, 'p' => $keepId, 'ref' => (string) $row['source_ref']]
            );
            if ($exists > 0) continue;
            $st = $pdo->prepare('UPDATE placement_economic_parties SET placement_id = :p WHERE tenant_id = :t AND id = :id');
            $st->execute(['p' => $keepId, 't' => $tenantId, 'id' => (int) $row['id']]);
            $moved += $st->rowCount();
        }
    }
    return $moved;
}

function _jobdivaMappingRehomePlacementMapping(\PDO $pdo, int $tenantId, string $externalId, int $keepId, array $duplicateIds): void
{
    if ($externalId === '' || $keepId <= 0) return;
    $externalIds = array_values(array_unique([$externalId, 'jd:' . $externalId]));
    [$dupSql, $dupParams] = _jobdivaMappingInClause('internal_entity_id', $duplicateIds ?: [-1]);
    [$extSql, $extParams] = _jobdivaMappingStringInClause('external_id', $externalIds);
    $params = array_merge($dupParams, $extParams, ['t' => $tenantId]);

    $sourceStmt = $pdo->prepare(
        "SELECT payload_snapshot, content_hash, direction
           FROM external_entity_mappings
          WHERE tenant_id = :t
            AND source_system = 'jobdiva'
            AND internal_entity_type = 'placement'
            AND ({$extSql} OR {$dupSql} OR internal_entity_id = :keep_filter_id)
       ORDER BY CASE WHEN internal_entity_id = :keep_order_id THEN 0 ELSE 1 END,
                CASE WHEN external_id = :external_order_id THEN 0 ELSE 1 END,
                updated_at DESC,
                id DESC
          LIMIT 1"
    );
    $sourceStmt->execute($params + [
        'keep_filter_id' => $keepId,
        'keep_order_id' => $keepId,
        'external_order_id' => $externalId,
    ]);
    $source = $sourceStmt->fetch(\PDO::FETCH_ASSOC) ?: [];

    $deleteParams = array_merge($dupParams, $extParams, ['t' => $tenantId, 'keep_id' => $keepId]);
    $pdo->prepare(
        "DELETE FROM external_entity_mappings
          WHERE tenant_id = :t
            AND source_system = 'jobdiva'
            AND internal_entity_type = 'placement'
            AND internal_entity_id <> :keep_id
            AND ({$extSql} OR {$dupSql})"
    )->execute($deleteParams);

    $existing = $pdo->prepare(
        "SELECT id
           FROM external_entity_mappings
          WHERE tenant_id = :t
            AND source_system = 'jobdiva'
            AND internal_entity_type = 'placement'
            AND internal_entity_id = :keep_id
          LIMIT 1"
    );
    $existing->execute(['t' => $tenantId, 'keep_id' => $keepId]);
    $existingId = (int) ($existing->fetchColumn() ?: 0);

    $payload = is_string($source['payload_snapshot'] ?? null) ? (string) $source['payload_snapshot'] : null;
    $hash = is_string($source['content_hash'] ?? null) ? (string) $source['content_hash'] : null;
    $direction = in_array((string) ($source['direction'] ?? 'pull'), ['pull', 'push', 'two_way', 'off'], true)
        ? (string) $source['direction']
        : 'pull';

    if ($existingId > 0) {
        $pdo->prepare(
            "UPDATE external_entity_mappings
                SET external_id = :external_id,
                    payload_snapshot = COALESCE(:payload_snapshot, payload_snapshot),
                    content_hash = COALESCE(:content_hash, content_hash),
                    direction = :direction,
                    sync_status = 'ok',
                    last_error = NULL,
                    last_seen_at = NOW(),
                    updated_at = NOW()
              WHERE tenant_id = :t AND id = :id"
        )->execute([
            'external_id' => $externalId,
            'payload_snapshot' => $payload,
            'content_hash' => $hash,
            'direction' => $direction,
            't' => $tenantId,
            'id' => $existingId,
        ]);
        return;
    }

    $pdo->prepare(
        "INSERT INTO external_entity_mappings
            (tenant_id, source_system, internal_entity_type, external_id,
             internal_entity_id, payload_snapshot, content_hash, direction,
             sync_status, last_seen_at, last_synced_at)
         VALUES
            (:t, 'jobdiva', 'placement', :external_id,
             :keep_id, :payload_snapshot, :content_hash, :direction,
             'ok', NOW(), NOW())"
    )->execute([
        't' => $tenantId,
        'external_id' => $externalId,
        'keep_id' => $keepId,
        'payload_snapshot' => $payload,
        'content_hash' => $hash,
        'direction' => $direction,
    ]);
}

/**
 * When the spreadsheet import and JobDiva projection also created parallel
 * people, move the source mapping to the retained person. The identity check
 * is intentionally strict and the duplicate person is only inactivated after
 * its last live placement has been archived.
 *
 * @return array{mappings_rehomed:int,people_inactivated:int}
 */
function _jobdivaMappingRehomeLegacyBridgePeople(
    \PDO $pdo,
    int $tenantId,
    int $keepPlacementId,
    array $duplicatePlacementIds,
    array $rows
): array {
    $summary = ['mappings_rehomed' => 0, 'people_inactivated' => 0];
    if (!_jobdivaMappingTableExists($pdo, 'people')) return $summary;

    $byPlacementId = [];
    foreach ($rows as $row) {
        $rowId = (int) ($row['id'] ?? 0);
        if ($rowId > 0) $byPlacementId[$rowId] = $row;
    }
    $keep = $byPlacementId[$keepPlacementId] ?? [];
    $keepPersonId = (int) ($keep['person_id'] ?? 0);
    if ($keepPersonId <= 0) return $summary;

    $normaliseName = static fn(array $row): string => preg_replace(
        '/[^a-z0-9]+/',
        '',
        strtolower(trim(
            (string) ($row['person_first_name'] ?? '') . ' ' . (string) ($row['person_last_name'] ?? '')
        ))
    ) ?: '';
    $usableEmail = static function (array $row): string {
        $email = strtolower(trim((string) ($row['person_email'] ?? '')));
        return $email !== '' && !str_ends_with($email, '@no-email.invalid') ? $email : '';
    };
    $keepName = $normaliseName($keep);
    $keepEmail = $usableEmail($keep);

    $duplicatePersonIds = [];
    foreach ($duplicatePlacementIds as $placementId) {
        $duplicate = $byPlacementId[(int) $placementId] ?? [];
        $duplicatePersonId = (int) ($duplicate['person_id'] ?? 0);
        if ($duplicatePersonId <= 0 || $duplicatePersonId === $keepPersonId) continue;
        $duplicateName = $normaliseName($duplicate);
        $duplicateEmail = $usableEmail($duplicate);
        if ($keepName === '' || $duplicateName === '' || $keepName !== $duplicateName) {
            throw new \RuntimeException('legacy placement bridge refused a conflicting person identity');
        }
        if ($keepEmail !== '' && $duplicateEmail !== '' && $keepEmail !== $duplicateEmail) {
            throw new \RuntimeException('legacy placement bridge refused conflicting person emails');
        }
        $duplicatePersonIds[$duplicatePersonId] = true;
    }

    foreach (array_keys($duplicatePersonIds) as $duplicatePersonId) {
        if (_jobdivaMappingTableExists($pdo, 'external_entity_mappings')) {
            $identityStmt = $pdo->prepare(
                "SELECT internal_entity_id, external_id
                   FROM external_entity_mappings
                  WHERE tenant_id = :t
                    AND source_system = 'jobdiva'
                    AND internal_entity_type = 'person'
                    AND internal_entity_id IN (:keep_person_id, :duplicate_person_id)
               ORDER BY id ASC"
            );
            $identityStmt->execute([
                't' => $tenantId,
                'keep_person_id' => $keepPersonId,
                'duplicate_person_id' => $duplicatePersonId,
            ]);
            $identityRows = $identityStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            $keepMappings = array_values(array_filter(
                $identityRows,
                static fn(array $row): bool => (int) ($row['internal_entity_id'] ?? 0) === $keepPersonId
            ));
            $duplicateMappings = array_values(array_filter(
                $identityRows,
                static fn(array $row): bool => (int) ($row['internal_entity_id'] ?? 0) === $duplicatePersonId
            ));
            if ($keepMappings && $duplicateMappings) {
                throw new \RuntimeException(
                    'legacy placement bridge refused to combine distinct JobDiva person mappings'
                );
            }

            $stmt = $pdo->prepare(
                "UPDATE external_entity_mappings
                    SET internal_entity_id = :keep_person_id,
                        sync_status = 'ok',
                        last_error = NULL,
                        updated_at = NOW()
                  WHERE tenant_id = :t
                    AND source_system = 'jobdiva'
                    AND internal_entity_type = 'person'
                    AND internal_entity_id = :duplicate_person_id"
            );
            $stmt->execute([
                'keep_person_id' => $keepPersonId,
                't' => $tenantId,
                'duplicate_person_id' => $duplicatePersonId,
            ]);
            $summary['mappings_rehomed'] += $stmt->rowCount();
        }

        $liveStmt = $pdo->prepare(
            "SELECT COUNT(*)
               FROM placements
              WHERE tenant_id = :t
                AND person_id = :person_id
                AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
                AND status IN ('draft', 'pending_start', 'active', 'on_hold')"
        );
        $liveStmt->execute(['t' => $tenantId, 'person_id' => $duplicatePersonId]);
        if ((int) $liveStmt->fetchColumn() === 0) {
            $stmt = $pdo->prepare(
                "UPDATE people
                    SET status = 'inactive', updated_at = NOW()
                  WHERE tenant_id = :t
                    AND id = :person_id
                    AND deleted_at IS NULL
                    AND status IN ('active', 'bench')"
            );
            $stmt->execute(['t' => $tenantId, 'person_id' => $duplicatePersonId]);
            $summary['people_inactivated'] += $stmt->rowCount();
        }
    }
    return $summary;
}

function _jobdivaMappingInvalidPlacementSources(\PDO $pdo, int $tenantId, int $limit = 5000): array
{
    $limit = max(1, min(5000, $limit));
    $rows = _jobdivaMappingRows(
        $pdo,
        "SELECT m.internal_entity_id AS placement_id, m.external_id, m.payload_snapshot,
                p.title
           FROM external_entity_mappings m
           JOIN placements p
             ON p.tenant_id = m.tenant_id
            AND p.id = m.internal_entity_id
          WHERE m.tenant_id = :t
            AND m.source_system = 'jobdiva'
            AND m.internal_entity_type = 'placement'
            AND m.sync_status = 'ok'
            AND m.payload_snapshot IS NOT NULL
            AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
          ORDER BY m.id ASC
          LIMIT {$limit}",
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

function _jobdivaMappingDuplicatePlacementGroups(\PDO $pdo, int $tenantId, int $limit = 100): array
{
    if (!_jobdivaMappingTableExists($pdo, 'placements')) {
        return [];
    }
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
                 ON m.tenant_id = p.tenant_id
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
        ? 'LEFT JOIN people pe ON pe.tenant_id = p.tenant_id AND pe.id = p.person_id'
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
          WHERE p.tenant_id = :t
            AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
            AND (" . implode(' OR ', $sourceWhere) . ")
       GROUP BY p.id, p.external_id, p.title, p.person_id, p.start_date, p.end_date,
                p.end_client_name, p.end_client_company_id, {$jobIdGroup}, p.status,
                p.created_at, p.updated_at{$personGroup}
       ORDER BY p.id ASC",
        ['t' => $tenantId]
    );
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
        $alreadyGrouped
    ));
    usort($out, static fn($a, $b) => ((int) ($b['count'] ?? 0) <=> (int) ($a['count'] ?? 0))
        ?: strcmp((string) ($a['external_id'] ?? ''), (string) ($b['external_id'] ?? '')));
    return array_slice($out, 0, $limit);
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
    array $excludedIds = []
): array {
    $approvedRateSelect = _jobdivaMappingTableExists($pdo, 'placement_rates')
        ? '(SELECT COUNT(*) FROM placement_rates pr
              WHERE pr.tenant_id = p.tenant_id
                AND pr.placement_id = p.id
                AND pr.approved_at IS NOT NULL)'
        : '0';
    $rows = _jobdivaMappingRows($pdo,
        "SELECT p.*, {$approvedRateSelect} AS approved_rate_count
           FROM placements p
          WHERE p.tenant_id = :t
            AND p.status IN ('pending_start', 'active', 'on_hold')
            AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
       ORDER BY p.person_id, p.id
          LIMIT 5000",
        ['t' => $tenantId]
    );

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

function _jobdivaMappingStaleActivePlacementRows(\PDO $pdo, int $tenantId, int $limit = 500): array
{
    if (!_jobdivaMappingTableExists($pdo, 'placements')) return [];
    $limit = max(1, min(5000, $limit));
    $scanLimit = 5000;
    $hasMappings = _jobdivaMappingTableExists($pdo, 'external_entity_mappings');
    $mappingJoin = $hasMappings
        ? "LEFT JOIN external_entity_mappings m
                 ON m.id = (
                    SELECT MAX(m2.id)
                      FROM external_entity_mappings m2
                     WHERE m2.tenant_id = p.tenant_id
                       AND m2.internal_entity_type = 'placement'
                       AND m2.internal_entity_id = p.id
                       AND m2.source_system = 'jobdiva'
                 )"
        : '';
    $sourceWhere = $hasMappings
        ? "(p.external_id LIKE 'jd:%' OR p.title LIKE 'JobDiva Placement %' OR m.id IS NOT NULL)"
        : "(p.external_id LIKE 'jd:%' OR p.title LIKE 'JobDiva Placement %')";
    $mappingFields = $hasMappings
        ? 'm.external_id AS mapping_external_id, m.payload_snapshot'
        : 'NULL AS mapping_external_id, NULL AS payload_snapshot';
    $rows = _jobdivaMappingRows($pdo,
        "SELECT p.id, p.external_id, p.title, p.person_id, p.start_date, p.end_date, p.status, p.updated_at,
                {$mappingFields}
           FROM placements p
           {$mappingJoin}
          WHERE p.tenant_id = :t
            AND p.status = 'active'
            AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
            AND {$sourceWhere}
       ORDER BY p.id ASC
          LIMIT {$scanLimit}",
        ['t' => $tenantId]
    );

    $out = [];
    foreach ($rows as $row) {
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
        if (count($out) >= $limit) break;
    }
    return $out;
}

function _jobdivaMappingStaleSourcePeopleRows(\PDO $pdo, int $tenantId, int $limit = 500): array
{
    if (!_jobdivaMappingTableExists($pdo, 'people') || !_jobdivaMappingTableExists($pdo, 'placements')) {
        return [];
    }
    $limit = max(1, min(5000, $limit));
    $hasMappings = _jobdivaMappingTableExists($pdo, 'external_entity_mappings');
    $sourceWhere = "(p.source = 'jobdiva' OR p.external_id LIKE 'jd:%')";
    $mappingSelect = $hasMappings
        ? "(SELECT pm.external_id
              FROM external_entity_mappings pm
             WHERE pm.tenant_id = p.tenant_id
               AND pm.source_system = 'jobdiva'
               AND pm.internal_entity_type = 'person'
               AND pm.internal_entity_id = p.id
          ORDER BY pm.id DESC
             LIMIT 1)"
        : 'NULL';

    return _jobdivaMappingRows(
        $pdo,
        "SELECT p.id, p.external_id, {$mappingSelect} AS candidate_external_id,
                p.first_name, p.last_name, p.email_primary, p.classification,
                p.status, p.updated_at,
                (SELECT COUNT(*)
                   FROM placements historical
                  WHERE historical.tenant_id = p.tenant_id
                    AND historical.person_id = p.id) AS historical_placement_count,
                (SELECT MAX(historical.end_date)
                   FROM placements historical
                  WHERE historical.tenant_id = p.tenant_id
                    AND historical.person_id = p.id
                    AND (historical.deleted_at IS NULL OR historical.deleted_at = '0000-00-00 00:00:00')) AS last_placement_end_date
           FROM people p
          WHERE p.tenant_id = :t
            AND p.deleted_at IS NULL
            AND p.status IN ('active', 'bench')
            AND {$sourceWhere}
            AND EXISTS (
                SELECT 1
                  FROM placements historical_source
                 WHERE historical_source.tenant_id = p.tenant_id
                   AND historical_source.person_id = p.id
            )
            AND NOT EXISTS (
                SELECT 1
                  FROM placements live
                 WHERE live.tenant_id = p.tenant_id
                   AND live.person_id = p.id
                   AND (live.deleted_at IS NULL OR live.deleted_at = '0000-00-00 00:00:00')
                   AND live.status IN ('draft', 'pending_start', 'active', 'on_hold')
                   AND (live.end_date IS NULL OR live.end_date = '' OR live.end_date >= :today)
            )
       ORDER BY p.updated_at ASC, p.id ASC
          LIMIT {$limit}",
        ['t' => $tenantId, 'today' => date('Y-m-d')]
    );
}

function _jobdivaMappingChooseDuplicatePlacementKeeper(array $group): int
{
    $rows = is_array($group['rows'] ?? null) ? $group['rows'] : [];
    $canonical = (string) ($group['canonical_external_id'] ?? '');
    $preferLegacyImport = (string) ($group['duplicate_basis'] ?? '') === 'jobdiva_start_legacy_import';
    $bestId = 0;
    $bestScore = -1;
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) continue;
        $title = trim((string) ($row['title'] ?? ''));
        $score = 0;
        if ($preferLegacyImport && preg_match('/^placements-xlsx-/i', (string) ($row['external_id'] ?? ''))) {
            // Preserve the row that already owns reviewed rates, C2C details,
            // documents, and spreadsheet lineage. Its external identity and
            // source mapping are rebound to JobDiva in the same transaction.
            $score += 200;
        }
        if ($canonical !== '' && (string) ($row['external_id'] ?? '') === $canonical) $score += 30;
        if (!empty($row['is_current_mapping'])) $score += 25;
        if ($title !== '' && !preg_match('/^JobDiva\s+Placement\s+\d+$/i', $title)) $score += 40;
        if (trim((string) ($row['external_id'] ?? '')) !== '') $score += 20;
        if (trim((string) ($row['jobdiva_job_id'] ?? '')) !== '') $score += 10;
        if (trim((string) ($row['end_client_name'] ?? '')) !== '') $score += 5;
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestId = $id;
        }
    }
    if ($bestId > 0) return $bestId;
    foreach ($rows as $row) {
        if ((int) ($row['id'] ?? 0) > 0) return (int) $row['id'];
    }
    return 0;
}

function _jobdivaMappingDuplicatePlacementChildCounts(\PDO $pdo, int $tenantId, array $placementIds): array
{
    $ids = array_values(array_filter(array_map('intval', $placementIds), static fn($id) => $id > 0));
    $counts = [];
    foreach ($ids as $id) $counts[$id] = 0;
    if (!$ids) return $counts;

    $activity = _jobdivaMappingPlacementChildActivity($pdo, $tenantId, $ids);
    foreach (($activity['per_placement'] ?? []) as $id => $count) {
        if (array_key_exists((int) $id, $counts)) $counts[(int) $id] += (int) $count;
    }
    return $counts;
}

function _jobdivaMappingDuplicatePlacementBlockingChildren(\PDO $pdo, int $tenantId, array $placementIds): array
{
    $ids = array_values(array_filter(array_map('intval', $placementIds), static fn($id) => $id > 0));
    if (!$ids) return [];
    $activity = _jobdivaMappingPlacementChildActivity($pdo, $tenantId, $ids);
    return (array) ($activity['by_table'] ?? []);
}

/**
 * Count every placement-owned workflow artifact. Invoice and bill lines are
 * tenant-scoped through their parent document; those line tables do not carry
 * tenant_id themselves.
 *
 * @return array{per_placement:array<int,int>,by_table:array<string,int>}
 */
function _jobdivaMappingPlacementChildActivity(\PDO $pdo, int $tenantId, array $placementIds): array
{
    $ids = array_values(array_filter(array_map('intval', $placementIds), static fn($id) => $id > 0));
    $perPlacement = [];
    foreach ($ids as $id) $perPlacement[$id] = 0;
    if (!$ids) return ['per_placement' => $perPlacement, 'by_table' => []];

    $sources = [
        ['table' => 'time_entries'],
        ['table' => 'time_daily_finance'],
        ['table' => 'time_approval_tokens'],
        ['table' => 'time_downstream_feed'],
        ['table' => 'ap_bills'],
        ['table' => 'placement_documents'],
        ['table' => 'people_pipeline_stages'],
        ['table' => 'placement_economic_items'],
        ['table' => 'placement_economic_obligations'],
        [
            'table' => 'billing_invoice_lines',
            'parent_table' => 'billing_invoices',
            'parent_key' => 'invoice_id',
        ],
        [
            'table' => 'ap_bill_lines',
            'parent_table' => 'ap_bills',
            'parent_key' => 'bill_id',
        ],
    ];
    $byTable = [];
    foreach ($sources as $source) {
        $table = (string) $source['table'];
        if (!_jobdivaMappingTableExists($pdo, $table)
            || !_jobdivaMappingColumnExists($pdo, $table, 'placement_id')) {
            continue;
        }
        [$inSql, $params] = _jobdivaMappingInClause('placement_id', $ids);
        $params['t'] = $tenantId;
        $parentTable = (string) ($source['parent_table'] ?? '');
        if ($parentTable !== '') {
            if (!_jobdivaMappingTableExists($pdo, $parentTable)) continue;
            $parentKey = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($source['parent_key'] ?? ''));
            if ($parentKey === '') continue;
            $sql = "SELECT child.placement_id, COUNT(*) AS c
                      FROM {$table} child
                      JOIN {$parentTable} parent ON parent.id = child.{$parentKey}
                     WHERE parent.tenant_id = :t AND child.{$inSql}
                  GROUP BY child.placement_id";
        } else {
            if (!_jobdivaMappingColumnExists($pdo, $table, 'tenant_id')) continue;
            $sql = "SELECT child.placement_id, COUNT(*) AS c
                      FROM {$table} child
                     WHERE child.tenant_id = :t AND child.{$inSql}
                  GROUP BY child.placement_id";
        }
        $rows = _jobdivaMappingRows($pdo, $sql, $params);
        foreach ($rows as $row) {
            $placementId = (int) ($row['placement_id'] ?? 0);
            $count = (int) ($row['c'] ?? 0);
            if ($placementId <= 0 || $count <= 0 || !array_key_exists($placementId, $perPlacement)) continue;
            $perPlacement[$placementId] += $count;
            $byTable[$table] = (int) ($byTable[$table] ?? 0) + $count;
        }
    }
    return ['per_placement' => $perPlacement, 'by_table' => $byTable];
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
        return 0;
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
        return [];
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
    } catch (\Throwable $_) {
        try {
            $pdo->query('SELECT 1 FROM ' . preg_replace('/[^A-Za-z0-9_]/', '', $table) . ' LIMIT 1');
            return true;
        } catch (\Throwable $_) {
            return false;
        }
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
    } catch (\Throwable $_) {
        return false;
    }
}
