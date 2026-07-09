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
 *       'placement_rates'        => 24680, // sibling placement_rates.id for this placement
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
    $rows = array_values(array_filter($rows, static function ($r): bool {
        return !integrationFieldMapIsProtectedTarget(
            (string) ($r['target_table'] ?? ''),
            (string) ($r['target_column'] ?? '')
        );
    }));
    foreach ($rows as &$r) {
        if (isset($r['enum_values']) && is_string($r['enum_values'])) {
            $decoded = json_decode($r['enum_values'], true);
            $r['enum_values'] = is_array($decoded) ? $decoded : null;
        }
    }
    return $rows;
}

function integrationFieldMapIsProtectedTarget(string $table, string $column): bool
{
    $column = strtolower(trim($column));
    if ($column === '') return true;
    if (in_array($column, [
        'id',
        'tenant_id',
        'external_id',
        'source_system',
        'created_at',
        'updated_at',
        'deleted_at',
        'created_by_user_id',
        'updated_by_user_id',
    ], true)) {
        return true;
    }
    return false;
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
function integrationPayloadResolvePathStrict(array $payload, string $path): mixed
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

function integrationPayloadSourcePathAliases(string $path): array
{
    $path = trim($path);
    if ($path === '' || $path === '$') return [$path];
    if (!preg_match('/^([^\.\[]+)(.*)$/u', $path, $m)) return [$path];

    $root = $m[1];
    $suffix = $m[2] ?? '';
    $rootKey = strtolower($root);
    $aliases = [
        'job' => ['_jd_job', 'staffing_job', 'jobdiva_job'],
        'staffing_job' => ['job', '_jd_job', 'jobdiva_job'],
        'jobdiva_job' => ['job', '_jd_job', 'staffing_job'],
        '_jd_job' => ['job', 'staffing_job', 'jobdiva_job'],

        'assignment' => ['_jd_start', 'start', 'jobdiva_assignment'],
        'start' => ['assignment', '_jd_start', 'jobdiva_assignment'],
        'jobdiva_assignment' => ['assignment', '_jd_start', 'start'],
        '_jd_start' => ['assignment', 'start', 'jobdiva_assignment'],

        'person' => ['_jd_candidate', 'candidate', 'employee', 'worker', 'jobdiva_candidate'],
        'candidate' => ['person', '_jd_candidate', 'jobdiva_candidate'],
        'employee' => ['person', '_jd_candidate', 'candidate', 'jobdiva_candidate'],
        'worker' => ['person', '_jd_candidate', 'candidate', 'jobdiva_candidate'],
        'jobdiva_candidate' => ['person', '_jd_candidate', 'candidate'],
        '_jd_candidate' => ['person', 'candidate', 'jobdiva_candidate'],

        'company' => ['_jd_customer', 'customer', 'client', 'end_client', 'jobdiva_customer'],
        'customer' => ['company', '_jd_customer', 'client', 'end_client', 'jobdiva_customer'],
        'client' => ['company', '_jd_customer', 'customer', 'end_client', 'jobdiva_customer'],
        'end_client' => ['company', '_jd_customer', 'customer', 'client', 'jobdiva_customer'],
        'jobdiva_customer' => ['company', '_jd_customer', 'customer', 'client', 'end_client'],
        '_jd_customer' => ['company', 'customer', 'client', 'end_client', 'jobdiva_customer'],

        'contact' => ['_jd_contact', 'jobdiva_contact'],
        'jobdiva_contact' => ['contact', '_jd_contact'],
        '_jd_contact' => ['contact', 'jobdiva_contact'],
    ];

    $out = [$path];
    foreach (($aliases[$rootKey] ?? []) as $aliasRoot) {
        $candidate = $aliasRoot . $suffix;
        if (!in_array($candidate, $out, true)) $out[] = $candidate;
    }
    return $out;
}

function integrationPayloadResolvePath(array $payload, string $path): mixed
{
    foreach (integrationPayloadSourcePathAliases($path) as $candidatePath) {
        $value = integrationPayloadResolvePathStrict($payload, $candidatePath);
        if ($value !== null) return $value;
    }
    return null;
}

function integrationFieldMapContextRowId(array $contextRowIds, array $mapping): int
{
    $linked = trim((string) ($mapping['linked_entity'] ?? 'self'));
    if ($linked === '') $linked = 'self';
    if ($linked !== 'self' && isset($contextRowIds[$linked]) && (int) $contextRowIds[$linked] > 0) {
        return (int) $contextRowIds[$linked];
    }

    $table = strtolower(trim((string) ($mapping['target_table'] ?? '')));
    $module = strtolower(trim((string) ($mapping['target_module'] ?? '')));
    $hasPlacementContext = isset($contextRowIds['placement'])
        || isset($contextRowIds['placement_rates'])
        || isset($contextRowIds['placement_corp_details']);
    $rootSelfFallback = $hasPlacementContext ? [] : ['self'];
    $candidates = match ($table) {
        'placements' => ['placement', 'self'],
        'placement_rates' => array_merge(['placement_rates'], $rootSelfFallback),
        'placement_corp_details' => ['placement_corp_details', 'placement', 'self'],
        'placement_client_chain' => match (true) {
            str_contains($linked, 'end_client') => ['placement_chain_end_client'],
            str_contains($linked, 'msp') => ['placement_chain_msp'],
            str_contains($linked, 'prime') => ['placement_chain_prime_vendor'],
            str_contains($linked, 'sub') => ['placement_chain_sub_vendor'],
            str_contains($linked, 'direct') => ['placement_chain_direct'],
            default => ['placement_chain_prime_vendor', 'placement_chain_msp', 'placement_chain_sub_vendor'],
        },
        'staffing_jobs' => array_merge(['staffing_job', 'job', 'jobdiva_job'], $rootSelfFallback),
        'people' => array_merge(['person', 'candidate', 'jobdiva_candidate', 'employee', 'worker'], $rootSelfFallback),
        'companies' => str_contains($linked, 'vendor')
            ? array_merge(['vendor_company', 'company'], $rootSelfFallback)
            : array_merge(['end_client_company', 'company', 'customer', 'client', 'jobdiva_customer'], $rootSelfFallback),
        'company_contacts' => array_merge(['contact', 'jobdiva_contact'], $rootSelfFallback),
        'custom_field_values' => match ($module) {
            'people' => array_merge(['person', 'candidate', 'jobdiva_candidate'], $rootSelfFallback),
            'companies' => str_contains($linked, 'vendor')
                ? array_merge(['vendor_company', 'company'], $rootSelfFallback)
                : array_merge(['end_client_company', 'company', 'customer', 'client', 'jobdiva_customer'], $rootSelfFallback),
            'placements' => ['placement', 'self'],
            default => [$linked, 'self'],
        },
        default => [$linked, 'self'],
    };

    foreach ($candidates as $candidate) {
        if (isset($contextRowIds[$candidate]) && (int) $contextRowIds[$candidate] > 0) {
            return (int) $contextRowIds[$candidate];
        }
    }
    return 0;
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
        $rowId  = integrationFieldMapContextRowId($contextRowIds, $m);
        if ($rowId <= 0) {
            $summary['skipped']++;
            $summary['errors'][] = "no_context_row for linked_entity={$linked} target={$m['target_table']}.{$m['target_column']} (mapping_id={$m['id']})";
            continue;
        }

        $table = (string) $m['target_table'];
        $col   = (string) $m['target_column'];
        if (integrationFieldMapIsProtectedTarget($table, $col)) {
            $summary['skipped']++;
            $summary['errors'][] = "protected_target {$table}.{$col} (mapping_id={$m['id']})";
            continue;
        }
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

/**
 * Dry-run evaluator for generalised mappings (Phase 2/3 shape).
 *
 * Mirrors tenantIntegrationFieldMapTestPayload() but resolves
 * source_path (dotted) and surfaces the full target identity
 * (target_module, target_table, target_column, linked_entity) for
 * each row. UI uses this to render the side-by-side preview the
 * operator sees BEFORE letting a sync run live.
 *
 * Each row in the result:
 *   mapping_id     : tenant_integration_field_map.id
 *   source_path    : the dotted path (or legacy external_field fallback)
 *   raw_value      : pre-transform value (string|number|null)
 *   resolved_value : post-transform value
 *   matched        : bool — did the source path resolve?
 *   target         : "{module}.{table}.{column} (linked={slug})" or
 *                    "legacy: {internal_field}" for un-migrated rows
 *   target_module/table/column/linked_entity
 *   transform / enabled / resolved (Phase 2 flag)
 *
 * @return array{integration:string,entity_type:string,results:array<int,array<string,mixed>>,totals:array<string,int>}
 */
function integrationFieldMapTestPayloadGeneralised(
    int $tenantId,
    string $integration,
    string $entityType,
    array $payload
): array {
    $rows = integrationFieldMapResolveGeneralised($tenantId, $integration, $entityType);
    $out  = []; $matched = 0; $unmatched = 0;
    foreach ($rows as $m) {
        $val = null; $raw = null;
        if (!empty($m['source_path'])) {
            $val = integrationPayloadResolvePath($payload, (string) $m['source_path']);
            $raw = $val;
        }
        if ($val === null && !empty($m['external_field'])
            && function_exists('tenantIntegrationFieldMapPluckPath')) {
            $maybe = tenantIntegrationFieldMapPluckPath($payload, (string) $m['external_field']);
            if ($maybe !== '') { $raw = $maybe; $val = $maybe; }
        }
        $isMatched = $val !== null && $val !== '';
        if ($isMatched && !empty($m['transform'])
            && function_exists('tenantIntegrationFieldMapApplyTransform')) {
            $val = tenantIntegrationFieldMapApplyTransform($val, (string) $m['transform']);
        }
        if ($isMatched) $matched++; else $unmatched++;
        $target = ($m['target_table'] ?? '') !== ''
            ? sprintf('%s.%s.%s (linked=%s)',
                $m['target_module'] ?? '?',
                $m['target_table']  ?? '?',
                $m['target_column'] ?? '?',
                $m['linked_entity'] ?: 'self')
            : sprintf('legacy: %s', $m['internal_field'] ?? '?');
        $out[] = [
            'mapping_id'     => (int) ($m['id'] ?? 0),
            'source_path'    => ($m['source_path'] ?? '') !== ''
                                  ? (string) $m['source_path']
                                  : (string) ($m['external_field'] ?? ''),
            'raw_value'      => $raw,
            'resolved_value' => $val,
            'matched'        => $isMatched,
            'target'         => $target,
            'target_module'  => $m['target_module'] ?? null,
            'target_table'   => $m['target_table']  ?? null,
            'target_column'  => $m['target_column'] ?? null,
            'linked_entity'  => $m['linked_entity'] ?: 'self',
            'transform'      => (string) ($m['transform'] ?? 'none'),
            'enabled'        => !empty($m['enabled']),
            'resolved'       => !empty($m['resolved']),
        ];
    }
    return [
        'integration' => $integration,
        'entity_type' => $entityType,
        'results'     => $out,
        'totals'      => ['total' => count($out), 'matched' => $matched, 'unmatched' => $unmatched],
    ];
}
