<?php
/**
 * /app/core/integrations/field_map_apply.php
 *
 * Phase 2 of the generalised field-mapping rebuild.
 *
 * Public surface:
 *   - integrationWritableTargetsList(?string $module, ?string $table)
 *       Catalog rows that drive the Field Mapping UI's right-pane
 *       (target picker). Falls back to tenant=NULL globals.
 *
 *   - integrationFieldMapResolveGeneralised(int $tid, string $integration,
 *                                           ?string $entityType = null)
 *       Returns enabled mapping rows with full target shape
 *       (source_path, target_module, target_table, target_column,
 *       linked_entity, transform). Each row carries a `resolved=true`
 *       flag once both sides are populated; legacy rows that haven't
 *       been migrated yet appear with `resolved=false`.
 *
 *   - integrationFieldMapApplyAll(int $tid, string $integration,
 *                                  string $entityType, array $payload,
 *                                  array $contextRowIds)
 *       Applies every enabled mapping for (tenant, integration,
 *       entity_type) against the enriched $payload, writing into the
 *       linked rows resolved via $contextRowIds. Tenant mapping
 *       ALWAYS wins over hardcoded sync defaults (decision (d)).
 *
 * Calling contract for the apply step:
 *   $contextRowIds = [
 *       'self'                   => 12345, // the entity being upserted (placement id)
 *       'person'                 => 67890, // linked person id (placements only)
 *       'end_client_company'     => 555,   // resolved end-client company id
 *       'vendor_company'         => 777,   // resolved vendor company id (PWP, etc.)
 *       'placement_rates'        => 12345, // sibling rates row id (== placement_id)
 *       'placement_corp_details' => 12345, // sibling corp-details row id (== placement_id)
 *   ];
 *
 * The caller is responsible for resolving these ids BEFORE calling
 * applyAll — this lib never queries to find a linked id, it only
 * writes through ones the caller hands it. Keeps the apply step
 * deterministic + tenant-leak-safe.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/field_map.php';
require_once __DIR__ . '/payload_field_index.php';

/**
 * Catalog rows for the picker. `tenant_id=NULL` globals only for now.
 *
 * @return array<int, array<string,mixed>>
 */
function integrationWritableTargetsList(?string $module = null, ?string $table = null): array
{
    try {
        $pdo = getDB();
    } catch (\Throwable $e) {
        return [];
    }
    $sql = 'SELECT id, target_module, target_table, target_column, value_type,
                   enum_values, description, default_linked_entity
              FROM integration_writable_targets
             WHERE enabled = 1 AND tenant_id IS NULL';
    $params = [];
    if ($module !== null && $module !== '') { $sql .= ' AND target_module = :m'; $params['m'] = $module; }
    if ($table  !== null && $table  !== '') { $sql .= ' AND target_table  = :t'; $params['t'] = $table;  }
    $sql .= ' ORDER BY target_module, target_table, target_column';
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }
    foreach ($rows as &$r) {
        if (isset($r['enum_values']) && is_string($r['enum_values'])) {
            $decoded = json_decode($r['enum_values'], true);
            $r['enum_values'] = is_array($decoded) ? $decoded : null;
        }
    }
    return $rows;
}

/**
 * Walk a dotted JSON path against the enriched payload. Supports
 * object-key + array-index notation:
 *   - `_jd_candidate.firstName`
 *   - `_jd_customer.address.city`
 *   - `_jd_candidate.skills[].name`  (returns the first element's name)
 *   - `_jd_candidate.skills[0].name` (explicit index)
 *
 * Returns null when the path doesn't resolve to a scalar leaf.
 * The deep-pluck variant (jobdivaPluckFieldDeep) is the legacy
 * shallow-tolerant resolver — this is the strict dotted-path one
 * that the Phase 2/3 UI builds against.
 */
