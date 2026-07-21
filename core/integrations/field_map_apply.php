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
            if (!is_array($cursor)) return null;
            if (array_key_exists($p, $cursor)) {
                $cursor = $cursor[$p];
                continue;
            }
            $needle = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $p));
            if ($needle === '') return null;
            $matched = false;
            foreach ($cursor as $k => $v) {
                if (!is_string($k) && !is_int($k)) continue;
                $key = strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $k));
                if ($key === $needle) {
                    $cursor = $v;
                    $matched = true;
                    break;
                }
            }
            if (!$matched) return null;
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

function integrationFieldMapPlacementIdFromContext(array $contextRowIds): int
{
    if (isset($contextRowIds['placement']) && (int) $contextRowIds['placement'] > 0) {
        return (int) $contextRowIds['placement'];
    }
    $hasPlacementShape = isset($contextRowIds['placement_rates'])
        || isset($contextRowIds['placement_corp_details'])
        || isset($contextRowIds['placement_commission_recruiter'])
        || isset($contextRowIds['placement_chain_end_client']);
    if ($hasPlacementShape && isset($contextRowIds['self']) && (int) $contextRowIds['self'] > 0) {
        return (int) $contextRowIds['self'];
    }
    return 0;
}

function integrationFieldMapCommissionRoleFromLinked(string $linkedEntity): string
{
    $linked = strtolower(trim($linkedEntity));
    if (str_contains($linked, 'account') || str_contains($linked, 'manager')) return 'account_manager';
    if (str_contains($linked, 'lead')) return 'lead';
    if (str_contains($linked, 'team')) return 'team';
    if (str_contains($linked, 'other')) return 'other';
    return 'recruiter';
}

function integrationFieldMapCommissionContextKey(string $linkedEntity): string
{
    return 'placement_commission_' . integrationFieldMapCommissionRoleFromLinked($linkedEntity);
}

function integrationFieldMapEnsurePlacementCommissionRow(
    int $tenantId,
    array $contextRowIds,
    string $linkedEntity
): int {
    $placementId = integrationFieldMapPlacementIdFromContext($contextRowIds);
    if ($tenantId <= 0 || $placementId <= 0) return 0;

    $role = integrationFieldMapCommissionRoleFromLinked($linkedEntity);
    try {
        $pdo = getDB();
        $existing = $pdo->prepare(
            'SELECT id
               FROM placement_commissions
              WHERE tenant_id = :t
                AND placement_id = :p
                AND role = :role
                AND (effective_to IS NULL OR effective_to >= CURRENT_DATE())
              ORDER BY effective_from DESC, id DESC
              LIMIT 1'
        );
        $existing->execute(['t' => $tenantId, 'p' => $placementId, 'role' => $role]);
        $existingId = (int) $existing->fetchColumn();
        if ($existingId > 0) return $existingId;

        $start = $pdo->prepare(
            'SELECT start_date
               FROM placements
              WHERE tenant_id = :t AND id = :p
              LIMIT 1'
        );
        $start->execute(['t' => $tenantId, 'p' => $placementId]);
        $effectiveFrom = trim((string) $start->fetchColumn());
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveFrom)) {
            $effectiveFrom = date('Y-m-d');
        }

        $pdo->prepare(
            'INSERT INTO placement_commissions
                (tenant_id, placement_id, role, basis, effective_from, notes)
             VALUES
                (:t, :p, :role, "net_margin", :ef, :notes)'
        )->execute([
            't' => $tenantId,
            'p' => $placementId,
            'role' => $role,
            'ef' => $effectiveFrom,
            'notes' => 'Source: integration field mapping projection',
        ]);
        return (int) $pdo->lastInsertId();
    } catch (\Throwable $e) {
        error_log('[field_map_apply] placement_commissions ensure failed: ' . $e->getMessage());
        return 0;
    }
}

