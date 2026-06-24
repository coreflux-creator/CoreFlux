<?php
/**
 * Accounting — Intercompany split engine.
 *
 * A single logical transaction (bank feed match, AP bill expense split,
 * manual JE) can post as N balanced JEs — one per entity — linked by a
 * shared `intercompany_group_id`.
 *
 * Core API:
 *   - intercompanyGetMapping($t, $from, $to)      → {due_from_account_code, due_to_account_code, …} | null
 *   - intercompanyUpsertMapping($t, $data)        → id
 *   - intercompanyDeriveGroupId()                 → random 32-char hex
 *   - intercompanyPostSplit($t, $payload, $uid)   → {group_id, jes[]}
 *   - intercompanyReverseGroup($t, $groupId, …)   → {reversals[]}
 *
 * Payload shape for intercompanyPostSplit():
 *   [
 *     'posting_date' => '2026-02-15',
 *     'memo'         => 'Parent CC shared charge',
 *     'source'       => [
 *       'entity_id'    => 1,
 *       'offset_line'  => [ 'account_code'=>'2100', 'amount'=>1000.00, 'side'=>'credit' ],
 *       // side=credit means cash/CC moved OUT of source entity (like a bank debit)
 *       // side=debit  means cash came IN
 *     ],
 *     'splits' => [
 *       // splits[0] can be the source entity's OWN share (no intercompany)
 *       ['entity_id'=>1, 'account_code'=>'6100', 'amount'=>700.00],
 *       ['entity_id'=>2, 'account_code'=>'6100', 'amount'=>300.00],
 *       ['entity_id'=>3, 'account_code'=>'6100', 'amount'=>50.00, 'ic_override'=>[
 *           'due_from_account_code'=>'1500','due_to_account_code'=>'2500',
 *       ]],
 *     ],
 *     'bank_statement_line_id' => 42,      // optional; marks the bank line matched
 *     'idempotency_prefix'     => 'ic:bankline:42',
 *   ]
 *
 * Sign convention (from the source's perspective):
 *   - side='credit' → cash/liability side credits in source (money left source).
 *     Source entity emits one JE:
 *       for each cross-entity split: Dr IC Due-From <target>
 *       for the source's own share : Dr <account_code>
 *       plus                        : Cr <offset_line.account_code>  (totals to amount)
 *     Each target entity emits one JE:
 *                                     Dr <account_code>  /  Cr IC Due-To <source>
 *
 *   - side='debit'  → the offset line is a debit (money came IN).
 *     Source JE: for each cross-entity split: Cr IC Due-To <target>
 *                for source's own share     : Cr <account_code>
 *                plus                       : Dr <offset_line.account_code>
 *     Target JEs:                 Cr <account_code>  /  Dr IC Due-From <source>
 *
 * All JEs are posted via accountingPostJe() → period guards, idempotency,
 * balance checks, audit.
 */

declare(strict_types=1);

require_once __DIR__ . '/accounting.php';

function intercompanyGetMapping(int $tenantId, int $fromEntityId, int $toEntityId): ?array
{
    return scopedFind(
        'SELECT * FROM accounting_intercompany_mappings
         WHERE tenant_id = :tenant_id AND from_entity_id = :fr AND to_entity_id = :to AND active = 1 LIMIT 1',
        ['fr' => $fromEntityId, 'to' => $toEntityId]
    );
}

