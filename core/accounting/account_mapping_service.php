<?php
/**
 * core/accounting/account_mapping_service.php
 * --------------------------------------------
 * Per-entity CoreFlux account ↔ provider account mapping. Provider-neutral
 * — Jaz, Zoho Books, QBO and Xero all flow through the same
 * `accounting_account_mappings` table because they all need the same
 * thing: when the CoreFlux GL emits a JE referencing account "1100
 * Accounts Receivable" we need to know what that account is called
 * inside the destination so the outbox can render a valid payload.
 *
 * Storage:
 *   tenant_id, sub_tenant_id, provider     ← scope
 *   coreflux_account_id (FK accounting_accounts.id)
 *   coreflux_account_code (denormalised — survives if the CF row is deleted)
 *   provider_account_id   — destination's own id (string; QBO Id, Jaz uuid, Zoho id)
 *   provider_account_code, name, type — denormalised for the mapping UI
 *   source: 'manual' | 'auto_code' | 'auto_name' | 'imported'
 *   confidence: 0–100, only the UI uses it (rendered as a badge)
 *
 * The (tenant, sub, provider, coreflux_account_id) UNIQUE constraint
 * makes every UPSERT exact. Provider-side dupes (two CF accounts mapped
 * to the same provider account) are ALLOWED and intentional — many CF
 * tenants merge multiple sub-accounts into a single destination account
 * to simplify the books.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

function accountingAccountMappingsList(int $tenantId, int $subTenantId, string $provider): array
{
    $stmt = getDB()->prepare(
        "SELECT m.id, m.coreflux_account_id, m.coreflux_account_code,
                a.name AS coreflux_account_name, a.account_type AS coreflux_account_type,
                m.provider_account_id, m.provider_account_code, m.provider_account_name,
                m.provider_account_type, m.confidence, m.source, m.notes,
                m.last_synced_at, m.created_at, m.updated_at
           FROM accounting_account_mappings m
           LEFT JOIN accounting_accounts a
                  ON a.id = m.coreflux_account_id AND a.tenant_id = m.tenant_id
          WHERE m.tenant_id = :t AND m.sub_tenant_id = :st AND m.provider = :p
          ORDER BY m.coreflux_account_code"
    );
    $stmt->execute(['t' => $tenantId, 'st' => $subTenantId, 'p' => $provider]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['id']                  = (int) $r['id'];
        $r['coreflux_account_id'] = (int) $r['coreflux_account_id'];
        $r['confidence']          = (int) $r['confidence'];
    }
    unset($r);
    return $rows;
}

/**
 * Return CoreFlux accounts NOT YET mapped for this (entity, provider).
 * Used by the mapping UI's "Unmapped accounts" panel + the auto-map suggester.
 */
function accountingAccountMappingsUnmapped(int $tenantId, int $subTenantId, string $provider): array
{
    $stmt = getDB()->prepare(
        "SELECT a.id, a.code, a.name, a.account_type, a.normal_side
           FROM accounting_accounts a
           LEFT JOIN accounting_account_mappings m
                  ON m.coreflux_account_id = a.id AND m.sub_tenant_id = :st AND m.provider = :p
          WHERE a.tenant_id = :t AND a.active = 1 AND m.id IS NULL
          ORDER BY a.code"
    );
    $stmt->execute(['t' => $tenantId, 'st' => $subTenantId, 'p' => $provider]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) $r['id'] = (int) $r['id'];
    unset($r);
    return $rows;
}

/**
 * Insert / update a single mapping. UPSERT key is (tenant, sub, provider,
 * coreflux_account_id) so re-saving the same CF account just rewrites
 * the provider side.
 */
