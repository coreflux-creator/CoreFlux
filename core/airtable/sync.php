<?php
/**
 * Airtable table-mapping pipeline.
 *
 * Each row in airtable_table_mappings binds one Airtable (base, table)
 * to one CoreFlux internal entity type. The pull worker reads records
 * from Airtable in pages, normalises them per the mapping's field_map,
 * and persists each record into external_entity_mappings under the
 * source_system='airtable' bucket.
 *
 * Public surface:
 *   airtableMappingList(int $tenantId): array
 *   airtableMappingGet(int $tenantId, int $id): ?array
 *   airtableMappingUpsert(int $tenantId, array $payload, ?int $userId): array
 *   airtableMappingDelete(int $tenantId, int $id, ?int $userId): void
 *   airtableSyncTable(int $tenantId, int $mappingId, ?int $userId, int $maxPages=20): array
 */
declare(strict_types=1);

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/../integrations/entity_mappings.php';
require_once __DIR__ . '/../integrations/field_map_apply.php';

const AIRTABLE_INTERNAL_ENTITIES = [
    // The pull worker is generic — it never writes to a core table.
    // It only persists records into external_entity_mappings keyed by
    // (tenant, 'airtable', internal_entity, external_id). Anything that
    // already participates in mapping (or that wants to in the future)
    // can be selected here.
    'company',
    'contact',
    'vendor',
    'customer',
    'placement',
    'note',
    'task',
    'opportunity',
    'generic',
];
const AIRTABLE_MAPPING_DIRECTIONS = ['pull', 'off'];
const AIRTABLE_LINK_STRATEGIES   = ['external_id', 'match_column', 'manual', 'none'];
const AIRTABLE_UNMATCHED_ACTIONS = ['skip', 'park', 'create_stub'];

/**
 * Per-entity default linkage strategy used when the operator hasn't
 * explicitly chosen one on a new mapping. Each entry resolves to a
 * tuple {strategy, internal_table, internal_column, unmatched_action}.
 *
 * Strategy = 'external_id'  → match on the CoreFlux table's
 *                              `external_id` column (canonical natural
 *                              key path; placements + people use this).
 * Strategy = 'match_column' → match on whatever `internal_column`
 *                              points to (e.g. companies.name).
 * Strategy = 'none'         → preserve Slice-1 synthetic behaviour
 *                              (no real linkage; payload stored only).
 *
 * Slice 2 defaults — selected per operator brief:
 *   placement → external_id (placements.external_id)
 *   contact   → match_column on people.email_primary
 *   company   → match_column on companies.name
 *   customer  → match_column on companies.name (same backing table)
 *   vendor    → match_column on ap_vendors_index.vendor_name
 *   others    → none
 */
const AIRTABLE_ENTITY_LINK_DEFAULTS = [
    'placement'  => ['external_id',  'placements',        'external_id',  'park'],
    'contact'    => ['match_column', 'people',            'email_primary','park'],
    'company'    => ['match_column', 'companies',         'name',         'park'],
    'customer'   => ['match_column', 'companies',         'name',         'park'],
    'vendor'     => ['match_column', 'ap_vendors_index',  'vendor_name',  'park'],
    'note'       => ['none',         null,                null,           'park'],
    'task'       => ['none',         null,                null,           'park'],
    'opportunity'=> ['none',         null,                null,           'park'],
    'generic'    => ['none',         null,                null,           'park'],
];

/**
 * Apply per-entity defaults onto a payload if the operator hasn't
 * explicitly set a linkage policy on this mapping yet. Returns the
 * tuple {strategy, table, column, unmatched_action} actually applied.
 * Used by airtableMappingUpsert() and exposed for tests + the relink
 * endpoint.
 */
function airtableResolveLinkDefaults(string $entity, array $payload): array
{
    $explicitStrategy = trim((string) ($payload['link_strategy'] ?? ''));
    if ($explicitStrategy !== '') {
        return [
            'strategy'          => $explicitStrategy,
            'match_at_field'    => trim((string) ($payload['link_match_airtable_field'] ?? '')) ?: null,
            'match_int_column'  => trim((string) ($payload['link_match_internal_column'] ?? '')) ?: null,
            'unmatched_action'  => trim((string) ($payload['link_unmatched_action'] ?? '')) ?: 'park',
        ];
    }
    $defaults = AIRTABLE_ENTITY_LINK_DEFAULTS[$entity] ?? ['none', null, null, 'park'];
    return [
        'strategy'          => $defaults[0],
        'match_at_field'    => null,
        'match_int_column'  => $defaults[2],
        'unmatched_action'  => $defaults[3],
    ];
}