function integrationFieldMapWritePlacementCorpDetails(int $tenantId, int $placementId, array $set): array
{
    $allowed = [
        'corp_legal_name' => true,
        'corp_address_line1' => true,
        'corp_address_line2' => true,
        'corp_city' => true,
        'corp_state' => true,
        'corp_postal_code' => true,
        'corp_country' => true,
        'corp_contact_name' => true,
        'corp_contact_email' => true,
        'corp_contact_phone' => true,
        'coi_expiry' => true,
    ];
    $clean = [];
    foreach ($set as $col => $value) {
        $col = (string) $col;
        if (!isset($allowed[$col])) continue;
        $clean[$col] = $value;
    }
    if ($tenantId <= 0 || $placementId <= 0 || !$clean) {
        return ['ok' => false, 'written' => 0, 'error' => 'no placement_corp_details values'];
    }

    try {
        $pdo = getDB();
        $exists = $pdo->prepare(
            'SELECT placement_id
               FROM placement_corp_details
              WHERE tenant_id = :t AND placement_id = :p
              LIMIT 1'
        );
        $exists->execute(['t' => $tenantId, 'p' => $placementId]);
        $hasRow = (bool) $exists->fetchColumn();

        if (!$hasRow && trim((string) ($clean['corp_legal_name'] ?? '')) === '') {
            return [
                'ok' => false,
                'written' => 0,
                'error' => 'placement_corp_details requires corp_legal_name before sibling fields can be written',
            ];
        }

        if ($hasRow) {
            $sets = [];
            $params = ['t' => $tenantId, 'p' => $placementId];
            foreach ($clean as $col => $value) {
                $ph = 'v_' . preg_replace('/[^a-z0-9_]/i', '_', $col);
                $sets[] = "`{$col}` = :{$ph}";
                $params[$ph] = $value;
            }
            $pdo->prepare(
                'UPDATE placement_corp_details
                    SET ' . implode(', ', $sets) . '
                  WHERE tenant_id = :t AND placement_id = :p'
            )->execute($params);
            return ['ok' => true, 'written' => count($clean), 'error' => ''];
        }

        $cols = ['placement_id', 'tenant_id'];
        $placeholders = [':placement_id', ':tenant_id'];
        $params = ['placement_id' => $placementId, 'tenant_id' => $tenantId];
        foreach ($clean as $col => $value) {
            $cols[] = "`{$col}`";
            $placeholders[] = ':' . $col;
            $params[$col] = $value;
        }
        $updates = [];
        foreach (array_keys($clean) as $col) {
            $updates[] = "`{$col}` = VALUES(`{$col}`)";
        }
        $pdo->prepare(
            'INSERT INTO placement_corp_details
                (' . implode(', ', $cols) . ')
             VALUES
                (' . implode(', ', $placeholders) . ')
             ON DUPLICATE KEY UPDATE ' . implode(', ', $updates)
        )->execute($params);
        return ['ok' => true, 'written' => count($clean), 'error' => ''];
    } catch (\Throwable $e) {
        return ['ok' => false, 'written' => 0, 'error' => $e->getMessage()];
    }
}

function integrationFieldMapChainRoleFromLinked(string $linkedEntity): string
{
    $linked = strtolower(trim($linkedEntity));
    if (str_contains($linked, 'end_client')) return 'end_client';
    if (str_contains($linked, 'msp')) return 'msp';
    if (str_contains($linked, 'sub')) return 'sub_vendor';
    if (str_contains($linked, 'direct')) return 'direct';
    return 'prime_vendor';
}

function integrationFieldMapChainContextKey(string $linkedEntity): string
{
    return 'placement_chain_' . integrationFieldMapChainRoleFromLinked($linkedEntity);
}

function integrationFieldMapChainPositionForRole(string $role): int
{
    return match ($role) {
        'end_client' => 0,
        'msp', 'direct' => 1,
        'prime_vendor' => 2,
        'sub_vendor' => 3,
        default => 2,
    };
}

