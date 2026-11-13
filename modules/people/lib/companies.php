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
require_once __DIR__ . '/../../../core/audit.php';
require_once __DIR__ . '/../../../core/record_numbers.php';

const COMPANY_ROLES = ['client','customer','vendor','msp','prime_vendor','sub_vendor','referrer','partner'];

/** Resolve Companies independently without redirecting the caller's other data. */
function companiesDirectoryTenantId(?int $workspaceId = null): int
{
    require_once __DIR__ . '/../../../core/sub_tenants.php';
    $active = $workspaceId ?? currentTenantId();
    if (!$active || $active <= 0) throw new RuntimeException('An active company workspace is required.');
    $owner = effectiveTenantIdForModule('companies', $active);
    if (!$owner) throw new RuntimeException('The company directory is unavailable.');
    return $owner;
}

/** Company endpoints live under People URLs, but their catalog has its own owner. */
function companiesUseDirectoryScope(): int
{
    $owner = companiesDirectoryTenantId();
    // Keep the authenticated workspace for permissions and audit. Only data
    // access uses the configured Companies directory, independently of People.
    setRequestModuleScope('companies');
    return $owner;
}

/** An explicit owner is resolved server-side; never take it from a request body. */
function companiesGet(int $id, ?int $directoryTenantId = null): ?array
{
    $sql = 'SELECT * FROM companies WHERE tenant_id = :tenant_id AND id = :id AND deleted_at IS NULL';
    if ($directoryTenantId === null) {
        $row = scopedFind($sql, ['id' => $id]);
    } else {
        if ($directoryTenantId <= 0) return null;
        $st = getDB()->prepare($sql);
        $st->execute(['tenant_id' => $directoryTenantId, 'id' => $id]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
    }
    if (!$row) return null;
    $row = coreRecordNumbersAttach(getDB(), (int) $row['tenant_id'], 'company', [$row])[0];
    unset($row['ein_full_ct'], $row['coreflux_source_state'], $row['active_name_key']);
    if (!empty($row['tags_json'])) {
        $row['tags'] = json_decode($row['tags_json'], true) ?: [];
    } else {
        $row['tags'] = [];
    }
    $row['roles']     = companyRoles($id);
    $row['contacts']  = companyContacts($id, (int)$row['tenant_id']);
    $row['addresses'] = companyAddresses($id, (int)$row['tenant_id']);
    return $row;
}

function companyAddresses(int $companyId, ?int $directoryTenantId = null): array
{
    $stmt = getDB()->prepare(
        'SELECT a.* FROM company_addresses a JOIN companies c ON c.id=a.company_id AND c.tenant_id=a.tenant_id
         WHERE c.id=:id AND c.tenant_id=:owner AND c.deleted_at IS NULL
         ORDER BY a.is_primary DESC, a.kind ASC, a.id ASC'
    );
    $stmt->execute(['id'=>$companyId,'owner'=>$directoryTenantId??companiesDirectoryTenantId()]);
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
}

function companyRoles(int $companyId): array
{
    $stmt = getDB()->prepare('SELECT role FROM company_roles WHERE company_id = :id ORDER BY granted_at');
    $stmt->execute(['id' => $companyId]);
    return array_map(fn ($r) => $r['role'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
}

function companyContacts(int $companyId, ?int $directoryTenantId = null): array
{
    $stmt = getDB()->prepare('SELECT cc.* FROM company_contacts cc JOIN companies c ON c.id=cc.company_id AND c.tenant_id=cc.tenant_id
        WHERE c.id=:id AND c.tenant_id=:owner AND c.deleted_at IS NULL ORDER BY cc.is_primary DESC, cc.name ASC');
    $stmt->execute(['id'=>$companyId,'owner'=>$directoryTenantId??companiesDirectoryTenantId()]);
    $rows=$stmt->fetchAll(\PDO::FETCH_ASSOC);
    foreach($rows as &$row)unset($row['coreflux_source_state']);
    unset($row);
    return $rows;
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
        // Distinct placeholders required by PDO_MYSQL native prepares.
        $where[]      = '(c.name LIKE :q OR c.legal_name LIKE :q2 OR ' . coreRecordNumberSearchSql('company', 'c', 'qnumber') . ')';
        $params['qnumber'] = preg_replace('/^C-/', 'CO-', strtoupper(trim((string) $filters['q'])));
        $params['q']  = '%' . str_replace(['%','_'], ['\\%','\\_'], $filters['q']) . '%';
        $params['q2'] = $params['q'];
    }
    if (!empty($filters['role'])) {
        $where[] = 'EXISTS (SELECT 1 FROM company_roles cr WHERE cr.company_id = c.id AND cr.role = :role)';
        $params['role'] = $filters['role'];
    } elseif (!empty($filters['roles'])) {
        $requestedRoles = is_array($filters['roles'])
            ? $filters['roles']
            : explode(',', (string) $filters['roles']);
        $requestedRoles = array_values(array_intersect(COMPANY_ROLES, array_map('trim', $requestedRoles)));
        if ($requestedRoles) {
            $roleParams = [];
            foreach ($requestedRoles as $index => $role) {
                $key = 'role_' . $index;
                $roleParams[] = ':' . $key;
                $params[$key] = $role;
            }
            $where[] = 'EXISTS (SELECT 1 FROM company_roles cr WHERE cr.company_id = c.id AND cr.role IN (' . implode(',', $roleParams) . '))';
        }
    }
    if (!empty($filters['status'])) {
        $where[] = 'c.status = :status';
        $params['status'] = $filters['status'];
    }

    $perPage = max(1, min(200, (int) ($filters['per_page'] ?? 50)));
    $page    = max(1, (int) ($filters['page'] ?? 1));
    $offset  = ($page - 1) * $perPage;
    $sortMap = [
        'id'           => 'c.id',
        'name'         => 'c.name',
        'legal_name'   => 'c.legal_name',
        'primary_contact_name' => 'c.primary_contact_name',
        'default_terms'=> 'c.default_terms',
        'city'         => 'c.city',
        'status'       => 'c.status',
        'use_count'    => 'c.use_count',
        'last_used_at' => 'c.last_used_at',
        'created_at'   => 'c.created_at',
    ];
    $sortKey  = (string) ($filters['sort'] ?? 'name');
    $sortExpr = $sortMap[$sortKey] ?? $sortMap['name'];
    $sortDir  = strtolower((string) ($filters['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

    $rows = scopedQuery(
        'SELECT c.id, c.name, c.legal_name, c.status, c.city, c.state, c.country,
                c.default_terms, c.currency, c.industry,
                c.primary_contact_name, c.primary_contact_email, c.use_count, c.last_used_at,
                (SELECT GROUP_CONCAT(role) FROM company_roles cr WHERE cr.company_id = c.id) AS roles_csv
         FROM companies c
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY ' . $sortExpr . ' ' . $sortDir . ', c.id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
        $params
    );
    foreach($rows as &$row)unset($row['coreflux_source_state']);
    unset($row);
    $rows = coreRecordNumbersAttach(getDB(), (int) effectiveTenantIdForRequest(), 'company', $rows);
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

    // Safe-to-write columns from the patch. Extended in slice 5 (2026-02)
    // to cover duns / ein_last4 / msa_signed_at so the JobDiva field-map
    // registry can rewire those at sync time. FKs (id, tenant_id), audit
    // timestamps, deleted_at, ein_full_ct (ciphertext) and the system-
    // managed use_count / last_used_at / msa_storage_object_id remain
    // intentionally excluded.
    $writable = [
        'legal_name','website','phone','duns','ein_last4',
        'primary_contact_name','primary_contact_email','primary_contact_phone',
        'address_line1','address_line2','city','state','postal_code','country',
        'msa_signed_at','notes','created_by_user_id',
    ];
    $patch = array_intersect_key($extra, array_flip($writable));
    // Client bridges may supply an explicit blank billing country. Treat it
    // as absent: preserve an existing country or the new-company US default.
    if (array_key_exists('country', $patch) && trim((string) $patch['country']) === '') {
        unset($patch['country']);
    }

    if (!$id) {
        $insert = array_merge([
            'tenant_id' => $tenantId,
            'name'      => $name,
            'country'   => 'US',
        ], $patch);
        $id = scopedInsert('companies', $insert);
    } else {
        // Re-encounter: update non-null patch fields so JobDiva edits flow
        // through on every delta sync (slice 5). Empty strings count as
        // "not provided" — we don't clobber existing data with blanks.
        // created_by_user_id is set only at insert; drop it from updates.
        unset($patch['created_by_user_id']);
        $updatable = array_filter(
            $patch,
            static fn($v) => $v !== null && $v !== ''
        );
        if (!empty($updatable)) {
            require_once __DIR__.'/company_source_ownership.php';
            companyNativeFields($pdo,(int)(currentTenantId()?:$tenantId),$tenantId,'companies',$id,$updatable,
                isset($extra['created_by_user_id'])?(int)$extra['created_by_user_id']:null);
        }
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
    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    getDB()->prepare('UPDATE companies SET use_count = use_count + 1, last_used_at = NOW() WHERE id = :id')
        ->execute(['id' => $companyId]);
}

function companiesAudit(string $event, array $meta = [], ?int $targetId = null): void
{
    try {
        $ctx  = function_exists('currentTenantContext') ? currentTenantContext() : null;
        platformAuditLogWrite(
            isset($ctx['tenant_id']) ? (int) $ctx['tenant_id'] : null,
            isset($ctx['user']['id']) ? (int) $ctx['user']['id'] : null,
            $event,
            $targetId,
            $meta,
            [
                'source' => 'people',
                'object_type' => 'company',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]
        );
    } catch (\Throwable $e) {
        error_log('[companies.audit] ' . $event . ' write-failed: ' . $e->getMessage());
    }
}

/**
 * Normalise a company name for duplicate detection.
 * Lowercase, strip punctuation, collapse whitespace, strip trailing
 * corporate suffixes (inc, llc, ltd, co, corp, company).
 */
function companiesNormalizeName(string $name): string
{
    $n = strtolower(trim($name));
    $n = preg_replace('/[^a-z0-9 ]+/', ' ', $n);
    $n = preg_replace('/\s+/', ' ', $n);
    $n = preg_replace('/\b(inc|incorporated|llc|l l c|ltd|limited|co|corp|corporation|company)\b\.?$/', '', trim($n));
    return trim($n);
}

/**
 * Return candidate duplicate pairs for the current tenant.
 * Two companies are candidates if companiesNormalizeName() yields the same
 * value OR if SOUNDEX() matches on the bare stem. At most one representative
 * per normalized key is returned.
 *
 * @return array [{normalized, companies: [{id,name,role_count,use_count}]}]
 */
function companiesDuplicateCandidates(int $tenantId, int $limit = 200): array
{
    $stmt = getDB()->prepare(
        'SELECT c.id, c.name, c.use_count, c.last_used_at,
                (SELECT COUNT(*) FROM company_roles cr WHERE cr.company_id = c.id) AS role_count
         FROM companies c
         WHERE c.tenant_id = :tid AND c.deleted_at IS NULL
         ORDER BY c.name ASC
         LIMIT ' . (int) $limit
    );
    $stmt->execute(['tid' => $tenantId]);
    $all = coreRecordNumbersAttach(getDB(),$tenantId,'company',$stmt->fetchAll(\PDO::FETCH_ASSOC));

    $groups = [];
    foreach ($all as $co) {
        $key = companiesNormalizeName((string) $co['name']);
        if ($key === '') continue;
        $groups[$key][] = $co;
    }
    $out = [];
    foreach ($groups as $k => $rows) {
        if (count($rows) < 2) continue;
        $out[] = ['normalized' => $k, 'companies' => $rows];
    }
    return $out;
}

/** Legacy entry point deliberately cannot bypass the reviewed operation. */
function companiesMerge(int $tenantId, int $survivorId, int $victimId, ?int $actorUserId = null): array
{
    throw new RuntimeException('Preview and confirm the company merge before applying it.',409);
}