function airtableMappingList(int $tenantId): array
{
    $stmt = getDB()->prepare(
        'SELECT id, tenant_id, base_id, base_name, table_id, table_name,
                internal_entity, direction, field_map, primary_field,
                link_strategy, link_match_airtable_field,
                link_match_internal_column, link_unmatched_action,
                last_sync_at, last_sync_error, last_records,
                created_at, updated_at
           FROM airtable_table_mappings
          WHERE tenant_id = :t
          ORDER BY base_name ASC, table_name ASC, id ASC'
    );
    $stmt->execute(['t' => $tenantId]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['tenant_id'] = (int) $r['tenant_id'];
        $r['last_records'] = (int) $r['last_records'];
        if (!empty($r['field_map'])) {
            $decoded = json_decode((string) $r['field_map'], true);
            $r['field_map'] = is_array($decoded) ? $decoded : new \stdClass();
        } else {
            $r['field_map'] = new \stdClass();
        }
    }
    unset($r);
    return $rows;
}

function airtableMappingGet(int $tenantId, int $id): ?array
{
    $stmt = getDB()->prepare(
        'SELECT * FROM airtable_table_mappings WHERE tenant_id = :t AND id = :id LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'id' => $id]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['id'] = (int) $row['id'];
    $row['tenant_id'] = (int) $row['tenant_id'];
    $row['last_records'] = (int) $row['last_records'];
    $decoded = !empty($row['field_map']) ? json_decode((string) $row['field_map'], true) : null;
    $row['field_map'] = is_array($decoded) ? $decoded : [];
    return $row;
}

function airtableMappingUpsert(int $tenantId, array $payload, ?int $userId): array
{
    $baseId    = trim((string) ($payload['base_id'] ?? ''));
    $tableId   = trim((string) ($payload['table_id'] ?? ''));
    $baseName  = trim((string) ($payload['base_name'] ?? ''));
    $tableName = trim((string) ($payload['table_name'] ?? ''));
    $entity    = trim((string) ($payload['internal_entity'] ?? ''));
    $dir       = trim((string) ($payload['direction'] ?? 'pull'));
    $primary   = trim((string) ($payload['primary_field'] ?? ''));
    $fieldMap  = $payload['field_map'] ?? [];

    if (!preg_match('/^app[A-Za-z0-9]{10,}$/', $baseId))   throw new \InvalidArgumentException('base_id invalid');
    if (!preg_match('/^tbl[A-Za-z0-9]{10,}$/', $tableId))  throw new \InvalidArgumentException('table_id invalid');
    if (!in_array($entity, AIRTABLE_INTERNAL_ENTITIES, true)) {
        throw new \InvalidArgumentException('internal_entity must be one of: ' . implode(',', AIRTABLE_INTERNAL_ENTITIES));
    }
    if (!in_array($dir, AIRTABLE_MAPPING_DIRECTIONS, true)) {
        throw new \InvalidArgumentException('direction must be pull or off');
    }
    if (!is_array($fieldMap)) throw new \InvalidArgumentException('field_map must be an object');

    // Resolve linkage policy — explicit operator value wins; otherwise
    // apply the per-entity defaults registered above.
    $link = airtableResolveLinkDefaults($entity, $payload);
    if (!in_array($link['strategy'], AIRTABLE_LINK_STRATEGIES, true)) {
        throw new \InvalidArgumentException('link_strategy must be one of: ' . implode(',', AIRTABLE_LINK_STRATEGIES));
    }
    if (!in_array($link['unmatched_action'], AIRTABLE_UNMATCHED_ACTIONS, true)) {
        throw new \InvalidArgumentException('link_unmatched_action must be one of: ' . implode(',', AIRTABLE_UNMATCHED_ACTIONS));
    }

    $pdo = getDB();
    $existing = null;
    $stmt = $pdo->prepare(
        'SELECT id FROM airtable_table_mappings
          WHERE tenant_id = :t AND base_id = :b AND table_id = :tb LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'b' => $baseId, 'tb' => $tableId]);
    $existing = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;

    if ($existing) {
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare(
            'UPDATE airtable_table_mappings
                SET base_name = :bn, table_name = :tn,
                    internal_entity = :ent, direction = :d,
                    field_map = :fm, primary_field = :pf,
                    link_strategy = :ls,
                    link_match_airtable_field  = :lmf,
                    link_match_internal_column = :lic,
                    link_unmatched_action      = :lua
              WHERE id = :id'
        )->execute([
            'bn'  => $baseName !== '' ? $baseName : null,
            'tn'  => $tableName !== '' ? $tableName : null,
            'ent' => $entity,
            'd'   => $dir,
            'fm'  => json_encode($fieldMap),
            'pf'  => $primary !== '' ? $primary : null,
            'ls'  => $link['strategy'],
            'lmf' => $link['match_at_field'],
            'lic' => $link['match_int_column'],
            'lua' => $link['unmatched_action'],
            'id'  => (int) $existing['id'],
        ]);
        $id = (int) $existing['id'];
    } else {
        $pdo->prepare(
            'INSERT INTO airtable_table_mappings
                (tenant_id, base_id, base_name, table_id, table_name,
                 internal_entity, direction, field_map, primary_field,
                 link_strategy, link_match_airtable_field,
                 link_match_internal_column, link_unmatched_action,
                 created_by_user_id)
             VALUES (:t, :b, :bn, :tb, :tn, :ent, :d, :fm, :pf,
                     :ls, :lmf, :lic, :lua, :uid)'
        )->execute([
            't'   => $tenantId,
            'b'   => $baseId,
            'bn'  => $baseName !== '' ? $baseName : null,
            'tb'  => $tableId,
            'tn'  => $tableName !== '' ? $tableName : null,
            'ent' => $entity,
            'd'   => $dir,
            'fm'  => json_encode($fieldMap),
            'pf'  => $primary !== '' ? $primary : null,
            'ls'  => $link['strategy'],
            'lmf' => $link['match_at_field'],
            'lic' => $link['match_int_column'],
            'lua' => $link['unmatched_action'],
            'uid' => $userId,
        ]);
        $id = (int) $pdo->lastInsertId();
    }
    airtableAudit($tenantId, 'mapping_upsert', [
        'base_id' => $baseId, 'table_id' => $tableId,
        'actor_user_id' => $userId,
        'detail' => ['mapping_id' => $id, 'entity' => $entity, 'direction' => $dir],
    ]);
    return airtableMappingGet($tenantId, $id);
}

