<?php
/**
 * core/accounting/account_import.php
 * ------------------------------------------------------------------
 * Imports provider chart-of-accounts rows that have no CoreFlux
 * counterpart into the tenant's `accounting_accounts` table, and
 * records `accounting_account_mappings` rows linking the new CF rows
 * back to the provider source.
 *
 * Called from `accountingAccountMappingsAutoMap()` as the third pass
 * (after code-based + name-based mapping).  Operators get a true
 * PULL semantic — they don't have to type the CoA manually if their
 * accounting backend already has it.
 *
 * Code allocation:
 *   - Provider CoAs (Jaz today) often have no native codes.  We
 *     allocate per-bucket sequentially:
 *       asset     → 1001, 1002, 1003, … (skipping codes already taken)
 *       liability → 2001, 2002, …
 *       equity    → 3001, …
 *       revenue   → 4001, …
 *       expense   → 5001, …
 *   - The "first hundred" of each bucket are usually reserved for the
 *     tenant's own canonical accounts, so we start at +1 to avoid
 *     colliding with the bank-shared `1000 Cash — Checking` row Plaid
 *     creates by default.
 *   - If the provider DOES expose a code, we use it verbatim (after
 *     truncating to 40 chars to fit the CHAR column).
 *
 * Idempotency: skips any provider_account_id present in the
 * `$alreadyConsumed` set (already mapped from this or a prior run).
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/account_mapping_service.php';

/**
 * @param array<int, array> $providerAccounts  Normalised provider rows.
 * @param array<string, bool> $alreadyConsumed  provider_account_id → true.
 * @return array{imported: int, errors: array<int, array>, allocated_codes: array<string, int>}
 */
function accountingImportProviderAccounts(
    int $tenantId, int $subTenantId, string $provider,
    array $providerAccounts, array $alreadyConsumed, ?int $userId = null
): array {
    if (empty($providerAccounts)) {
        return ['imported' => 0, 'errors' => [], 'allocated_codes' => []];
    }
    $pdo = getDB();

    // Pre-load every CF code already taken for this tenant so we can
    // allocate without N round-trips.
    $taken = [];
    $st = $pdo->prepare('SELECT code FROM accounting_accounts WHERE tenant_id = :t');
    $st->execute(['t' => $tenantId]);
    foreach ($st->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $c) {
        $taken[strtolower((string) $c)] = true;
    }

    // Per-bucket cursor — starts at base+1 (e.g. asset → 1001).
    $bucketBase = [
        'asset'     => 1000,
        'liability' => 2000,
        'equity'    => 3000,
        'revenue'   => 4000,
        'expense'   => 5000,
    ];
    $normalSide = [
        'asset'     => 'debit',
        'liability' => 'credit',
        'equity'    => 'credit',
        'revenue'   => 'credit',
        'expense'   => 'debit',
    ];
    $cursor = [];
    foreach ($bucketBase as $b => $_) $cursor[$b] = 1; // start at +1

    $imported = 0; $errors = []; $allocatedCodes = [];
    foreach ($providerAccounts as $pa) {
        $pid = (string) ($pa['id'] ?? $pa['provider_id'] ?? '');
        if ($pid === '')                continue;       // unidentifiable
        if (!empty($alreadyConsumed[$pid])) continue;   // already mapped

        $bucket = strtolower((string) ($pa['type'] ?? ''));
        if (!isset($bucketBase[$bucket])) {
            $errors[] = [
                'provider_id' => $pid,
                'name'        => (string) ($pa['name'] ?? ''),
                'error'       => "unrecognized account_type \"{$bucket}\"",
            ];
            continue;
        }
        $name = trim((string) ($pa['name'] ?? ''));
        if ($name === '') {
            $errors[] = ['provider_id' => $pid, 'error' => 'provider row missing name'];
            continue;
        }
        // If the provider DOES expose a code, prefer it (truncate to 40
        // chars to fit the column).  Otherwise allocate within the
        // bucket sequentially.
        $providerCode = trim((string) ($pa['code'] ?? ''));
        if ($providerCode !== '') {
            $code = substr($providerCode, 0, 40);
            if (isset($taken[strtolower($code)])) {
                // Collision — fall through to bucket-allocator instead
                // of overwriting an existing CF row.
                $code = '';
            }
        } else { $code = ''; }
        if ($code === '') {
            // Walk the bucket cursor forward until a free slot opens.
            $base = $bucketBase[$bucket];
            $tries = 0;
            do {
                $candidate = (string) ($base + $cursor[$bucket]++);
                if ($tries++ > 9999) {
                    $errors[] = [
                        'provider_id' => $pid,
                        'name'        => $name,
                        'error'       => "bucket {$bucket} exhausted (>9999 attempts)",
                    ];
                    continue 2;
                }
            } while (isset($taken[strtolower($candidate)]));
            $code = $candidate;
        }
        $taken[strtolower($code)] = true;

        $currency = strtoupper(substr((string) ($pa['currency'] ?? 'USD'), 0, 3)) ?: null;
        $desc     = (string) ($pa['description'] ?? '');
        if (strlen($desc) > 500) $desc = substr($desc, 0, 500);

        try {
            $pdo->prepare(
                "INSERT INTO accounting_accounts
                    (tenant_id, code, name, account_type, normal_side,
                     parent_account_id, is_postable, currency, description,
                     active, created_at, updated_at)
                 VALUES
                    (:t, :c, :n, :at, :ns, NULL, 1, :cur, :d, 1, NOW(), NOW())"
            )->execute([
                't'   => $tenantId,
                'c'   => $code,
                'n'   => substr($name, 0, 255),
                'at'  => $bucket,
                'ns'  => $normalSide[$bucket],
                'cur' => $currency,
                'd'   => ($desc !== '' ? $desc : null),
            ]);
            $newCfId = (int) $pdo->lastInsertId();
        } catch (\Throwable $e) {
            $errors[] = [
                'provider_id' => $pid,
                'name'        => $name,
                'error'       => 'INSERT failed: ' . $e->getMessage(),
            ];
            continue;
        }

        // Save the mapping so subsequent syncs treat this Jaz row as
        // already-mapped — source = 'imported', confidence = 100.
        try {
            accountingAccountMappingsSave(
                $tenantId, $subTenantId, $provider,
                [
                    'coreflux_account_id'   => $newCfId,
                    'provider_account_id'   => $pid,
                    'provider_account_code' => $providerCode,
                    'provider_account_name' => $name,
                    'provider_account_type' => $bucket,
                    'source'                => 'imported',
                    'confidence'            => 100,
                ],
                $userId
            );
        } catch (\Throwable $e) {
            // Roll back the INSERT — the operator can re-run and it'll
            // re-attempt cleanly thanks to the consumed-set lookup.
            try {
                $pdo->prepare('DELETE FROM accounting_accounts WHERE id = :id AND tenant_id = :t LIMIT 1')
                    ->execute(['id' => $newCfId, 't' => $tenantId]);
            } catch (\Throwable $_) { /* swallow */ }
            $errors[] = [
                'provider_id' => $pid,
                'name'        => $name,
                'error'       => 'mapping save failed: ' . $e->getMessage(),
            ];
            unset($taken[strtolower($code)]);
            continue;
        }
        $allocatedCodes[$pid] = $code;
        $imported++;
    }

    return [
        'imported'        => $imported,
        'errors'          => $errors,
        'allocated_codes' => $allocatedCodes,
    ];
}