function integrationPayloadResolvePath(array $payload, string $path): mixed
{
    if ($path === '' || $path === '$') return null;
    $cursor = $payload;
    // Split on `.` while respecting array index suffixes
    $parts = preg_split('/(\.|(?=\[))/u', $path) ?: [];
    foreach ($parts as $p) {
        if ($p === '' || $p === '.') continue;
        if ($p[0] === '[') {
            // [], [0], [1] — for [] we take the first element.
            $idx = trim($p, "[]");
            if (!is_array($cursor)) return null;
            if ($idx === '') {
                if (!array_is_list($cursor) || empty($cursor)) return null;
                $cursor = $cursor[0];
            } else {
                $i = (int) $idx;
                if (!isset($cursor[$i])) return null;
                $cursor = $cursor[$i];
            }
        } else {
            if (!is_array($cursor) || !array_key_exists($p, $cursor)) return null;
            $cursor = $cursor[$p];
        }
    }
    if (is_array($cursor)) return null; // not a scalar leaf
    return $cursor;
}

/**
 * Generalised resolver — returns ALL enabled mappings for
 * (tenant, integration, [entity_type]) with both legacy and new
 * shape fields. Caller filters by target_module as needed.
 *
 * @return array<int, array<string,mixed>>
 */
function integrationFieldMapResolveGeneralised(
    int $tid, string $integration, ?string $entityType = null
): array {
    if ($tid <= 0 || $integration === '') return [];
    try {
        $pdo = getDB();
    } catch (\Throwable $e) {
        return [];
    }
    $sql = 'SELECT id, entity_type, external_field, source_path, internal_field,
                   target_module, target_table, target_column, linked_entity,
                   transform, enabled
              FROM tenant_integration_field_map
             WHERE tenant_id = :t AND integration = :i AND enabled = 1';
    $params = ['t' => $tid, 'i' => $integration];
    if ($entityType !== null && $entityType !== '') {
        $sql .= ' AND entity_type = :e';
        $params['e'] = $entityType;
    }
    $sql .= ' ORDER BY target_module, target_table, target_column';
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }
    foreach ($rows as &$r) {
        $r['resolved'] = !empty($r['target_module'])
                       && !empty($r['target_table'])
                       && !empty($r['target_column']);
    }
    return $rows;
}

/**
 * Apply every enabled tenant mapping to a synced record. Writes are
 * scoped per (target_table, internal_row_id) so a single mapping run
 * can hydrate placements, placement_rates, the linked person row,
 * the end-client company row, AND a custom field — all from one
 * enriched payload.
 *
 * Tenant mapping ALWAYS wins (decision d) — the apply step does NOT
 * check if the column is already populated; the latest write
 * overwrites. Operators who want "fallback" semantics should leave
 * the mapping disabled.
 *
 * Returns a summary array describing what was attempted/written for
 * audit + debug purposes.
 *
 * @return array{attempted:int, written:int, skipped:int, errors:array<int,string>}
 */