function airtableMappingDelete(int $tenantId, int $id, ?int $userId): void
{
    $row = airtableMappingGet($tenantId, $id);
    if (!$row) return;
    getDB()->prepare(
        'DELETE FROM airtable_table_mappings WHERE id = :id AND tenant_id = :t'
    )->execute(['id' => $id, 't' => $tenantId]);
    airtableAudit($tenantId, 'mapping_delete', [
        'base_id' => $row['base_id'], 'table_id' => $row['table_id'],
        'actor_user_id' => $userId,
        'detail' => ['mapping_id' => $id],
    ]);
}

/**
 * Returns the set of tenant ids the user is authorised to manage as an
 * admin (direct or via_parent). Mirrors the access matrix used by
 * /api/admin/manageable_tenants.php — kept inline here so the duplicate
 * flow doesn't depend on the SPA payload.
 *
 * @return array<int, true>  set of tenant_ids
 */
function airtableUserAdminTenantSet(int $userId, string $globalRole, bool $isGlobalAdmin): array
{
    $pdo = getDB();
    if ($globalRole === 'master_admin' || $isGlobalAdmin) {
        $rows = $pdo->query("SELECT id FROM tenants WHERE is_active = 1")->fetchAll(\PDO::FETCH_COLUMN);
        $set = [];
        foreach ($rows as $tid) $set[(int) $tid] = true;
        return $set;
    }
    require_once __DIR__ . '/../memberships.php';
    $stmt = $pdo->prepare(
        "SELECT src.tenant_id, src.persona_type AS role
           FROM " . membershipReadSourceSql() . " src
           JOIN tenants t ON t.id = src.tenant_id AND t.is_active = 1
          WHERE src.user_id = :u"
    );
    $stmt->execute(['u' => $userId]);
    $set = [];
    $adminParentIds = [];
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
        $tid  = (int) $r['tenant_id'];
        $role = (string) ($r['role'] ?? 'employee');
        if (in_array($role, ['tenant_admin', 'admin', 'master_admin'], true)) {
            $set[$tid] = true;
            $adminParentIds[] = $tid;
        }
    }
    // via_parent — every sub-tenant whose parent is a tenant the user admins.
    if ($adminParentIds) {
        $place = implode(',', array_fill(0, count($adminParentIds), '?'));
        $sub = $pdo->prepare(
            "SELECT id FROM tenants
              WHERE is_active = 1 AND tenant_type = 'sub' AND parent_id IN ($place)"
        );
        $sub->execute($adminParentIds);
        foreach ($sub->fetchAll(\PDO::FETCH_COLUMN) as $tid) $set[(int) $tid] = true;
    }
    return $set;
}

/**
 * Duplicate one source mapping into a list of target tenants. Each
 * target must (a) be in the caller's admin-tenant set, and (b) have an
 * active Airtable connection. Targets that fail either check are
 * reported in `skipped` so the UI can surface the reason.
 *
 * Returns { source_mapping_id, created, updated, skipped, errors }.
 */