function integrationFieldMapFindPlacementChainRow(
    int $tenantId,
    array $contextRowIds,
    string $linkedEntity
): int {
    $placementId = integrationFieldMapPlacementIdFromContext($contextRowIds);
    if ($tenantId <= 0 || $placementId <= 0) return 0;
    $role = integrationFieldMapChainRoleFromLinked($linkedEntity);
    try {
        $st = getDB()->prepare(
            'SELECT id
               FROM placement_client_chain
              WHERE tenant_id = :t
                AND placement_id = :p
                AND party_role = :role
              ORDER BY position ASC, id ASC
              LIMIT 1'
        );
        $st->execute(['t' => $tenantId, 'p' => $placementId, 'role' => $role]);
        return (int) $st->fetchColumn();
    } catch (\Throwable $e) {
        error_log('[field_map_apply] placement_client_chain lookup failed: ' . $e->getMessage());
        return 0;
    }
}

function integrationFieldMapEnsurePlacementChainRow(
    int $tenantId,
    array $contextRowIds,
    string $linkedEntity,
    mixed $partyNameRaw
): int {
    $placementId = integrationFieldMapPlacementIdFromContext($contextRowIds);
    $partyName = trim((string) $partyNameRaw);
    if ($tenantId <= 0 || $placementId <= 0 || $partyName === '') return 0;

    $role = integrationFieldMapChainRoleFromLinked($linkedEntity);
    try {
        $pdo = getDB();
        $existingId = integrationFieldMapFindPlacementChainRow($tenantId, $contextRowIds, $linkedEntity);

        $companyId = null;
        $companiesLib = dirname(__DIR__, 2) . '/modules/people/lib/companies.php';
        if (is_file($companiesLib)) {
            require_once $companiesLib;
        }
        if (function_exists('companiesUpsertByName')) {
            $companyRole = $role === 'end_client' || $role === 'direct' ? 'client' : $role;
            try {
                $companyId = companiesUpsertByName($tenantId, $partyName, [], [$companyRole]);
            } catch (\Throwable $e) {
                error_log('[field_map_apply] chain company upsert skipped: ' . $e->getMessage());
                $companyId = null;
            }
        }

        if ($existingId > 0) {
            $sql = 'UPDATE placement_client_chain
                       SET party_name = :name'
                 . (($companyId !== null && $companyId > 0) ? ', company_id = :company_id' : '')
                 . ' WHERE id = :id AND tenant_id = :t';
            $params = ['name' => $partyName, 'id' => $existingId, 't' => $tenantId];
            if ($companyId !== null && $companyId > 0) $params['company_id'] = $companyId;
            $pdo->prepare($sql)->execute($params);
            return $existingId;
        }

        $desiredPosition = integrationFieldMapChainPositionForRole($role);
        $usedStmt = $pdo->prepare(
            'SELECT position
               FROM placement_client_chain
              WHERE tenant_id = :t AND placement_id = :p'
        );
        $usedStmt->execute(['t' => $tenantId, 'p' => $placementId]);
        $used = array_map('intval', $usedStmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
        $position = $desiredPosition;
        while (in_array($position, $used, true) && $position < 255) {
            $position++;
        }

        $pdo->prepare(
            'INSERT INTO placement_client_chain
                (tenant_id, placement_id, position, party_name, party_role, company_id)
             VALUES
                (:t, :p, :pos, :name, :role, :company_id)'
        )->execute([
            't' => $tenantId,
            'p' => $placementId,
            'pos' => $position,
            'name' => $partyName,
            'role' => $role,
            'company_id' => ($companyId !== null && $companyId > 0) ? $companyId : null,
        ]);
        return (int) $pdo->lastInsertId();
    } catch (\Throwable $e) {
        error_log('[field_map_apply] placement_client_chain ensure failed: ' . $e->getMessage());
        return 0;
    }
}

function integrationFieldMapPlacementEffectiveFrom(int $tenantId, int $placementId): string
{
    if ($tenantId <= 0 || $placementId <= 0) return date('Y-m-d');
    try {
        $st = getDB()->prepare(
            'SELECT start_date
               FROM placements
              WHERE tenant_id = :t AND id = :p
              LIMIT 1'
        );
        $st->execute(['t' => $tenantId, 'p' => $placementId]);
        $start = trim((string) $st->fetchColumn());
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) return $start;
    } catch (\Throwable $e) {
        error_log('[field_map_apply] placement start_date lookup failed: ' . $e->getMessage());
    }
    return date('Y-m-d');
}

