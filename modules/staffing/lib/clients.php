<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../core/tenant_scope.php';
require_once __DIR__ . '/../../../core/sub_tenants.php';
require_once __DIR__ . '/../../people/lib/companies.php';

/**
 * Client identity follows the shared placements/companies catalog. Financial
 * activity can remain isolated in a sub-tenant while every entity sees the
 * same customer record and company id.
 */
function staffingClientCatalogTenantId(int $activeTenantId): int
{
    try {
        return effectiveTenantIdForModule('placements', $activeTenantId) ?? $activeTenantId;
    } catch (\Throwable $_) {
        return $activeTenantId;
    }
}

/**
 * Execute a client-catalog query against the already-resolved catalog tenant.
 *
 * Client identity follows the placements graph, while the request itself may
 * originate from an isolated sub-tenant. Do not ask scopedQuery() to resolve
 * that boundary a second time: bind the catalog tenant explicitly.
 */
function staffingClientCatalogQuery(int $tenantId, string $sql, array $params = []): array
{
    $pdo = getDB();
    if (!$pdo) return [];
    if (!str_contains($sql, ':tenant_id')) {
        throw new \InvalidArgumentException('Client catalog query must include :tenant_id');
    }
    $params['tenant_id'] = $tenantId;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
}

function staffingClientCatalogFind(int $tenantId, string $sql, array $params = []): ?array
{
    $rows = staffingClientCatalogQuery($tenantId, $sql, $params);
    return $rows[0] ?? null;
}

function staffingClientFindForCompany(int $tenantId, ?int $companyId): ?array
{
    if (!$companyId || $companyId <= 0) return null;
    return staffingClientCatalogFind(
        $tenantId,
        "SELECT *
           FROM staffing_clients
          WHERE tenant_id = :tenant_id
            AND company_id = :company_id
       ORDER BY CASE WHEN status = 'active' THEN 0 ELSE 1 END, id ASC
          LIMIT 1",
        ['company_id' => $companyId]
    );
}

/**
 * Deduplicate an established bill-to profile within the same company.
 * End-client identity never supplies or replaces the billing relationship.
 */
