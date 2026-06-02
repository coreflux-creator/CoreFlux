<?php
/**
 * core/accounting/sync_config_service.php
 * ---------------------------------------
 * Per-entity sync configuration for the provider-neutral accounting
 * backend. Each (tenant_id, sub_tenant_id, provider) row in
 * accounting_provider_connections carries its own sync_config JSON:
 *
 *   {
 *     "journal_entries":   "push" | "pull" | "two_way" | "off",
 *     "contacts":          ...,
 *     "invoices":          ...,
 *     "bills":             ...,
 *     "payments":          ...,
 *     "chart_of_accounts": "pull" | "off",
 *     "intercompany":      "push" | "off"     // distinct from journal_entries:
 *                                              // ON means intercompany JEs
 *                                              // sync alongside ordinary ones.
 *   }
 *
 * Defaults are "off" everywhere so an entity has to opt-in entity-type
 * by entity-type. Consolidation + elimination JEs ignore the toggle
 * entirely (they're CoreFlux-platform-only by spec) — see
 * accountingShouldSyncJournalEntry() below.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

const ACC_SYNC_ENTITY_TYPES = [
    'journal_entries',
    'intercompany',
    'contacts',
    'invoices',
    'bills',
    'payments',
    'chart_of_accounts',
];

const ACC_SYNC_DIRECTIONS = ['push', 'pull', 'two_way', 'off'];

/**
 * Return the sync_config for one (tenant, sub_tenant, provider), with
 * defaults filled in. Always returns every key in ACC_SYNC_ENTITY_TYPES
 * (callers can therefore index without a guard).
 */
function accountingSyncConfigGet(int $tenantId, int $subTenantId, string $provider): array
{
    $stmt = getDB()->prepare(
        'SELECT sync_config FROM accounting_provider_connections
          WHERE tenant_id = :t AND sub_tenant_id = :st AND provider = :p
          LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'st' => $subTenantId, 'p' => $provider]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    $raw = $row ? json_decode((string) ($row['sync_config'] ?? ''), true) : [];
    if (!is_array($raw)) $raw = [];

    $config = [];
    foreach (ACC_SYNC_ENTITY_TYPES as $entity) {
        $val = $raw[$entity] ?? 'off';
        if (!in_array($val, ACC_SYNC_DIRECTIONS, true)) $val = 'off';
        // chart_of_accounts is now bi-directional capable — operators
        // commonly want to author the CoA in CoreFlux and push it to
        // the destination ledger (Jaz, Zoho, QBO). Previously this was
        // coerced to 'pull'|'off' because the destination was assumed
        // to own its CoA; lifted 2026-02 per direction.
        $config[$entity] = $val;
    }
    return $config;
}

/**
 * Persist sync_config — only known entity types are accepted; unknown keys
 * are silently dropped so a malicious or stale UI doesn't pollute the JSON.
 * Returns the canonical (post-normalization) config.
 */
function accountingSyncConfigSave(int $tenantId, int $subTenantId, string $provider, array $config): array
{
    $normalized = [];
    foreach (ACC_SYNC_ENTITY_TYPES as $entity) {
        $val = $config[$entity] ?? 'off';
        if (!in_array($val, ACC_SYNC_DIRECTIONS, true)) $val = 'off';
        // CoA bi-directional lifted 2026-02; see read-side comment above.
        $normalized[$entity] = $val;
    }
    getDB()->prepare(
        'UPDATE accounting_provider_connections
            SET sync_config = :cfg
          WHERE tenant_id = :t AND sub_tenant_id = :st AND provider = :p'
    )->execute([
        'cfg' => json_encode($normalized, JSON_UNESCAPED_SLASHES),
        't'   => $tenantId,
        'st'  => $subTenantId,
        'p'   => $provider,
    ]);
    return $normalized;
}

/**
 * High-level predicate the writers call:
 *   if (accountingShouldSync($tid, $sub, 'jaz', 'invoices', 'push')) { … }
 *
 * Returns true when the configured direction is the requested one OR
 * "two_way". Returns false if no connection exists.
 */
function accountingShouldSync(int $tenantId, int $subTenantId, string $provider, string $entityType, string $direction): bool
{
    if (!in_array($entityType, ACC_SYNC_ENTITY_TYPES, true)) return false;
    if (!in_array($direction,  ACC_SYNC_DIRECTIONS,   true)) return false;
    $cfg     = accountingSyncConfigGet($tenantId, $subTenantId, $provider);
    $current = $cfg[$entityType] ?? 'off';
    if ($current === 'off') return false;
    if ($current === $direction) return true;
    if ($current === 'two_way' && in_array($direction, ['push','pull'], true)) return true;
    return false;
}

/**
 * Copy sync_config (and optionally account mappings) from one entity to
 * another inside the same master tenant. Tenants with 10+ legal entities
 * routinely want identical sync rules across all of them — without this,
 * an admin has to re-pick the matrix on every entity. With this they
 * click "Copy from EAST" and the new entity inherits the entire policy.
 *
 * Safe by default: target entity's existing sync_config / mappings are
 * REPLACED when $overwriteExisting is true (default), otherwise the call
 * fails when either is non-empty so a misclick doesn't trample real config.
 *
 * Returns: ['sync_config' => …, 'mappings_copied' => N, 'mappings_skipped' => N]
 */
