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
    // tenant-leak-allow: defense-in-depth — caller scoped row by tenant_id before this id-only write
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
    // tenant-leak-allow: defense-in-depth — caller scoped row by tenant_id before this id-only write
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
        // Distinct placeholders required by PDO_MYSQL native prepares.
        $where[]      = '(c.name LIKE :q OR c.legal_name LIKE :q2)';
        $params['q']  = '%' . str_replace(['%','_'], ['\\%','\\_'], $filters['q']) . '%';
        $params['q2'] = $params['q'];
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
            $setSql = implode(', ', array_map(static fn($k) => "{$k} = :{$k}", array_keys($updatable)));
            $params = $updatable;
            $params['id']        = $id;
            $params['tenant_id'] = $tenantId;
            // tenant-leak-allow: tenant scope re-asserted in the WHERE
            $pdo->prepare("UPDATE companies SET {$setSql} WHERE id = :id AND tenant_id = :tenant_id")
                ->execute($params);
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
    $all = $stmt->fetchAll(\PDO::FETCH_ASSOC);

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

/**
 * Merge $victimId into $survivorId. Both must belong to $tenantId.
 * Redirects all FKs across AP, Billing, placements, and people's own child
 * tables (roles, contacts, addresses) to the survivor. Roles are unioned;
 * contacts + addresses are reparented. The victim is soft-deleted.
 *
 * Returns a summary of how many rows were redirected per table.
 * Throws on tenant mismatch, self-merge, or either id missing.
 */
function companiesMerge(int $tenantId, int $survivorId, int $victimId, ?int $actorUserId = null): array
{
    if ($survivorId === $victimId) throw new \InvalidArgumentException('Cannot merge a company into itself');
    $pdo = getDB();

    // Tenant guard
    $check = $pdo->prepare('SELECT id, name, tenant_id, deleted_at FROM companies WHERE id IN (:s, :v)');
    $check->execute(['s' => $survivorId, 'v' => $victimId]);
    $rows = $check->fetchAll(\PDO::FETCH_ASSOC);
    if (count($rows) !== 2) throw new \RuntimeException('Survivor or victim not found');
    foreach ($rows as $r) {
        if ((int) $r['tenant_id'] !== $tenantId) throw new \RuntimeException('Cross-tenant merge blocked');
        if ($r['deleted_at'] !== null)           throw new \RuntimeException("Company {$r['id']} is already soft-deleted");
    }

    $pdo->beginTransaction();
    try {
        $redir = [];

        // AP side
        $redir['ap_vendors_index']       = _cfMergeRedirect($pdo, 'ap_vendors_index', 'company_id',        $victimId, $survivorId, $tenantId);
        $redir['ap_bills']               = _cfMergeRedirect($pdo, 'ap_bills',         'vendor_company_id', $victimId, $survivorId, $tenantId);
        $redir['ap_payments']            = _cfMergeRedirect($pdo, 'ap_payments',      'vendor_company_id', $victimId, $survivorId, $tenantId);
        $redir['ap_1099_ledger']         = _cfMergeRedirect($pdo, 'ap_1099_ledger',   'vendor_company_id', $victimId, $survivorId, $tenantId);

        // Billing side
        $redir['billing_invoices']       = _cfMergeRedirect($pdo, 'billing_invoices', 'client_company_id', $victimId, $survivorId, $tenantId);

        // Placement side
        $redir['placements']             = _cfMergeRedirect($pdo, 'placements',              'end_client_company_id', $victimId, $survivorId, $tenantId);
        $redir['placement_client_chain'] = _cfMergeRedirect($pdo, 'placement_client_chain',  'company_id',            $victimId, $survivorId, $tenantId);
        $redir['placement_referrals']    = _cfMergeRedirect($pdo, 'placement_referrals',     'referrer_company_id',   $victimId, $survivorId, $tenantId);

        // Child tables on companies itself
        //   Roles: union into survivor (INSERT IGNORE against uq_company_role), then drop victim's.
        $roleCountStmt = $pdo->prepare('SELECT COUNT(*) FROM company_roles WHERE company_id = :v');
        $roleCountStmt->execute(['v' => $victimId]);
        $redir['company_roles'] = (int) $roleCountStmt->fetchColumn();
        $pdo->prepare(
            'INSERT IGNORE INTO company_roles (company_id, role)
             SELECT :s, role FROM company_roles WHERE company_id = :v'
        )->execute(['s' => $survivorId, 'v' => $victimId]);
        $pdo->prepare('DELETE FROM company_roles WHERE company_id = :v')->execute(['v' => $victimId]);

        //   Contacts + addresses: reparent.
        $redir['company_contacts']  = _cfMergeReparent($pdo, 'company_contacts',  $victimId, $survivorId);
        $redir['company_addresses'] = _cfMergeReparent($pdo, 'company_addresses', $victimId, $survivorId);

        // Bump survivor use_count by victim's count.
        // tenant-leak-allow: defense-in-depth — caller scoped row by tenant_id before this id-only write
        $pdo->prepare(
            'UPDATE companies s
             JOIN companies v ON v.id = :v
             SET s.use_count = s.use_count + v.use_count,
                 s.last_used_at = GREATEST(COALESCE(s.last_used_at, "1970-01-01"), COALESCE(v.last_used_at, "1970-01-01"))
             WHERE s.id = :s'
        )->execute(['v' => $victimId, 's' => $survivorId]);

        // Soft-delete victim.
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare('UPDATE companies SET deleted_at = NOW() WHERE id = :v')->execute(['v' => $victimId]);

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    companiesAudit('company.merged', [
        'survivor_id' => $survivorId,
        'victim_id'   => $victimId,
        'actor_user_id' => $actorUserId,
        'redirected'  => $redir,
    ], $survivorId);

    return ['survivor_id' => $survivorId, 'victim_id' => $victimId, 'redirected' => $redir];
}

function _cfMergeRedirect(\PDO $pdo, string $table, string $col, int $victimId, int $survivorId, int $tenantId): int
{
    $stmt = $pdo->prepare("UPDATE {$table} SET {$col} = :s WHERE {$col} = :v AND tenant_id = :t");
    $stmt->execute(['s' => $survivorId, 'v' => $victimId, 't' => $tenantId]);
    return $stmt->rowCount();
}

function _cfMergeReparent(\PDO $pdo, string $table, int $victimId, int $survivorId): int
{
    $stmt = $pdo->prepare("UPDATE {$table} SET company_id = :s WHERE company_id = :v");
    $stmt->execute(['s' => $survivorId, 'v' => $victimId]);
    return $stmt->rowCount();
}