function accountingAccountMappingsSave(
    int $tenantId,
    int $subTenantId,
    string $provider,
    array $data,
    ?int $userId = null
): array {
    $cfId = (int) ($data['coreflux_account_id'] ?? 0);
    if ($cfId <= 0) throw new \InvalidArgumentException('coreflux_account_id required');

    // Denormalise the CF code so the row stays useful even if the CF
    // account row is soft-deleted later.
    $cfRow = getDB()->prepare(
        'SELECT id, code FROM accounting_accounts WHERE tenant_id = :t AND id = :id LIMIT 1'
    );
    $cfRow->execute(['t' => $tenantId, 'id' => $cfId]);
    $cf = $cfRow->fetch(\PDO::FETCH_ASSOC);
    if (!$cf) throw new \InvalidArgumentException('coreflux account not found in this tenant');

    $providerAccountId = trim((string) ($data['provider_account_id'] ?? ''));
    if ($providerAccountId === '') throw new \InvalidArgumentException('provider_account_id required');

    $source = (string) ($data['source'] ?? 'manual');
    if (!in_array($source, ['manual','auto_code','auto_name','imported'], true)) $source = 'manual';
    $confidence = (int) ($data['confidence'] ?? ($source === 'manual' ? 100 : 80));
    if ($confidence < 0)   $confidence = 0;
    if ($confidence > 100) $confidence = 100;

    getDB()->prepare(
        'INSERT INTO accounting_account_mappings
           (tenant_id, sub_tenant_id, provider,
            coreflux_account_id, coreflux_account_code,
            provider_account_id, provider_account_code,
            provider_account_name, provider_account_type,
            confidence, source, notes, created_by_user_id)
         VALUES
           (:t, :st, :p,
            :cfid, :cfcode,
            :paid, :pcode, :pname, :ptype,
            :conf, :src, :notes, :uid)
         ON DUPLICATE KEY UPDATE
            provider_account_id   = VALUES(provider_account_id),
            provider_account_code = VALUES(provider_account_code),
            provider_account_name = VALUES(provider_account_name),
            provider_account_type = VALUES(provider_account_type),
            confidence            = VALUES(confidence),
            source                = VALUES(source),
            notes                 = VALUES(notes),
            updated_at            = NOW()'
    )->execute([
        't'      => $tenantId,
        'st'     => $subTenantId,
        'p'      => $provider,
        'cfid'   => $cfId,
        'cfcode' => $cf['code'],
        'paid'   => $providerAccountId,
        'pcode'  => $data['provider_account_code'] ?? null,
        'pname'  => $data['provider_account_name'] ?? null,
        'ptype'  => $data['provider_account_type'] ?? null,
        'conf'   => $confidence,
        'src'    => $source,
        'notes'  => $data['notes'] ?? null,
        'uid'    => $userId,
    ]);

    // Return the canonical row.
    $stmt = getDB()->prepare(
        'SELECT * FROM accounting_account_mappings
          WHERE tenant_id = :t AND sub_tenant_id = :st AND provider = :p
            AND coreflux_account_id = :cfid LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'st' => $subTenantId, 'p' => $provider, 'cfid' => $cfId]);
    return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
}

function accountingAccountMappingsDelete(int $tenantId, int $subTenantId, string $provider, int $mappingId): void
{
    getDB()->prepare(
        'DELETE FROM accounting_account_mappings
          WHERE id = :id AND tenant_id = :t AND sub_tenant_id = :st AND provider = :p'
    )->execute(['id' => $mappingId, 't' => $tenantId, 'st' => $subTenantId, 'p' => $provider]);
}

/**
 * Bulk auto-map by exact-code match between CoreFlux accounts and the
 * provider's chart of accounts (fetched live via the adapter).
 * Returns ['mapped' => N, 'skipped_existing' => N, 'no_provider_match' => N]
 * and a list of the new mapping rows so the UI can render them.
 *
 * Only creates mappings — never deletes. Operators stay in control of
 * destructive changes.
 */