function airtableMappingDuplicate(
    int $sourceTenantId,
    int $sourceMappingId,
    array $targetTenantIds,
    int $userId,
    string $globalRole,
    bool $isGlobalAdmin
): array {
    $source = airtableMappingGet($sourceTenantId, $sourceMappingId);
    if (!$source) throw new \RuntimeException('Source mapping not found');

    $allowed = airtableUserAdminTenantSet($userId, $globalRole, $isGlobalAdmin);
    $pdo = getDB();
    $created = []; $updated = []; $skipped = []; $errors = [];

    foreach ($targetTenantIds as $rawTid) {
        $tid = (int) $rawTid;
        if ($tid <= 0)              { continue; }
        if ($tid === $sourceTenantId) { $skipped[] = ['tenant_id' => $tid, 'reason' => 'is_source']; continue; }
        if (!isset($allowed[$tid])) { $skipped[] = ['tenant_id' => $tid, 'reason' => 'not_admin']; continue; }

        // Target must have an active Airtable connection to actually sync.
        $conn = $pdo->prepare(
            'SELECT id, status FROM airtable_connections WHERE tenant_id = :t LIMIT 1'
        );
        $conn->execute(['t' => $tid]);
        $row = $conn->fetch(\PDO::FETCH_ASSOC);
        if (!$row || $row['status'] !== 'active') {
            $skipped[] = ['tenant_id' => $tid, 'reason' => 'no_connection'];
            continue;
        }

        // Check if target already has the same (base, table) — distinguish
        // "created" from "updated" for the response payload.
        $exStmt = $pdo->prepare(
            'SELECT id FROM airtable_table_mappings
              WHERE tenant_id = :t AND base_id = :b AND table_id = :tb LIMIT 1'
        );
        $exStmt->execute(['t' => $tid, 'b' => $source['base_id'], 'tb' => $source['table_id']]);
        $isUpdate = (bool) $exStmt->fetch(\PDO::FETCH_ASSOC);

        try {
            $newRow = airtableMappingUpsert($tid, [
                'base_id'         => $source['base_id'],
                'base_name'       => $source['base_name'],
                'table_id'        => $source['table_id'],
                'table_name'      => $source['table_name'],
                'internal_entity' => $source['internal_entity'],
                'direction'       => $source['direction'],
                'field_map'       => $source['field_map'],
                'primary_field'   => $source['primary_field'],
            ], $userId);
            $bucket = $isUpdate ? 'updated' : 'created';
            ${$bucket}[] = ['tenant_id' => $tid, 'mapping_id' => (int) $newRow['id']];
        } catch (\Throwable $e) {
            $errors[] = ['tenant_id' => $tid, 'error' => substr($e->getMessage(), 0, 300)];
        }
    }

    // Audit on the source tenant — explains where the mapping fanned out.
    airtableAudit($sourceTenantId, 'mapping_duplicate', [
        'base_id' => $source['base_id'], 'table_id' => $source['table_id'],
        'actor_user_id' => $userId,
        'items_processed' => count($created) + count($updated),
        'items_skipped'   => count($skipped),
        'items_failed'    => count($errors),
        'detail' => [
            'source_mapping_id' => $sourceMappingId,
            'targets'           => $targetTenantIds,
            'created'           => $created,
            'updated'           => $updated,
            'skipped'           => $skipped,
            'errors'            => $errors,
        ],
    ]);

    return [
        'source_mapping_id' => $sourceMappingId,
        'created'           => $created,
        'updated'           => $updated,
        'skipped'           => $skipped,
        'errors'            => $errors,
    ];
}

/**
 * Resolve linkage for a single Airtable record. Returns:
 *   ['action'      => 'link'|'skip',
 *    'internal_id' => int|null,
 *    'sync_status' => 'ok'|'unmatched'|'ambiguous']
 *
 * Strategy semantics:
 *   • `none`         — always returns sync_status='ok' with internal_id=null
 *                      so the sync loop falls back to the synthetic-id
 *                      behaviour (Slice 1 compatibility).
 *   • `external_id`  — looks up the target table by its `external_id`
 *                      column with the Airtable record id. (Strict by
 *                      design — operators picking this strategy are
 *                      saying "the Airtable record id IS my CoreFlux
 *                      external_id".)
 *   • `match_column` — looks up the configured internal column with the
 *                      value held in the configured Airtable field. If
 *                      no match → 'unmatched'. If 2+ matches → 'ambiguous'.
 *   • `manual`       — never auto-links; always returns 'unmatched' so
 *                      the operator wires it manually via the relink UI.
 *
 * `unmatched_action` decides what happens when sync_status != 'ok':
 *   • `skip`  → record never lands (action='skip')
 *   • `park`  → record lands with the non-ok sync_status flag
 *   • `create_stub` → same as 'park' for now (Slice-3 will auto-create
 *                      a minimal entity row when the operator opts in).
 */