function staffingClientRelinkCanonicalPlacements(int $tenantId): int
{
    $pdo = getDB();
    if (!$pdo) throw new \RuntimeException('No database connection');
    $stmt = $pdo->prepare(
        'UPDATE placements p
           JOIN staffing_clients current_client
             ON current_client.tenant_id = p.tenant_id AND current_client.id = p.client_id
           JOIN (
                 SELECT tenant_id, company_id,
                        COALESCE(MIN(CASE WHEN status = \'active\' THEN id END), MIN(id)) AS client_id
                   FROM staffing_clients WHERE company_id IS NOT NULL
                  GROUP BY tenant_id, company_id
                ) canonical_choice
             ON canonical_choice.tenant_id = p.tenant_id
            AND canonical_choice.company_id = current_client.company_id
           JOIN staffing_clients canonical
             ON canonical.id = canonical_choice.client_id AND canonical.tenant_id = p.tenant_id
            SET p.client_id = canonical.id, p.updated_at = NOW()
          WHERE p.tenant_id = :tenant_id AND p.deleted_at IS NULL
            AND current_client.id <> canonical.id
            AND (p.coreflux_overridden_fields IS NULL OR (
                JSON_VALID(p.coreflux_overridden_fields)
                AND NOT JSON_CONTAINS(p.coreflux_overridden_fields, \'"client_id"\')
            ))'
    );
    $stmt->execute(['tenant_id' => $tenantId]);
    return $stmt->rowCount();
}

/**
 * Retire client rows created only because the generic JobDiva company/contact
 * importer used to promote every organization. Source companies, contacts,
 * placements, and mappings remain untouched.
 */
function staffingClientRetireUnsupportedJobDivaPromotions(int $tenantId): int
{
    $pdo = getDB();
    if (!$pdo) throw new \RuntimeException('No database connection');
    $stmt = $pdo->prepare(
        "UPDATE staffing_clients sc
           JOIN external_entity_mappings company_map
             ON company_map.tenant_id = sc.tenant_id
            AND company_map.source_system = 'jobdiva'
            AND company_map.internal_entity_type = 'company'
            AND company_map.internal_entity_id = sc.company_id
      LEFT JOIN placements p
             ON p.tenant_id = sc.tenant_id
            AND p.client_id = sc.id
            AND p.deleted_at IS NULL
      LEFT JOIN external_entity_mappings customer_map
             ON customer_map.tenant_id = sc.tenant_id
            AND customer_map.internal_entity_type = 'customer'
            AND customer_map.internal_entity_id = sc.id
            AND customer_map.source_system IN ('qbo', 'quickbooks_online', 'zoho', 'zoho_books')
      LEFT JOIN audit_log manual_audit
             ON manual_audit.tenant_id = sc.tenant_id
            AND manual_audit.target_id = sc.id
            AND manual_audit.event IN ('staffing.client.created', 'staffing.client.updated')
            SET sc.status = 'inactive', sc.updated_at = NOW()
          WHERE sc.tenant_id = :tenant_id
            AND sc.status = 'active'
            AND p.id IS NULL
            AND customer_map.id IS NULL
            AND manual_audit.id IS NULL
            AND (sc.external_id IS NULL OR TRIM(sc.external_id) = '')"
    );
    $stmt->execute(['tenant_id' => $tenantId]);
    $retired = $stmt->rowCount();

    $roleStmt = $pdo->prepare(
        "DELETE cr FROM company_roles cr
           JOIN staffing_clients sc ON sc.company_id = cr.company_id AND sc.tenant_id = :tenant_id
           JOIN external_entity_mappings company_map
             ON company_map.tenant_id = sc.tenant_id
            AND company_map.source_system = 'jobdiva'
            AND company_map.internal_entity_type = 'company'
            AND company_map.internal_entity_id = sc.company_id
      LEFT JOIN placements p
             ON p.tenant_id = sc.tenant_id
            AND p.client_id = sc.id
            AND p.deleted_at IS NULL
      LEFT JOIN external_entity_mappings customer_map
             ON customer_map.tenant_id = sc.tenant_id
            AND customer_map.internal_entity_type = 'customer'
            AND customer_map.internal_entity_id = sc.id
      LEFT JOIN audit_log manual_audit
             ON manual_audit.tenant_id = sc.tenant_id
            AND manual_audit.target_id = sc.id
            AND manual_audit.event IN ('staffing.client.created', 'staffing.client.updated')
          WHERE cr.role = 'client'
            AND sc.status = 'inactive'
            AND p.id IS NULL
            AND customer_map.id IS NULL
            AND manual_audit.id IS NULL
            AND (sc.external_id IS NULL OR TRIM(sc.external_id) = '')"
    );
    $roleStmt->execute(['tenant_id' => $tenantId]);
    return $retired;
}

/**
 * Retire legacy client rows that have no trustworthy business provenance.
 *
 * Older projection paths could create a staffing client from a candidate,
 * contact, requisition customer, or chain label. Those rows defaulted to
 * source_system=manual even though no user created them. A real client must
 * now be supported by at least one of:
 *   - a non-JobDiva placement created or imported directly in CoreFlux;
 *   - an accounting customer mapping;
 *   - an explicit client create/update/import audit event; or
 *   - a named integration source (exact JobDiva assignment clients are
 *     stamped source_system=jobdiva by the projector before this runs).
 *
 * Rows are only inactivated. Source companies, mappings, placements, and
 * accounting history remain available for audit and recovery.
 */
function staffingClientRetireUnsupportedLegacyRows(int $tenantId): int
{
    $pdo = getDB();
    if (!$pdo) throw new \RuntimeException('No database connection');

    $unsupported = "sc.tenant_id = :tenant_id
        AND sc.status = 'active'
        AND COALESCE(sc.source_system, 'manual') = 'manual'
        AND customer_map.id IS NULL
        AND manual_audit.id IS NULL
        AND NOT EXISTS (
            SELECT 1
              FROM placements direct_placement
             WHERE direct_placement.tenant_id = sc.tenant_id
               AND direct_placement.client_id = sc.id
               AND direct_placement.deleted_at IS NULL
               AND NOT EXISTS (
                   SELECT 1
                     FROM external_entity_mappings placement_map
                    WHERE placement_map.tenant_id = direct_placement.tenant_id
                      AND placement_map.source_system = 'jobdiva'
                      AND placement_map.internal_entity_type = 'placement'
                      AND placement_map.internal_entity_id = direct_placement.id
               )
        )";

    $stmt = $pdo->prepare(
        "UPDATE staffing_clients sc
      LEFT JOIN external_entity_mappings customer_map
             ON customer_map.tenant_id = sc.tenant_id
            AND customer_map.internal_entity_type = 'customer'
            AND customer_map.internal_entity_id = sc.id
            AND customer_map.source_system IN ('qbo', 'quickbooks_online', 'zoho', 'zoho_books')
      LEFT JOIN audit_log manual_audit
             ON manual_audit.tenant_id = sc.tenant_id
            AND manual_audit.target_id = sc.id
            AND manual_audit.event IN ('staffing.client.created', 'staffing.client.updated', 'staffing.client.imported')
            SET sc.status = 'inactive', sc.updated_at = NOW()
          WHERE {$unsupported}"
    );
    $stmt->execute(['tenant_id' => $tenantId]);
    $retired = $stmt->rowCount();

    $roleStmt = $pdo->prepare(
        "DELETE cr FROM company_roles cr
           JOIN staffing_clients sc
             ON sc.tenant_id = :tenant_id
            AND sc.company_id = cr.company_id
      LEFT JOIN external_entity_mappings customer_map
             ON customer_map.tenant_id = sc.tenant_id
            AND customer_map.internal_entity_type = 'customer'
            AND customer_map.internal_entity_id = sc.id
            AND customer_map.source_system IN ('qbo', 'quickbooks_online', 'zoho', 'zoho_books')
      LEFT JOIN audit_log manual_audit
             ON manual_audit.tenant_id = sc.tenant_id
            AND manual_audit.target_id = sc.id
            AND manual_audit.event IN ('staffing.client.created', 'staffing.client.updated', 'staffing.client.imported')
          WHERE cr.role = 'client'
            AND sc.status = 'inactive'
            AND COALESCE(sc.source_system, 'manual') = 'manual'
            AND customer_map.id IS NULL
            AND manual_audit.id IS NULL
            AND NOT EXISTS (
                SELECT 1
                  FROM placements direct_placement
                 WHERE direct_placement.tenant_id = sc.tenant_id
                   AND direct_placement.client_id = sc.id
                   AND direct_placement.deleted_at IS NULL
                   AND NOT EXISTS (
                       SELECT 1
                         FROM external_entity_mappings placement_map
                        WHERE placement_map.tenant_id = direct_placement.tenant_id
                          AND placement_map.source_system = 'jobdiva'
                          AND placement_map.internal_entity_type = 'placement'
                          AND placement_map.internal_entity_id = direct_placement.id
                   )
            )"
    );
    $roleStmt->execute(['tenant_id' => $tenantId]);
    return $retired;
}

/** Synthetic names are never valid client identities, even for history. */
function staffingClientRetireJobDivaPlaceholders(int $tenantId): int
{
    $pdo = getDB();
    if (!$pdo) throw new \RuntimeException('No database connection');
    $stmt = $pdo->prepare(
        "UPDATE staffing_clients
            SET status = 'inactive', updated_at = NOW()
          WHERE tenant_id = :tenant_id
            AND status = 'active'
            AND name REGEXP '^JobDiva Company [0-9]+$'"
    );
    $stmt->execute(['tenant_id' => $tenantId]);
    $retired = $stmt->rowCount();

    $pdo->prepare(
        "DELETE cr FROM company_roles cr
           JOIN staffing_clients sc
             ON sc.tenant_id = :tenant_id
            AND sc.company_id = cr.company_id
          WHERE cr.role = 'client'
            AND sc.status = 'inactive'
            AND sc.name REGEXP '^JobDiva Company [0-9]+$'"
    )->execute(['tenant_id' => $tenantId]);
    return $retired;
}

/**
 * QuickBooks Customer includes both top-level customers and sub-customers or
 * jobs. Keep sub-customer mappings for accounting history, but do not present
 * them as separate active end clients unless a placement independently uses
 * that exact canonical company.
 */
function staffingClientRetireQboSubcustomers(int $tenantId): int
{
    $pdo = getDB();
    if (!$pdo) throw new \RuntimeException('No database connection');
    $stmt = $pdo->prepare(
        "UPDATE staffing_clients sc
           JOIN external_entity_mappings customer_map
             ON customer_map.tenant_id = sc.tenant_id
            AND customer_map.internal_entity_type = 'customer'
            AND customer_map.internal_entity_id = sc.id
            AND customer_map.source_system IN ('qbo', 'quickbooks_online')
      LEFT JOIN placements p
             ON p.tenant_id = sc.tenant_id
            AND p.client_id = sc.id
            AND p.deleted_at IS NULL
      LEFT JOIN audit_log manual_audit
             ON manual_audit.tenant_id = sc.tenant_id
            AND manual_audit.target_id = sc.id
            AND manual_audit.event IN ('staffing.client.created', 'staffing.client.updated')
            SET sc.status = 'inactive', sc.updated_at = NOW()
          WHERE sc.tenant_id = :tenant_id
            AND sc.status = 'active'
            AND p.id IS NULL
            AND manual_audit.id IS NULL
            AND JSON_VALID(customer_map.payload_snapshot)
            AND (
                 COALESCE(JSON_UNQUOTE(JSON_EXTRACT(customer_map.payload_snapshot, '$.ParentRef.value')), '') <> ''
              OR LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(customer_map.payload_snapshot, '$.Job')), 'false')) = 'true'
            )"
    );
    $stmt->execute(['tenant_id' => $tenantId]);
    return $stmt->rowCount();
}