function accountingAccountMappingsAutoMap(int $tenantId, int $subTenantId, string $provider, ?int $userId = null): array
{
    require_once __DIR__ . '/provider_adapter.php';

    // 1. Get the provider's CoA. Each adapter normalises this to:
    //    [{provider_id, code, name, type}, ...]
    $adapter = accountingProviderAdapterFor($provider);
    try {
        $report = $adapter->getChartOfAccounts($tenantId, $subTenantId, []);
    } catch (\Throwable $e) {
        return ['error' => 'Provider CoA fetch failed: ' . $e->getMessage(), 'mapped' => 0];
    }
    $providerAccounts = is_array($report['accounts'] ?? null) ? $report['accounts'] : [];
    if (empty($providerAccounts)) {
        return ['error' => 'Provider returned no accounts', 'mapped' => 0];
    }
    // Build code→row + name→row lookups.  Some providers (Jaz) don't
    // expose account codes at all and rely on name as the natural key —
    // we fall back to case-insensitive name matching when no codes are
    // present.  Both maps are case-insensitive.
    $byCode = [];
    $byName = [];
    $nameNorm = static function (string $s): string {
        $s = strtolower(trim($s));
        // Strip provider parent-path prefixes (e.g. "Travel:Vehicle rental"
        // → "vehicle rental") so a CoreFlux "Vehicle Rental" matches Jaz's
        // nested-path naming convention.
        $tail = strrchr($s, ':');
        if ($tail !== false) $s = ltrim($tail, ':');
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return $s;
    };
    foreach ($providerAccounts as $pa) {
        $code = strtolower((string) ($pa['code'] ?? ''));
        if ($code !== '') $byCode[$code] = $pa;
        $n = $nameNorm((string) ($pa['name'] ?? ''));
        if ($n !== '' && !isset($byName[$n])) {
            $byName[$n] = $pa; // first-write wins (skip duplicate-name shadows)
        }
    }
    $hasCodes = !empty($byCode);

    // 2. Walk unmapped CF accounts.
    $unmapped = accountingAccountMappingsUnmapped($tenantId, $subTenantId, $provider);
    $newMappings   = [];
    $noMatch       = 0;
    $matchedByName = 0;
    $matchedByCode = 0;
    foreach ($unmapped as $cf) {
        $cfCode = strtolower((string) ($cf['code'] ?? ''));
        $cfName = $nameNorm((string) ($cf['name'] ?? ''));

        $pa = null; $source = ''; $confidence = 0;
        if ($cfCode !== '' && isset($byCode[$cfCode])) {
            $pa = $byCode[$cfCode]; $source = 'auto_code'; $confidence = 80;
            $matchedByCode++;
        } elseif ($cfName !== '' && isset($byName[$cfName])) {
            $pa = $byName[$cfName]; $source = 'auto_name'; $confidence = 60;
            $matchedByName++;
        }
        if (!$pa) { $noMatch++; continue; }

        $newMappings[] = accountingAccountMappingsSave(
            $tenantId, $subTenantId, $provider,
            [
                'coreflux_account_id'   => (int) $cf['id'],
                'provider_account_id'   => (string) ($pa['id']   ?? $pa['provider_id'] ?? ''),
                'provider_account_code' => (string) ($pa['code'] ?? ''),
                'provider_account_name' => (string) ($pa['name'] ?? ''),
                'provider_account_type' => (string) ($pa['type'] ?? ''),
                'source'                => $source,
                'confidence'            => $confidence,
            ],
            $userId
        );
    }

    $out = [
        'mapped'             => count($newMappings),
        'no_provider_match'  => $noMatch,
        'matched_by_code'    => $matchedByCode,
        'matched_by_name'    => $matchedByName,
        'provider_has_codes' => $hasCodes,
        'new_mappings'       => $newMappings,
    ];
    // Operators benefit from knowing WHY a run was empty.
    if (count($newMappings) === 0 && !$hasCodes && empty($byName)) {
        $out['error'] = 'Provider accounts carry no codes or names — auto-map unavailable';
    } elseif (count($newMappings) === 0 && !$hasCodes) {
        $out['note'] = 'Provider has no account codes; tried name-match against ' . count($byName) . ' provider rows but found no matches — review the Step 4 list and map manually';
    } elseif ($matchedByName > 0) {
        $out['note'] = "Auto-mapped {$matchedByCode} by code + {$matchedByName} by name — name matches are confidence=60, please confirm.";
    }
    return $out;
}

/**
 * Push-side counterpart of accountingAccountMappingsAutoMap().
 *
 * For every unmapped CoreFlux account that does NOT yet have a provider
 * counterpart with the matching code, create one on the provider via
 * the adapter's createAccount() method and persist the mapping.
 *
 * Per-account best-effort: a failure on any one account never blocks
 * the rest of the batch.  The returned `errors` array surfaces them so
 * the UI can flag rows that didn't take.
 *
 * Returns:
 *   - pushed:           int — how many provider rows were created
 *   - skipped_existing: int — how many CF accounts already had a code-match upstream
 *   - errors:           list — per-account error rows
 *   - new_mappings:     list — freshly created mapping rows
 */