function intercompanyUpsertMapping(int $tenantId, array $data): int
{
    $fr = (int) $data['from_entity_id'];
    $to = (int) $data['to_entity_id'];
    if ($fr <= 0 || $to <= 0) throw new \InvalidArgumentException('from_entity_id + to_entity_id required');
    if ($fr === $to)           throw new \InvalidArgumentException('from and to entity must differ');
    $df = trim((string) ($data['due_from_account_code'] ?? ''));
    $dt = trim((string) ($data['due_to_account_code']   ?? ''));
    if ($df === '' || $dt === '') throw new \InvalidArgumentException('due_from + due_to account codes required');

    $existing = intercompanyGetMapping($tenantId, $fr, $to);
    if ($existing) {
        scopedUpdate('accounting_intercompany_mappings', (int) $existing['id'], [
            'due_from_account_code' => $df,
            'due_to_account_code'   => $dt,
            'notes'                 => $data['notes'] ?? null,
            'active'                => isset($data['active']) ? (int) $data['active'] : 1,
        ]);
        accountingAudit('accounting.intercompany.mapping_updated', [
            'id' => (int) $existing['id'], 'from' => $fr, 'to' => $to,
        ], (int) $existing['id']);
        _intercompanyLogCrossTenant($tenantId, $fr, $to, $df, $dt, (int) $existing['id'], 'updated');
        return (int) $existing['id'];
    }
    $id = scopedInsert('accounting_intercompany_mappings', [
        'from_entity_id'        => $fr,
        'to_entity_id'          => $to,
        'due_from_account_code' => $df,
        'due_to_account_code'   => $dt,
        'notes'                 => $data['notes'] ?? null,
        'active'                => 1,
    ]);
    accountingAudit('accounting.intercompany.mapping_created', [
        'id' => $id, 'from' => $fr, 'to' => $to,
    ], $id);
    _intercompanyLogCrossTenant($tenantId, $fr, $to, $df, $dt, $id, 'created');
    return $id;
}

/**
 * Cross-tenant audit shim — fires only when the from/to entities sit on
 * different tenants (same-tenant mappings are already in accountingAudit).
 */
function _intercompanyLogCrossTenant(
    int $actingTenantId, int $fromEntityId, int $toEntityId,
    string $dueFromAcct, string $dueToAcct, int $mappingId, string $verb
): void {
    require_once __DIR__ . '/../../../core/cross_tenant_audit.php';
    $fTid = crossTenantAuditEntityTenantId($fromEntityId);
    $tTid = crossTenantAuditEntityTenantId($toEntityId);
    if ($fTid === 0 || $tTid === 0 || $fTid === $tTid) return;

    $user = function_exists('getCurrentUser') ? getCurrentUser() : null;
    crossTenantAuditLog(
        $actingTenantId,
        $fTid,
        $tTid,
        $verb === 'updated'
            ? 'intercompany.mapping_updated'
            : 'intercompany.mapping_created',
        [
            'mapping_id'            => $mappingId,
            'from_entity_id'        => $fromEntityId,
            'to_entity_id'          => $toEntityId,
            'due_from_account_code' => $dueFromAcct,
            'due_to_account_code'   => $dueToAcct,
        ],
        $fromEntityId,
        $toEntityId,
        $user ? (int) ($user['id'] ?? 0) : null,
        $user ? (string) ($user['email'] ?? $user['name'] ?? '') : null
    );
}

function intercompanyDeriveGroupId(): string
{
    return bin2hex(random_bytes(16));
}

/**
 * Resolve mapping with ad-hoc override support (choice 1c).
 */
function intercompanyResolvePair(int $tenantId, int $fromEntityId, int $toEntityId, ?array $override): array
{
    if ($override && !empty($override['due_from_account_code']) && !empty($override['due_to_account_code'])) {
        return [
            'due_from_account_code' => (string) $override['due_from_account_code'],
            'due_to_account_code'   => (string) $override['due_to_account_code'],
            'source'                => 'override',
        ];
    }
    $m = intercompanyGetMapping($tenantId, $fromEntityId, $toEntityId);
    if (!$m) {
        throw new \RuntimeException(
            "No intercompany mapping from entity {$fromEntityId} to {$toEntityId}. "
            . 'Configure one in Settings → Intercompany or provide ic_override at post time.'
        );
    }
    return [
        'due_from_account_code' => (string) $m['due_from_account_code'],
        'due_to_account_code'   => (string) $m['due_to_account_code'],
        'source'                => 'mapping',
    ];
}

/**
 * Core engine. Returns { group_id, jes: [{ entity_id, je_id, je_number }, …] }.
 *
 * Throws \RuntimeException on unbalanced payload, missing mapping, closed period, etc.
 */