function integrationFieldMapRateUnitValue(mixed $raw, string $fallback = 'hour'): string
{
    $s = strtolower(trim((string) $raw));
    if ($s === '' || $s === 'h' || str_contains($s, 'hour')) return 'hour';
    if (str_contains($s, 'day')) return 'day';
    if (str_contains($s, 'week')) return 'week';
    if (str_contains($s, 'month')) return 'month';
    if (str_contains($s, 'project') || str_contains($s, 'fixed')) return 'project';
    return in_array($fallback, ['hour', 'day', 'week', 'month', 'project'], true) ? $fallback : 'hour';
}

function integrationFieldMapCurrencyValue(mixed $raw, string $fallback = 'USD'): string
{
    $s = strtoupper(trim((string) $raw));
    if (preg_match('/\b([A-Z]{3})\b/', $s, $m)) return $m[1];
    $s = substr($s, 0, 3);
    if (strlen($s) === 3 && preg_match('/^[A-Z]{3}$/', $s)) return $s;
    return preg_match('/^[A-Z]{3}$/', $fallback) ? $fallback : 'USD';
}

/**
 * Create the sibling placement_rates row needed by mapping overrides.
 *
 * Unlike placements/companies, placement_rates has required economics.
 * We only create a source-backed draft when BOTH bill_rate and pay_rate
 * resolve to positive values. That prevents the bad zero-margin fallback
 * where pay silently became bill just to satisfy NOT NULL constraints.
 *
 * @return array{id:int,error:string}
 */
function integrationFieldMapInsertPlacementRateRow(
    int $tenantId,
    int $placementId,
    array $set
): array {
    $billRate = array_key_exists('bill_rate', $set) ? integrationFieldMapNumberValue($set['bill_rate']) : null;
    $payRate  = array_key_exists('pay_rate', $set) ? integrationFieldMapNumberValue($set['pay_rate']) : null;
    if ($billRate === null || $billRate <= 0 || $payRate === null || $payRate <= 0) {
        return [
            'id' => 0,
            'error' => 'placement_rates_missing_required: bill_rate and pay_rate must both resolve to positive source values before creating a draft rate',
        ];
    }

    $effectiveFrom = array_key_exists('effective_from', $set)
        ? (integrationFieldMapDateValue($set['effective_from']) ?: '')
        : '';
    if ($effectiveFrom === '') {
        $effectiveFrom = integrationFieldMapPlacementEffectiveFrom($tenantId, $placementId);
    }
    $effectiveTo = array_key_exists('effective_to', $set)
        ? integrationFieldMapDateValue($set['effective_to'])
        : null;

    $billUnit = integrationFieldMapRateUnitValue($set['bill_rate_unit'] ?? 'hour');
    $payUnit  = integrationFieldMapRateUnitValue($set['pay_rate_unit'] ?? $billUnit, $billUnit);
    $currency = integrationFieldMapCurrencyValue($set['currency'] ?? 'USD');

    $ot = array_key_exists('ot_multiplier', $set) ? integrationFieldMapNumberValue($set['ot_multiplier']) : null;
    if ($ot === null || $ot <= 0) $ot = 1.50;
    $dt = array_key_exists('dt_multiplier', $set) ? integrationFieldMapNumberValue($set['dt_multiplier']) : null;
    if ($dt === null || $dt <= 0) $dt = 2.00;

    $adder = array_key_exists('adder_pct', $set) ? integrationFieldMapPercentValue($set['adder_pct']) : null;
    $backgroundFee = array_key_exists('background_fee_total', $set)
        ? integrationFieldMapNumberValue($set['background_fee_total'])
        : null;

    try {
        $pdo = getDB();
        $pdo->prepare(
            'INSERT INTO placement_rates
                (tenant_id, placement_id, effective_from, effective_to,
                 bill_rate, bill_rate_unit, pay_rate, pay_rate_unit, currency,
                 ot_multiplier, dt_multiplier, adder_pct, background_fee_total)
             VALUES
                (:t, :p, :ef, :et,
                 :br, :bru, :pr, :pru, :cur,
                 :ot, :dt, :adder, :bg)'
        )->execute([
            't' => $tenantId,
            'p' => $placementId,
            'ef' => $effectiveFrom,
            'et' => $effectiveTo,
            'br' => $billRate,
            'bru' => $billUnit,
            'pr' => $payRate,
            'pru' => $payUnit,
            'cur' => $currency,
            'ot' => $ot,
            'dt' => $dt,
            'adder' => $adder,
            'bg' => $backgroundFee,
        ]);
        return ['id' => (int) $pdo->lastInsertId(), 'error' => ''];
    } catch (\Throwable $e) {
        error_log('[field_map_apply] placement_rates insert failed: ' . $e->getMessage());
        return ['id' => 0, 'error' => 'placement_rates_insert_failed: ' . $e->getMessage()];
    }
}