function integrationFieldMapApplyAll(
    int $tid,
    string $integration,
    string $entityType,
    array $payload,
    array $contextRowIds
): array {
    $summary = ['attempted' => 0, 'written' => 0, 'skipped' => 0, 'errors' => []];
    if ($tid <= 0 || $integration === '' || $entityType === '') return $summary;
    $maps = integrationFieldMapResolveGeneralised($tid, $integration, $entityType);
    if (!$maps) return $summary;

    try {
        $pdo = getDB();
    } catch (\Throwable $e) {
        $summary['errors'][] = 'no_db: ' . $e->getMessage();
        return $summary;
    }

    // Bucket writes by (target_table, row_id) so we issue ONE UPDATE
    // per row even when a single payload writes a dozen columns to
    // the same row. Halves write count on placements (which often
    // get 10+ mapped columns).
    $bucket = []; // key: "tbl#id" → ['table' => ..., 'id' => ..., 'set' => [col=>val], 'cf' => [code=>val]]

    foreach ($maps as $m) {
        $summary['attempted']++;

        if (empty($m['target_table']) || empty($m['target_column'])) {
            // Legacy row (pre-Phase-2 backfill missed it) — skip and let
            // the hardcoded syncer fallback path handle this column.
            $summary['skipped']++;
            continue;
        }

        // Resolve source value: source_path (dotted) takes priority,
        // legacy `external_field` (flat) is the fallback.
        $val = null;
        if (!empty($m['source_path'])) {
            $val = integrationPayloadResolvePath($payload, (string) $m['source_path']);
        }
        if ($val === null && !empty($m['external_field'])) {
            // Walk shallow + enriched nests via the existing legacy
            // path-resolver; we only reuse the value if it's a
            // string (the registry never wrote anything else here).
            if (function_exists('tenantIntegrationFieldMapPluckPath')) {
                $maybe = tenantIntegrationFieldMapPluckPath($payload, (string) $m['external_field']);
                if ($maybe !== '') $val = $maybe;
            }
        }
        if ($val === null || $val === '') { $summary['skipped']++; continue; }

        // Apply transform (cents_to_dollars / date_normalise / etc.) —
        // reuses the existing slice-4 transform helper so legacy +
        // generalised mappings share semantics.
        if (function_exists('tenantIntegrationFieldMapApplyTransform') && !empty($m['transform'])) {
            $val = tenantIntegrationFieldMapApplyTransform($val, (string) $m['transform']);
            if ($val === null || $val === '') { $summary['skipped']++; continue; }
        }

        $linked = (string) ($m['linked_entity'] ?? 'self');
        $rowId  = isset($contextRowIds[$linked]) ? (int) $contextRowIds[$linked] : 0;
        if ($rowId <= 0) {
            $summary['skipped']++;
            $summary['errors'][] = "no_context_row for linked_entity={$linked} (mapping_id={$m['id']})";
            continue;
        }

        $table = (string) $m['target_table'];
        $col   = (string) $m['target_column'];
        $key   = $table . '#' . $rowId;
        if (!isset($bucket[$key])) {
            $bucket[$key] = ['table' => $table, 'id' => $rowId, 'set' => [], 'cf' => []];
        }

        if ($table === 'custom_field_values') {
            // target_column carries the custom_fields.code; the apply
            // step writes via the existing custom-fields primitive.
            $bucket[$key]['cf'][$col] = $val;
        } else {
            $bucket[$key]['set'][$col] = $val;
        }
    }

    // Flush bucket → DB.
    foreach ($bucket as $b) {
        if (!empty($b['set'])) {
            try {
                $sets = [];
                $params = ['id' => $b['id'], 't' => $tid];
                foreach ($b['set'] as $c => $v) {
                    $ph = 'v_' . preg_replace('/[^a-z0-9_]/i', '_', $c);
                    $sets[] = "`{$c}` = :{$ph}";
                    $params[$ph] = $v;
                }
                $sql = "UPDATE `{$b['table']}` SET " . implode(', ', $sets)
                     . ' WHERE id = :id AND tenant_id = :t';
                $st = $pdo->prepare($sql);
                $st->execute($params);
                $summary['written'] += count($b['set']);
            } catch (\Throwable $e) {
                $summary['errors'][] = "write_fail {$b['table']}#{$b['id']}: " . $e->getMessage();
            }
        }
        if (!empty($b['cf'])) {
            try {
                require_once __DIR__ . '/../custom_fields.php';
                foreach ($b['cf'] as $code => $v) {
                    if (function_exists('customFieldValueUpsert')) {
                        customFieldValueUpsert($tid, $entityType, $b['id'], $code, $v);
                        $summary['written']++;
                    } else {
                        $summary['errors'][] = "custom_fields lib missing — code={$code}";
                    }
                }
            } catch (\Throwable $e) {
                $summary['errors'][] = "cf_write_fail {$b['table']}#{$b['id']}: " . $e->getMessage();
            }
        }
    }

    return $summary;
}
