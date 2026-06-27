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
            'Some JobDiva placement Start IDs resolve to more than one active CoreFlux placement row.',
            'Run Repair duplicate placements after confirming no skipped rows have downstream billing/time/AP activity.'
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
                sc.id AS existing_client_id, sc.company_id AS existing_client_company_id
           FROM external_entity_mappings m
           JOIN placements p ON p.id = m.internal_entity_id AND p.tenant_id = m.tenant_id
      LEFT JOIN companies c ON c.id = p.end_client_company_id AND c.tenant_id = p.tenant_id AND c.deleted_at IS NULL
      LEFT JOIN staffing_clients sc ON sc.id = p.client_id AND sc.tenant_id = p.tenant_id
          WHERE m.tenant_id = :t
            AND m.source_system = 'jobdiva'
            AND m.internal_entity_type = 'placement'
            AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
            AND (
                 p.client_id IS NULL
              OR sc.id IS NULL
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
        $name = trim((string) ($row['end_client_name'] ?? ''));
        if ($name === '') $name = trim((string) ($row['company_name'] ?? ''));
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
            if (trim((string) ($row['end_client_name'] ?? '')) === '' && !empty($clientRef['name'])) {
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
        $keepId = _jobdivaMappingChooseDuplicatePlacementKeeper($group);
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
            $summary['external_ids_restored']++;
            continue;
        }

        try {
            $pdo->beginTransaction();
            $canonical = 'jd:' . $norm;
            $st = $pdo->prepare(
                'UPDATE placements
                    SET external_id = :ext, updated_at = NOW()
                  WHERE tenant_id = :t AND id = :id AND external_id <> :ext'
            );
            $st->execute(['ext' => $canonical, 't' => $tenantId, 'id' => $keepId]);
            if ($st->rowCount() > 0) $summary['external_ids_restored']++;

            $pdo->prepare(
                "UPDATE external_entity_mappings
                    SET internal_entity_id = :iid,
                        updated_at = NOW(),
                        last_seen_at = NOW()
                  WHERE tenant_id = :t
                    AND source_system = 'jobdiva'
                    AND internal_entity_type = 'placement'
                    AND external_id = :ext"
            )->execute(['iid' => $keepId, 't' => $tenantId, 'ext' => $norm]);

            [$inSql, $params] = _jobdivaMappingInClause('id', $duplicateIds);
            $params['t'] = $tenantId;
            $pdo->prepare(
                "UPDATE placements
                    SET deleted_at = NOW(), updated_at = NOW()
                  WHERE tenant_id = :t AND {$inSql}"
            )->execute($params);

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
    if (!_jobdivaMappingTableExists($pdo, 'placements')
        || !_jobdivaMappingTableExists($pdo, 'external_entity_mappings')) {
        return [];
    }
    $limit = max(1, min(500, $limit));
    $mappingRows = _jobdivaMappingRows($pdo,
        "SELECT external_id, internal_entity_id
           FROM external_entity_mappings
          WHERE tenant_id = :t
            AND source_system = 'jobdiva'
            AND internal_entity_type = 'placement'",
        ['t' => $tenantId]
    );
    $mapped = [];
    foreach ($mappingRows as $row) {
        $ext = _jobdivaMappingNormalisePlacementExternalId((string) ($row['external_id'] ?? ''));
        $iid = (int) ($row['internal_entity_id'] ?? 0);
        if ($ext === '') continue;
        $mapped[$ext] ??= ['internal_ids' => []];
        if ($iid > 0) $mapped[$ext]['internal_ids'][$iid] = true;
    }
    if (!$mapped) return [];

    $placementRows = _jobdivaMappingRows($pdo,
        "SELECT id, external_id, title, person_id, start_date, status, created_at, updated_at
           FROM placements
          WHERE tenant_id = :t
            AND external_id IS NOT NULL
            AND external_id <> ''
            AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
       ORDER BY id ASC",
        ['t' => $tenantId]
    );
    $groups = [];
    foreach ($placementRows as $row) {
        $norm = _jobdivaMappingNormalisePlacementExternalId((string) ($row['external_id'] ?? ''));
        if ($norm === '' || !isset($mapped[$norm])) continue;
        $id = (int) ($row['id'] ?? 0);
        $row['is_current_mapping'] = $id > 0 && !empty($mapped[$norm]['internal_ids'][$id]);
        $row['canonical_external_id'] = 'jd:' . $norm;
        $groups[$norm] ??= ['external_id' => $norm, 'count' => 0, 'rows' => []];
        $groups[$norm]['rows'][] = $row;
        $groups[$norm]['count']++;
    }
    $out = array_values(array_filter($groups, static fn($group) => (int) ($group['count'] ?? 0) > 1));
    usort($out, static fn($a, $b) => ((int) ($b['count'] ?? 0) <=> (int) ($a['count'] ?? 0))
        ?: strcmp((string) ($a['external_id'] ?? ''), (string) ($b['external_id'] ?? '')));
    return array_slice($out, 0, $limit);
}

function _jobdivaMappingChooseDuplicatePlacementKeeper(array $group): int
{
    $rows = is_array($group['rows'] ?? null) ? $group['rows'] : [];
    foreach ($rows as $row) {
        if (!empty($row['is_current_mapping']) && (int) ($row['id'] ?? 0) > 0) {
            return (int) $row['id'];
        }
    }
    $canonical = 'jd:' . (string) ($group['external_id'] ?? '');
    foreach ($rows as $row) {
        if ((string) ($row['external_id'] ?? '') === $canonical && (int) ($row['id'] ?? 0) > 0) {
            return (int) $row['id'];
        }
    }
    foreach ($rows as $row) {
        if ((int) ($row['id'] ?? 0) > 0) return (int) $row['id'];
    }
    return 0;
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
