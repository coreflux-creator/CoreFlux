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
        // chart_of_accounts can't be "push" or "two_way" — the destination
        // owns its CoA. Coerce silently rather than reject so legacy rows
        // don't trap operators.
        if ($entity === 'chart_of_accounts' && !in_array($val, ['pull','off'], true)) {
            $val = 'pull';
        }
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
        if ($entity === 'chart_of_accounts' && !in_array($val, ['pull','off'], true)) {
            $val = 'pull';
        }
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
