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

function airtableMappingList(int $tenantId): array
{
    $stmt = getDB()->prepare(
        'SELECT id, tenant_id, base_id, base_name, table_id, table_name,
                internal_entity, direction, field_map, primary_field,
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
                    field_map = :fm, primary_field = :pf
              WHERE id = :id'
        )->execute([
            'bn'  => $baseName !== '' ? $baseName : null,
            'tn'  => $tableName !== '' ? $tableName : null,
            'ent' => $entity,
            'd'   => $dir,
            'fm'  => json_encode($fieldMap),
            'pf'  => $primary !== '' ? $primary : null,
            'id'  => (int) $existing['id'],
        ]);
        $id = (int) $existing['id'];
    } else {
        $pdo->prepare(
            'INSERT INTO airtable_table_mappings
                (tenant_id, base_id, base_name, table_id, table_name,
                 internal_entity, direction, field_map, primary_field,
                 created_by_user_id)
             VALUES (:t, :b, :bn, :tb, :tn, :ent, :d, :fm, :pf, :uid)'
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
 * Pull every record in an Airtable table through the field_map and
 * upsert into external_entity_mappings under source_system='airtable'.
 * The internal_entity_id is synthesised from the Airtable record id —
 * we DON'T touch any CoreFlux core table. That makes the worker safe
 * for any entity type the user picks; downstream features can join
 * external_entity_mappings on demand.
 *
 * Returns { records, created, updated, unchanged, failed, pages }.
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

                    // Synthetic internal_entity_id — sha256(externalId) ⇒ 31-bit int.
                    // This is purely a placeholder to satisfy the
                    // external_entity_mappings NOT NULL constraint; the
                    // actual record lives in payload_snapshot. Once a
                    // downstream feature wants the data inside a real
                    // CoreFlux table, it remaps internal_entity_id via
                    // mappingUpsert() with a real id.
                    $synthetic = hexdec(substr(hash('sha256', $tenantId . ':' . $externalId), 0, 7)) | 0x1;

                    $existingMap = mappingFindInternal($tenantId, 'airtable', $mapping['internal_entity'], $externalId);
                    $internalId  = $existingMap ? (int) $existingMap['internal_entity_id'] : $synthetic;
                    $upserted = mappingUpsert(
                        $tenantId, 'airtable', $mapping['internal_entity'],
                        $externalId, $internalId, $normalised, 'pull'
                    );
                    if (!$existingMap) {
                        $created++;
                    } elseif (!empty($upserted['changed'])) {
                        $updated++;
                    } else {
                        $unchanged++;
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
            'items_skipped'   => 0,
            'items_failed'    => $failed,
            'detail' => [
                'records' => $totalRecords, 'created' => $created, 'updated' => $updated,
                'unchanged' => $unchanged, 'failed' => $failed,
                'pages' => $pages, 'latency_ms' => $latency, 'errors' => $errors,
            ],
        ]);

        return [
            'records' => $totalRecords, 'created' => $created, 'updated' => $updated,
            'unchanged' => $unchanged, 'failed' => $failed,
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