function airtableResolveLink(int $tenantId, array $mapping, string $externalId, array $fields): array
{
    $strategy = (string) ($mapping['link_strategy'] ?? 'none');
    $entity   = (string) ($mapping['internal_entity'] ?? '');
    $action   = (string) ($mapping['link_unmatched_action'] ?? 'park');

    if ($strategy === 'none') {
        return ['action' => 'link', 'internal_id' => null, 'sync_status' => 'ok'];
    }

    $atField  = (string) ($mapping['link_match_airtable_field']  ?? '');
    $intCol   = (string) ($mapping['link_match_internal_column'] ?? '');

    // Map entity → CoreFlux table for lookup.
    $defaults  = AIRTABLE_ENTITY_LINK_DEFAULTS[$entity] ?? null;
    $intTable  = $defaults[1] ?? null;
    if ($intCol === '' && $defaults) $intCol = $defaults[2] ?? '';
    if (!$intTable || $intCol === '') {
        return _airtableUnmatched($action);
    }

    // Decide the lookup VALUE we're matching with.
    if ($strategy === 'external_id') {
        $needle = $externalId;            // Airtable rec ID itself
        $lookupCol = 'external_id';       // canonical natural-key path
    } elseif ($strategy === 'match_column') {
        // Operator chose the Airtable field; pull the value from $fields.
        if ($atField === '' || !array_key_exists($atField, $fields)) {
            return _airtableUnmatched($action);
        }
        $raw = $fields[$atField];
        if (is_array($raw)) $raw = $raw[0] ?? null;     // Airtable linked-records arrive as arrays
        if ($raw === null || $raw === '')   return _airtableUnmatched($action);
        $needle    = (string) $raw;
        $lookupCol = $intCol;
    } elseif ($strategy === 'manual') {
        return _airtableUnmatched($action);
    } else {
        return _airtableUnmatched($action);
    }

    // Sanitize column identifier — only [A-Za-z0-9_] allowed; rejects
    // SQL-injection attempts on the writable-targets path.
    if (!preg_match('/^[A-Za-z0-9_]+$/', $lookupCol) || !preg_match('/^[A-Za-z0-9_]+$/', $intTable)) {
        return _airtableUnmatched($action);
    }

    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare(
            "SELECT id FROM `{$intTable}`
              WHERE tenant_id = :t AND `{$lookupCol}` = :v
              LIMIT 2"
        );
        $stmt->execute(['t' => $tenantId, 'v' => $needle]);
        $matches = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
    } catch (\Throwable $e) {
        // If the lookup table doesn't exist yet (e.g. fresh tenant, no
        // ap module migrated), park gracefully — don't fail the sync.
        error_log('[airtableResolveLink] ' . $e->getMessage());
        return _airtableUnmatched($action);
    }

    if (count($matches) === 0) return _airtableUnmatched($action);
    if (count($matches) >  1)  return _airtableAmbiguous($action);
    return ['action' => 'link', 'internal_id' => (int) $matches[0], 'sync_status' => 'ok'];
}

function _airtableUnmatched(string $action): array
{
    if ($action === 'skip') return ['action' => 'skip', 'internal_id' => null, 'sync_status' => 'unmatched'];
    return ['action' => 'link', 'internal_id' => null, 'sync_status' => 'unmatched'];
}

function _airtableAmbiguous(string $action): array
{
    if ($action === 'skip') return ['action' => 'skip', 'internal_id' => null, 'sync_status' => 'ambiguous'];
    return ['action' => 'link', 'internal_id' => null, 'sync_status' => 'ambiguous'];
}

/**
 * Relink existing external_entity_mappings rows for a given Airtable
 * mapping after the operator changes the linkage policy. Iterates the
 * current payload_snapshot of each row and re-runs the resolver.
 *
 * Returns { scanned, relinked, still_unmatched, still_ambiguous }.
 */
