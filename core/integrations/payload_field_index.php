<?php
/**
 * /app/core/integrations/payload_field_index.php
 *
 * Phase 1 of the generalised field-mapping rebuild.
 *
 * Every time a payload lands and is persisted via mappingUpsert(),
 * we ALSO flatten it to dotted JSON paths and record each path in
 * `integration_payload_field_index`. The Integration Settings UI
 * then queries this table to render the picker tree of available
 * source fields — no more guessing field names, no more shipping
 * code to pick up a newly-returned field.
 *
 * Path convention (matches the JS dot-bracket convention common in
 * spreadsheet-style mapping UIs):
 *   - Object keys                         → `foo.bar`
 *   - Array elements (descended into)     → `foo[].bar`
 *   - Scalars at leaf                     → recorded with value_type
 *   - Empty arrays / null                 → recorded with sentinel
 *
 * Sample values are truncated to 200 chars and never include
 * objects/arrays — only scalar leaves. PII concern: the snapshot
 * already stores the full payload in `external_entity_mappings`,
 * so the index doesn't widen exposure.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

/**
 * Walk a payload, emitting (path, scalar_sample, value_type) tuples
 * for every leaf AND every object/array bone. The intermediate-node
 * emissions (with value_type='object' / 'array') let the UI render
 * collapsible tree branches even when the operator hasn't drilled
 * into them yet.
 *
 * @return array<int, array{path:string, value:string|null, type:string}>
 */
function integrationPayloadFlatten(mixed $node, string $prefix = ''): array
{
    $out = [];
    if (is_array($node)) {
        // Distinguish list-ish from object-ish. PHP doesn't, JSON does,
        // so we sniff sequential numeric keys.
        $isList = array_is_list($node);
        if ($isList) {
            // Record the array bone itself so the picker shows the key.
            if ($prefix !== '') {
                $out[] = ['path' => $prefix, 'value' => null, 'type' => 'array'];
            }
            // For arrays, walk the FIRST element only (representative)
            // with `[]` suffix. Walking every element would explode the
            // index for tenants with thousands of array entries per
            // payload.
            if (!empty($node)) {
                $first = $node[0];
                $out = array_merge($out, integrationPayloadFlatten($first, $prefix . '[]'));
            }
        } else {
            if ($prefix !== '') {
                $out[] = ['path' => $prefix, 'value' => null, 'type' => 'object'];
            }
            foreach ($node as $k => $v) {
                if (!is_string($k) && !is_int($k)) continue;
                $key  = (string) $k;
                // Skip path components with weird chars by replacing them
                // — the picker shows the path verbatim so we want it
                // readable + addressable later.
                $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $key) ?? $key;
                $sub  = $prefix === '' ? $safe : ($prefix . '.' . $safe);
                $out  = array_merge($out, integrationPayloadFlatten($v, $sub));
            }
        }
        return $out;
    }
    // Scalar leaf
    if (is_bool($node)) {
        $type = 'boolean';
    } elseif (is_int($node) || is_float($node)) {
        $type = 'number';
    } elseif ($node === null) {
        $type = 'null';
    } elseif (is_string($node)) {
        $type = 'string';
    } else {
        $type = 'unknown';
    }
    $sample = null;
    if ($node !== null && !is_bool($node)) {
        $sample = is_scalar($node) ? (string) $node : null;
    } elseif (is_bool($node)) {
        $sample = $node ? 'true' : 'false';
    }
    if ($sample !== null && strlen($sample) > 200) $sample = substr($sample, 0, 200);
    $out[] = ['path' => $prefix === '' ? '$' : $prefix, 'value' => $sample, 'type' => $type];
    return $out;
}

/**
 * Upsert every path observed in $payload into the field index for
 * (tenant, integration, entity_type). Increments occurrence_count,
 * refreshes sample_value + last_seen_at on existing rows, inserts
 * new rows on never-before-seen paths.
 *
 * Soft-degrades if migration 076 hasn't been applied yet — callers
 * (mappingUpsert) treat this as fire-and-forget.
 */
function integrationPayloadFieldIndexRecord(
    int $tenantId,
    string $integration,
    string $entityType,
    array $payload
): int {
    if ($tenantId <= 0 || $integration === '' || $entityType === '') return 0;
    $rows = integrationPayloadFlatten($payload);
    if (!$rows) return 0;

    try {
        $pdo = getDB();
    } catch (\Throwable $e) {
        return 0;
    }

    $upserted = 0;
    // Batch via a single prepared statement for speed — large enriched
    // JobDiva payloads can carry 200+ paths each.
    try {
        $st = $pdo->prepare(
            'INSERT INTO integration_payload_field_index
                (tenant_id, integration, entity_type, source_path, value_type,
                 sample_value, occurrence_count, first_seen_at, last_seen_at)
             VALUES
                (:t, :i, :e, :p, :vt, :sv, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                value_type       = VALUES(value_type),
                sample_value     = COALESCE(VALUES(sample_value), sample_value),
                occurrence_count = occurrence_count + 1,
                last_seen_at     = NOW()'
        );
    } catch (\Throwable $e) {
        // Migration not applied — silently skip; the UI will show an
        // empty picker until the index is built.
        return 0;
    }

    foreach ($rows as $r) {
        try {
            $st->execute([
                't'  => $tenantId,
                'i'  => $integration,
                'e'  => $entityType,
                'p'  => substr((string) $r['path'], 0, 255),
                'vt' => (string) $r['type'],
                'sv' => $r['value'],
            ]);
            $upserted++;
        } catch (\Throwable $e) {
            // Single-row failure shouldn't tank the rest. Log + continue.
            error_log('[payload_field_index] insert failed: ' . $e->getMessage());
        }
    }
    return $upserted;
}

/**
 * List the indexed paths for (tenant, integration, entity_type) for
 * the UI picker. Returns rows ordered by occurrence_count DESC so
 * the most-stable fields surface first.
 *
 * @return array<int, array<string,mixed>>
 */
function integrationPayloadFieldIndexList(
    int $tenantId,
    string $integration,
    string $entityType,
    int $limit = 500
): array {
    if ($tenantId <= 0 || $integration === '' || $entityType === '') return [];
    try {
        $pdo = getDB();
        $st = $pdo->prepare(
            'SELECT source_path, value_type, sample_value,
                    occurrence_count, first_seen_at, last_seen_at
               FROM integration_payload_field_index
              WHERE tenant_id = :t AND integration = :i AND entity_type = :e
              ORDER BY occurrence_count DESC, source_path ASC
              LIMIT ' . max(1, min(2000, $limit))
        );
        $st->execute(['t' => $tenantId, 'i' => $integration, 'e' => $entityType]);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Distinct (integration, entity_type) tuples that have any indexed
 * paths for this tenant — drives the picker's source dropdown.
 *
 * @return array<int, array{integration:string, entity_type:string, path_count:int, last_seen_at:string|null}>
 */
function integrationPayloadFieldIndexSources(int $tenantId): array
{
    if ($tenantId <= 0) return [];
    try {
        $pdo = getDB();
        $st = $pdo->prepare(
            'SELECT integration, entity_type,
                    COUNT(*) AS path_count,
                    MAX(last_seen_at) AS last_seen_at
               FROM integration_payload_field_index
              WHERE tenant_id = :t
              GROUP BY integration, entity_type
              ORDER BY integration, entity_type'
        );
        $st->execute(['t' => $tenantId]);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}