/**
 * Restore a real client consumer row when an active placement would otherwise
 * point at an inactive client. Only durable business provenance can reactivate
 * a row; synthetic JobDiva placeholders remain ineligible.
 *
 * @return array{companies_checked:int,clients_reactivated:int,unresolved_company_ids:array<int>}
 */
function staffingClientReactivateProvenActivePlacementClients(int $tenantId): array
{
    $pdo = getDB();
    if (!$pdo) throw new \RuntimeException('No database connection');

    $companyStmt = $pdo->prepare(
        "SELECT billing_client.company_id AS company_id,
                MIN(COALESCE(p.client_id, 0)) AS preferred_client_id
           FROM placements p
           JOIN staffing_clients billing_client ON billing_client.tenant_id = p.tenant_id AND billing_client.id = p.client_id
          WHERE p.tenant_id = :tenant_id
            AND p.status = 'active'
            AND p.deleted_at IS NULL
            AND billing_client.company_id IS NOT NULL
            AND NOT EXISTS (
                SELECT 1
                  FROM staffing_clients active_client
                 WHERE active_client.tenant_id = p.tenant_id
                   AND active_client.company_id = billing_client.company_id
                   AND active_client.status = 'active'
            )
       GROUP BY billing_client.company_id
       ORDER BY billing_client.company_id"
    );
    $companyStmt->execute(['tenant_id' => $tenantId]);
    $companies = $companyStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $candidateStmt = $pdo->prepare(
        "SELECT sc.id, sc.company_id
           FROM staffing_clients sc
          WHERE sc.tenant_id = :tenant_id
            AND sc.company_id = :company_id
            AND sc.status <> 'active'
            AND sc.name NOT REGEXP '^JobDiva Company [0-9]+$'
            AND (
                 COALESCE(NULLIF(TRIM(sc.source_system), ''), 'manual') <> 'manual'
              OR EXISTS (
                    SELECT 1
                      FROM external_entity_mappings customer_map
                     WHERE customer_map.tenant_id = sc.tenant_id
                       AND customer_map.internal_entity_type = 'customer'
                       AND customer_map.internal_entity_id = sc.id
                       AND customer_map.source_system IN ('qbo', 'quickbooks_online', 'zoho', 'zoho_books')
                 )
              OR EXISTS (
                    SELECT 1
                      FROM audit_log manual_audit
                     WHERE manual_audit.tenant_id = sc.tenant_id
                       AND manual_audit.target_id = sc.id
                       AND manual_audit.event IN ('staffing.client.created', 'staffing.client.updated', 'staffing.client.imported')
                 )
              OR EXISTS (
                    SELECT 1
                      FROM placements direct_placement
                     WHERE direct_placement.tenant_id = sc.tenant_id
                       AND direct_placement.client_id = sc.id
                       AND direct_placement.deleted_at IS NULL
                       AND NOT EXISTS (
                           SELECT 1
                             FROM external_entity_mappings placement_map
                            WHERE placement_map.tenant_id = direct_placement.tenant_id
                              AND placement_map.source_system = 'jobdiva'
                              AND placement_map.internal_entity_type = 'placement'
                              AND placement_map.internal_entity_id = direct_placement.id
                       )
                 )
            )
       ORDER BY CASE WHEN sc.id = :preferred_client_id THEN 0 ELSE 1 END,
                CASE WHEN COALESCE(NULLIF(TRIM(sc.source_system), ''), 'manual') <> 'manual' THEN 0 ELSE 1 END,
                sc.id ASC
          LIMIT 1"
    );
    $activateStmt = $pdo->prepare(
        "UPDATE staffing_clients
            SET status = 'active', updated_at = NOW()
          WHERE tenant_id = :tenant_id
            AND id = :id
            AND status <> 'active'"
    );

    $reactivated = 0;
    $unresolved = [];
    foreach ($companies as $company) {
        $companyId = (int) ($company['company_id'] ?? 0);
        if ($companyId <= 0) continue;
        $candidateStmt->execute([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'preferred_client_id' => (int) ($company['preferred_client_id'] ?? 0),
        ]);
        $candidate = $candidateStmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$candidate) {
            $unresolved[] = $companyId;
            continue;
        }
        $activateStmt->execute(['tenant_id' => $tenantId, 'id' => (int) $candidate['id']]);
        $reactivated += $activateStmt->rowCount();
        companiesAddRole($companyId, 'client');
    }

    return [
        'companies_checked' => count($companies),
        'clients_reactivated' => $reactivated,
        'unresolved_company_ids' => $unresolved,
    ];
}