function airtableRelinkExistingRows(int $tenantId, int $mappingId, ?int $userId): array
{
    $mapping = airtableMappingGet($tenantId, $mappingId);
    if (!$mapping) throw new \RuntimeException('Mapping not found');

    $stmt = getDB()->prepare(
        "SELECT id, external_id, payload_snapshot, internal_entity_id, sync_status
           FROM external_entity_mappings
          WHERE tenant_id = :t
            AND source_system = 'airtable'
            AND internal_entity_type = :et"
    );
    $stmt->execute(['t' => $tenantId, 'et' => $mapping['internal_entity']]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $scanned = 0; $relinked = 0; $stillUnmatched = 0; $stillAmbiguous = 0;
    $upd = getDB()->prepare(
        "UPDATE external_entity_mappings
            SET internal_entity_id = :iid,
                sync_status = :s
          WHERE id = :id AND tenant_id = :t"
    );

    // We work the snapshot back into a fields[] shape — the snapshot
    // was already normalised via field_map at sync time, so we re-key
    // by the operator's chosen 'link_match_airtable_field' if present.
    foreach ($rows as $r) {
        $scanned++;
        $snap = json_decode((string) ($r['payload_snapshot'] ?? '[]'), true);
        if (!is_array($snap)) $snap = [];

        // Treat the snapshot AS the field map output — match_column
        // strategy may need the original Airtable name; if the operator
        // mapped airtable->core, we look up by core key too.
        $resolved = airtableResolveLink($tenantId, $mapping, (string) $r['external_id'], $snap);
        if ($resolved['action'] === 'skip') continue;

        $newId     = $resolved['internal_id'] ?? (int) $r['internal_entity_id'];
        $newStatus = $resolved['sync_status'];

        $upd->execute([
            'iid' => $newId,
            's'   => $newStatus,
            'id'  => (int) $r['id'],
            't'   => $tenantId,
        ]);
        if      ($newStatus === 'ok')        $relinked++;
        elseif  ($newStatus === 'unmatched') $stillUnmatched++;
        elseif  ($newStatus === 'ambiguous') $stillAmbiguous++;
    }

    airtableAudit($tenantId, 'relink', [
        'base_id' => $mapping['base_id'], 'table_id' => $mapping['table_id'],
        'actor_user_id' => $userId,
        'items_processed' => $scanned,
        'items_skipped'   => 0,
        'detail' => [
            'mapping_id' => $mappingId,
            'scanned' => $scanned, 'relinked' => $relinked,
            'still_unmatched' => $stillUnmatched, 'still_ambiguous' => $stillAmbiguous,
            'link_strategy'   => $mapping['link_strategy'],
        ],
    ]);

    return [
        'scanned'         => $scanned,
        'relinked'        => $relinked,
        'still_unmatched' => $stillUnmatched,
        'still_ambiguous' => $stillAmbiguous,
        'link_strategy'   => $mapping['link_strategy'],
    ];
}

/**
 * Aggregated linkage stats for a single mapping. Cheap GROUP BY on
 * external_entity_mappings.sync_status. Used by the AirtableSettings
 * mapping-row badge UI.
 *
 * Slice-3.1 — when link_strategy='none' the resolver returns
 * sync_status='ok' even though the row is stored with a synthetic
 * internal_entity_id (no real link). We split that out as
 * `stored_only` so the "Linked to CoreFlux row" tile doesn't
 * misleadingly show 100% green when records are actually orphaned
 * in the integrations vault.
 */
function airtableLinkStats(int $tenantId, int $mappingId): array
{
    $mapping = airtableMappingGet($tenantId, $mappingId);
    if (!$mapping) throw new \RuntimeException('Mapping not found');

    $stmt = getDB()->prepare(
        "SELECT sync_status, COUNT(*) AS n
           FROM external_entity_mappings
          WHERE tenant_id = :t
            AND source_system = 'airtable'
            AND internal_entity_type = :et
       GROUP BY sync_status"
    );
    $stmt->execute(['t' => $tenantId, 'et' => $mapping['internal_entity']]);
    $rows = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR) ?: [];

    $okCount      = (int) ($rows['ok']        ?? 0);
    $isStoredOnly = ((string) ($mapping['link_strategy'] ?? 'none')) === 'none';

    return [
        'mapping_id'  => $mappingId,
        // When strategy=none, all 'ok' rows are synthetic-id rows that
        // aren't actually linked to a real CoreFlux entity row.
        'linked'      => $isStoredOnly ? 0           : $okCount,
        'stored_only' => $isStoredOnly ? $okCount    : 0,
        'unmatched'   => (int) ($rows['unmatched'] ?? 0),
        'ambiguous'   => (int) ($rows['ambiguous'] ?? 0),
        'stale'       => (int) ($rows['stale']     ?? 0),
        'error'       => (int) ($rows['error']     ?? 0),
        'total'       => array_sum(array_map('intval', $rows)),
    ];
}

/**
 * Pull every record in an Airtable table through the field_map and
 * upsert into external_entity_mappings under source_system='airtable'.
 * Slice 2 — uses airtableResolveLink() to attach the internal_entity_id
 * to a real CoreFlux row (companies, ap_vendors_index, placements,
 * etc.) when the mapping has a strategy. Unmatched records park with
 * sync_status='unmatched' for operator reconciliation.
 *
 * Returns { records, created, updated, unchanged, failed, skipped,
 *           linked, unmatched, ambiguous, link_strategy, pages }.
 */