function integrationFieldMapContextRowId(array $contextRowIds, array $mapping, int $tenantId = 0): int
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
        || isset($contextRowIds['placement_commission_recruiter'])
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
        'placement_commissions' => match (true) {
            str_contains($linked, 'account') || str_contains($linked, 'manager') => ['placement_commission_account_manager'],
            str_contains($linked, 'recruit') => ['placement_commission_recruiter'],
            str_contains($linked, 'lead') => ['placement_commission_lead'],
            str_contains($linked, 'team') => ['placement_commission_team'],
            str_contains($linked, 'other') => ['placement_commission_other'],
            default => [
                'placement_commission_recruiter',
                'placement_commission_account_manager',
                'placement_commission_lead',
                'placement_commission_team',
                'placement_commission_other',
            ],
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
    if ($tenantId > 0 && $table === 'placement_commissions') {
        return integrationFieldMapEnsurePlacementCommissionRow($tenantId, $contextRowIds, $linked);
    }
    if ($tenantId > 0 && $table === 'placement_client_chain') {
        return integrationFieldMapFindPlacementChainRow($tenantId, $contextRowIds, $linked);
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

function integrationFieldMapNumberValue(mixed $raw): ?float
{
    if ($raw === null) return null;
    if (is_int($raw) || is_float($raw)) return (float) $raw;
    $s = trim((string) $raw);
    if ($s === '') return null;
    $s = str_replace([',', '$'], '', $s);
    if (is_numeric($s)) return (float) $s;
    if (preg_match('/-?\d+(?:\.\d+)?/', $s, $m)) return (float) $m[0];
    return null;
}

function integrationFieldMapPercentValue(mixed $raw): ?float
{
    if ($raw === null) return null;
    if (is_int($raw) || is_float($raw)) {
        $n = (float) $raw;
    } else {
        $s = trim((string) $raw);
        if ($s === '') return null;
        $hadPercent = str_contains($s, '%');
        $s = str_replace([',', '%'], '', $s);
        if (!is_numeric($s)) {
            if (!preg_match('/-?\d+(?:\.\d+)?/', $s, $m)) return null;
            $s = $m[0];
        }
        $n = (float) $s;
        if ($hadPercent) $n /= 100;
    }
    if (abs($n) > 1) $n /= 100;
    return round($n, 6);
}

function integrationFieldMapDateValue(mixed $raw): ?string
{
    if ($raw === null) return null;
    if (function_exists('jobdivaNormaliseDate')) {
        $normalised = jobdivaNormaliseDate($raw);
        if ($normalised !== null) return $normalised;
    }
    $s = trim((string) $raw);
    if ($s === '') return null;
    if (is_numeric($s)) {
        $n = (float) $s;
        $ts = $n >= 1000000000000 ? (int) floor($n / 1000) : (int) $n;
        if ($ts > 0) return gmdate('Y-m-d', $ts);
    }
    $ts = strtotime($s);
    return $ts === false ? null : date('Y-m-d', $ts);
}

function integrationFieldMapBoolValue(mixed $raw): ?int
{
    if ($raw === null) return null;
    if (is_bool($raw)) return $raw ? 1 : 0;
    if (is_int($raw) || is_float($raw)) return ((float) $raw) > 0 ? 1 : 0;
    $s = strtolower(trim((string) $raw));
    if ($s === '') return null;
    if (in_array($s, ['1', 'true', 'yes', 'y', 'on', 'checked'], true)) return 1;
    if (in_array($s, ['0', 'false', 'no', 'n', 'off', 'unchecked'], true)) return 0;
    return null;
}

function integrationFieldMapEngagementValue(mixed $raw): ?string
{
    $s = trim((string) $raw);
    if ($s === '') return null;
    if (function_exists('jobdivaNormalisePlacementEngagementType')) {
        $normalised = jobdivaNormalisePlacementEngagementType($s, '');
        if ($normalised !== '') return $normalised;
    }
    $key = strtolower(str_replace(['-', '/', '\\'], ' ', $s));
    $key = preg_replace('/\s+/', ' ', $key) ?: $key;
    if (str_contains($key, 'temp to perm') || str_contains($key, 'contract to hire')) return 'temp_to_perm';
    if (str_contains($key, 'direct hire') || str_contains($key, 'perm')) return 'direct_hire';
    if (str_contains($key, '1099') || str_contains($key, 'independent contractor')) return '1099';
    if (str_contains($key, 'c2c') || str_contains($key, 'corp to corp') || str_contains($key, 'crop to crop')) return 'c2c';
    if (str_contains($key, 'w2') || str_contains($key, 'w 2') || str_contains($key, 'employee')) return 'w2';
    return in_array($key, ['w2', '1099', 'c2c', 'temp_to_perm', 'direct_hire', 'internal'], true) ? $key : null;
}

function integrationFieldMapPersonClassificationValue(mixed $raw): ?string
{
    $eng = integrationFieldMapEngagementValue($raw);
    if ($eng !== null) {
        return match ($eng) {
            'temp_to_perm' => 'temp',
            'direct_hire' => 'perm',
            default => $eng,
        };
    }
    $key = strtolower(trim((string) $raw));
    return in_array($key, ['temp', 'perm', 'candidate', 'alumni'], true) ? $key : null;
}

function integrationFieldMapCoerceTargetValue(mixed $val, array $mapping): mixed
{
    $table = strtolower(trim((string) ($mapping['target_table'] ?? '')));
    $col = strtolower(trim((string) ($mapping['target_column'] ?? '')));
    if ($table === '' || $col === '') return $val;

    if ($table === 'placements' && $col === 'engagement_type') {
        return integrationFieldMapEngagementValue($val);
    }
    if ($table === 'people' && $col === 'classification') {
        return integrationFieldMapPersonClassificationValue($val);
    }
    if ($table === 'people' && $col === 'worker_class') {
        $eng = integrationFieldMapEngagementValue($val);
        if ($eng !== null) {
            return match ($eng) {
                '1099' => 'contractor_1099',
                'c2c' => 'c2c',
                'w2' => 'w2_temp',
                default => 'employee',
            };
        }
    }
    if ($table === 'placements' && $col === 'remote_policy') {
        $s = strtolower(trim((string) $val));
        return match ($s) {
            'onsite', 'on-site', 'on site', 'on_site' => 'onsite',
            'hybrid' => 'hybrid',
            'remote', 'work from home', 'work_from_home', 'wfh' => 'remote',
            default => null,
        };
    }
    if ($table === 'placements' && in_array($col, ['client_bill_cycle', 'vendor_pay_cycle'], true)) {
        $s = strtolower(trim((string) $val));
        $s = preg_replace('/[\s(].*$/', '', $s) ?: $s;
        return match ($s) {
            'weekly' => 'weekly',
            'biweekly', 'bi-weekly', 'bi_weekly' => 'biweekly',
            'semimonthly', 'semi-monthly', 'semi_monthly' => 'semimonthly',
            'monthly' => 'monthly',
            'adhoc', 'ad-hoc', 'ad_hoc' => 'adhoc',
            default => null,
        };
    }
    if ($table === 'placement_client_chain' && $col === 'party_role') {
        $s = strtolower(trim((string) $val));
        $s = str_replace([' ', '-'], '_', $s);
        return match ($s) {
            'end_client', 'client', 'customer' => 'end_client',
            'msp', 'vms', 'managed_service_provider' => 'msp',
            'prime_vendor', 'vendor', 'supplier', 'agency' => 'prime_vendor',
            'sub_vendor', 'subcontractor', 'sub_supplier' => 'sub_vendor',
            'direct' => 'direct',
            default => null,
        };
    }
    if ($table === 'placement_rates' && in_array($col, ['bill_rate_unit', 'pay_rate_unit'], true)) {
        $s = strtolower(trim((string) $val));
        if ($s === '' || $s === 'h' || str_contains($s, 'hour')) return 'hour';
        if (str_contains($s, 'day')) return 'day';
        if (str_contains($s, 'week')) return 'week';
        if (str_contains($s, 'month')) return 'month';
        if (str_contains($s, 'project') || str_contains($s, 'fixed')) return 'project';
        return null;
    }
    if ($table === 'placement_rates' && $col === 'currency') {
        $s = strtoupper(trim((string) $val));
        if (preg_match('/\b([A-Z]{3})\b/', $s, $m)) return $m[1];
        $s = substr($s, 0, 3);
        return strlen($s) === 3 ? $s : null;
    }
    if (str_ends_with($col, '_date')
        || in_array($col, ['effective_from', 'effective_to', 'opened_at', 'closed_at', 'hire_date', 'termination_date', 'work_auth_expiry'], true)
        || str_ends_with($col, '_signed_at')
        || str_ends_with($col, '_expires_on')
        || str_ends_with($col, '_expiry')) {
        return integrationFieldMapDateValue($val);
    }
    if (str_ends_with($col, '_pct') || in_array($col, ['split_pct', 'adder_pct', 'portal_fee_pct'], true)) {
        return integrationFieldMapPercentValue($val);
    }
    if (in_array($col, ['bill_rate', 'pay_rate', 'flat_amount', 'portal_fee_flat', 'background_fee_total', 'ot_multiplier', 'dt_multiplier'], true)) {
        return integrationFieldMapNumberValue($val);
    }
    if (str_starts_with($col, 'is_') || in_array($col, [
        'tokenized_email_approval_enabled',
        'bulk_uploads_can_be_pre_approved',
        'requires_sponsorship',
        'w9_on_file',
        'coi_on_file',
    ], true)) {
        return integrationFieldMapBoolValue($val);
    }
    return $val;
}

function integrationFieldMapShouldSkipUnsafeJobDivaEngagement(
    string $integration,
    string $entityType,
    array $mapping,
    mixed $rawValue,
    mixed $coercedValue,
    array $payload
): bool {
    if (strtolower($integration) !== 'jobdiva' || strtolower($entityType) !== 'placement') return false;
    if ($coercedValue !== 'c2c') return false;
    $table = strtolower(trim((string) ($mapping['target_table'] ?? '')));
    $col = strtolower(trim((string) ($mapping['target_column'] ?? '')));
    if ($table !== 'placements' || $col !== 'engagement_type') return false;

    if (function_exists('jobdivaNormalisePlacementEngagementType')
        && jobdivaNormalisePlacementEngagementType((string) $rawValue, '') === 'c2c') {
        return false;
    }
    if (function_exists('jobdivaPlacementPayloadHasC2CProof')) {
        return !jobdivaPlacementPayloadHasC2CProof($payload);
    }

    return false;
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
    $bucket = []; // key: "tbl#id" or "placement_rates@placement#id" => write bucket

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
        $rawValForSafety = $val;
        $val = integrationFieldMapCoerceTargetValue($val, $m);
        if ($val === null || $val === '') { $summary['skipped']++; continue; }
        if (integrationFieldMapShouldSkipUnsafeJobDivaEngagement(
            $integration,
            $entityType,
            $m,
            $rawValForSafety,
            $val,
            $payload
        )) {
            $summary['skipped']++;
            $summary['errors'][] = "unsafe_jobdiva_engagement_c2c target={$m['target_table']}.{$m['target_column']} (mapping_id={$m['id']})";
            continue;
        }

        $linked = (string) ($m['linked_entity'] ?? 'self');
        $table = (string) $m['target_table'];
        $col   = (string) $m['target_column'];
        $tableLower = strtolower($table);
        $colLower = strtolower($col);

        $rowId  = integrationFieldMapContextRowId($contextRowIds, $m, $tid);
        $pendingPlacementRateFor = 0;
        if ($rowId <= 0 && $tableLower === 'placement_client_chain' && $colLower === 'party_name') {
            $rowId = integrationFieldMapEnsurePlacementChainRow($tid, $contextRowIds, $linked, $val);
        }
        if ($rowId <= 0 && $tableLower === 'placement_rates') {
            $pendingPlacementRateFor = integrationFieldMapPlacementIdFromContext($contextRowIds);
        }
        if ($rowId <= 0 && $pendingPlacementRateFor <= 0) {
            $summary['skipped']++;
            $summary['errors'][] = "no_context_row for linked_entity={$linked} target={$m['target_table']}.{$m['target_column']} (mapping_id={$m['id']})";
            continue;
        }
        if ($tableLower === 'placement_commissions') {
            $contextRowIds[integrationFieldMapCommissionContextKey($linked)] = $rowId;
        } elseif ($tableLower === 'placement_client_chain') {
            $contextRowIds[integrationFieldMapChainContextKey($linked)] = $rowId;
        }

        if (integrationFieldMapIsProtectedTarget($table, $col)) {
            $summary['skipped']++;
            $summary['errors'][] = "protected_target {$table}.{$col} (mapping_id={$m['id']})";
            continue;
        }
        $key = $pendingPlacementRateFor > 0
            ? $table . '@placement#' . $pendingPlacementRateFor
            : $table . '#' . $rowId;
        if (!isset($bucket[$key])) {
            $bucket[$key] = [
                'table' => $table,
                'id' => $rowId,
                'placement_id' => $pendingPlacementRateFor,
                'set' => [],
                'cf' => [],
            ];
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
                $tableLower = strtolower((string) ($b['table'] ?? ''));
                if ($tableLower === 'placement_rates' && (int) ($b['id'] ?? 0) <= 0) {
                    $placementId = (int) ($b['placement_id'] ?? 0);
                    $insert = integrationFieldMapInsertPlacementRateRow($tid, $placementId, $b['set']);
                    if ((int) ($insert['id'] ?? 0) > 0) {
                        $summary['written'] += count($b['set']);
                    } else {
                        $summary['skipped'] += count($b['set']);
                        $summary['errors'][] = (string) ($insert['error'] ?? 'placement_rates_insert_failed');
                    }
                    continue;
                }
                if ($tableLower === 'placement_corp_details') {
                    $corpWrite = integrationFieldMapWritePlacementCorpDetails($tid, (int) $b['id'], $b['set']);
                    if (!empty($corpWrite['ok'])) {
                        $summary['written'] += (int) ($corpWrite['written'] ?? 0);
                    } else {
                        $summary['errors'][] = "write_fail {$b['table']}#{$b['id']}: " . (string) ($corpWrite['error'] ?? 'unknown error');
                    }
                    continue;
                }
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
