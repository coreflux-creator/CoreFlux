<?php
/**
 * QuickBooks Online — Slice 2 outbound sync drivers.
 *
 * `qboSyncJournalEntries()` pushes posted CoreFlux journal entries into
 * the tenant's QBO company, using `external_entity_mappings` for
 * idempotency (mapping table only — no schema additions in this slice).
 *
 * Account resolution: each JE line references a `accounting_accounts.id`.
 * To build a QBO `JournalEntry`, every line needs an `AccountRef` (QBO
 * Account.Id). We map CoreFlux accounts to QBO accounts opportunistically:
 *   1. If a mapping already exists for (source='quickbooks_online',
 *      entity_type='account', internal_entity_id=$accountId), use it.
 *   2. Otherwise, query QBO COA by AcctNum (= CoreFlux account `code`).
 *      Persist the match via mappingUpsert(). If no AcctNum match, skip
 *      the JE and audit it under `items_skipped` with a reason. A proper
 *      COA mirror lands in Slice 4 — this auto-discovery is a thin
 *      bootstrap for tenants whose QBO COA already mirrors their codes.
 *
 * Public surface:
 *   qboSyncJournalEntries(int $tid, ?int $userId, array $opts=[]): array
 *   qboBuildJournalEntryPayload(array $je, array $lines, callable $resolveAccount): array
 *   qboResolveAccountRef(int $tid, int $accountId): ?array
 *
 * Opts:
 *   - limit:     int (default 50) — max JEs per run
 *   - dry_run:   bool — build payloads but skip the POST; useful for CFO
 *                preview (the "Dry-run mode" we suggested as a follow-on).
 *   - je_ids:    array<int> — restrict the run to specific JEs
 *
 * Return shape:
 *   {
 *     pushed:                 int,
 *     skipped_unmapped:       int,
 *     failed:                 int,
 *     latency_ms:             int,
 *     results: [
 *       { je_id, je_number, qbo_id?, status: 'pushed'|'skipped'|'failed', reason? }
 *     ]
 *   }
 */
declare(strict_types=1);

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/../integrations/entity_mappings.php';

const QBO_SOURCE = 'quickbooks_online';

/**
 * Look up (or auto-discover) the QBO Account.Id for a CoreFlux account.
 * Returns ['value'=>qboId, 'name'=>qboName] suitable for AccountRef, or
 * null when no match exists.
 */
