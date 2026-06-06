<?php
/**
 * Zoho Books — Slice 2 outbound sync drivers.
 *
 * `zohoBooksSyncJournalEntries()` pushes posted CoreFlux journal
 * entries into the tenant's Zoho Books organization. Mirrors the QBO
 * Slice 2 architecture exactly:
 *   - `external_entity_mappings` is the source of truth for idempotency.
 *     A JE is "shipped" iff a mapping row exists for
 *     (tenant_id, 'zoho_books', 'journal_entry', internal_entity_id=je.id).
 *   - Account resolution: each JE line references `accounting_accounts.id`.
 *     Zoho needs the corresponding `account_id` (string) on each line.
 *     The resolver looks up existing mapping first, then auto-discovers
 *     by `account_code` (= CoreFlux `code`) via Zoho's chartofaccounts
 *     endpoint. If a code doesn't match anything Zoho recognises, the
 *     JE is skipped with `reason=unmapped_accounts` and surfaced in the
 *     audit so a tenant can either rename Zoho accounts or wait for
 *     Slice 3 (CoA pull) to fix the map.
 *
 * Endpoint:
 *   GET  /books/v3/chartofaccounts?account_code=NNNN
 *   POST /books/v3/journals
 *   GET  /books/v3/journals/{id}             (not used in this slice)
 *
 * Public surface:
 *   zohoBooksSyncJournalEntries(int $tid, ?int $userId, array $opts=[]): array
 *   zohoBooksBuildJournalPayload(array $je, array $lines, callable $resolveAccount): array
 *   zohoBooksResolveAccountRef(int $tid, int $accountId): ?array
 *
 * Opts (mirrors QBO):
 *   - limit:   int  (default 50, max 500)
 *   - dry_run: bool — build payloads without POSTing
 *   - je_ids:  array<int>  — restrict the run to specific JEs
 *
 * Return shape (mirrors QBO):
 *   { pushed, skipped_unmapped, failed, considered, latency_ms,
 *     dry_run, results: [...] }
 */
declare(strict_types=1);

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/../integrations/entity_mappings.php';
require_once __DIR__ . '/../integrations/verify_create.php';

const ZOHO_BOOKS_SOURCE = 'zoho_books';

/**
 * Look up (or auto-discover) the Zoho Books account_id for a CoreFlux
 * account. Returns ['value'=>zohoId, 'name'=>zohoName] suitable for the
 * journal line, or null when no match exists.
 */
