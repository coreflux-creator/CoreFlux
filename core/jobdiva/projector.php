<?php
/**
 * JobDiva -> CoreFlux graph projector.
 *
 * This file is the adapter contract between JobDiva native mirrors and the
 * CoreFlux graphs that workflows consume. Field Mapping Studio remains an
 * enrichment layer; it must not become a second persistence path for
 * placements, people, companies, staffing jobs, clients, or rates.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/canonical_graph.php';
require_once __DIR__ . '/../integrations/entity_mappings.php';
require_once __DIR__ . '/../../modules/staffing/lib/clients.php';
require_once __DIR__ . '/../../modules/staffing/lib/jobs.php';

function jobdivaProjectorContract(): array
{
    return [
        'source_system' => 'jobdiva',
        'principle' => 'Native JobDiva rows are mirrored as source evidence; canonical CoreFlux graphs own workflow state.',
        'stages' => [
            'mirror' => [
                'owner' => 'external_entity_mappings',
                'description' => 'Store native JobDiva payloads without deciding workflow identity.',
                'outputs' => ['jobdiva_job', 'jobdiva_candidate', 'jobdiva_contact', 'jobdiva_assignment'],
            ],
            'identity_resolve' => [
                'owner' => 'external_entity_mappings',
                'description' => 'Resolve native ids to CoreFlux people, companies, contacts, staffing jobs, placements, and time entries.',
                'outputs' => ['person', 'company', 'contact', 'staffing_job', 'placement', 'time_entry'],
            ],
            'project_coreflux' => [
                'owner' => 'coreflux_graphs',
                'description' => 'Write the canonical rows downstream modules consume.',
                'outputs' => ['people', 'companies', 'company_contacts', 'staffing_jobs', 'staffing_clients', 'placements', 'placement_rates', 'time_entries'],
            ],
            'workflow_readiness' => [
                'owner' => 'projector',
                'description' => 'Check whether projected rows are usable by placement activation, time, billing, payroll, AP, and reporting.',
                'outputs' => ['person_link', 'staffing_job_link', 'end_client_company_link', 'staffing_client_link', 'rate_row', 'approved_rate_window'],
            ],
            'field_map_enrichment' => [
                'owner' => 'tenant_integration_field_map',
                'description' => 'Apply tenant-selected field paths to already-resolved graph owners.',
                'outputs' => ['field_map_writes'],
            ],
        ],
        'canonical_graphs' => jobdivaCanonicalGraphCatalog(),
    ];
}

function jobdivaProjectorProjectPlacement(int $tenantId, array $payload, ?int $userId = null, array $opts = []): array
{
    $summary = [
        'projected' => false,
        'external_id' => null,
        'placement_id' => null,
        'identities' => [
            'person_id' => 0,
            'end_client_company_id' => 0,
            'staffing_job_id' => 0,
        ],
        'join_stats' => [],
        'mapping_writes' => 0,
        'field_map' => ['attempted' => 0, 'written' => 0, 'skipped' => 0, 'errors' => []],
        'readiness' => ['ok' => false, 'missing' => ['not_projected'], 'warnings' => [], 'facts' => []],
        'errors' => [],
    ];
    if ($tenantId <= 0) {
        $summary['errors'][] = 'invalid tenant id';
        return $summary;
    }

    try {
        $joinStats = [];
        if (empty($opts['payload_is_enriched']) && function_exists('jobdivaPlacementPayloadWithMirrors')) {
            $payload = jobdivaPlacementPayloadWithMirrors($tenantId, $payload, $joinStats);
        } elseif (function_exists('jobdivaExtractJoinedSubPayloads')) {
            $joinStats = jobdivaProjectorInferJoinStats($payload);
            if (function_exists('jobdivaCanonicalPlacementPayload')) {
                $payload = jobdivaCanonicalPlacementPayload($payload, jobdivaExtractJoinedSubPayloads($payload));
            }
        }
        $summary['join_stats'] = $joinStats;

        $externalId = trim((string) ($opts['external_id'] ?? ''));
        if ($externalId === '') {
            $externalId = jobdivaProjectorPluck($payload, [
                'id', 'startId', 'start_id', 'placementId', 'placement_id', 'startID', 'STARTID',
            ]);
        }
        if (str_starts_with($externalId, 'jd:')) $externalId = substr($externalId, 3);
        if ($externalId === '') throw new \RuntimeException('missing JobDiva placement/start id');
        $summary['external_id'] = $externalId;

        $personId = (int) ($opts['person_id'] ?? 0);
        if ($personId <= 0 && function_exists('jobdivaPlacementsAutoCreatePerson')) {
            $personId = (int) (jobdivaPlacementsAutoCreatePerson($tenantId, $payload, $userId) ?? 0);
        }
        if ($personId <= 0) throw new \RuntimeException('could not resolve JobDiva candidate to CoreFlux person');

        $endClientCompanyId = isset($opts['end_client_company_id']) ? (int) $opts['end_client_company_id'] : 0;
        if ($endClientCompanyId <= 0) {
            $endClientCompanyId = (int) (jobdivaProjectorResolveEndClientCompany($tenantId, $payload, $userId) ?? 0);
        }

        $writePayload = $payload;
        $existingPlacementId = (int) ($opts['existing_placement_id'] ?? 0);
        if ($existingPlacementId > 0) {
            $writePayload['__cf_existing_placement_id'] = $existingPlacementId;
        }

        if (!function_exists('jobdivaSyncUpsertPlacement')) {
            throw new \RuntimeException('jobdivaSyncUpsertPlacement is not loaded');
        }
        $placementId = jobdivaSyncUpsertPlacement(
            $tenantId,
            $personId,
            $endClientCompanyId > 0 ? $endClientCompanyId : null,
            $writePayload,
            $externalId,
            $userId
        );
        unset($writePayload['__cf_existing_placement_id']);

        mappingUpsert($tenantId, 'jobdiva', 'placement', $externalId, $placementId, $writePayload, 'pull', $userId);
        $summary['mapping_writes']++;

        if (function_exists('jobdivaIndexJoinedSubPayloads')) {
            try {
                jobdivaIndexJoinedSubPayloads($tenantId, $writePayload);
            } catch (\Throwable $e) {
                error_log('[jobdiva projector] joined payload index failed: ' . $e->getMessage());
            }
        }

        $staffingJobId = function_exists('jobdivaPlacementStaffingJobId')
            ? jobdivaPlacementStaffingJobId($tenantId, $placementId)
            : 0;
        if (function_exists('jobdivaApplyPlacementFieldMappings')) {
            try {
                $summary['field_map'] = jobdivaApplyPlacementFieldMappings(
                    $tenantId,
                    $placementId,
                    $personId,
                    $endClientCompanyId > 0 ? $endClientCompanyId : null,
                    $staffingJobId,
                    $writePayload
                );
            } catch (\Throwable $e) {
                $summary['field_map']['errors'][] = $e->getMessage();
                error_log('[jobdiva projector] field mapping failed: ' . $e->getMessage());
            }
        }

        $summary['projected'] = true;
        $summary['placement_id'] = $placementId;
        $summary['identities'] = [
            'person_id' => $personId,
            'end_client_company_id' => $endClientCompanyId,
            'staffing_job_id' => $staffingJobId,
        ];
        $summary['readiness'] = jobdivaProjectorPlacementReadiness($tenantId, $placementId);
        return $summary;
    } catch (\Throwable $e) {
        $summary['errors'][] = $e->getMessage();
        return $summary;
    }
}

function jobdivaProjectorPlacementReadiness(int $tenantId, int $placementId): array
{
    $out = ['ok' => false, 'missing' => [], 'warnings' => [], 'facts' => []];
    if ($tenantId <= 0 || $placementId <= 0) {
        $out['missing'][] = 'placement';
        return $out;
    }

    try {
        $pdo = getDB();
        $st = $pdo->prepare(
            'SELECT p.id, p.person_id, p.staffing_job_id, p.end_client_company_id, p.client_id, p.start_date,
                    sc.company_id AS client_company_id
               FROM placements p
          LEFT JOIN staffing_clients sc ON sc.tenant_id = p.tenant_id AND sc.id = p.client_id
              WHERE p.tenant_id = :t
                AND p.id = :id
                AND (p.deleted_at IS NULL OR p.deleted_at = "0000-00-00 00:00:00")
              LIMIT 1'
        );
        $st->execute(['t' => $tenantId, 'id' => $placementId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            $out['missing'][] = 'placement';
            return $out;
        }

        foreach ([
            'person_id' => 'person_link',
            'staffing_job_id' => 'staffing_job_link',
            'end_client_company_id' => 'end_client_company_link',
            'client_id' => 'staffing_client_link',
        ] as $column => $code) {
            if (empty($row[$column]) || (int) $row[$column] <= 0) $out['missing'][] = $code;
        }
        if (!empty($row['end_client_company_id']) && !empty($row['client_company_id'])
            && (int) $row['end_client_company_id'] !== (int) $row['client_company_id']) {
            $out['missing'][] = 'staffing_client_company_match';
        }

        $rateFacts = jobdivaProjectorPlacementRateFacts($tenantId, $placementId, (string) ($row['start_date'] ?? ''));
        $out['facts'] = [
            'placement_id' => $placementId,
            'person_id' => (int) ($row['person_id'] ?? 0),
            'staffing_job_id' => (int) ($row['staffing_job_id'] ?? 0),
            'end_client_company_id' => (int) ($row['end_client_company_id'] ?? 0),
            'client_id' => (int) ($row['client_id'] ?? 0),
            'rate_rows' => $rateFacts['rate_rows'],
            'draft_rate_rows' => $rateFacts['draft_rate_rows'],
            'approved_rate_rows' => $rateFacts['approved_rate_rows'],
            'approved_rate_covers_start' => $rateFacts['approved_rate_covers_start'],
        ];
        if ((int) $rateFacts['rate_rows'] <= 0) $out['missing'][] = 'rate_row';
        if ((int) $rateFacts['draft_rate_rows'] > 0) $out['warnings'][] = 'draft_rate_pending_approval';
        if ((int) $rateFacts['approved_rate_rows'] > 0 && !$rateFacts['approved_rate_covers_start']) {
            $out['warnings'][] = 'approved_rate_not_covering_placement_start';
        }

        $out['missing'] = array_values(array_unique($out['missing']));
        $out['warnings'] = array_values(array_unique($out['warnings']));
        $out['ok'] = count($out['missing']) === 0;
        return $out;
    } catch (\Throwable $e) {
        $out['missing'][] = 'readiness_check_failed';
        $out['warnings'][] = substr($e->getMessage(), 0, 180);
        return $out;
    }
}

function jobdivaProjectorReadinessCounts(int $tenantId): array
{
    $counts = [
        'missing_person' => 0,
        'missing_staffing_job' => 0,
        'missing_end_client_company' => 0,
        'missing_staffing_client' => 0,
        'client_company_mismatch' => 0,
        'missing_rate_row' => 0,
        'active_missing_approved_rate' => 0,
    ];
    if ($tenantId <= 0) return $counts;

    try {
        $pdo = getDB();
        if (!jobdivaProjectorTableExists($pdo, 'placements')) return $counts;
        $base = "FROM external_entity_mappings m
                 JOIN placements p ON p.id = m.internal_entity_id AND p.tenant_id = m.tenant_id
                WHERE m.tenant_id = :t
                  AND m.source_system = 'jobdiva'
                  AND m.internal_entity_type = 'placement'
                  AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')";
        foreach ([
            'missing_person' => 'p.person_id IS NULL OR p.person_id = 0',
            'missing_staffing_job' => 'p.staffing_job_id IS NULL OR p.staffing_job_id = 0',
            'missing_end_client_company' => 'p.end_client_company_id IS NULL OR p.end_client_company_id = 0',
            'missing_staffing_client' => 'p.client_id IS NULL OR p.client_id = 0',
        ] as $key => $where) {
            if (str_contains($where, 'staffing_job_id') && !jobdivaProjectorColumnExists($pdo, 'placements', 'staffing_job_id')) continue;
            if (str_contains($where, 'end_client_company_id') && !jobdivaProjectorColumnExists($pdo, 'placements', 'end_client_company_id')) continue;
            if (str_contains($where, 'client_id') && !jobdivaProjectorColumnExists($pdo, 'placements', 'client_id')) continue;
            if (str_contains($where, 'person_id') && !jobdivaProjectorColumnExists($pdo, 'placements', 'person_id')) continue;
            $counts[$key] = jobdivaProjectorScalar($pdo, "SELECT COUNT(*) {$base} AND ({$where})", ['t' => $tenantId]);
        }

        if (jobdivaProjectorTableExists($pdo, 'staffing_clients')
            && jobdivaProjectorColumnExists($pdo, 'placements', 'client_id')
            && jobdivaProjectorColumnExists($pdo, 'placements', 'end_client_company_id')
            && jobdivaProjectorColumnExists($pdo, 'staffing_clients', 'company_id')) {
            $counts['client_company_mismatch'] = jobdivaProjectorScalar($pdo,
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
        }

        if (jobdivaProjectorTableExists($pdo, 'placement_rates')) {
            $counts['missing_rate_row'] = jobdivaProjectorScalar($pdo,
                "SELECT COUNT(*)
                   FROM external_entity_mappings m
                   JOIN placements p ON p.id = m.internal_entity_id AND p.tenant_id = m.tenant_id
              LEFT JOIN placement_rates pr ON pr.tenant_id = p.tenant_id AND pr.placement_id = p.id
                  WHERE m.tenant_id = :t
                    AND m.source_system = 'jobdiva'
                    AND m.internal_entity_type = 'placement'
                    AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
                    AND pr.id IS NULL",
                ['t' => $tenantId]
            );
            $counts['active_missing_approved_rate'] = jobdivaProjectorScalar($pdo,
                "SELECT COUNT(*)
                   FROM external_entity_mappings m
                   JOIN placements p ON p.id = m.internal_entity_id AND p.tenant_id = m.tenant_id
              LEFT JOIN placement_rates pr
                     ON pr.tenant_id = p.tenant_id
                    AND pr.placement_id = p.id
                    AND pr.approved_at IS NOT NULL
                    AND pr.effective_from <= p.start_date
                    AND (pr.effective_to IS NULL OR pr.effective_to >= p.start_date)
                  WHERE m.tenant_id = :t
                    AND m.source_system = 'jobdiva'
                    AND m.internal_entity_type = 'placement'
                    AND p.status = 'active'
                    AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
                    AND pr.id IS NULL",
                ['t' => $tenantId]
            );
        }
    } catch (\Throwable $e) {
        error_log('[jobdiva projector] readiness counts failed: ' . $e->getMessage());
    }

    return $counts;
}

function jobdivaProjectorResolveEndClientCompany(int $tenantId, array $payload, ?int $userId): ?int
{
    $companyExtId = jobdivaProjectorPluck($payload, [
        'companyId', 'company_id', 'company id', 'endClientCompanyId',
        'COMPANYID', 'companyID', 'end client company id',
    ]);
    $endClientName = jobdivaProjectorEndClientNameFromPayload($payload);
    if ($companyExtId !== '') {
        $cm = mappingFindInternal($tenantId, 'jobdiva', 'company', $companyExtId);
        if ($cm) return (int) $cm['internal_entity_id'];
        if ($endClientName !== '') {
            return jobdivaProjectorEnsureEndClientCompany(
                $tenantId,
                $endClientName,
                $userId,
                $payload,
                $companyExtId,
                'company'
            );
        }
    }

    $customerExtId = jobdivaProjectorPluckDeep($payload, [
        'customerId', 'customer_id', 'customer id', 'customerID', 'CUSTOMERID',
        'clientId', 'client_id', 'client id',
    ], jobdivaProjectorEndClientNestOrder());
    if ($customerExtId !== '') {
        foreach (['jobdiva_customer', 'company'] as $mapType) {
            $cm = mappingFindInternal($tenantId, 'jobdiva', $mapType, $customerExtId);
            if ($cm) return (int) $cm['internal_entity_id'];
        }
        if ($endClientName !== '' && function_exists('jobdivaResolveOrAutoCreateEndClient')) {
            return jobdivaResolveOrAutoCreateEndClient($tenantId, $customerExtId, $endClientName, $userId, $payload);
        }
    }

    if ($endClientName !== '') {
        return jobdivaProjectorEnsureEndClientCompany($tenantId, $endClientName, $userId, $payload);
    }

    return null;
}

function jobdivaProjectorEnsureEndClientCompany(
    int $tenantId,
    string $name,
    ?int $userId,
    array $payload,
    string $externalId = '',
    string $mapType = 'jobdiva_customer'
): ?int {
    $name = trim($name);
    if ($tenantId <= 0 || $name === '') return null;

    try {
        if (function_exists('staffingClientEnsureForCompany')) {
            $clientRef = staffingClientEnsureForCompany($tenantId, null, $name, [
                'created_by_user_id' => $userId,
            ]);
            $companyId = (int) ($clientRef['company_id'] ?? 0);
        } elseif (function_exists('companiesUpsertByName')) {
            $companyId = companiesUpsertByName($tenantId, $name, [
                'created_by_user_id' => $userId,
            ], ['client']);
        } else {
            return null;
        }

        if ($companyId > 0) {
            if (trim($externalId) !== '') {
                mappingUpsert($tenantId, 'jobdiva', $mapType, trim($externalId), $companyId, $payload, 'pull', $userId);
            }
            if (function_exists('integrationPayloadFieldIndexRecord')) {
                try {
                    integrationPayloadFieldIndexRecord($tenantId, 'jobdiva', 'company', $payload);
                } catch (\Throwable $e) {
                    error_log('[jobdiva projector] company index failed: ' . $e->getMessage());
                }
            }
            return $companyId;
        }
    } catch (\Throwable $e) {
        error_log('[jobdiva projector] end-client company ensure failed: ' . $e->getMessage());
    }
    return null;
}

function jobdivaProjectorEndClientNestOrder(): array
{
    return [
        '_jd_customer', 'customer', 'Customer', 'client', 'Client',
        '_jd_job', 'job', 'Job', 'jobInfo', 'jobObj', 'jobRecord',
        '_jd_start', 'assignment', 'start', 'Start',
    ];
}

function jobdivaProjectorEndClientNameFromPayload(array $payload): string
{
    $specific = [
        'endClientName', 'end_client_name', 'end client name',
        'endClientCompanyName', 'end_client_company_name', 'end client company name',
        'customerName', 'customer_name', 'customer name',
        'clientName', 'client_name', 'client name',
        'companyName', 'company_name', 'company name', 'COMPANYNAME',
        'jobCompanyName', 'job_company_name', 'job company name',
    ];
    $v = jobdivaProjectorPluck($payload, $specific);
    if ($v !== '') return $v;

    foreach (['_jd_customer', 'customer', 'Customer', 'client', 'Client'] as $nest) {
        if (!isset($payload[$nest]) || !is_array($payload[$nest])) continue;
        $v = jobdivaProjectorPluck($payload[$nest], array_merge($specific, ['name']));
        if ($v !== '') return $v;
    }
    foreach (['_jd_job', 'job', 'Job', 'jobInfo', 'jobObj', 'jobRecord'] as $nest) {
        if (!isset($payload[$nest]) || !is_array($payload[$nest])) continue;
        $v = jobdivaProjectorPluck($payload[$nest], $specific);
        if ($v !== '') return $v;
    }
    foreach (['_jd_start', 'assignment', 'start', 'Start'] as $nest) {
        if (!isset($payload[$nest]) || !is_array($payload[$nest])) continue;
        $v = jobdivaProjectorPluck($payload[$nest], $specific);
        if ($v !== '') return $v;
    }
    return '';
}

function jobdivaProjectorPlacementRateFacts(int $tenantId, int $placementId, string $startDate): array
{
    $facts = [
        'rate_rows' => 0,
        'draft_rate_rows' => 0,
        'approved_rate_rows' => 0,
        'approved_rate_covers_start' => false,
    ];
    try {
        $pdo = getDB();
        if (!jobdivaProjectorTableExists($pdo, 'placement_rates')) return $facts;
        $st = $pdo->prepare(
            'SELECT
                COUNT(*) AS rate_rows,
                SUM(CASE WHEN approved_at IS NULL THEN 1 ELSE 0 END) AS draft_rate_rows,
                SUM(CASE WHEN approved_at IS NOT NULL THEN 1 ELSE 0 END) AS approved_rate_rows,
                SUM(CASE WHEN approved_at IS NOT NULL
                          AND (:sd = "" OR effective_from <= :sd2)
                          AND (:sd3 = "" OR effective_to IS NULL OR effective_to >= :sd4)
                         THEN 1 ELSE 0 END) AS approved_rate_covers_start
               FROM placement_rates
              WHERE tenant_id = :t AND placement_id = :p'
        );
        $st->execute([
            't' => $tenantId,
            'p' => $placementId,
            'sd' => $startDate,
            'sd2' => $startDate,
            'sd3' => $startDate,
            'sd4' => $startDate,
        ]);
        $row = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
        $facts['rate_rows'] = (int) ($row['rate_rows'] ?? 0);
        $facts['draft_rate_rows'] = (int) ($row['draft_rate_rows'] ?? 0);
        $facts['approved_rate_rows'] = (int) ($row['approved_rate_rows'] ?? 0);
        $facts['approved_rate_covers_start'] = (int) ($row['approved_rate_covers_start'] ?? 0) > 0;
    } catch (\Throwable $e) {
        error_log('[jobdiva projector] rate facts failed: ' . $e->getMessage());
    }
    return $facts;
}

function jobdivaProjectorInferJoinStats(array $payload): array
{
    return [
        'jobs_joined' => isset($payload['_jd_job']) && is_array($payload['_jd_job']) ? 1 : 0,
        'candidates_joined' => isset($payload['_jd_candidate']) && is_array($payload['_jd_candidate']) ? 1 : 0,
        'contacts_joined' => isset($payload['_jd_contact']) && is_array($payload['_jd_contact']) ? 1 : 0,
        'assignments_joined' => isset($payload['_jd_start']) && is_array($payload['_jd_start']) ? 1 : 0,
    ];
}

function jobdivaProjectorPluck(array $payload, array $candidates): string
{
    if (function_exists('jobdivaPluckField')) return jobdivaPluckField($payload, $candidates);
    $norm = [];
    foreach ($payload as $key => $value) {
        if (!is_string($key)) continue;
        $nk = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));
        if ($nk !== '' && !array_key_exists($nk, $norm)) $norm[$nk] = $value;
    }
    foreach ($candidates as $candidate) {
        $nk = strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $candidate));
        if (!array_key_exists($nk, $norm)) continue;
        $value = $norm[$nk];
        if (!is_scalar($value) || $value === null) continue;
        $out = trim((string) $value);
        if ($out !== '') return $out;
    }
    return '';
}

function jobdivaProjectorPluckDeep(array $payload, array $candidates, ?array $nestOrder = null): string
{
    if (function_exists('jobdivaPluckFieldDeep')) {
        return $nestOrder !== null
            ? jobdivaPluckFieldDeep($payload, $candidates, $nestOrder)
            : jobdivaPluckFieldDeep($payload, $candidates);
    }
    $v = jobdivaProjectorPluck($payload, $candidates);
    if ($v !== '') return $v;
    foreach (($nestOrder ?? ['_jd_candidate', '_jd_job', '_jd_customer', '_jd_contact', '_jd_start', 'job', 'candidate', 'customer', 'contact']) as $nest) {
        if (!isset($payload[$nest]) || !is_array($payload[$nest])) continue;
        $v = jobdivaProjectorPluck($payload[$nest], $candidates);
        if ($v !== '') return $v;
    }
    return '';
}

function jobdivaProjectorScalar(\PDO $pdo, string $sql, array $params = []): int
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int) $st->fetchColumn();
}

function jobdivaProjectorTableExists(\PDO $pdo, string $table): bool
{
    try {
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $st = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :tbl LIMIT 1");
            $st->execute(['tbl' => $table]);
            return $st->fetchColumn() !== false;
        }
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = :tbl'
        );
        $st->execute(['tbl' => $table]);
        return (int) $st->fetchColumn() > 0;
    } catch (\Throwable $_) {
        return false;
    }
}

function jobdivaProjectorColumnExists(\PDO $pdo, string $table, string $column): bool
{
    try {
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $table);
            $cols = $pdo->query("PRAGMA table_info({$safeTable})")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($cols as $col) {
                if (($col['name'] ?? '') === $column) return true;
            }
            return false;
        }
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = :tbl AND column_name = :col'
        );
        $st->execute(['tbl' => $table, 'col' => $column]);
        return (int) $st->fetchColumn() > 0;
    } catch (\Throwable $_) {
        return false;
    }
}