function intercompanyPostSplit(int $tenantId, array $payload, ?int $actorUserId = null): array
{
    $source = $payload['source'] ?? null;
    $splits = $payload['splits'] ?? [];
    if (!$source || empty($source['entity_id']) || empty($source['offset_line'])) {
        throw new \InvalidArgumentException('source.entity_id + source.offset_line required');
    }
    if (!is_array($splits) || count($splits) < 1) {
        throw new \InvalidArgumentException('At least one split row required');
    }
    $offset = $source['offset_line'];
    $side   = strtolower((string) ($offset['side'] ?? 'credit'));
    if (!in_array($side, ['credit','debit'], true)) {
        throw new \InvalidArgumentException("source.offset_line.side must be 'credit' or 'debit'");
    }
    $sourceEntityId = (int) $source['entity_id'];
    $postingDate    = (string) ($payload['posting_date'] ?? date('Y-m-d'));
    $memo           = (string) ($payload['memo'] ?? '');
    $idemPrefix     = (string) ($payload['idempotency_prefix'] ?? 'ic:' . intercompanyDeriveGroupId());

    // Sum splits — must equal the offset amount to within half a cent.
    $total = 0.0;
    foreach ($splits as $s) $total += round((float) ($s['amount'] ?? 0), 2);
    $offsetAmount = round((float) ($offset['amount'] ?? 0), 2);
    if (abs($total - $offsetAmount) > 0.005) {
        throw new \RuntimeException(sprintf(
            'Splits total %.2f does not equal offset amount %.2f', $total, $offsetAmount
        ));
    }
    if ($offsetAmount <= 0) throw new \InvalidArgumentException('offset amount must be > 0');

    $groupId = $payload['group_id'] ?? intercompanyDeriveGroupId();

    // Partition splits: source's own share (no IC) vs cross-entity share (needs IC offset).
    $sourceOwnLines = [];
    $crossByEntity  = []; // [target_entity_id => [ ['account_code'=>..., 'amount'=>..., 'memo'=>..., 'override'=>...], … ]]
    foreach ($splits as $s) {
        $ent = (int) ($s['entity_id'] ?? $sourceEntityId);
        $amt = round((float) ($s['amount'] ?? 0), 2);
        if ($amt <= 0) continue;
        if ($ent === $sourceEntityId) {
            $sourceOwnLines[] = ['account_code' => (string) $s['account_code'], 'amount' => $amt, 'memo' => $s['memo'] ?? null];
        } else {
            $crossByEntity[$ent] = $crossByEntity[$ent] ?? [];
            $crossByEntity[$ent][] = [
                'account_code' => (string) $s['account_code'],
                'amount'       => $amt,
                'memo'         => $s['memo'] ?? null,
                'override'     => $s['ic_override'] ?? null,
            ];
        }
    }

    $db = getDB();
    $ownsTxn = cf_tx_begin($db);
    $posted = [];
    try {
        // ── Build + post the SOURCE entity's JE ────────────────────────
        $sourceLines = [];
        // For side=credit: split rows are debits; offset is credit.
        // For side=debit : split rows are credits; offset is debit.
        $isOffsetCredit = ($side === 'credit');
        foreach ($sourceOwnLines as $l) {
            $sourceLines[] = [
                'account_code' => $l['account_code'],
                'debit'        => $isOffsetCredit ? $l['amount'] : 0,
                'credit'       => $isOffsetCredit ? 0 : $l['amount'],
                'memo'         => $l['memo'],
            ];
        }
        foreach ($crossByEntity as $targetEntityId => $targetLines) {
            $pair   = intercompanyResolvePair($tenantId, $sourceEntityId, $targetEntityId, $targetLines[0]['override'] ?? null);
            $sum    = array_sum(array_map(fn ($x) => $x['amount'], $targetLines));
            $sourceLines[] = [
                'account_code'           => $pair['due_from_account_code'],
                'debit'                  => $isOffsetCredit ? $sum : 0,
                'credit'                 => $isOffsetCredit ? 0 : $sum,
                'memo'                   => 'IC Due-From entity ' . $targetEntityId,
                'counterparty_entity_id' => $targetEntityId,
            ];
        }
        // Offset line (cash / CC liability / AP / etc.)
        $sourceLines[] = [
            'account_code' => (string) $offset['account_code'],
            'debit'        => $isOffsetCredit ? 0 : $offsetAmount,
            'credit'       => $isOffsetCredit ? $offsetAmount : 0,
            'memo'         => $offset['memo'] ?? null,
        ];

        $sourceJe = accountingPostJe($tenantId, [
            'posting_date'      => $postingDate,
            'memo'              => $memo ? ($memo . ' (IC group ' . substr($groupId, 0, 8) . ')') : 'IC source leg',
            'source_module'     => 'manual',
            'source_ref_type'   => 'intercompany_group',
            'source_ref_id'     => null,
            'idempotency_key'   => $idemPrefix . ':source',
            'entity_id'         => $sourceEntityId,
            'lines'             => $sourceLines,
        ], $actorUserId, true);
        $db->prepare('UPDATE accounting_journal_entries SET intercompany_group_id = :g WHERE id = :id AND tenant_id = :t')
            ->execute(['g' => $groupId, 'id' => $sourceJe['je_id'], 't' => $tenantId]);
        $posted[] = ['entity_id' => $sourceEntityId, 'je_id' => (int) $sourceJe['je_id'], 'je_number' => $sourceJe['je_number'], 'role' => 'source'];

        // ── Build + post each TARGET entity's JE ───────────────────────
        foreach ($crossByEntity as $targetEntityId => $targetLines) {
            $pair = intercompanyResolvePair($tenantId, $sourceEntityId, $targetEntityId, $targetLines[0]['override'] ?? null);
            $targetSum  = array_sum(array_map(fn ($x) => $x['amount'], $targetLines));
            $lines = [];
            foreach ($targetLines as $tl) {
                $lines[] = [
                    'account_code' => $tl['account_code'],
                    'debit'        => $isOffsetCredit ? $tl['amount'] : 0,
                    'credit'       => $isOffsetCredit ? 0 : $tl['amount'],
                    'memo'         => $tl['memo'],
                ];
            }
            $lines[] = [
                'account_code'           => $pair['due_to_account_code'],
                'debit'                  => $isOffsetCredit ? 0 : $targetSum,
                'credit'                 => $isOffsetCredit ? $targetSum : 0,
                'memo'                   => 'IC Due-To entity ' . $sourceEntityId,
                'counterparty_entity_id' => $sourceEntityId,
            ];

            $tjs = accountingPostJe($tenantId, [
                'posting_date'      => $postingDate,
                'memo'              => $memo ? ($memo . ' (IC group ' . substr($groupId, 0, 8) . ')') : 'IC target leg',
                'source_module'     => 'manual',
                'source_ref_type'   => 'intercompany_group',
                'source_ref_id'     => null,
                'idempotency_key'   => $idemPrefix . ':target:' . $targetEntityId,
                'entity_id'         => $targetEntityId,
                'lines'             => $lines,
            ], $actorUserId, true);
            $db->prepare('UPDATE accounting_journal_entries SET intercompany_group_id = :g WHERE id = :id AND tenant_id = :t')
                ->execute(['g' => $groupId, 'id' => $tjs['je_id'], 't' => $tenantId]);
            $posted[] = ['entity_id' => $targetEntityId, 'je_id' => (int) $tjs['je_id'], 'je_number' => $tjs['je_number'], 'role' => 'target'];
        }

        // ── Optional: mark the bank statement line matched (to source JE) ─
        if (!empty($payload['bank_statement_line_id'])) {
            $db->prepare(
                "UPDATE accounting_bank_statement_lines
                 SET match_status = 'matched', matched_je_id = :je, matched_at = NOW(), matched_by_user_id = :u
                 WHERE id = :id AND tenant_id = :t"
            )->execute([
                'je' => $posted[0]['je_id'], 'u' => $actorUserId,
                'id' => (int) $payload['bank_statement_line_id'], 't' => $tenantId,
            ]);
        }

        cf_tx_commit($db, $ownsTxn);
    } catch (\Throwable $e) {
        cf_tx_rollback($db, $ownsTxn);
        throw $e;
    }

    accountingAudit('accounting.intercompany.split_posted', [
        'group_id'  => $groupId,
        'entities'  => array_values(array_unique(array_map(fn ($p) => $p['entity_id'], $posted))),
        'leg_count' => count($posted),
        'total'     => $offsetAmount,
    ], null);

    return ['group_id' => $groupId, 'jes' => $posted];
}


