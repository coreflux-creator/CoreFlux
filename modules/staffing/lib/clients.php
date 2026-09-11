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
        'SELECT * FROM staffing_clients WHERE tenant_id = :tenant_id AND company_id = :company_id LIMIT 1',
        ['company_id' => $companyId]
    );
}

/**
 * Reassert the placement -> client consumer link from canonical company
 * identity. `placements.client_id` is a convenience pointer; it must never
 * disagree with `placements.end_client_company_id`.
 */
function staffingClientRelinkCanonicalPlacements(int $tenantId): int
{
    $pdo = getDB();
    if (!$pdo) throw new \RuntimeException('No database connection');
    $stmt = $pdo->prepare(
        'UPDATE placements p
           JOIN staffing_clients canonical
             ON canonical.tenant_id = p.tenant_id
            AND canonical.company_id = p.end_client_company_id
      LEFT JOIN staffing_clients current_client
             ON current_client.tenant_id = p.tenant_id
            AND current_client.id = p.client_id
            SET p.client_id = canonical.id,
                p.end_client_name = canonical.name,
                p.updated_at = NOW()
          WHERE p.tenant_id = :tenant_id
            AND p.deleted_at IS NULL
            AND p.end_client_company_id IS NOT NULL
            AND (
                 p.client_id IS NULL
              OR current_client.id IS NULL
              OR current_client.company_id IS NULL
              OR current_client.company_id <> p.end_client_company_id
            )'
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
            AND p.end_client_company_id = sc.company_id
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
            AND p.end_client_company_id = sc.company_id
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
            AND p.end_client_company_id = sc.company_id
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

function staffingClientCatalogIntegritySummary(int $tenantId): array
{
    $pdo = getDB();
    if (!$pdo) throw new \RuntimeException('No database connection');
    $queries = [
        'active_clients' => "SELECT COUNT(*) FROM staffing_clients WHERE tenant_id = :t AND status = 'active'",
        'canonical_companies' => 'SELECT COUNT(DISTINCT end_client_company_id) FROM placements WHERE tenant_id = :t AND deleted_at IS NULL AND end_client_company_id IS NOT NULL',
        'client_company_mismatches' => 'SELECT COUNT(*)
             FROM placements p
        LEFT JOIN staffing_clients sc ON sc.tenant_id = p.tenant_id AND sc.id = p.client_id
            WHERE p.tenant_id = :t AND p.deleted_at IS NULL AND p.end_client_company_id IS NOT NULL
              AND (sc.id IS NULL OR sc.company_id IS NULL OR sc.company_id <> p.end_client_company_id)',
        'active_jobdiva_placeholders' => "SELECT COUNT(*) FROM staffing_clients WHERE tenant_id = :t AND status = 'active' AND name REGEXP '^JobDiva Company [0-9]+$'",
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
    $name = trim($name);
    if ($name === '') {
        throw new \InvalidArgumentException('client name required');
    }

    $pdo = getDB();
    if (!$pdo) throw new \RuntimeException('No database connection');

    if ($companyId && !empty($extra['sync_company_patch'])) {
        staffingClientApplyCompanyPatch($tenantId, (int) $companyId, array_merge($extra, ['name' => $name]));
    }

    $company = null;
    if ($companyId && $companyId > 0) {
        $stmt = $pdo->prepare('SELECT id, name, legal_name, industry, primary_contact_name, primary_contact_email, primary_contact_phone,
                                      address_line1, address_line2, city, state, postal_code, country
                                 FROM companies
                                WHERE tenant_id = :t AND id = :id AND deleted_at IS NULL
                                LIMIT 1');
        $stmt->execute(['t' => $tenantId, 'id' => $companyId]);
        $company = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    if (!$company) {
        $companyPatch = [
            'legal_name'             => $extra['legal_name'] ?? null,
            'primary_contact_name'   => $extra['primary_contact_name'] ?? null,
            'primary_contact_email'  => $extra['primary_contact_email'] ?? null,
            'primary_contact_phone'  => $extra['primary_contact_phone'] ?? null,
            'address_line1'          => $extra['billing_address_line1'] ?? null,
            'address_line2'          => $extra['billing_address_line2'] ?? null,
            'city'                   => $extra['billing_city'] ?? null,
            'state'                  => $extra['billing_state'] ?? null,
            'postal_code'            => $extra['billing_postal_code'] ?? null,
            'country'                => $extra['billing_country'] ?? null,
            'created_by_user_id'     => $extra['created_by_user_id'] ?? null,
        ];
        $companyId = companiesUpsertByName($tenantId, $name, $companyPatch, ['client']);
        $stmt = $pdo->prepare('SELECT id, name, legal_name, industry, primary_contact_name, primary_contact_email, primary_contact_phone,
                                      address_line1, address_line2, city, state, postal_code, country
                                 FROM companies
                                WHERE tenant_id = :t AND id = :id AND deleted_at IS NULL
                                LIMIT 1');
        $stmt->execute(['t' => $tenantId, 'id' => $companyId]);
        $company = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    } else {
        companiesAddRole((int) $company['id'], 'client');
    }

    $companyId = $company ? (int) $company['id'] : null;
    $name = trim((string) ($company['name'] ?? $name));

    $existing = null;
    if ($companyId) {
        $stmt = $pdo->prepare('SELECT * FROM staffing_clients WHERE tenant_id = :t AND company_id = :cid LIMIT 1');
        $stmt->execute(['t' => $tenantId, 'cid' => $companyId]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
    if (!$existing) {
        $stmt = $pdo->prepare('SELECT * FROM staffing_clients WHERE tenant_id = :t AND name = :n LIMIT 1');
        $stmt->execute(['t' => $tenantId, 'n' => $name]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    $payload = [
        'company_id'             => $companyId,
        'name'                   => $name,
        'legal_name'             => $company['legal_name'] ?? ($extra['legal_name'] ?? null),
        'industry'               => $company['industry'] ?? ($extra['industry'] ?? null),
        'primary_contact_name'   => $company['primary_contact_name'] ?? ($extra['primary_contact_name'] ?? null),
        'primary_contact_email'  => $company['primary_contact_email'] ?? ($extra['primary_contact_email'] ?? null),
        'primary_contact_phone'  => $company['primary_contact_phone'] ?? ($extra['primary_contact_phone'] ?? null),
        'billing_address_line1'  => $company['address_line1'] ?? ($extra['billing_address_line1'] ?? null),
        'billing_address_line2'  => $company['address_line2'] ?? ($extra['billing_address_line2'] ?? null),
        'billing_city'           => $company['city'] ?? ($extra['billing_city'] ?? null),
        'billing_state'          => $company['state'] ?? ($extra['billing_state'] ?? null),
        'billing_postal_code'    => $company['postal_code'] ?? ($extra['billing_postal_code'] ?? null),
        'billing_country'        => $company['country'] ?? ($extra['billing_country'] ?? 'US'),
        'payment_terms_days'     => array_key_exists('payment_terms_days', $extra)
            ? (int) $extra['payment_terms_days']
            : ($existing ? null : 30),
        'status'                 => array_key_exists('status', $extra)
            ? $extra['status']
            : ($existing ? null : 'active'),
    ];

    if ($existing) {
        $patch = [];
        foreach ($payload as $key => $value) {
            if ($value === null || $value === '') continue;
            if (!array_key_exists($key, $existing) || (string) ($existing[$key] ?? '') !== (string) $value) {
                $patch[$key] = $value;
            }
        }
        if ($patch) {
            $sets = [];
            $params = ['tenant_id' => $tenantId, 'id' => (int) $existing['id']];
            foreach ($patch as $key => $value) {
                $sets[] = "`{$key}` = :{$key}";
                $params[$key] = $value;
            }
            $params['updated_at'] = date('Y-m-d H:i:s');
            $sets[] = 'updated_at = :updated_at';
            $pdo->prepare(
                'UPDATE staffing_clients SET ' . implode(', ', $sets) . ' WHERE tenant_id = :tenant_id AND id = :id'
            )->execute($params);
        }
        return ['client_id' => (int) $existing['id'], 'company_id' => $companyId, 'name' => $name];
    }

    $payload = array_filter($payload, static fn($v) => $v !== null);
    $payload['tenant_id'] = $tenantId;
    $payload['created_at'] = date('Y-m-d H:i:s');
    $cols = array_keys($payload);
    $placeholders = array_map(static fn($c) => ":{$c}", $cols);
    $pdo->prepare(
        'INSERT INTO staffing_clients (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', $placeholders) . ')'
    )->execute($payload);
    $clientId = (int) $pdo->lastInsertId();
    return ['client_id' => $clientId, 'company_id' => $companyId, 'name' => $name];
}

function staffingClientApplyCompanyPatch(int $tenantId, int $companyId, array $patch): void
{
    if ($companyId <= 0) return;
    $map = [
        'name' => 'name',
        'legal_name' => 'legal_name',
        'industry' => 'industry',
        'primary_contact_name' => 'primary_contact_name',
        'primary_contact_email' => 'primary_contact_email',
        'primary_contact_phone' => 'primary_contact_phone',
        'billing_address_line1' => 'address_line1',
        'billing_address_line2' => 'address_line2',
        'billing_city' => 'city',
        'billing_state' => 'state',
        'billing_postal_code' => 'postal_code',
        'billing_country' => 'country',
    ];
    $sets = [];
    $params = ['tenant_id' => $tenantId, 'id' => $companyId];
    foreach ($map as $source => $column) {
        if (!array_key_exists($source, $patch)) continue;
        $value = $patch[$source];
        if ($value === null || $value === '') continue;
        $sets[] = "`{$column}` = :{$column}";
        $params[$column] = $value;
    }
    if (!$sets) return;
    getDB()->prepare('UPDATE companies SET ' . implode(', ', $sets) . ' WHERE tenant_id = :tenant_id AND id = :id')
        ->execute($params);
}
