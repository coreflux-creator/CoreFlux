<?php
/**
 * core/qbo/account_import.php
 * ------------------------------------------------------------------
 * QBO CoA parity with Jaz — imports unmapped QBO accounts directly
 * into `accounting_accounts` and seeds an `external_entity_mappings`
 * row so the JE pusher's resolver (`qboResolveAccountRef`) finds
 * the freshly-created CF account on the next push without manual
 * intervention.
 *
 * Why this file is QBO-specific (vs. re-using
 * `core/accounting/account_import.php` like Jaz does):
 *   - Jaz writes to `accounting_account_mappings` (Slice 4 mapping
 *     table with `source/confidence` semantics).
 *   - QBO writes to `external_entity_mappings` (Sprint 8a / Slice
 *     A2 generic store, keyed on `qbo_id ↔ accounting_accounts.id`
 *     and read by `qboResolveAccountRef`).
 *   Two different mapping registries → two different importers.
 *   Both share the same bucket-allocator strategy (1001+ for asset,
 *   2001+ for liability, etc.) so the resulting CF rows look
 *   consistent regardless of source.
 *
 * Public surface:
 *   qboImportUnmappedAccounts(int $tenantId, array $qboSamples, ?int $userId=null): array
 *       returns {imported, errors[], allocated_codes[]}
 *
 *   qboAccountCreateManualMapping(int $tenantId, string $qboId, int $cfAccountId, ?int $userId=null): array
 *       writes an `external_entity_mappings` row so the operator
 *       can resolve a QBO row to a hand-picked existing CF account
 *       directly from the QboSettings UI.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../integrations/entity_mappings.php';
require_once __DIR__ . '/client.php';

/**
 * @param array<int, array{qbo_id:string, name:string, acct_num?:?string,
 *                          classification?:?string, currency?:?string,
 *                          payload?:?array}> $qboSamples
 *   QBO sample rows from `qboSyncAccounts()` (the `unmapped_samples`
 *   envelope field).  Each must carry `qbo_id` + `name`.
 *
 * @return array{imported:int, errors:array<int,array>, allocated_codes:array<string,string>}
 */
function qboImportUnmappedAccounts(int $tenantId, array $qboSamples, ?int $userId = null): array
{
    if (empty($qboSamples)) {
        return ['imported' => 0, 'errors' => [], 'allocated_codes' => []];
    }
    $pdo = getDB();

    // Pre-load every CF code already taken for this tenant so we can
    // allocate without N round-trips (same pattern as the Jaz importer).
    $taken = [];
    $st = $pdo->prepare('SELECT code FROM accounting_accounts WHERE tenant_id = :t');
    $st->execute(['t' => $tenantId]);
    foreach ($st->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $c) {
        $taken[strtolower((string) $c)] = true;
    }

    // QBO Classification → CF account_type + normal_side.
    // QBO's "Classification" is the high-level bucket; "AccountType" is
    // a sub-type ("Bank", "Accounts Receivable", etc.) we don't need
    // for the CF model.
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
    foreach ($bucketBase as $b => $_) $cursor[$b] = 1;

    $imported = 0; $errors = []; $allocated = [];
    foreach ($qboSamples as $row) {
        $qboId = trim((string) ($row['qbo_id'] ?? ''));
        $name  = trim((string) ($row['name']   ?? ''));
        if ($qboId === '' || $name === '') {
            $errors[] = ['qbo_id' => $qboId, 'error' => 'sample missing qbo_id or name'];
            continue;
        }
        // Defensive — if a mapping already exists from a parallel pull,
        // skip the import so we don't double-insert.
        if (mappingFindInternal($tenantId, QBO_SOURCE, 'account', $qboId)) {
            continue;
        }

        $bucket = qboClassificationToBucket((string) ($row['classification'] ?? ''));
        if ($bucket === null) {
            $errors[] = [
                'qbo_id' => $qboId, 'name' => $name,
                'error'  => 'unrecognized classification "' . ($row['classification'] ?? '') . '"',
            ];
            continue;
        }

        // Prefer QBO's AcctNum if present; fall back to bucket allocator.
        $providerCode = trim((string) ($row['acct_num'] ?? ''));
        $code = '';
        if ($providerCode !== '') {
            $candidate = substr($providerCode, 0, 40);
            if (!isset($taken[strtolower($candidate)])) {
                $code = $candidate;
            }
        }
        if ($code === '') {
            $base  = $bucketBase[$bucket];
            $tries = 0;
            do {
                $candidate = (string) ($base + $cursor[$bucket]++);
                if ($tries++ > 9999) {
                    $errors[] = [
                        'qbo_id' => $qboId, 'name' => $name,
                        'error'  => "bucket {$bucket} exhausted (>9999 attempts)",
                    ];
                    continue 2;
                }
            } while (isset($taken[strtolower($candidate)]));
            $code = $candidate;
        }
        $taken[strtolower($code)] = true;

        $currency = strtoupper(substr((string) ($row['currency'] ?? 'USD'), 0, 3)) ?: null;

        try {
            $pdo->prepare(
                "INSERT INTO accounting_accounts
                    (tenant_id, code, name, account_type, normal_side,
                     parent_account_id, is_postable, currency,
                     active, created_at, updated_at)
                 VALUES
                    (:t, :c, :n, :at, :ns, NULL, 1, :cur, 1, NOW(), NOW())"
            )->execute([
                't'   => $tenantId,
                'c'   => $code,
                'n'   => substr($name, 0, 255),
                'at'  => $bucket,
                'ns'  => $normalSide[$bucket],
                'cur' => $currency,
            ]);
            $newCfId = (int) $pdo->lastInsertId();
        } catch (\Throwable $e) {
            $errors[] = [
                'qbo_id' => $qboId, 'name' => $name,
                'error'  => 'INSERT failed: ' . $e->getMessage(),
            ];
            continue;
        }

        // Seed external_entity_mappings so qboResolveAccountRef() picks
        // this up on the next JE push without another COA pull.
        try {
            $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [
                'Id'             => $qboId,
                'Name'           => $name,
                'AcctNum'        => $providerCode !== '' ? $providerCode : null,
                'Classification' => $row['classification'] ?? null,
                'CurrencyRef'    => ['value' => $currency],
            ];
            mappingUpsert($tenantId, QBO_SOURCE, 'account', $qboId, $newCfId, $payload, 'pull');
        } catch (\Throwable $e) {
            // Roll back the CF row — keeps the import idempotent on retry.
            try {
                $pdo->prepare('DELETE FROM accounting_accounts WHERE id = :id AND tenant_id = :t LIMIT 1')
                    ->execute(['id' => $newCfId, 't' => $tenantId]);
            } catch (\Throwable $_) { /* swallow */ }
            $errors[] = [
                'qbo_id' => $qboId, 'name' => $name,
                'error'  => 'mapping save failed: ' . $e->getMessage(),
            ];
            unset($taken[strtolower($code)]);
            continue;
        }
        $allocated[$qboId] = $code;
        $imported++;
    }

    // One audit row capturing the whole batch — operators see this in
    // the QboSettings recent-audit feed.
    if ($imported > 0 || $errors) {
        qboAudit($tenantId, 'import_qbo_accounts', [
            'entity_type' => 'account', 'direction' => 'pull',
            'ok' => true, 'actor_user_id' => $userId,
            'items_processed' => $imported,
            'items_skipped'   => 0,
            'items_failed'    => count($errors),
            'detail' => [
                'allocated_codes' => $allocated,
                'errors'          => array_slice($errors, 0, 20),
            ],
        ]);
    }

    return ['imported' => $imported, 'errors' => $errors, 'allocated_codes' => $allocated];
}