/**
 * Intercompany elimination worksheet —
 *   for every posted JE line where counterparty_entity_id is not null,
 *   surface whether we have a mirror line in the counterparty's books.
 *
 * Returns:
 *   {
 *     as_of,
 *     groups:   [{group_id, legs:[{entity_id, je_number, debit_total, credit_total, status}]}, ...],
 *     pairs:    [{
 *        from_entity_id, to_entity_id,
 *        from_total_debit, from_total_credit,
 *        to_total_debit,   to_total_credit,
 *        imbalance_signed,   // from_debit - to_credit
 *        line_count_from,  line_count_to,
 *     }, ...],
 *     orphans:  [{line_id, je_id, entity_id, account_code, counterparty_entity_id, amount_signed, posting_date}, ...]
 *                // orphans = IC-tagged lines with no matching line on the counterparty's books
 *   }
 */
function intercompanyEliminationWorksheet(int $tenantId, ?string $from = null, ?string $to = null): array
{
    $db = getDB();
    $params = ['t' => $tenantId];
    $dateFilter = '';
    if ($from) { $dateFilter .= ' AND je.posting_date >= :f'; $params['f'] = $from; }
    if ($to)   { $dateFilter .= ' AND je.posting_date <= :to2'; $params['to2'] = $to; }

    // ── All IC groups + leg totals ──────────────────────────────────────
    $stmt = $db->prepare(
        "SELECT je.intercompany_group_id AS group_id,
                je.id AS je_id, je.je_number, je.entity_id, je.posting_date,
                je.total_debit, je.total_credit, je.status
         FROM accounting_journal_entries je
         WHERE je.tenant_id = :t
           AND je.intercompany_group_id IS NOT NULL
           $dateFilter
         ORDER BY je.intercompany_group_id, je.entity_id"
    );
    $stmt->execute($params);
    $groupsMap = [];
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
        $g = $r['group_id'];
        $groupsMap[$g] = $groupsMap[$g] ?? ['group_id' => $g, 'legs' => [], 'leg_count' => 0, 'posting_date' => $r['posting_date']];
        $groupsMap[$g]['legs'][] = [
            'je_id'       => (int) $r['je_id'],
            'je_number'   => $r['je_number'],
            'entity_id'   => (int) $r['entity_id'],
            'debit_total' => round((float) $r['total_debit'], 2),
            'credit_total'=> round((float) $r['total_credit'], 2),
            'status'      => $r['status'],
        ];
        $groupsMap[$g]['leg_count']++;
    }
    $groups = array_values($groupsMap);

    // ── Pair-level totals (from_entity → to_entity) from IC-tagged lines ─
    $stmt = $db->prepare(
        "SELECT je.entity_id AS from_entity_id,
                l.counterparty_entity_id AS to_entity_id,
                SUM(l.debit)  AS total_debit,
                SUM(l.credit) AS total_credit,
                COUNT(*)      AS line_count
         FROM accounting_journal_entry_lines l
         JOIN accounting_journal_entries je ON je.id = l.je_id
         WHERE je.tenant_id = :t
           AND je.status = 'posted'
           AND l.counterparty_entity_id IS NOT NULL
           $dateFilter
         GROUP BY je.entity_id, l.counterparty_entity_id"
    );
    $stmt->execute($params);
    $byPair = []; // keyed "from:to"
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
        $byPair[$r['from_entity_id'] . ':' . $r['to_entity_id']] = [
            'from_entity_id' => (int) $r['from_entity_id'],
            'to_entity_id'   => (int) $r['to_entity_id'],
            'total_debit'    => round((float) $r['total_debit'],  2),
            'total_credit'   => round((float) $r['total_credit'], 2),
            'line_count'     => (int) $r['line_count'],
        ];
    }

    // Pair matching: for (A, B) pair from A's books look up (B, A) from B's books.
    $seen = [];
    $pairs = [];
    foreach ($byPair as $k => $fromData) {
        $from = $fromData['from_entity_id'];
        $to   = $fromData['to_entity_id'];
        $mirrorKey = $to . ':' . $from;
        $sig = min($from, $to) . ':' . max($from, $to);
        if (isset($seen[$sig])) continue;
        $seen[$sig] = true;
        $mirror = $byPair[$mirrorKey] ?? null;
        $pairs[] = [
            'from_entity_id'   => $from,
            'to_entity_id'     => $to,
            'from_total_debit' => $fromData['total_debit'],
            'from_total_credit'=> $fromData['total_credit'],
            'to_total_debit'   => $mirror['total_debit']  ?? 0.0,
            'to_total_credit'  => $mirror['total_credit'] ?? 0.0,
            // Perfect mirror: A's Due-From debit == B's Due-To credit.
            'imbalance_signed' => round(
                ($fromData['total_debit'] - $fromData['total_credit'])
                + (($mirror['total_debit'] ?? 0) - ($mirror['total_credit'] ?? 0)),
                2
            ),
            'line_count_from'  => $fromData['line_count'],
            'line_count_to'    => $mirror['line_count'] ?? 0,
        ];
    }

    // ── Orphans — IC-tagged lines whose JE is NOT in a group (manually tagged) ─
    $stmt = $db->prepare(
        "SELECT l.id AS line_id, l.je_id, je.entity_id, a.code AS account_code,
                l.counterparty_entity_id, (l.debit - l.credit) AS amount_signed,
                je.posting_date
         FROM accounting_journal_entry_lines l
         JOIN accounting_journal_entries je ON je.id = l.je_id
         JOIN accounting_accounts a ON a.id = l.account_id
         WHERE je.tenant_id = :t
           AND je.status = 'posted'
           AND l.counterparty_entity_id IS NOT NULL
           AND je.intercompany_group_id IS NULL
           $dateFilter
         ORDER BY je.posting_date, l.id
         LIMIT 500"
    );
    $stmt->execute($params);
    $orphans = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($orphans as &$o) $o['amount_signed'] = round((float) $o['amount_signed'], 2);
    unset($o);

    return [
        'as_of'   => $to ?: date('Y-m-d'),
        'from'    => $from,
        'to'      => $to,
        'groups'  => $groups,
        'pairs'   => $pairs,
        'orphans' => $orphans,
        'summary' => [
            'group_count'       => count($groups),
            'pair_count'        => count($pairs),
            'imbalanced_pairs'  => count(array_filter($pairs, fn ($p) => abs($p['imbalance_signed']) > 0.005)),
            'orphan_line_count' => count($orphans),
        ],
    ];
}

function intercompanyReverseGroup(int $tenantId, string $groupId, string $reason, ?int $actorUserId = null): array
{
    if (trim($reason) === '') throw new \InvalidArgumentException('reason required');
    $stmt = getDB()->prepare(
        "SELECT id FROM accounting_journal_entries
         WHERE tenant_id = :t AND intercompany_group_id = :g AND status = 'posted'"
    );
    $stmt->execute(['t' => $tenantId, 'g' => $groupId]);
    $jeIds = array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'id');
    if (!$jeIds) throw new \RuntimeException('No posted JEs found in group');
    $reversals = [];
    foreach ($jeIds as $jeId) {
        $reversals[] = accountingReverseJe($tenantId, (int) $jeId, $reason, $actorUserId);
    }
    accountingAudit('accounting.intercompany.group_reversed', [
        'group_id' => $groupId,
        'reversed_count' => count($reversals),
        'reason'   => $reason,
    ], null);
    return ['group_id' => $groupId, 'reversals' => $reversals];
}
