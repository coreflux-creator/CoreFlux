<?php
/**
 * /app/core/airtable/sync_slice4.php
 *
 * Reconciliation queue helpers introduced in Slice 4:
 *   • airtableSearchInternalEntities — typeahead over the lookup table
 *     for a given entity, used by the Reconcile UI when an operator
 *     wants to manually link an unmatched row.
 *   • airtableCreateStubFromVault — promote a single
 *     external_entity_mappings vault row into a brand-new CoreFlux
 *     entity row, wired back to the vault via internal_entity_id.
 *   • airtablePromoteVaultMapping — bulk "promote" a stored-only
 *     mapping (entity=generic / link_strategy=none) onto a real entity
 *     + strategy. Updates the mapping, then re-runs linkage and
 *     optionally creates stubs for everything left unmatched.
 *
 * Kept in its own file so we don't bloat sync.php further; loaded
 * lazily by api/airtable.php's new case branches.
 */
declare(strict_types=1);

require_once __DIR__ . '/sync.php';

/**
 * Search the lookup table behind a given Airtable internal_entity for
 * rows that match $q. Returns at most $limit rows shaped for a UI
 * dropdown. Soft-fails to [] when the entity has no defined lookup
 * table (note / task / opportunity / generic).
 *
 * @return array<int, array{id:int, label:string, sublabel:?string}>
 */
function airtableSearchInternalEntities(int $tenantId, string $entity, string $q, int $limit = 20): array
{
    $defaults = AIRTABLE_ENTITY_LINK_DEFAULTS[$entity] ?? null;
    $table    = $defaults[1] ?? null;
    $defCol   = $defaults[2] ?? null;
    if (!$table) return [];

    // Column whitelist per table — keeps SQL identifiers locked down.
    static $tableCols = [
        'placements'        => ['id', 'external_id', 'placement_external_id', 'first_name', 'last_name', 'job_title'],
        'people'            => ['id', 'email_primary', 'first_name', 'last_name', 'phone_primary'],
        'companies'         => ['id', 'name', 'website', 'industry'],
        'ap_vendors_index'  => ['id', 'vendor_name', 'vendor_email', 'tax_id_last4'],
    ];
    $cols = $tableCols[$table] ?? ['id'];

    // Build a tenant-scoped LIKE query across the searchable columns.
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return [];
    $likeCols = array_filter($cols, fn ($c) => $c !== 'id' && preg_match('/^[A-Za-z0-9_]+$/', $c));
    if (!$likeCols) return [];

    $whereLike = implode(' OR ', array_map(fn ($c) => "`{$c}` LIKE :q", $likeCols));
    $select    = implode(', ', array_map(fn ($c) => "`{$c}`", $cols));
    $needle    = '%' . $q . '%';

    try {
        $pdo  = getDB();
        $sql  = "SELECT {$select} FROM `{$table}`
                  WHERE tenant_id = :t
                    AND ({$whereLike})
               ORDER BY id DESC
                  LIMIT {$limit}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['t' => $tenantId, 'q' => $needle]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $r) {
        $label = '';
        $sub   = null;
        switch ($table) {
            case 'placements':
                $label = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: ('Placement #' . $r['id']);
                $sub   = (string) ($r['placement_external_id'] ?? $r['external_id'] ?? $r['job_title'] ?? '');
                break;
            case 'people':
                $label = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: (string) ($r['email_primary'] ?? ('Person #' . $r['id']));
                $sub   = (string) ($r['email_primary'] ?? '');
                break;
            case 'companies':
                $label = (string) ($r['name'] ?? ('Company #' . $r['id']));
                $sub   = (string) ($r['website'] ?? $r['industry'] ?? '');
                break;
            case 'ap_vendors_index':
                $label = (string) ($r['vendor_name'] ?? ('Vendor #' . $r['id']));
                $sub   = (string) ($r['vendor_email'] ?? '');
                break;
            default:
                $label = $defCol && isset($r[$defCol]) ? (string) $r[$defCol] : '#' . $r['id'];
        }
        $out[] = [
            'id'       => (int) $r['id'],
            'label'    => $label,
            'sublabel' => $sub !== '' ? $sub : null,
        ];
    }
    return $out;
}

