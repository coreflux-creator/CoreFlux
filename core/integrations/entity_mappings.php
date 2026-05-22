<?php
/**
 * Integration-agnostic external entity mappings (Sprint 8a / Slice A2).
 *
 * One pipeline that ANY 3rd-party integration (JobDiva today, Bullhorn /
 * Greenhouse tomorrow) uses to bind an external record id to a CoreFlux
 * internal record id WITHOUT mutating core tables (no `jobdiva_company_id`
 * column on `companies`, etc.).
 *
 * Public surface (ALL functions are tenant-scoped + source_system agnostic):
 *
 *   mappingHash(array $payload): string
 *       sha256 hex of canonicalised JSON. Identical input → identical hash
 *       regardless of key order. Used for dirty-check.
 *
 *   mappingUpsert(int $tenantId, string $source, string $entityType,
 *                 string $externalId, int $internalId,
 *                 ?array $payload = null,
 *                 string $direction = 'pull'): array
 *       Idempotent insert-or-update. Bumps `last_seen_at` always; bumps
 *       `last_synced_at` only when the content_hash actually changed (or
 *       was previously NULL). Returns the row + a `changed` boolean.
 *
 *   mappingFindInternal(int $tenantId, string $source, string $entityType,
 *                       string $externalId): ?array
 *       External-id → internal row. Used when a webhook arrives carrying
 *       an external id and we need to find the CoreFlux record to update.
 *
 *   mappingFindExternal(int $tenantId, string $source, string $entityType,
 *                       int $internalId): ?array
 *       Internal-id → external row. Used when CoreFlux has changed an
 *       internal record and we need to know what to push back to the source.
 *
 *   mappingMarkStatus(int $tenantId, int $mappingId,
 *                     string $status, ?string $error = null): void
 *       Updates `sync_status` ('ok'|'stale'|'error'|'deleted_in_source')
 *       and an optional error message. Bumps updated_at.
 *
 *   mappingDelete(int $tenantId, string $source, string $entityType,
 *                 string $externalId): void
 *       Removes a mapping (typically when source signals hard-delete).
 *
 *   mappingListForInternal(int $tenantId, string $entityType,
 *                          int $internalId): array
 *       All external ids any source has for a given internal record.
 *
 * Key invariants enforced by schema (see migration 022):
 *   - One external id ↔ one internal record per (tenant, source, type).
 *   - One internal record has ≤ 1 external id per (source, type).
 */
declare(strict_types=1);

const EXTERNAL_MAPPING_DIRECTIONS = ['pull', 'push', 'two_way', 'off'];
const EXTERNAL_MAPPING_STATUSES   = ['ok', 'stale', 'error', 'deleted_in_source'];

/**
 * Canonical JSON hash. Sorts keys recursively so logically-equal payloads
 * always produce identical hashes regardless of key order.
 */
function mappingHash(array $payload): string
{
    $sorted = mappingCanonicalise($payload);
    $json   = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        // json_encode can fail on resource handles or NaN; fall back to serialize.
        $json = serialize($sorted);
    }
    return hash('sha256', $json);
}

function mappingCanonicalise($value)
{
    if (is_array($value)) {
        // Recurse first so nested arrays are also sorted.
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = mappingCanonicalise($v);
        }
        // Sort by key for assoc arrays; preserve order for plain lists.
        $isList = array_keys($out) === range(0, count($out) - 1);
        if (!$isList) {
            ksort($out);
        }
        return $out;
    }
    return $value;
}