function airtableSyncTable(int $tenantId, int $mappingId, ?int $userId, int $maxPages = 20): array
{
    $mapping = airtableMappingGet($tenantId, $mappingId);
    if (!$mapping) throw new \RuntimeException('Mapping not found');
    if ($mapping['direction'] !== 'pull') {
        return [
            'records' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0,
            'failed' => 0, 'pages' => 0, 'skipped' => true, 'reason' => 'direction is off',
        ];
    }

    $start = microtime(true);
    $offset = null;
    $pages = 0;
    $totalRecords = 0; $created = 0; $updated = 0; $unchanged = 0; $failed = 0;
    $linked = 0; $unmatched = 0; $ambiguous = 0; $skipped = 0;
    // Slice-3 — Studio field-mapping application rollup.
    $fieldMapAttempted = 0; $fieldMapWritten = 0; $fieldMapErrors = [];
    $errors = [];

    try {
        do {
            $page = airtableSelectRecords($tenantId, $mapping['base_id'], $mapping['table_id'], $offset, 100);
            $pages++;
            foreach (($page['records'] ?? []) as $rec) {
                $totalRecords++;
                try {
                    $externalId = (string) ($rec['id'] ?? '');
                    if ($externalId === '') { $failed++; continue; }
                    $fields = is_array($rec['fields'] ?? null) ? $rec['fields'] : [];

                    // Normalise per field_map. Source key is the Airtable
                    // field name; destination key is whatever the operator
                    // picked. If field_map is empty, store the raw record.
                    $normalised = [];
                    if (!empty($mapping['field_map'])) {
                        foreach ($mapping['field_map'] as $airField => $coreField) {
                            if (!is_string($coreField) || $coreField === '') continue;
                            if (array_key_exists($airField, $fields)) {
                                $normalised[$coreField] = $fields[$airField];
                            }
                        }
                    } else {
                        $normalised = $fields;
                    }

                    // Surface an updated_at-style timestamp for downstream
                    // change detection. Airtable returns createdTime per
                    // record; modifiedTime requires a system-supplied
                    // field, so we conservatively use the response payload.
                    if (isset($rec['createdTime'])) {
                        $normalised['_airtable_created_time'] = $rec['createdTime'];
                    }
                    // Slice-3 — record a deep-link back to Airtable so the
                    // entity drawer can render an "Open in Airtable" CTA.
                    // base_id / table_id are tenant-validated above via
                    // airtableMappingGet().
                    $normalised['_airtable_record_url'] =
                        'https://airtable.com/'
                        . rawurlencode($mapping['base_id'])
                        . '/' . rawurlencode($mapping['table_id'])
                        . '/' . rawurlencode($externalId);
                    $normalised['_airtable_mapping_id']  = (int) $mapping['id'];
                    $normalised['_airtable_base_name']   = (string) ($mapping['base_name']  ?? '');
                    $normalised['_airtable_table_name']  = (string) ($mapping['table_name'] ?? '');

                    // Slice-2 linkage resolution — find the real CoreFlux
                    // row by the mapping's link policy. Returns null when
                    // no match found OR ambiguous (multiple matches).
                    $resolved = airtableResolveLink($tenantId, $mapping, $externalId, $fields);

                    if ($resolved['action'] === 'skip') {
                        $skipped++;
                        continue;
                    }

                    // Synthetic id used as the fallback when resolver
                    // didn't land on a real row but unmatched_action='park'.
                    $synthetic   = hexdec(substr(hash('sha256', $tenantId . ':' . $externalId), 0, 7)) | 0x1;
                    $existingMap = mappingFindInternal($tenantId, 'airtable', $mapping['internal_entity'], $externalId);
                    $internalId  = $resolved['internal_id']
                                    ?? ($existingMap ? (int) $existingMap['internal_entity_id'] : $synthetic);

                    $syncStatus = $resolved['sync_status'];   // 'ok'|'unmatched'|'ambiguous'

                    $upserted = mappingUpsert(
                        $tenantId, 'airtable', $mapping['internal_entity'],
                        $externalId, $internalId, $normalised, 'pull'
                    );
                    // mappingUpsert always writes sync_status='ok'. When the
                    // resolver flagged unmatched/ambiguous, patch the row.
                    if ($syncStatus !== 'ok') {
                        $stMark = getDB()->prepare(
                            "UPDATE external_entity_mappings
                                SET sync_status = :s
                              WHERE tenant_id = :t
                                AND source_system = 'airtable'
                                AND internal_entity_type = :et
                                AND external_id = :ext"
                        );
                        $stMark->execute([
                            's'   => $syncStatus,
                            't'   => $tenantId,
                            'et'  => $mapping['internal_entity'],
                            'ext' => $externalId,
                        ]);
                    }
                    if (!$existingMap) {
                        $created++;
                    } elseif (!empty($upserted['changed'])) {
                        $updated++;
                    } else {
                        $unchanged++;
                    }
                    if      ($syncStatus === 'ok')         $linked++;
                    elseif  ($syncStatus === 'unmatched')  $unmatched++;
                    elseif  ($syncStatus === 'ambiguous')  $ambiguous++;

                    // Slice-3 — apply tenant Studio field mappings onto
                    // the real CoreFlux row. Only fires when the resolver
                    // produced a real internal_id (NOT the synthetic
                    // hash-derived id used for parked rows); otherwise we
                    // could clobber column data on a sha-collided row.
                    if ($syncStatus === 'ok' && !empty($resolved['internal_id'])) {
                        try {
                            // Pass the RAW Airtable fields as the payload
                            // so Studio mappings can reference field names
                            // exactly as they appear in Airtable.
                            // Provide both 'self' and a per-entity slug so
                            // operators can route to sibling rows (e.g.
                            // placement_rates) once those are wired in
                            // future slices.
                            $context = ['self' => (int) $resolved['internal_id']];
                            $context[$mapping['internal_entity']] = (int) $resolved['internal_id'];
                            $fma = integrationFieldMapApplyAll(
                                $tenantId, 'airtable',
                                (string) $mapping['internal_entity'],
                                $fields, $context
                            );
                            $fieldMapAttempted += (int) ($fma['attempted'] ?? 0);
                            $fieldMapWritten   += (int) ($fma['written']   ?? 0);
                            if (!empty($fma['errors']) && count($fieldMapErrors) < 5) {
                                foreach ($fma['errors'] as $em) {
                                    if (count($fieldMapErrors) >= 5) break;
                                    $fieldMapErrors[] = substr((string) $em, 0, 200);
                                }
                            }
                        } catch (\Throwable $e) {
                            // Field-map failures must never tank the sync
                            // — they're a layered enrichment, not a
                            // required write. Surface in errors[] for
                            // operator visibility.
                            if (count($fieldMapErrors) < 5) {
                                $fieldMapErrors[] = 'apply_throw: ' . substr($e->getMessage(), 0, 200);
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    if (count($errors) < 5) $errors[] = substr($e->getMessage(), 0, 200);
                }
            }
            $offset = $page['offset'] ?? null;
        } while ($offset && $pages < $maxPages);

        $latency = (int) round((microtime(true) - $start) * 1000);
        // tenant-leak-allow: defense-in-depth — mapping_id was just validated under tenant scope via airtableMappingGet
        getDB()->prepare(
            'UPDATE airtable_table_mappings
                SET last_sync_at = NOW(), last_sync_error = :e, last_records = :n
              WHERE id = :id'
        )->execute([
            'e'  => $errors ? substr(implode(' | ', $errors), 0, 500) : null,
            'n'  => $totalRecords,
            'id' => $mappingId,
        ]);
        airtableAudit($tenantId, 'sync_table', [
            'ok' => $failed === 0,
            'base_id' => $mapping['base_id'],
            'table_id' => $mapping['table_id'],
            'direction' => 'pull',
            'actor_user_id' => $userId,
            'items_processed' => $created + $updated + $unchanged,
            'items_skipped'   => $skipped,
            'items_failed'    => $failed,
            'detail' => [
                'records' => $totalRecords, 'created' => $created, 'updated' => $updated,
                'unchanged' => $unchanged, 'failed' => $failed, 'skipped' => $skipped,
                'linked'    => $linked,    'unmatched' => $unmatched, 'ambiguous' => $ambiguous,
                'link_strategy' => $mapping['link_strategy'],
                'field_map_attempted' => $fieldMapAttempted,
                'field_map_written'   => $fieldMapWritten,
                'field_map_errors'    => $fieldMapErrors,
                'pages' => $pages, 'latency_ms' => $latency, 'errors' => $errors,
            ],
        ]);

        return [
            'records' => $totalRecords, 'created' => $created, 'updated' => $updated,
            'unchanged' => $unchanged, 'failed' => $failed, 'skipped' => $skipped,
            'linked'    => $linked,    'unmatched' => $unmatched, 'ambiguous' => $ambiguous,
            'link_strategy' => $mapping['link_strategy'],
            'field_map_attempted' => $fieldMapAttempted,
            'field_map_written'   => $fieldMapWritten,
            'field_map_errors'    => $fieldMapErrors,
            'pages' => $pages, 'latency_ms' => $latency, 'errors' => $errors,
        ];
    } catch (\Throwable $e) {
        // tenant-leak-allow: defense-in-depth — mapping_id was just validated under tenant scope via airtableMappingGet
        getDB()->prepare(
            'UPDATE airtable_table_mappings SET last_sync_error = :e WHERE id = :id'
        )->execute(['e' => substr($e->getMessage(), 0, 500), 'id' => $mappingId]);
        airtableAudit($tenantId, 'sync_table', [
            'ok' => false,
            'base_id' => $mapping['base_id'],
            'table_id' => $mapping['table_id'],
            'direction' => 'pull',
            'actor_user_id' => $userId,
            'detail' => ['error' => substr($e->getMessage(), 0, 500), 'pages' => $pages, 'records' => $totalRecords],
        ]);
        throw $e;
    }
}
