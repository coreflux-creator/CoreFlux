<?php
/**
 * Zoho Books — Slice 3: Chart of Accounts mirror (pull).
 *
 * Replaces the per-line `account_code` auto-discovery shortcut from
 * Slice 2 with a bulk pull of every Zoho Books CoA account into
 * `external_entity_mappings`. Once a run lands, the JE pusher's
 * `zohoBooksResolveAccountRef()` hits the cache and `skipped_unmapped`
 * drops to near-zero (only truly absent accounts remain).
 *
 * Match strategy (mirrors QBO Slice 4a exactly):
 *   1. existing mapping (zoho account_id ↔ accounting_accounts.id)
 *   2. match by account_code = accounting_accounts.code
 *   3. no match → record under `unmapped_zoho_accounts` audit so the
 *      CFO can either rename Zoho to match or extend the CoreFlux CoA.
 *
 * Public surface:
 *   zohoBooksSyncChartOfAccounts(int $tid, ?int $userId, array $opts=[]): array
 *
 * Opts:
 *   - limit:     int  (default 2000, cap 5000)
 *   - max_pages: int  (default 20, cap 100)
 */
declare(strict_types=1);

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/sync_je.php';                 // ZOHO_BOOKS_SOURCE constant
require_once __DIR__ . '/../integrations/entity_mappings.php';

function zohoBooksSyncChartOfAccounts(int $tenantId, ?int $userId, array $opts = []): array
{
    $start    = microtime(true);
    $limit    = max(1, min(5000, (int) ($opts['limit']     ?? 2000)));
    $maxPages = max(1, min(100,  (int) ($opts['max_pages'] ?? 20)));

    $conn = zohoBooksConnection($tenantId);
    if (!$conn || $conn['status'] !== 'active' || (string) $conn['organization_id'] === 'pending') {
        throw new \RuntimeException('Zoho Books is not connected for this tenant');
    }
    $cfg = zohoBooksSyncConfigRead($tenantId);
    if (!in_array($cfg['chart_of_accounts'] ?? 'off', ['pull', 'two_way'], true)) {
        throw new \RuntimeException('Chart of accounts direction is not pull/two_way for this tenant');
    }

    // Index CoreFlux accounts by code for the account_code fallback.
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT id, code, name FROM accounting_accounts WHERE tenant_id = :t');
    $stmt->execute(['t' => $tenantId]);
    $byCode = [];
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
        $code = trim((string) ($row['code'] ?? ''));
        if ($code === '') continue;
        $byCode[$code] = (int) $row['id'];
    }

    $matched = 0; $newlyMapped = 0; $unmapped = 0; $unchanged = 0;
    $unmappedSamples = [];
    $page = 1;
    $pulled = 0;
    $pagesScanned = 0;

    while ($pulled < $limit && $pagesScanned < $maxPages) {
        $pagesScanned++;
        try {
            $resp = zohoBooksCall($tenantId, 'GET', '/books/v3/chartofaccounts', null, [
                'page'     => $page,
                'per_page' => min(200, $limit - $pulled),
                'filter_by' => 'AccountType.All',
            ]);
        } catch (\Throwable $e) {
            zohoBooksAudit($tenantId, 'sync_account_error', [
                'ok' => false, 'actor_user_id' => $userId,
                'entity_type' => 'account', 'direction' => 'pull',
                'detail' => ['error' => substr($e->getMessage(), 0, 500), 'page' => $page],
            ]);
            throw $e;
        }
        $rows = is_array($resp['chartofaccounts'] ?? null) ? $resp['chartofaccounts'] : [];
        if (count($rows) === 0) break;

        foreach ($rows as $zo) {
            $zoId    = (string) ($zo['account_id']   ?? '');
            $name    = (string) ($zo['account_name'] ?? '');
            $code    = trim((string) ($zo['account_code'] ?? ''));
            if ($zoId === '') continue;

            $existing = mappingFindInternal($tenantId, ZOHO_BOOKS_SOURCE, 'account', $zoId);
            if ($existing) {
                $up = mappingUpsert($tenantId, ZOHO_BOOKS_SOURCE, 'account', $zoId, (int) $existing['internal_entity_id'], $zo, 'pull');
                if ($up['changed']) $newlyMapped++;
                else                $unchanged++;
                $matched++;
                continue;
            }
            if ($code !== '' && isset($byCode[$code])) {
                mappingUpsert($tenantId, ZOHO_BOOKS_SOURCE, 'account', $zoId, $byCode[$code], $zo, 'pull');
                $newlyMapped++; $matched++;
            } else {
                $unmapped++;
                if (count($unmappedSamples) < 20) {
                    $unmappedSamples[] = [
                        'zoho_id'      => $zoId,
                        'name'         => $name,
                        'account_code' => $code !== '' ? $code : null,
                        'account_type' => (string) ($zo['account_type'] ?? ''),
                    ];
                }
            }
        }
        $pulled += count($rows);
        // Zoho's page_context.has_more_page reports if more pages exist;
        // be conservative and stop on a short page too.
        $hasMore = (bool) ($resp['page_context']['has_more_page'] ?? false);
        if (!$hasMore || count($rows) < 1) break;
        $page++;
    }

    if ($unmapped > 0 && $unmappedSamples) {
        zohoBooksAudit($tenantId, 'unmapped_zoho_accounts', [
            'ok' => true, 'actor_user_id' => $userId,
            'entity_type' => 'account', 'direction' => 'pull',
            'items_skipped' => $unmapped,
            'detail' => ['samples' => $unmappedSamples, 'total_unmapped' => $unmapped],
        ]);
    }

    $latency = (int) round((microtime(true) - $start) * 1000);
    zohoBooksAudit($tenantId, 'sync_accounts', [
        'entity_type' => 'account', 'direction' => 'pull',
        'ok' => true, 'actor_user_id' => $userId,
        'items_processed' => $newlyMapped,
        'items_skipped'   => $unchanged + $unmapped,
        'items_failed'    => 0,
        'detail' => [
            'matched_total'   => $matched,
            'newly_mapped'    => $newlyMapped,
            'unchanged'       => $unchanged,
            'unmapped_in_zoho'=> $unmapped,
            'pulled'          => $pulled,
            'pages'           => $pagesScanned,
            'latency_ms'      => $latency,
        ],
    ]);

    return [
        'pulled'           => $pulled,
        'matched'          => $matched,
        'newly_mapped'     => $newlyMapped,
        'unchanged'        => $unchanged,
        'unmapped'         => $unmapped,
        'pages'            => $pagesScanned,
        'latency_ms'       => $latency,
        'unmapped_samples' => $unmappedSamples,
    ];
}