/**
 * Try to pluck a value from an Airtable payload using a small set of
 * common aliases. The lookup is case-insensitive and whitespace-
 * insensitive so "First Name", "first_name", "firstname" all match.
 */
function _airtablePluckFieldAlias(array $fields, array $aliases): ?string
{
    $norm = [];
    foreach ($fields as $k => $v) {
        if (!is_string($k)) continue;
        $key = strtolower(preg_replace('/[^a-z0-9]/i', '', $k));
        $norm[$key] = is_array($v) ? ($v[0] ?? null) : $v;
    }
    foreach ($aliases as $a) {
        $key = strtolower(preg_replace('/[^a-z0-9]/i', '', $a));
        if (isset($norm[$key]) && $norm[$key] !== '' && $norm[$key] !== null) {
            return is_scalar($norm[$key]) ? (string) $norm[$key] : null;
        }
    }
    return null;
}

/**
 * Create a minimal CoreFlux entity row from an Airtable payload.
 * Returns the new internal id, or null when we can't infer enough
 * fields to create a valid row (e.g. no name on a company stub).
 */
function airtableCreateStubFromVault(
    int $tenantId, string $entity, array $payload, string $externalId, ?int $userId
): ?int {
    $defaults = AIRTABLE_ENTITY_LINK_DEFAULTS[$entity] ?? null;
    $table    = $defaults[1] ?? null;
    if (!$table) return null;
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return null;

    // Strip the Airtable-injected metadata so the field map below
    // never sees `_airtable_record_url` etc.
    $fields = [];
    foreach ($payload as $k => $v) {
        if (is_string($k) && $k !== '' && $k[0] !== '_') $fields[$k] = $v;
    }

    // Build the INSERT row per entity. Returns null when the minimum
    // viable column set isn't reachable — operators see "skipped:
    // insufficient_data" in the rollup.
    $row = null;
    switch ($entity) {
        case 'company':
        case 'customer': {
            $name = _airtablePluckFieldAlias($fields, ['Company', 'Company Name', 'Name', 'name']);
            if (!$name) return null;
            $row = [
                'tenant_id'  => $tenantId,
                'name'       => substr($name, 0, 255),
                'website'    => _airtablePluckFieldAlias($fields, ['Website', 'URL', 'Domain']) ?: null,
                'industry'   => _airtablePluckFieldAlias($fields, ['Industry', 'Sector']) ?: null,
                'created_at' => date('Y-m-d H:i:s'),
            ];
            break;
        }
        case 'contact': {
            $email = _airtablePluckFieldAlias($fields, ['Email', 'Email Address', 'Primary Email']);
            if (!$email) return null;
            $first = _airtablePluckFieldAlias($fields, ['First Name', 'firstname', 'fname']);
            $last  = _airtablePluckFieldAlias($fields, ['Last Name',  'lastname',  'lname',  'surname']);
            if (!$first && !$last) {
                // Fall back to splitting a combined "Name" field.
                $combined = _airtablePluckFieldAlias($fields, ['Name', 'Full Name', 'Contact Name']);
                if ($combined) {
                    $parts = preg_split('/\s+/', trim($combined), 2);
                    $first = $parts[0] ?? null;
                    $last  = $parts[1] ?? null;
                }
            }
            $row = [
                'tenant_id'      => $tenantId,
                'email_primary'  => substr($email, 0, 255),
                'first_name'     => $first ? substr($first, 0, 120) : null,
                'last_name'      => $last  ? substr($last,  0, 120) : null,
                'phone_primary'  => _airtablePluckFieldAlias($fields, ['Phone', 'Mobile', 'Phone Number']) ?: null,
                'created_at'     => date('Y-m-d H:i:s'),
            ];
            break;
        }
        case 'vendor': {
            $name = _airtablePluckFieldAlias($fields, ['Vendor', 'Vendor Name', 'Name', 'Supplier']);
            if (!$name) return null;
            $row = [
                'tenant_id'    => $tenantId,
                'vendor_name'  => substr($name, 0, 255),
                'vendor_email' => _airtablePluckFieldAlias($fields, ['Email', 'AP Email', 'Billing Email']) ?: null,
                'created_at'   => date('Y-m-d H:i:s'),
            ];
            break;
        }
        case 'placement': {
            $first = _airtablePluckFieldAlias($fields, ['First Name', 'firstname']);
            $last  = _airtablePluckFieldAlias($fields, ['Last Name',  'lastname']);
            $extId = _airtablePluckFieldAlias($fields, ['External ID', 'Placement ID', 'placement_external_id']) ?: $externalId;
            if (!$first && !$last && !$extId) return null;
            $row = [
                'tenant_id'              => $tenantId,
                'external_id'            => substr((string) $extId, 0, 120),
                'first_name'             => $first ? substr($first, 0, 120) : null,
                'last_name'              => $last  ? substr($last,  0, 120) : null,
                'job_title'              => _airtablePluckFieldAlias($fields, ['Title', 'Job Title', 'Role']) ?: null,
                'created_at'             => date('Y-m-d H:i:s'),
            ];
            break;
        }
        default:
            return null;
    }
    if (!$row) return null;

    // Build INSERT dynamically across whatever columns came back. Skip
    // nulls so we don't trip NOT NULL columns that have defaults.
    $cols = array_filter(array_keys($row), fn ($k) => $row[$k] !== null);
    if (count($cols) <= 1) return null;
    $placeholders = array_map(fn ($c) => ":{$c}", $cols);
    $sql = sprintf(
        'INSERT INTO `%s` (%s) VALUES (%s)',
        $table,
        implode(', ', array_map(fn ($c) => "`{$c}`", $cols)),
        implode(', ', $placeholders)
    );
    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare($sql);
        $bind = [];
        foreach ($cols as $c) $bind[$c] = $row[$c];
        $stmt->execute($bind);
        $newId = (int) $pdo->lastInsertId();
        airtableAudit($tenantId, 'create_stub', [
            'actor_user_id' => $userId,
            'detail' => [
                'entity'      => $entity,
                'table'       => $table,
                'external_id' => $externalId,
                'new_id'      => $newId,
                'columns'     => array_values($cols),
            ],
        ]);
        return $newId;
    } catch (\Throwable $e) {
        error_log('[airtableCreateStubFromVault] ' . $e->getMessage());
        return null;
    }
}