function accountingSyncConfigCopy(
    int $tenantId,
    int $fromSubTenantId,
    int $toSubTenantId,
    string $provider,
    bool $includeAccountMappings = true,
    bool $overwriteExisting = true
): array {
    if ($fromSubTenantId === $toSubTenantId) {
        throw new \InvalidArgumentException('from and to sub_tenant_id must differ');
    }
    // 1. Source must have a connection at all.
    $pdo = getDB();
    $check = $pdo->prepare(
        'SELECT id FROM accounting_provider_connections
          WHERE tenant_id = :t AND sub_tenant_id = :st AND provider = :p LIMIT 1'
    );
    $check->execute(['t' => $tenantId, 'st' => $fromSubTenantId, 'p' => $provider]);
    if (!$check->fetchColumn()) {
        throw new \InvalidArgumentException('Source entity has no ' . $provider . ' connection to copy from');
    }
    $check->execute(['t' => $tenantId, 'st' => $toSubTenantId, 'p' => $provider]);
    if (!$check->fetchColumn()) {
        throw new \InvalidArgumentException('Target entity has no ' . $provider . ' connection yet — connect it first');
    }

    // 2. Pull source sync_config and apply to target (full overwrite is the
    //    expected semantics — it's a "copy" not a "merge").
    $sourceCfg = accountingSyncConfigGet($tenantId, $fromSubTenantId, $provider);
    $targetCfg = accountingSyncConfigGet($tenantId, $toSubTenantId, $provider);
    if (!$overwriteExisting) {
        $nonOff = array_filter($targetCfg, fn($v) => $v !== 'off');
        if (!empty($nonOff)) {
            throw new \InvalidArgumentException('Target entity already has a non-empty sync_config; pass overwrite=true to replace it');
        }
    }
    $saved = accountingSyncConfigSave($tenantId, $toSubTenantId, $provider, $sourceCfg);

    // 3. Optionally copy account mappings. Only copy mappings for CF
    //    accounts that exist on the TARGET tenant (almost always identical
    //    in CoreFlux since the CoA is master-tenant-scoped, but we guard
    //    anyway). We never delete on the target; if a mapping for the
    //    same CF account already exists on the target it's overwritten
    //    when overwriteExisting=true, otherwise skipped.
    $copied = 0; $skipped = 0;
    if ($includeAccountMappings) {
        require_once __DIR__ . '/account_mapping_service.php';
        $sourceMappings = accountingAccountMappingsList($tenantId, $fromSubTenantId, $provider);
        $existing = $pdo->prepare(
            'SELECT coreflux_account_id FROM accounting_account_mappings
              WHERE tenant_id = :t AND sub_tenant_id = :st AND provider = :p'
        );
        $existing->execute(['t' => $tenantId, 'st' => $toSubTenantId, 'p' => $provider]);
        $alreadyMapped = array_flip(array_map('intval', $existing->fetchAll(\PDO::FETCH_COLUMN) ?: []));

        foreach ($sourceMappings as $m) {
            $cfId = (int) $m['coreflux_account_id'];
            if (isset($alreadyMapped[$cfId]) && !$overwriteExisting) { $skipped++; continue; }
            try {
                accountingAccountMappingsSave($tenantId, $toSubTenantId, $provider, [
                    'coreflux_account_id'   => $cfId,
                    'provider_account_id'   => $m['provider_account_id'],
                    'provider_account_code' => $m['provider_account_code'] ?? null,
                    'provider_account_name' => $m['provider_account_name'] ?? null,
                    'provider_account_type' => $m['provider_account_type'] ?? null,
                    'source'                => 'imported',
                    // Inherit the source's confidence; original auto-map ratings stay intact.
                    'confidence'            => (int) ($m['confidence'] ?? 100),
                    'notes'                 => 'Copied from entity #' . $fromSubTenantId,
                ]);
                $copied++;
            } catch (\Throwable $e) { $skipped++; }
        }
    }

    return [
        'sync_config'      => $saved,
        'mappings_copied'  => $copied,
        'mappings_skipped' => $skipped,
        'from'             => $fromSubTenantId,
        'to'               => $toSubTenantId,
    ];
}

/**
 * JE-specific guard: returns true if a JE should be enqueued for sync.
 * Skips consolidation/elimination JEs always (platform-only by spec).
 * Honours `intercompany` separately when the JE is flagged intercompany.
 */
function accountingShouldSyncJournalEntry(int $tenantId, int $subTenantId, string $provider, array $je): bool
{
    // Consolidation / elimination — never sync.
    if ((int) ($je['is_consolidation_entry'] ?? 0) === 1) return false;
    $memo = strtolower((string) ($je['memo'] ?? ''));
    if (strpos($memo, 'consolidation') !== false || strpos($memo, 'elimination') !== false) {
        return false;
    }
    // Intercompany has its own toggle (separate from journal_entries) so
    // operators can sync ordinary JEs without intercompany or vice versa.
    $isIntercompany = (int) ($je['intercompany_group_id'] ?? 0) > 0
                    || strpos($memo, 'intercompany') !== false;
    $entityType = $isIntercompany ? 'intercompany' : 'journal_entries';
    return accountingShouldSync($tenantId, $subTenantId, $provider, $entityType, 'push');
}

