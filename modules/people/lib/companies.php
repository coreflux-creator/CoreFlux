<?php
/**
 * Companies directory — first-class tenant-scoped company records.
 *
 * Other modules reference these by FK instead of free-text:
 *   - placements.end_client_company_id
 *   - placement_client_chain.company_id
 *   - placement_referrals.referrer_company_id
 *
 * Long-term unification target for ap_vendors_index / billing customer data.
 *
 * SPEC reference: cross-module discussion 2026-02 — placements vendors/clients
 * "come from data elsewhere in the platform"; this is the source of truth.
 */

require_once __DIR__ . '/../../../core/tenant_scope.php';
require_once __DIR__ . '/../../../core/encryption.php';

const COMPANY_ROLES = ['client','customer','vendor','msp','prime_vendor','sub_vendor','referrer','partner'];

function companiesGet(int $id): ?array
{
    $row = scopedFind('SELECT * FROM companies WHERE tenant_id = :tenant_id AND id = :id AND deleted_at IS NULL', ['id' => $id]);
    if (!$row) return null;
    unset($row['ein_full_ct']);
    if (!empty($row['tags_json'])) {
        $row['tags'] = json_decode($row['tags_json'], true) ?: [];
    } else {
        $row['tags'] = [];
    }
    $row['roles']     = companyRoles($id);
    $row['contacts']  = companyContacts($id);
    $row['addresses'] = companyAddresses($id);
    return $row;
}

function companyAddresses(int $companyId): array
{
    $stmt = getDB()->prepare(
        'SELECT * FROM company_addresses WHERE company_id = :id
         ORDER BY is_primary DESC, kind ASC, id ASC'
    );
    $stmt->execute(['id' => $companyId]);
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
}

function companyRoles(int $companyId): array
{
    $stmt = getDB()->prepare('SELECT role FROM company_roles WHERE company_id = :id ORDER BY granted_at');
    $stmt->execute(['id' => $companyId]);
    return array_map(fn ($r) => $r['role'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
}

function companyContacts(int $companyId): array
{
    $stmt = getDB()->prepare('SELECT * FROM company_contacts WHERE company_id = :id ORDER BY is_primary DESC, name ASC');
    $stmt->execute(['id' => $companyId]);
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
}

/**
 * List companies. Filters: q (typeahead), role, has_msa.
 * Always excludes soft-deleted.
 */
function companiesList(array $filters = []): array
{
    $where = ['c.tenant_id = :tenant_id', 'c.deleted_at IS NULL'];
    $params = [];

    if (!empty($filters['q'])) {
        $where[] = '(c.name LIKE :q OR c.legal_name LIKE :q)';
        $params['q'] = '%' . str_replace(['%','_'], ['\\%','\\_'], $filters['q']) . '%';
    }
    if (!empty($filters['role'])) {
        $where[] = 'EXISTS (SELECT 1 FROM company_roles cr WHERE cr.company_id = c.id AND cr.role = :role)';
        $params['role'] = $filters['role'];
    }

    $perPage = max(1, min(200, (int) ($filters['per_page'] ?? 50)));
    $page    = max(1, (int) ($filters['page'] ?? 1));
    $offset  = ($page - 1) * $perPage;

    $rows = scopedQuery(
        'SELECT c.id, c.name, c.legal_name, c.city, c.state, c.country,
                c.primary_contact_name, c.primary_contact_email, c.use_count, c.last_used_at,
                (SELECT GROUP_CONCAT(role) FROM company_roles cr WHERE cr.company_id = c.id) AS roles_csv
         FROM companies c
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY c.name ASC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
        $params
    );
    foreach ($rows as &$r) $r['roles'] = $r['roles_csv'] ? explode(',', $r['roles_csv']) : [];
    unset($r);

    $cnt = scopedQuery('SELECT COUNT(*) AS c FROM companies c WHERE ' . implode(' AND ', $where), $params);
    return ['rows' => $rows, 'total' => (int) ($cnt[0]['c'] ?? 0), 'page' => $page, 'per_page' => $perPage];
}

/**
 * Idempotent upsert by (tenant_id, name). Useful for "create-on-the-fly"
 * from PlacementCreate when a typeahead returns no match. Returns the id.
 */
function companiesUpsertByName(int $tenantId, string $name, array $extra = [], array $rolesToEnsure = []): int
{
    $name = trim($name);
    if ($name === '') throw new \InvalidArgumentException('company name required');

    $pdo = getDB();
    $find = $pdo->prepare('SELECT id FROM companies WHERE tenant_id = :t AND name = :n AND deleted_at IS NULL');
    $find->execute(['t' => $tenantId, 'n' => $name]);
    $id = (int) $find->fetchColumn();

    if (!$id) {
        $insert = array_merge([
            'tenant_id'    => $tenantId,
            'name'         => $name,
            'country'      => 'US',
        ], array_intersect_key($extra, array_flip([
            'legal_name','website','phone','primary_contact_name','primary_contact_email','primary_contact_phone',
            'address_line1','address_line2','city','state','postal_code','country','notes','created_by_user_id',
        ])));
        $id = scopedInsert('companies', $insert);
    }

    foreach ($rolesToEnsure as $role) {
        if (!in_array($role, COMPANY_ROLES, true)) continue;
        $pdo->prepare('INSERT IGNORE INTO company_roles (company_id, role) VALUES (:c, :r)')
            ->execute(['c' => $id, 'r' => $role]);
    }
    return $id;
}

function companiesAddRole(int $companyId, string $role): void
{
    if (!in_array($role, COMPANY_ROLES, true)) {
        throw new \InvalidArgumentException("invalid role: {$role}");
    }
    getDB()->prepare('INSERT IGNORE INTO company_roles (company_id, role) VALUES (:c, :r)')
        ->execute(['c' => $companyId, 'r' => $role]);
}

function companiesRemoveRole(int $companyId, string $role): void
{
    getDB()->prepare('DELETE FROM company_roles WHERE company_id = :c AND role = :r')
        ->execute(['c' => $companyId, 'r' => $role]);
}

function companiesBumpUsage(int $companyId): void
{
    getDB()->prepare('UPDATE companies SET use_count = use_count + 1, last_used_at = NOW() WHERE id = :id')
        ->execute(['id' => $companyId]);
}

function companiesAudit(string $event, array $meta = [], ?int $targetId = null): void
{
    try {
        $ctx  = function_exists('currentTenantContext') ? currentTenantContext() : null;
        getDB()->prepare(
            'INSERT INTO audit_log (tenant_id, actor_user_id, event, target_id, meta_json, ip_address, created_at)
             VALUES (:tenant_id, :actor, :event, :target_id, :meta_json, :ip, NOW())'
        )->execute([
            'tenant_id' => $ctx['tenant_id'] ?? null,
            'actor'     => $ctx['user']['id'] ?? null,
            'event'     => $event,
            'target_id' => $targetId,
            'meta_json' => json_encode($meta),
            'ip'        => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (\Throwable $e) {
        error_log('[companies.audit] ' . $event . ' write-failed: ' . $e->getMessage());
    }
}