function accountingAccountMappingsPushToProvider(int $tenantId, int $subTenantId, string $provider, ?int $userId = null): array
{
    require_once __DIR__ . '/provider_adapter.php';
    $adapter = accountingProviderAdapterFor($provider);

    // Snapshot the provider's existing CoA so we can skip codes that
    // already live on the destination (treat that as "skipped_existing"
    // rather than re-creating it — the auto-pull mapper would have
    // mapped those anyway).
    try {
        $report = $adapter->getChartOfAccounts($tenantId, $subTenantId, []);
    } catch (\Throwable $e) {
        return ['error' => 'Provider CoA pre-fetch failed: ' . $e->getMessage(), 'pushed' => 0];
    }
    $providerCodes = [];
    foreach (($report['accounts'] ?? []) as $pa) {
        $code = strtolower((string) ($pa['code'] ?? ''));
        if ($code !== '') $providerCodes[$code] = $pa;
    }

    $unmapped = accountingAccountMappingsUnmapped($tenantId, $subTenantId, $provider);
    $skippedExisting = 0;
    $errors          = [];
    $newMappings     = [];
    foreach ($unmapped as $cf) {
        $code = strtolower((string) ($cf['code'] ?? ''));
        if ($code === '') continue;
        if (isset($providerCodes[$code])) {
            // Provider already has this code — map to the existing one
            // (the same thing the pull auto-mapper would have done).
            $pa = $providerCodes[$code];
            $newMappings[] = accountingAccountMappingsSave(
                $tenantId, $subTenantId, $provider,
                [
                    'coreflux_account_id'   => (int) $cf['id'],
                    'provider_account_id'   => (string) ($pa['id']   ?? $pa['provider_id'] ?? ''),
                    'provider_account_code' => (string) ($pa['code'] ?? ''),
                    'provider_account_name' => (string) ($pa['name'] ?? ''),
                    'provider_account_type' => (string) ($pa['type'] ?? ''),
                    'source'                => 'auto_code',
                    'confidence'            => 80,
                ],
                $userId
            );
            $skippedExisting++;
            continue;
        }
        try {
            $idem = 'coa_push:' . $tenantId . ':' . $subTenantId . ':' . $cf['code'];
            $created = $adapter->createAccount($tenantId, $subTenantId, [
                'code'        => (string) ($cf['code']  ?? ''),
                'name'        => (string) ($cf['name']  ?? ''),
                'type'        => (string) ($cf['account_type'] ?? 'asset'),
                'currency'    => 'USD',
                'description' => 'Pushed from CoreFlux',
            ], $idem);
            $newMappings[] = accountingAccountMappingsSave(
                $tenantId, $subTenantId, $provider,
                [
                    'coreflux_account_id'   => (int) $cf['id'],
                    'provider_account_id'   => (string) $created['provider_object_id'],
                    'provider_account_code' => (string) ($created['provider_account_code'] ?? $cf['code']),
                    'provider_account_name' => (string) ($created['provider_account_name'] ?? $cf['name']),
                    'provider_account_type' => (string) ($created['provider_account_type'] ?? $cf['account_type']),
                    'source'                => 'imported',
                    'confidence'            => 100,
                ],
                $userId
            );
        } catch (\Throwable $e) {
            $errors[] = [
                'coreflux_account_id' => (int) $cf['id'],
                'code'                => (string) ($cf['code'] ?? ''),
                'error'               => $e->getMessage(),
            ];
        }
    }

    return [
        'pushed'           => count($newMappings) - $skippedExisting,
        'skipped_existing' => $skippedExisting,
        'errors'           => $errors,
        'new_mappings'     => $newMappings,
    ];
}

/**
 * Look up the provider-side account id given a CoreFlux account id.
 * Returns null if unmapped — outbox enqueue should then route to the
 * "needs mapping" dead-letter queue instead of failing the push.
 */
function accountingAccountMappingLookup(int $tenantId, int $subTenantId, string $provider, int $coreFluxAccountId): ?array
{
    $stmt = getDB()->prepare(
        'SELECT provider_account_id, provider_account_code, provider_account_name, confidence
           FROM accounting_account_mappings
          WHERE tenant_id = :t AND sub_tenant_id = :st AND provider = :p
            AND coreflux_account_id = :cfid LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'st' => $subTenantId, 'p' => $provider, 'cfid' => $coreFluxAccountId]);
    $r = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $r ?: null;
}