/**
 * Bulk promote a mapping from its current (entity, link_strategy) to a
 * new pair. Updates the mapping row, re-runs the linker across every
 * vault row, and (if $createStubs) creates a CoreFlux entity for every
 * vault row that's still unmatched after re-linking.
 *
 * Returns rollup: { scanned, linked, stubs_created, stubs_failed,
 *                   still_unmatched, still_ambiguous }.
 */
function airtablePromoteVaultMapping(
    int $tenantId, int $mappingId, array $newPolicy, bool $createStubs, ?int $userId
): array {
    $mapping = airtableMappingGet($tenantId, $mappingId);
    if (!$mapping) throw new \RuntimeException('Mapping not found');

    $payload = [
        'id'                          => $mappingId,
        'base_id'                     => $mapping['base_id'],
        'base_name'                   => $mapping['base_name'],
        'table_id'                    => $mapping['table_id'],
        'table_name'                  => $mapping['table_name'],
        'direction'                   => $mapping['direction'],
        'internal_entity'             => $newPolicy['internal_entity'] ?? $mapping['internal_entity'],
        'field_map'                   => json_decode((string) ($mapping['field_map'] ?? '{}'), true) ?: [],
        'link_strategy'               => $newPolicy['link_strategy']                ?? 'external_id',
        'link_match_airtable_field'   => $newPolicy['link_match_airtable_field']    ?? null,
        'link_match_internal_column'  => $newPolicy['link_match_internal_column']   ?? null,
        'link_unmatched_action'       => $newPolicy['link_unmatched_action']        ?? 'park',
    ];
    airtableMappingUpsert($tenantId, $payload, $userId);

    // Re-pull the mapping so we resolve again with the new policy.
    $mapping = airtableMappingGet($tenantId, $mappingId);

    // If the operator switched entity types, vault rows are still
    // stored under the OLD internal_entity_type — migrate them across
    // so the linker can find them.
    $oldEntity = (string) ($newPolicy['_previous_entity'] ?? '');
    if ($oldEntity !== '' && $oldEntity !== $mapping['internal_entity']) {
        $migrate = getDB()->prepare(
            "UPDATE external_entity_mappings
                SET internal_entity_type = :new
              WHERE tenant_id = :t
                AND source_system = 'airtable'
                AND internal_entity_type = :old"
        );
        $migrate->execute([
            'new' => $mapping['internal_entity'],
            't'   => $tenantId,
            'old' => $oldEntity,
        ]);
    }

    $stmt = getDB()->prepare(
        "SELECT id, external_id, payload_snapshot
           FROM external_entity_mappings
          WHERE tenant_id = :t
            AND source_system = 'airtable'
            AND internal_entity_type = :et"
    );
    $stmt->execute(['t' => $tenantId, 'et' => $mapping['internal_entity']]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $scanned = 0; $linked = 0; $stillUnmatched = 0; $stillAmbiguous = 0;
    $stubsCreated = 0; $stubsFailed = 0;
    $upd = getDB()->prepare(
        "UPDATE external_entity_mappings
            SET internal_entity_id = :iid,
                sync_status = :s,
                last_synced_at = NOW()
          WHERE id = :id AND tenant_id = :t"
    );
    foreach ($rows as $r) {
        $scanned++;
        $snap = json_decode((string) ($r['payload_snapshot'] ?? '[]'), true);
        if (!is_array($snap)) $snap = [];

        // Re-strip Airtable metadata when handing the snapshot to the
        // linker as if it were the raw $fields payload.
        $fields = [];
        foreach ($snap as $k => $v) {
            if (is_string($k) && $k !== '' && $k[0] !== '_') $fields[$k] = $v;
        }

        $resolved = airtableResolveLink($tenantId, $mapping, (string) $r['external_id'], $fields);
        $newId    = $resolved['internal_id'] ?? null;
        $status   = $resolved['sync_status'];

        if ($status !== 'ok' && $createStubs) {
            $stubId = airtableCreateStubFromVault(
                $tenantId, $mapping['internal_entity'], $snap, (string) $r['external_id'], $userId
            );
            if ($stubId !== null) {
                $newId  = $stubId;
                $status = 'ok';
                $stubsCreated++;
            } else {
                $stubsFailed++;
            }
        }

        $upd->execute([
            'iid' => $newId ?: 0,
            's'   => $status,
            'id'  => (int) $r['id'],
            't'   => $tenantId,
        ]);
        if      ($status === 'ok')        $linked++;
        elseif  ($status === 'unmatched') $stillUnmatched++;
        elseif  ($status === 'ambiguous') $stillAmbiguous++;
    }

    $rollup = [
        'scanned'         => $scanned,
        'linked'          => $linked,
        'stubs_created'   => $stubsCreated,
        'stubs_failed'    => $stubsFailed,
        'still_unmatched' => $stillUnmatched,
        'still_ambiguous' => $stillAmbiguous,
        'mapping_id'      => $mappingId,
        'new_entity'      => $mapping['internal_entity'],
        'link_strategy'   => $mapping['link_strategy'],
    ];

    airtableAudit($tenantId, 'promote_vault', [
        'base_id' => $mapping['base_id'], 'table_id' => $mapping['table_id'],
        'actor_user_id' => $userId,
        'items_processed' => $scanned,
        'detail' => $rollup + ['create_stubs' => $createStubs],
    ]);
    return $rollup;
}
