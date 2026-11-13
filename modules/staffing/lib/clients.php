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