/** @return array<int,array<string,mixed>> */
function staffingClientActivePlacementClientIssues(int $tenantId, int $limit = 25): array
{
    $limit = max(1, min(100, $limit));
    return staffingClientCatalogQuery(
        $tenantId,
        "SELECT p.id AS placement_id,
                p.external_id,
                p.end_client_company_id,
                p.end_client_name,
                p.client_id,
                sc.name AS client_name,
                sc.status AS client_status,
                sc.source_system AS client_source_system
           FROM placements p
      LEFT JOIN staffing_clients sc
             ON sc.tenant_id = p.tenant_id
            AND sc.id = p.client_id
          WHERE p.tenant_id = :tenant_id
            AND p.status = 'active'
            AND p.deleted_at IS NULL
            AND (sc.id IS NULL OR sc.status <> 'active')
       ORDER BY p.id
          LIMIT {$limit}"
    );
}

function staffingClientCatalogIntegritySummary(int $tenantId): array
{
    $pdo = getDB();
    if (!$pdo) throw new \RuntimeException('No database connection');
    $queries = [
        'active_clients' => "SELECT COUNT(*) FROM staffing_clients WHERE tenant_id = :t AND status = 'active'",
        'canonical_companies' => 'SELECT COUNT(DISTINCT sc.company_id) FROM placements p JOIN staffing_clients sc ON sc.tenant_id = p.tenant_id AND sc.id = p.client_id WHERE p.tenant_id = :t AND p.deleted_at IS NULL',
        'client_company_mismatches' => 'SELECT COUNT(*)
             FROM placements p
        LEFT JOIN staffing_clients sc ON sc.tenant_id = p.tenant_id AND sc.id = p.client_id
            WHERE p.tenant_id = :t AND p.deleted_at IS NULL
              AND (sc.id IS NULL OR sc.company_id IS NULL OR NOT EXISTS (
                  SELECT 1 FROM companies bc WHERE bc.id = sc.company_id
                    AND bc.tenant_id = sc.tenant_id AND bc.deleted_at IS NULL
              ))',
        'active_jobdiva_placeholders' => "SELECT COUNT(*) FROM staffing_clients WHERE tenant_id = :t AND status = 'active' AND name REGEXP '^JobDiva Company [0-9]+$'",
        'active_unproven_clients' => "SELECT COUNT(*)
             FROM staffing_clients sc
        LEFT JOIN external_entity_mappings customer_map
               ON customer_map.tenant_id = sc.tenant_id
              AND customer_map.internal_entity_type = 'customer'
              AND customer_map.internal_entity_id = sc.id
              AND customer_map.source_system IN ('qbo', 'quickbooks_online', 'zoho', 'zoho_books')
        LEFT JOIN audit_log manual_audit
               ON manual_audit.tenant_id = sc.tenant_id
              AND manual_audit.target_id = sc.id
              AND manual_audit.event IN ('staffing.client.created', 'staffing.client.updated', 'staffing.client.imported')
            WHERE sc.tenant_id = :t
              AND sc.status = 'active'
              AND COALESCE(sc.source_system, 'manual') = 'manual'
              AND customer_map.id IS NULL
              AND manual_audit.id IS NULL
              AND NOT EXISTS (
                  SELECT 1
                    FROM placements direct_placement
                   WHERE direct_placement.tenant_id = sc.tenant_id
                     AND direct_placement.client_id = sc.id
                     AND direct_placement.deleted_at IS NULL
                     AND NOT EXISTS (
                         SELECT 1
                           FROM external_entity_mappings placement_map
                          WHERE placement_map.tenant_id = direct_placement.tenant_id
                            AND placement_map.source_system = 'jobdiva'
                            AND placement_map.internal_entity_type = 'placement'
                            AND placement_map.internal_entity_id = direct_placement.id
                     )
              )",
        'active_placements_with_inactive_client' => "SELECT COUNT(*)
             FROM placements p
             JOIN staffing_clients sc ON sc.tenant_id = p.tenant_id AND sc.id = p.client_id
            WHERE p.tenant_id = :t
              AND p.status = 'active'
              AND p.deleted_at IS NULL
              AND sc.status <> 'active'",
    ];
    $summary = [];
    foreach ($queries as $key => $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['t' => $tenantId]);
        $summary[$key] = (int) $stmt->fetchColumn();
    }
    return $summary;
}