/**
 * Create a manual mapping between a QBO account id and an existing
 * CoreFlux accounting_accounts row.  Used by the inline "Map this to…"
 * dropdown on QboSettings → unmapped accounts.
 *
 * @return array{ok:bool, mapping:array, cf_account:array}
 */
function qboAccountCreateManualMapping(int $tenantId, string $qboId, int $cfAccountId, ?int $userId = null): array
{
    $qboId = trim($qboId);
    if ($qboId === '') throw new \RuntimeException('qbo_id required');
    if ($cfAccountId <= 0) throw new \RuntimeException('cf_account_id required');

    $pdo = getDB();
    $st = $pdo->prepare('SELECT id, code, name, account_type FROM accounting_accounts WHERE tenant_id = :t AND id = :id LIMIT 1');
    $st->execute(['t' => $tenantId, 'id' => $cfAccountId]);
    $cf = $st->fetch(\PDO::FETCH_ASSOC);
    if (!$cf) throw new \RuntimeException("CF account #{$cfAccountId} not found in tenant {$tenantId}");

    // Defensive: refuse to overwrite an existing mapping silently. Operator
    // must explicitly remove the old one first (which sync_accounts auto-
    // refreshes on every pull, so they're never stale by accident).
    $existing = mappingFindInternal($tenantId, QBO_SOURCE, 'account', $qboId);
    if ($existing && (int) $existing['internal_entity_id'] !== $cfAccountId) {
        throw new \RuntimeException(
            sprintf('QBO account %s is already mapped to CF account #%d — remove that mapping first.',
                $qboId, (int) $existing['internal_entity_id'])
        );
    }

    $payload = ['Id' => $qboId, 'mapped_by_operator' => true, 'manual_mapping_at' => date('c')];
    $up = mappingUpsert($tenantId, QBO_SOURCE, 'account', $qboId, $cfAccountId, $payload, 'pull');

    qboAudit($tenantId, 'manual_account_map', [
        'entity_type' => 'account', 'direction' => 'pull',
        'ok' => true, 'actor_user_id' => $userId,
        'items_processed' => 1,
        'detail' => [
            'qbo_id'        => $qboId,
            'cf_account_id' => $cfAccountId,
            'cf_code'       => (string) ($cf['code'] ?? ''),
            'cf_name'       => (string) ($cf['name'] ?? ''),
            'was_changed'   => (bool) ($up['changed'] ?? false),
        ],
    ]);

    return [
        'ok'         => true,
        'mapping'    => $up,
        'cf_account' => $cf,
    ];
}

/**
 * QBO `Classification` (Title-cased) → CF account_type bucket.
 * QBO's enum: Asset / Liability / Equity / Revenue / Expense.  Some
 * older accounts return blank — we treat blank as null so the caller
 * can decide what to do (the importer logs an error; the resolver
 * skips).
 */
function qboClassificationToBucket(string $classification): ?string
{
    $c = strtolower(trim($classification));
    if ($c === '') return null;
    static $map = [
        'asset'     => 'asset',
        'liability' => 'liability',
        'equity'    => 'equity',
        'revenue'   => 'revenue',
        'income'    => 'revenue',   // legacy QBO returns "Income" sometimes
        'expense'   => 'expense',
        // Cost-of-goods rows show up as Classification='Expense' so they
        // fold naturally; nothing else to map.
    ];
    return $map[$c] ?? null;
}