function zohoBooksResolveAccountRef(int $tenantId, int $accountId): ?array
{
    $existing = mappingFindExternal($tenantId, ZOHO_BOOKS_SOURCE, 'account', $accountId);
    if ($existing) {
        $snap = $existing['payload_snapshot'] ? json_decode((string) $existing['payload_snapshot'], true) : null;
        return [
            'value' => (string) $existing['external_id'],
            'name'  => is_array($snap) ? (string) ($snap['account_name'] ?? '') : '',
        ];
    }

    // Auto-discover by account_code = CoreFlux account `code`.
    $stmt = getDB()->prepare('SELECT id, code, name FROM accounting_accounts WHERE id = :id AND tenant_id = :t LIMIT 1');
    $stmt->execute(['id' => $accountId, 't' => $tenantId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) return null;
    $code = trim((string) $row['code']);
    if ($code === '') return null;

    try {
        $resp = zohoBooksCall($tenantId, 'GET', '/books/v3/chartofaccounts', null, [
            'account_code' => $code,
            'per_page'     => 1,
        ]);
    } catch (\Throwable $e) {
        return null;
    }
    $accounts = $resp['chartofaccounts'] ?? [];
    if (!is_array($accounts) || count($accounts) === 0) return null;
    $hit  = $accounts[0];
    $zoId = (string) ($hit['account_id']   ?? '');
    $zName= (string) ($hit['account_name'] ?? '');
    if ($zoId === '') return null;

    mappingUpsert($tenantId, ZOHO_BOOKS_SOURCE, 'account', $zoId, $accountId, $hit, 'pull');
    return ['value' => $zoId, 'name' => $zName];
}

/**
 * Pure function: build a Zoho Books `/books/v3/journals` payload from a
 * CoreFlux JE + its lines. `$resolveAccount` is callable(int $accountId): ?array
 * returning {value, name} for the Zoho account, or null when the line
 * should be dropped (caller treats any null as a hard skip).
 *
 * Per https://www.zoho.com/books/api/v3/journals/:
 *   {
 *     "journal_date":     "YYYY-MM-DD",
 *     "reference_number": "JE-NNN",
 *     "notes":            "memo",
 *     "line_items": [
 *       { "account_id": "...", "description": "...", "debit_or_credit": "debit"|"credit", "amount": 12.34 },
 *       ...
 *     ]
 *   }
 */
function zohoBooksBuildJournalPayload(array $je, array $lines, callable $resolveAccount): array
{
    $payload = [
        'journal_date'     => (string) ($je['posting_date'] ?? date('Y-m-d')),
        'reference_number' => (string) ($je['je_number'] ?? ''),
        'notes'            => (string) ($je['memo'] ?? ''),
        'line_items'       => [],
    ];
    foreach ($lines as $line) {
        $debit  = (float) ($line['debit']  ?? 0);
        $credit = (float) ($line['credit'] ?? 0);
        if ($debit <= 0 && $credit <= 0) continue;
        $isDebit = $debit > 0;
        $amount  = $isDebit ? $debit : $credit;
        $ref = $resolveAccount((int) ($line['account_id'] ?? 0));
        if (!$ref) {
            // Sentinel for the caller — any unresolved line aborts the JE.
            $payload['line_items'][] = ['_unresolved_account_id' => (int) ($line['account_id'] ?? 0)];
            continue;
        }
        $payload['line_items'][] = [
            'account_id'      => (string) $ref['value'],
            'description'     => (string) ($line['memo'] ?? ''),
            'debit_or_credit' => $isDebit ? 'debit' : 'credit',
            'amount'          => round($amount, 2),
        ];
    }
    return $payload;
}

/**
 * Driver — pulls eligible CoreFlux JEs, posts each to Zoho Books,
 * upserts the mapping, audits the run. Returns aggregate counts.
 */
function zohoBooksSyncJournalEntries(int $tenantId, ?int $userId, array $opts = []): array
{
    $__zbSub = isset($opts["sub_tenant_id"]) && (int) $opts["sub_tenant_id"] > 0 ? (int) $opts["sub_tenant_id"] : null;
    $GLOBALS["__zb_sub_tenant_id"] = $__zbSub ?? 0;
    $start = microtime(true);
    $limit    = max(1, min(500, (int) ($opts['limit'] ?? 50)));
    $dryRun   = !empty($opts['dry_run']);
    $restrict = isset($opts['je_ids']) && is_array($opts['je_ids'])
        ? array_values(array_filter(array_map('intval', $opts['je_ids'])))
        : [];

    $conn = zohoBooksConnection($tenantId, isset($opts["sub_tenant_id"]) && (int) $opts["sub_tenant_id"] > 0 ? (int) $opts["sub_tenant_id"] : null);
    if (!$conn || $conn['status'] !== 'active' || (string) $conn['organization_id'] === 'pending') {
        throw new \RuntimeException('Zoho Books is not connected for this tenant');
    }
    $config = zohoBooksSyncConfigRead($tenantId);
    if (!in_array($config['journal_entries'] ?? 'off', ['push', 'two_way'], true)) {
        throw new \RuntimeException('Journal entries direction is not push/two_way for this tenant');
    }

    // Eligible JEs: posted, not yet mapped under source='zoho_books'.
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
    $bind = [ZOHO_BOOKS_SOURCE, $tenantId];
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

        // tenant-leak-allow: defense-in-depth — caller scoped row by tenant_id before this id-only read
        $lineStmt = $pdo->prepare(
            'SELECT line_no, account_id, debit, credit, memo
               FROM accounting_journal_entry_lines
              WHERE je_id = :id
           ORDER BY line_no ASC, id ASC'
        );
        $lineStmt->execute(['id' => $jeId]);
        $lines = $lineStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $resolver = static function (int $acctId) use ($tenantId) {
            return zohoBooksResolveAccountRef($tenantId, $acctId);
        };
        $payload = zohoBooksBuildJournalPayload($je, $lines, $resolver);

        $unresolved = [];
        foreach ($payload['line_items'] as $l) {
            if (isset($l['_unresolved_account_id'])) $unresolved[] = (int) $l['_unresolved_account_id'];
        }
        if (!empty($unresolved)) {
            $skipped++;
            $results[] = [
                'je_id' => $jeId, 'je_number' => $je['je_number'],
                'status' => 'skipped', 'reason' => 'unmapped_accounts',
                'unresolved_account_ids' => array_values(array_unique($unresolved)),
            ];
            zohoBooksAudit($tenantId, 'sync_je_skip', [
                'entity_type' => 'journal_entry', 'direction' => 'push',
                'ok' => true, 'actor_user_id' => $userId, 'items_skipped' => 1,
                'detail' => ['je_id' => $jeId, 'reason' => 'unmapped_accounts', 'unresolved_account_ids' => array_values(array_unique($unresolved))],
            ]);
            continue;
        }

        if ($dryRun) {
            $pushed++;
            $results[] = ['je_id' => $jeId, 'je_number' => $je['je_number'], 'status' => 'dry_run', 'payload' => $payload];
            continue;
        }

        try {
            $resp = zohoBooksCall($tenantId, 'POST', '/books/v3/journals', $payload);
            // Zoho returns the created journal under `journal` (object) with `journal_id`.
            $zoId = (string) ($resp['journal']['journal_id'] ?? '');
            if ($zoId === '') throw new \RuntimeException('Zoho Books accepted but returned no journal.journal_id');
            mappingUpsert($tenantId, ZOHO_BOOKS_SOURCE, 'journal_entry', $zoId, $jeId, $payload, 'push');
            $pushed++;
            // Charter primitive #5 — post-push verification.
            $verify = zohoBooksVerifyCreate($tenantId, 'journal_entry', $zoId, 'active');
            $itemStatus = ($verify['verified'] ?? false) ? 'pushed' : 'pushed_unverified';
            $results[] = ['je_id' => $jeId, 'je_number' => $je['je_number'], 'zoho_id' => $zoId, 'status' => $itemStatus, 'verify' => $verify];
            zohoBooksAudit($tenantId, 'sync_je_push', [
                'entity_type' => 'journal_entry', 'direction' => 'push',
                'ok' => true, 'actor_user_id' => $userId, 'items_processed' => 1,
                'detail' => ['je_id' => $jeId, 'zoho_id' => $zoId, 'verify' => $verify],
            ]);
        } catch (\Throwable $e) {
            $failed++;
            $results[] = [
                'je_id' => $jeId, 'je_number' => $je['je_number'],
                'status' => 'failed', 'reason' => substr($e->getMessage(), 0, 300),
            ];
            zohoBooksAudit($tenantId, 'sync_je_push', [
                'entity_type' => 'journal_entry', 'direction' => 'push',
                'ok' => false, 'actor_user_id' => $userId, 'items_failed' => 1,
                'detail' => ['je_id' => $jeId, 'error' => substr($e->getMessage(), 0, 500)],
            ]);
        }
    }

    $latency = (int) round((microtime(true) - $start) * 1000);
    zohoBooksAudit($tenantId, 'sync_je', [
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