function mappingUpsert(
    int $tenantId,
    string $source,
    string $entityType,
    string $externalId,
    int $internalId,
    ?array $payload = null,
    string $direction = 'pull'
): array {
    if ($tenantId <= 0)         throw new \InvalidArgumentException('tenant_id required');
    if ($source === '')         throw new \InvalidArgumentException('source_system required');
    if ($entityType === '')     throw new \InvalidArgumentException('internal_entity_type required');
    if ($externalId === '')     throw new \InvalidArgumentException('external_id required');
    if ($internalId <= 0)       throw new \InvalidArgumentException('internal_entity_id required');
    if (!in_array($direction, EXTERNAL_MAPPING_DIRECTIONS, true)) {
        throw new \InvalidArgumentException('direction must be one of: ' . implode(',', EXTERNAL_MAPPING_DIRECTIONS));
    }

    $hash    = $payload !== null ? mappingHash($payload) : null;
    $snapshot = $payload !== null ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

    $pdo = getDB();
    $existing = mappingFindInternal($tenantId, $source, $entityType, $externalId);

    if ($existing) {
        $changed = ($hash !== null && $hash !== $existing['content_hash']);
        // Internal id can move (e.g. merge); accept the new one.
        $bindings = [
            'iid'   => $internalId,
            'dir'   => $direction,
            'snap'  => $snapshot,
            'hash'  => $hash,
            'id'    => (int) $existing['id'],
            't'     => $tenantId,
        ];
        if ($changed || $existing['internal_entity_id'] != $internalId) {
            $pdo->prepare(
                'UPDATE external_entity_mappings
                    SET internal_entity_id = :iid,
                        direction          = :dir,
                        payload_snapshot   = :snap,
                        content_hash       = :hash,
                        sync_status        = "ok",
                        last_error         = NULL,
                        last_seen_at       = NOW(),
                        last_synced_at     = NOW()
                  WHERE id = :id AND tenant_id = :t'
            )->execute($bindings);
        } else {
            // Unchanged — just bump last_seen_at.
            $pdo->prepare(
                'UPDATE external_entity_mappings
                    SET last_seen_at = NOW()
                  WHERE id = :id AND tenant_id = :t'
            )->execute(['id' => (int) $existing['id'], 't' => $tenantId]);
        }
        $row = mappingFindInternal($tenantId, $source, $entityType, $externalId);
        $row['changed'] = $changed;
        return $row;
    }

    // Insert. Race-safe via UNIQUE KEY uk_external — ON DUPLICATE KEY catches
    // a concurrent insert and converts it to an update.
    $pdo->prepare(
        'INSERT INTO external_entity_mappings
            (tenant_id, source_system, internal_entity_type, external_id,
             internal_entity_id, payload_snapshot, content_hash, direction,
             sync_status, last_seen_at, last_synced_at)
         VALUES
            (:t, :src, :et, :ext, :iid, :snap, :hash, :dir, "ok", NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            internal_entity_id = VALUES(internal_entity_id),
            payload_snapshot   = VALUES(payload_snapshot),
            content_hash       = VALUES(content_hash),
            direction          = VALUES(direction),
            sync_status        = "ok",
            last_error         = NULL,
            last_seen_at       = NOW(),
            last_synced_at     = NOW()'
    )->execute([
        't'    => $tenantId,
        'src'  => $source,
        'et'   => $entityType,
        'ext'  => $externalId,
        'iid'  => $internalId,
        'snap' => $snapshot,
        'hash' => $hash,
        'dir'  => $direction,
    ]);
    $row = mappingFindInternal($tenantId, $source, $entityType, $externalId);
    $row['changed'] = true; // brand new
    return $row;
}

function mappingFindInternal(int $tenantId, string $source, string $entityType, string $externalId): ?array
{
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT id, tenant_id, source_system, internal_entity_type, external_id,
                internal_entity_id, payload_snapshot, content_hash, direction,
                sync_status, last_error, last_synced_at, last_seen_at,
                created_at, updated_at
           FROM external_entity_mappings
          WHERE tenant_id = :t
            AND source_system = :src
            AND internal_entity_type = :et
            AND external_id = :ext
          LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'src' => $source, 'et' => $entityType, 'ext' => $externalId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row ?: null;
}

function mappingFindExternal(int $tenantId, string $source, string $entityType, int $internalId): ?array
{
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT id, tenant_id, source_system, internal_entity_type, external_id,
                internal_entity_id, payload_snapshot, content_hash, direction,
                sync_status, last_error, last_synced_at, last_seen_at,
                created_at, updated_at
           FROM external_entity_mappings
          WHERE tenant_id = :t
            AND source_system = :src
            AND internal_entity_type = :et
            AND internal_entity_id = :iid
          LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'src' => $source, 'et' => $entityType, 'iid' => $internalId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row ?: null;
}

function mappingMarkStatus(int $tenantId, int $mappingId, string $status, ?string $error = null): void
{
    if (!in_array($status, EXTERNAL_MAPPING_STATUSES, true)) {
        throw new \InvalidArgumentException('status must be one of: ' . implode(',', EXTERNAL_MAPPING_STATUSES));
    }
    $pdo = getDB();
    $pdo->prepare(
        'UPDATE external_entity_mappings
            SET sync_status = :s,
                last_error  = :e
          WHERE id = :id AND tenant_id = :t'
    )->execute([
        's'  => $status,
        'e'  => $error !== null ? substr($error, 0, 500) : null,
        'id' => $mappingId,
        't'  => $tenantId,
    ]);
}

function mappingDelete(int $tenantId, string $source, string $entityType, string $externalId): void
{
    $pdo = getDB();
    $pdo->prepare(
        'DELETE FROM external_entity_mappings
          WHERE tenant_id = :t
            AND source_system = :src
            AND internal_entity_type = :et
            AND external_id = :ext'
    )->execute(['t' => $tenantId, 'src' => $source, 'et' => $entityType, 'ext' => $externalId]);
}

function mappingListForInternal(int $tenantId, string $entityType, int $internalId): array
{
    $pdo = getDB();
    // payload_snapshot is included so the Connected Sources panel can
    // surface the source-system's native IDs (JobDiva Job #, Candidate #,
    // etc.) without a second round-trip. Callers that don't need it can
    // ignore the column; the row size is bounded by the source-side
    // payload, which is already tenant-scoped.
    $stmt = $pdo->prepare(
        'SELECT id, source_system, external_id, sync_status, direction,
                last_synced_at, last_seen_at, payload_snapshot
           FROM external_entity_mappings
          WHERE tenant_id = :t
            AND internal_entity_type = :et
            AND internal_entity_id = :iid
          ORDER BY source_system ASC'
    );
    $stmt->execute(['t' => $tenantId, 'et' => $entityType, 'iid' => $internalId]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    // payload_snapshot is JSON; decode for the wire so the frontend
    // doesn't have to double-parse.
    foreach ($rows as &$r) {
        if (isset($r['payload_snapshot']) && is_string($r['payload_snapshot'])) {
            $decoded = json_decode($r['payload_snapshot'], true);
            $r['payload_snapshot'] = is_array($decoded) ? $decoded : null;
        }
    }
    return $rows;
}
