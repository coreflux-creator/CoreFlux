<?php
/**
 * QuickBooks Online — Slice 4a: Chart of Accounts mirror (pull).
 *
 * Replaces the fragile AcctNum auto-discovery shortcut from Slice 2 with
 * a proper bulk pull of every QBO Account into `external_entity_mappings`.
 * After one successful run, `qboResolveAccountRef()` (Slice 2) hits the
 * mapping cache instead of doing an ad-hoc QBO query per line.
 *
 * Match strategy:
 *   1. existing mapping (qbo_id ↔ accounting_accounts.id)
 *   2. match by AcctNum = accounting_accounts.code
 *   3. no match → log to qbo_sync_audit as `unmapped_qbo_account` with the
 *      QBO Id + Name + AcctNum so a controller can decide whether to
 *      create the CoreFlux account or ignore. We do NOT auto-insert into
 *      accounting_accounts — COA structure is the controller's call.
 *
 * Public surface:
 *   qboSyncAccounts(int $tid, ?int $userId, array $opts=[]): array
 */
declare(strict_types=1);

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/../integrations/entity_mappings.php';

function qboSyncAccounts(int $tenantId, ?int $userId, array $opts = []): array
{
    $start    = microtime(true);
    $limit    = max(1, min(5000, (int) ($opts['limit'] ?? 2000)));
    $maxPages = max(1, min(100,  (int) ($opts['max_pages'] ?? 20)));

    $conn = qboConnection($tenantId);
    if (!$conn || $conn['status'] !== 'active') {
        throw new \RuntimeException('QuickBooks is not connected for this tenant');
    }
    $cfg = qboSyncConfigRead($tenantId);
    if (!in_array($cfg['chart_of_accounts'] ?? 'off', ['pull', 'two_way'], true)) {
        throw new \RuntimeException('Chart of accounts direction is not pull/two_way for this tenant');
    }
    $realm = (string) $conn['realm_id'];

    $pdo = getDB();
    // Index CoreFlux accounts by code for the AcctNum fallback.
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
    $startPos = 1;
    $pulled = 0;
    $pages = 0;

    while ($pulled < $limit && $pages < $maxPages) {
        $pages++;
        $pageSize = min(QBO_PAGE_SIZE_FOR_ACCOUNTS, $limit - $pulled);
        $query = sprintf('SELECT * FROM Account STARTPOSITION %d MAXRESULTS %d', $startPos, $pageSize);
        try {
            $resp = qboCall($tenantId, 'GET', '/v3/company/' . $realm . '/query', null, [
                'query'        => $query,
                'minorversion' => 65,
            ]);
        } catch (\Throwable $e) {
            qboAudit($tenantId, 'sync_account_error', [
                'ok' => false, 'actor_user_id' => $userId,
                'entity_type' => 'account', 'direction' => 'pull',
                'detail' => ['error' => substr($e->getMessage(), 0, 500), 'page' => $pages],
            ]);
            throw $e;
        }
        $rows = $resp['QueryResponse']['Account'] ?? [];
        if (!is_array($rows) || count($rows) === 0) break;

        foreach ($rows as $qbo) {
            $qboId  = (string) ($qbo['Id'] ?? '');
            $name   = (string) ($qbo['Name'] ?? '');
            $acctNum = trim((string) ($qbo['AcctNum'] ?? ''));
            if ($qboId === '') continue;

            $existing = mappingFindInternal($tenantId, QBO_SOURCE, 'account', $qboId);
            if ($existing) {
                // Already mapped — refresh the payload snapshot so the
                // controller sees current QBO state.
                $up = mappingUpsert($tenantId, QBO_SOURCE, 'account', $qboId, (int) $existing['internal_entity_id'], $qbo, 'pull');
                if ($up['changed']) $newlyMapped++;
                else                $unchanged++;
                $matched++;
                continue;
            }
            if ($acctNum !== '' && isset($byCode[$acctNum])) {
                mappingUpsert($tenantId, QBO_SOURCE, 'account', $qboId, $byCode[$acctNum], $qbo, 'pull');
                $newlyMapped++; $matched++;
            } else {
                $unmapped++;
                if (count($unmappedSamples) < 20) {
                    $unmappedSamples[] = [
                        'qbo_id'   => $qboId,
                        'name'     => $name,
                        'acct_num' => $acctNum !== '' ? $acctNum : null,
                    ];
                }
            }
        }
        $pulled += count($rows);
        if (count($rows) < $pageSize) break;
        $startPos += count($rows);
    }

    if ($unmapped > 0 && $unmappedSamples) {
        // Single audit row carrying up to 20 unmapped samples — controllers
        // see this in the QboSettings recent-audit feed.
        qboAudit($tenantId, 'unmapped_qbo_accounts', [
            'ok' => true, 'actor_user_id' => $userId,
            'entity_type' => 'account', 'direction' => 'pull',
            'items_skipped' => $unmapped,
            'detail' => ['samples' => $unmappedSamples, 'total_unmapped' => $unmapped],
        ]);
    }

    $latency = (int) round((microtime(true) - $start) * 1000);
    qboAudit($tenantId, 'sync_accounts', [
        'entity_type' => 'account', 'direction' => 'pull',
        'ok' => true, 'actor_user_id' => $userId,
        'items_processed' => $newlyMapped,
        'items_skipped'   => $unchanged + $unmapped,
        'items_failed'    => 0,
        'detail' => [
            'matched_total' => $matched, 'newly_mapped' => $newlyMapped,
            'unchanged'     => $unchanged, 'unmapped_in_qbo' => $unmapped,
            'pulled'        => $pulled, 'pages' => $pages, 'latency_ms' => $latency,
        ],
    ]);

    return [
        'matched'         => $matched,
        'newly_mapped'    => $newlyMapped,
        'unchanged'       => $unchanged,
        'unmapped_in_qbo' => $unmapped,
        'unmapped_samples'=> $unmappedSamples,
        'pulled'          => $pulled,
        'pages'           => $pages,
        'latency_ms'      => $latency,
    ];
}

// Accounts have a smaller page size — QBO Account list is usually <500
// entries total, so 100 per page is plenty and keeps the auto-resolver
// snappy for tenants with a deep chart.
const QBO_PAGE_SIZE_FOR_ACCOUNTS = 100;
