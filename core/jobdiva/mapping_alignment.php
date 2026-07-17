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
        if ($duplicatePlacementGroups) {
            $samples['duplicate_placements'] = array_slice($duplicatePlacementGroups, 0, min(10, $limit));
        }
        _jobdivaMappingAddIssue(
            $issues,
            'critical',
            'duplicate_jobdiva_placement_rows',
            'placement',
            count($duplicatePlacementGroups),
            'Some JobDiva placement identities resolve to more than one active CoreFlux placement row.',
            'Preview duplicates, then archive duplicate rows with no downstream billing/time/AP activity.'
        );

        $staleActiveRows = _jobdivaMappingStaleActivePlacementRows($pdo, $tenantId, 5000);
        $relationships['placement_graph']['active_past_end_date'] = count($staleActiveRows);
        if ($staleActiveRows) {
            $samples['active_past_end_date'] = array_slice($staleActiveRows, 0, min(10, $limit));
        }
        _jobdivaMappingAddIssue(
            $issues,
            'critical',
            'placement_active_past_end_date',
            'placement',
            count($staleActiveRows),
            'Active JobDiva placements have an end date in the past.',
            'Preview stale active placements, then mark them ended so active placement, billing, payroll, and reporting views stop treating them as live.'
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
    $limit = max(1, min(1000, $limit));
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
                        $payload = jobdivaPlacementPayloadWithMirrors($tenantId, $payload, $mirrorStats);
                    }
                } catch (\Throwable $e) {
                    error_log('[jobdiva mapping repair] payload mirror enrichment failed: ' . $e->getMessage());
                }
            }
        }

        $payloadClientName = $payload && function_exists('jobdivaEndClientNameFromPayload')
            ? jobdivaEndClientNameFromPayload($payload)
            : '';

        if ($payloadClientName !== '' && function_exists('jobdivaProjectorResolveEndClientCompany')) {
            try {
                $resolvedCompanyId = jobdivaProjectorResolveEndClientCompany($tenantId, $payload, $userId);
                if ($resolvedCompanyId !== null && $resolvedCompanyId > 0) {
                    $companyId = (int) $resolvedCompanyId;
                }
            } catch (\Throwable $e) {
                error_log('[jobdiva mapping repair] end-client resolve failed: ' . $e->getMessage());
            }
        }

        $name = trim($payloadClientName);
        if ($name === '') $name = trim((string) ($row['end_client_name'] ?? ''));
        if ($name === '') $name = trim((string) ($row['company_name'] ?? ''));
        if ($name === '') $name = trim((string) ($row['existing_client_name'] ?? ''));
        if ($name === '') $name = trim($payloadClientName);
        if ($name === '' && $companyId !== null && $companyId > 0) {
            try {
                $nameStmt = $pdo->prepare(
                    'SELECT name FROM companies
                      WHERE tenant_id = :t AND id = :id AND deleted_at IS NULL
                      LIMIT 1'
                );
                $nameStmt->execute(['t' => $tenantId, 'id' => $companyId]);
                $name = trim((string) ($nameStmt->fetchColumn() ?: ''));
            } catch (\Throwable $e) {
                error_log('[jobdiva mapping repair] company-name lookup failed: ' . $e->getMessage());
            }
        }
        if ($name === '') {
            $summary['skipped']++;
            continue;
        }
        try {
            $clientRef = staffingClientEnsureForCompany($tenantId, $companyId, $name, [
                'created_by_user_id' => $userId,
            ]);
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
        'errors' => [],
    ];
    $limit = max(1, min(5000, $limit));

    if (!function_exists('jobdivaReprojectMirroredPlacementGraphs')) {
        $summary['failed']++;
        $summary['errors'][] = 'Canonical projection function is not loaded';
        return $summary;
    }

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

    $projection = jobdivaReprojectMirroredPlacementGraphs($tenantId, $userId, $limit);
    $summary['checked'] = (int) ($projection['placements_seen'] ?? 0);
    $summary['projected'] = (int) ($projection['placements_projected'] ?? 0);
    $summary['mapping_writes'] = (int) ($projection['mapping_writes'] ?? 0);
    $summary['field_map_writes'] = (int) ($projection['field_map_writes'] ?? 0);
    foreach (['jobs_joined', 'candidates_joined', 'contacts_joined', 'assignments_joined'] as $k) {
        $summary[$k] = (int) ($projection[$k] ?? 0);
    }

    foreach ((array) ($projection['errors'] ?? []) as $err) {
        if (is_array($err)) {
            $prefix = !empty($err['placement_id']) ? 'placement ' . (int) $err['placement_id'] . ': ' : '';
            $summary['errors'][] = $prefix . (string) ($err['error'] ?? json_encode($err));
        } else {
            $summary['errors'][] = (string) $err;
        }
        if (count($summary['errors']) >= 10) break;
    }
    $summary['failed'] = count((array) ($projection['errors'] ?? []));
    $summary['skipped'] = max(0, $summary['checked'] - $summary['projected'] - $summary['failed']);

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

    // Order matters: replay source evidence into canonical graph owners first,
    // then clean duplicate/orphan placement shells and downstream blockers.
    $steps['canonical_projection'] = jobdivaMappingRepairCanonicalProjection($tenantId, $userId, $limit);
    $steps['duplicate_placements'] = jobdivaMappingRepairDuplicatePlacements($tenantId, $userId, min(500, $limit), false);
    $steps['client_links'] = jobdivaMappingRepairStaffingClientLinks($tenantId, $userId, $limit);
    $steps['stale_active_placements'] = jobdivaMappingRepairStaleActivePlacements($tenantId, $userId, $limit, false);
    $steps['source_rate_drafts'] = jobdivaMappingRepairSourceRateDrafts($tenantId, $user, $limit);

    $failed = 0;
    $changed = 0;
    foreach ($steps as $step) {
        $failed += (int) ($step['failed'] ?? 0);
        $changed += (int) ($step['placements_archived'] ?? 0);
        $changed += (int) ($step['external_ids_restored'] ?? 0);
        $changed += (int) ($step['repaired'] ?? 0);
        $changed += (int) ($step['ended'] ?? 0);
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
    $summary = ['dry_run' => $dryRun, 'checked' => 0, 'ended' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
    $limit = max(1, min(1000, $limit));
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
        if ($dryRun) {
            $summary['ended']++;
            continue;
        }
        try {
            $stmt = $pdo->prepare(
                "UPDATE placements
                    SET status = 'ended', updated_at = NOW()
                  WHERE tenant_id = :t
                    AND id = :id
                    AND status = 'active'
                    AND end_date IS NOT NULL
                    AND end_date <> ''
                    AND end_date < :today"
            );
            $stmt->execute(['t' => $tenantId, 'id' => $placementId, 'today' => date('Y-m-d')]);
            if ($stmt->rowCount() > 0) {
                $summary['ended']++;
                if (function_exists('placementsAudit')) {
                    placementsAudit('placement.status_repaired_from_jobdiva_end_date', [
                        'placement_id' => $placementId,
                        'prior_status' => 'active',
                        'status' => 'ended',
                        'end_date' => (string) ($row['end_date'] ?? ''),
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
                'items_processed' => $summary['ended'],
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
        $keepId = count($rowsWithChildren) === 1
            ? (int) $rowsWithChildren[0]
            : _jobdivaMappingChooseDuplicatePlacementKeeper($group);
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
            $canonicalPreview = (string) ($group['canonical_external_id'] ?? '');
            foreach ($rows as $row) {
                if ((int) ($row['id'] ?? 0) === $keepId && $canonicalPreview !== '' && (string) ($row['external_id'] ?? '') !== $canonicalPreview) {
                    $summary['external_ids_restored']++;
                    break;
                }
            }
            continue;
        }

        try {
            $pdo->beginTransaction();
            $canonical = (string) ($group['canonical_external_id'] ?? '');
            [$inSql, $params] = _jobdivaMappingInClause('id', $duplicateIds);
            $params['t'] = $tenantId;
            $pdo->prepare(
                "UPDATE placements
                    SET deleted_at = NOW(), updated_at = NOW()
                  WHERE tenant_id = :t AND {$inSql}"
            )->execute($params);

            _jobdivaMappingRehomePlacementMapping($pdo, $tenantId, $norm, $keepId, $duplicateIds);

            if ($canonical !== '') {
                $st = $pdo->prepare(
                    'UPDATE placements
                        SET external_id = :ext_set, updated_at = NOW()
                      WHERE tenant_id = :t AND id = :id AND external_id <> :ext_filter'
                );
                $st->execute(['ext_set' => $canonical, 'ext_filter' => $canonical, 't' => $tenantId, 'id' => $keepId]);
                if ($st->rowCount() > 0) $summary['external_ids_restored']++;
            }

            $pdo->commit();
            $summary['groups_repaired']++;
            $summary['placements_archived'] += count($duplicateIds);
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

    $placementRows = _jobdivaMappingRows($pdo,
        "SELECT p.id, p.external_id, p.title, p.person_id, p.start_date, p.end_date,
                p.end_client_name, p.end_client_company_id, {$jobIdSelect}, p.status,
                p.created_at, p.updated_at
           FROM placements p
           {$mappingJoin}
          WHERE p.tenant_id = :t
            AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
            AND (" . implode(' OR ', $sourceWhere) . ")
       GROUP BY p.id, p.external_id, p.title, p.person_id, p.start_date, p.end_date,
                p.end_client_name, p.end_client_company_id, {$jobIdGroup}, p.status,
                p.created_at, p.updated_at
       ORDER BY p.id ASC",
        ['t' => $tenantId]
    );
    $groups = [];
    $rowsGroupedByStartId = [];
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
        if ($id > 0) $rowsGroupedByStartId[$id] = true;
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
    usort($out, static fn($a, $b) => ((int) ($b['count'] ?? 0) <=> (int) ($a['count'] ?? 0))
        ?: strcmp((string) ($a['external_id'] ?? ''), (string) ($b['external_id'] ?? '')));
    return array_slice($out, 0, $limit);
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

function _jobdivaMappingStaleActivePlacementRows(\PDO $pdo, int $tenantId, int $limit = 500): array
{
    if (!_jobdivaMappingTableExists($pdo, 'placements')) return [];
    $limit = max(1, min(5000, $limit));
    $hasMappings = _jobdivaMappingTableExists($pdo, 'external_entity_mappings');
    $mappingJoin = $hasMappings
        ? "LEFT JOIN external_entity_mappings m
                 ON m.tenant_id = p.tenant_id
                AND m.internal_entity_type = 'placement'
                AND m.internal_entity_id = p.id
                AND m.source_system = 'jobdiva'"
        : '';
    $sourceWhere = $hasMappings
        ? "(p.external_id LIKE 'jd:%' OR p.title LIKE 'JobDiva Placement %' OR m.id IS NOT NULL)"
        : "(p.external_id LIKE 'jd:%' OR p.title LIKE 'JobDiva Placement %')";
    return _jobdivaMappingRows($pdo,
        "SELECT p.id, p.external_id, p.title, p.person_id, p.start_date, p.end_date, p.status, p.updated_at
           FROM placements p
           {$mappingJoin}
          WHERE p.tenant_id = :t
            AND p.status = 'active'
            AND p.end_date IS NOT NULL
            AND p.end_date <> ''
            AND p.end_date < :today
            AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
            AND {$sourceWhere}
       GROUP BY p.id, p.external_id, p.title, p.person_id, p.start_date, p.end_date, p.status, p.updated_at
       ORDER BY p.end_date ASC, p.id ASC
          LIMIT {$limit}",
        ['t' => $tenantId, 'today' => date('Y-m-d')]
    );
}

function _jobdivaMappingChooseDuplicatePlacementKeeper(array $group): int
{
    $rows = is_array($group['rows'] ?? null) ? $group['rows'] : [];
    $canonical = (string) ($group['canonical_external_id'] ?? '');
    $bestId = 0;
    $bestScore = -1;
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) continue;
        $title = trim((string) ($row['title'] ?? ''));
        $score = 0;
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

    $tables = [
        'time_entries',
        'time_daily_finance',
        'time_approval_tokens',
        'billing_invoice_lines',
        'ap_bill_lines',
    ];
    foreach ($tables as $table) {
        if (!_jobdivaMappingTableExists($pdo, $table) || !_jobdivaMappingColumnExists($pdo, $table, 'placement_id')) {
            continue;
        }
        [$inSql, $params] = _jobdivaMappingInClause('placement_id', $ids);
        $params['t'] = $tenantId;
        $rows = _jobdivaMappingRows($pdo,
            "SELECT placement_id, COUNT(*) AS c
               FROM {$table}
              WHERE tenant_id = :t AND {$inSql}
           GROUP BY placement_id",
            $params
        );
        foreach ($rows as $row) {
            $id = (int) ($row['placement_id'] ?? 0);
            if ($id > 0 && array_key_exists($id, $counts)) {
                $counts[$id] += (int) ($row['c'] ?? 0);
            }
        }
    }
    return $counts;
}

function _jobdivaMappingDuplicatePlacementBlockingChildren(\PDO $pdo, int $tenantId, array $placementIds): array
{
    $ids = array_values(array_filter(array_map('intval', $placementIds), static fn($id) => $id > 0));
    if (!$ids) return [];
    $tables = [
        'time_entries',
        'time_daily_finance',
        'time_approval_tokens',
        'billing_invoice_lines',
        'ap_bill_lines',
    ];
    $blocking = [];
    foreach ($tables as $table) {
        if (!_jobdivaMappingTableExists($pdo, $table) || !_jobdivaMappingColumnExists($pdo, $table, 'placement_id')) {
            continue;
        }
        [$inSql, $params] = _jobdivaMappingInClause('placement_id', $ids);
        $params['t'] = $tenantId;
        $count = _jobdivaMappingScalar($pdo,
            "SELECT COUNT(*) FROM {$table} WHERE tenant_id = :t AND {$inSql}",
            $params
        );
        if ($count > 0) $blocking[$table] = $count;
    }
    return $blocking;
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