function qboResolveAccountRef(int $tenantId, int $accountId): ?array
{
    $existing = mappingFindExternal($tenantId, QBO_SOURCE, 'account', $accountId);
    if ($existing) {
        $snap = $existing['payload_snapshot'] ? json_decode((string) $existing['payload_snapshot'], true) : null;
        return [
            'value' => (string) $existing['external_id'],
            'name'  => is_array($snap) ? (string) ($snap['Name'] ?? '') : '',
        ];
    }

    // Auto-discover by AcctNum = CoreFlux account code.
    $stmt = getDB()->prepare('SELECT id, code, name FROM accounting_accounts WHERE id = :id AND tenant_id = :t LIMIT 1');
    $stmt->execute(['id' => $accountId, 't' => $tenantId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) return null;

    $code = trim((string) $row['code']);
    if ($code === '') return null;

    $row2 = qboConnection($tenantId);
    if (!$row2) return null;
    $realm = (string) $row2['realm_id'];
    // QBO Query API: SELECT Id,Name,AcctNum FROM Account WHERE AcctNum = '1010'
    // Escape single quotes per QBO spec.
    $safeCode = str_replace("'", "\\'", $code);
    $q = "select Id, Name, AcctNum from Account where AcctNum = '{$safeCode}'";
    try {
        $resp = qboCall($tenantId, 'GET', '/v3/company/' . $realm . '/query', null, [
            'query'        => $q,
            'minorversion' => 65,
        ]);
    } catch (\Throwable $e) {
        return null;
    }
    $accounts = $resp['QueryResponse']['Account'] ?? [];
    if (!is_array($accounts) || count($accounts) === 0) return null;
    $hit = $accounts[0];
    $qboId   = (string) ($hit['Id']   ?? '');
    $qboName = (string) ($hit['Name'] ?? '');
    if ($qboId === '') return null;

    mappingUpsert($tenantId, QBO_SOURCE, 'account', $qboId, $accountId, $hit, 'pull');
    return ['value' => $qboId, 'name' => $qboName];
}

/**
 * Pure function: build a QBO JournalEntry payload from a CoreFlux JE +
 * its lines. `$resolveAccount` is a callable(int $accountId): ?array
 * returning an AccountRef-shaped array or null when the line should be
 * dropped (caller handles "any null → skip the JE").
 *
 * Separated for testability — the smoke test can drive this directly
 * without a database round-trip.
 */
function qboBuildJournalEntryPayload(array $je, array $lines, callable $resolveAccount): array
{
    $payload = [
        'TxnDate'     => (string) ($je['posting_date'] ?? date('Y-m-d')),
        'DocNumber'   => (string) ($je['je_number'] ?? ''),
        'PrivateNote' => (string) ($je['memo'] ?? ''),
        'Line'        => [],
    ];
    foreach ($lines as $line) {
        $debit  = (float) ($line['debit']  ?? 0);
        $credit = (float) ($line['credit'] ?? 0);
        if ($debit <= 0 && $credit <= 0) continue;
        $isDebit = $debit > 0;
        $amount  = $isDebit ? $debit : $credit;
        $ref = $resolveAccount((int) ($line['account_id'] ?? 0));
        if (!$ref) {
            // Caller treats any null as a hard skip; surface in payload as
            // null so the caller can detect & abort the JE.
            $payload['Line'][] = ['_unresolved_account_id' => (int) ($line['account_id'] ?? 0)];
            continue;
        }
        $payload['Line'][] = [
            'Description' => (string) ($line['memo'] ?? ''),
            'Amount'      => round($amount, 2),
            'DetailType'  => 'JournalEntryLineDetail',
            'JournalEntryLineDetail' => [
                'PostingType' => $isDebit ? 'Debit' : 'Credit',
                'AccountRef'  => [
                    'value' => (string) $ref['value'],
                    'name'  => (string) ($ref['name'] ?? ''),
                ],
            ],
        ];
    }
    return $payload;
}

/**
 * Driver — pulls eligible CoreFlux JEs, pushes each to QBO,
 * upserts mapping, audits the run. Returns aggregate counts.
 */
function qboSyncJournalEntries(int $tenantId, ?int $userId, array $opts = []): array
{
    $start = microtime(true);
    $limit  = max(1, min(500, (int) ($opts['limit'] ?? 50)));
    $dryRun = !empty($opts['dry_run']);
    $restrict = isset($opts['je_ids']) && is_array($opts['je_ids']) ? array_values(array_filter(array_map('intval', $opts['je_ids']))) : [];

    $conn = qboConnection($tenantId);
    if (!$conn || $conn['status'] !== 'active') {
        throw new \RuntimeException('QuickBooks is not connected for this tenant');
    }
    $config = qboSyncConfigRead($tenantId);
    if (!in_array($config['journal_entries'] ?? 'off', ['push', 'two_way'], true)) {
        throw new \RuntimeException('Journal entries direction is not push/two_way for this tenant');
    }
    $realm = (string) $conn['realm_id'];

    // Eligible JEs: posted, not yet mapped. LEFT JOIN against
    // external_entity_mappings filters out the ones we've already shipped
    // (idempotency at the SELECT layer).
    $pdo = getDB();
    $sql = "SELECT je.id, je.je_number, je.posting_date, je.memo, je.status
              FROM accounting_journal_entries je
         LEFT JOIN external_entity_mappings m
                ON m.tenant_id = je.tenant_id
               AND m.source_system = ?
               AND m.internal_entity_type = 'journal_entry'
               AND m.internal_entity_id = je.id
             WHERE je.tenant_id = ?
               AND je.status = 'posted'
               AND m.id IS NULL";
    $bind = [QBO_SOURCE, $tenantId];
    if (!empty($restrict)) {
        $in = implode(',', array_fill(0, count($restrict), '?'));
        $sql .= " AND je.id IN ($in)";
        $bind = array_merge($bind, $restrict);
    }
    $sql .= " ORDER BY je.posted_at ASC, je.id ASC LIMIT " . (int) $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($bind);
    $jes = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $pushed = 0; $skipped = 0; $failed = 0; $results = [];

    foreach ($jes as $je) {
        $jeId = (int) $je['id'];

        $lineStmt = $pdo->prepare(
            'SELECT line_no, account_id, debit, credit, memo
               FROM accounting_journal_entry_lines
              WHERE je_id = :id
           ORDER BY line_no ASC, id ASC'
        );
        $lineStmt->execute(['id' => $jeId]);
        $lines = $lineStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $resolver = static function (int $acctId) use ($tenantId) {
            return qboResolveAccountRef($tenantId, $acctId);
        };
        $payload = qboBuildJournalEntryPayload($je, $lines, $resolver);

        // Any line with `_unresolved_account_id` aborts the JE.
        $unresolved = [];
        foreach ($payload['Line'] as $l) {
            if (isset($l['_unresolved_account_id'])) $unresolved[] = (int) $l['_unresolved_account_id'];
        }
        if (!empty($unresolved)) {
            $skipped++;
            $results[] = [
                'je_id' => $jeId, 'je_number' => $je['je_number'],
                'status' => 'skipped',
                'reason' => 'unmapped_accounts',
                'unresolved_account_ids' => array_values(array_unique($unresolved)),
            ];
            qboAudit($tenantId, 'sync_je_skip', [
                'entity_type' => 'journal_entry', 'direction' => 'push',
                'ok' => true, 'actor_user_id' => $userId, 'items_skipped' => 1,
                'detail' => ['je_id' => $jeId, 'reason' => 'unmapped_accounts', 'unresolved_account_ids' => array_values(array_unique($unresolved))],
            ]);
            continue;
        }

        if ($dryRun) {
            $pushed++; // counts as "would have pushed"
            $results[] = ['je_id' => $jeId, 'je_number' => $je['je_number'], 'status' => 'dry_run', 'payload' => $payload];
            continue;
        }

        try {
            $resp = qboCall($tenantId, 'POST', '/v3/company/' . $realm . '/journalentry?minorversion=65', $payload);
            $qboId = (string) ($resp['JournalEntry']['Id'] ?? '');
            if ($qboId === '') throw new \RuntimeException('QBO accepted but returned no JournalEntry.Id');
            mappingUpsert($tenantId, QBO_SOURCE, 'journal_entry', $qboId, $jeId, $payload, 'push');
            $pushed++;
            $results[] = ['je_id' => $jeId, 'je_number' => $je['je_number'], 'qbo_id' => $qboId, 'status' => 'pushed'];
            qboAudit($tenantId, 'sync_je_push', [
                'entity_type' => 'journal_entry', 'direction' => 'push',
                'ok' => true, 'actor_user_id' => $userId, 'items_processed' => 1,
                'detail' => ['je_id' => $jeId, 'qbo_id' => $qboId],
            ]);
        } catch (\Throwable $e) {
            $failed++;
            $results[] = [
                'je_id' => $jeId, 'je_number' => $je['je_number'],
                'status' => 'failed', 'reason' => substr($e->getMessage(), 0, 300),
            ];
            qboAudit($tenantId, 'sync_je_push', [
                'entity_type' => 'journal_entry', 'direction' => 'push',
                'ok' => false, 'actor_user_id' => $userId, 'items_failed' => 1,
                'detail' => ['je_id' => $jeId, 'error' => substr($e->getMessage(), 0, 500)],
            ]);
        }
    }

    $latency = (int) round((microtime(true) - $start) * 1000);
    qboAudit($tenantId, 'sync_je', [
        'entity_type' => 'journal_entry', 'direction' => 'push',
        'ok' => ($failed === 0),
        'actor_user_id' => $userId,
        'items_processed' => $pushed,
        'items_skipped'   => $skipped,
        'items_failed'    => $failed,
        'detail' => [
            'latency_ms' => $latency, 'limit' => $limit, 'dry_run' => $dryRun,
            'considered' => count($jes),
        ],
    ]);

    return [
        'pushed'           => $pushed,
        'skipped_unmapped' => $skipped,
        'failed'           => $failed,
        'considered'       => count($jes),
        'latency_ms'       => $latency,
        'dry_run'          => $dryRun,
        'results'          => $results,
    ];
}