function staffingClientCatalogUpdate(int $tenantId, int $clientId, array $patch): int
{
    $pdo = getDB();
    if (!$pdo) throw new \RuntimeException('No database connection');

    unset($patch['id'], $patch['tenant_id'], $patch['created_at']);
    if (!$patch) return 0;
    $patch['updated_at'] = $patch['updated_at'] ?? date('Y-m-d H:i:s');

    $sets = [];
    $params = ['tenant_id' => $tenantId, 'id' => $clientId];
    foreach ($patch as $column => $value) {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', (string) $column)) {
            throw new \InvalidArgumentException("Invalid client column: {$column}");
        }
        $sets[] = "`{$column}` = :{$column}";
        $params[$column] = $value;
    }
    $stmt = $pdo->prepare(
        'UPDATE staffing_clients SET ' . implode(', ', $sets) . ' WHERE tenant_id = :tenant_id AND id = :id'
    );
    $stmt->execute($params);
    return $stmt->rowCount();
}

/**
 * Ensure the staffing client consumer row exists for a canonical company.
 *
 * People/Companies owns organization identity. Staffing consumes that graph
 * through staffing_clients so staffing-specific fields and reports can remain
 * module-owned without creating a second client universe.
 *
 * @return array{client_id:int, company_id:int|null, name:string}
 */
function staffingClientEnsureForCompany(int $tenantId, ?int $companyId, string $name, array $extra = []): array
{
    require_once __DIR__.'/client_source.php';
    return staffingClientProjectSource($tenantId,$companyId,$name,$extra);
}
