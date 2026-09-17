<?php
/**
 * Accounting — Bank Reconciliation library.
 *
 * Pure server-side helpers: CSV import, line match/unmatch, rule
 * application. No HTTP — call these from /api/accounting/bank_*.php.
 *
 * Functions:
 *   - bankRecImportCsv()      Import a CSV into accounting_bank_statement_lines.
 *                             De-dups on (tenant_id, bank_account_id, fitid).
 *                             Auto-detects column mapping if header_map is null.
 *   - bankRecMatchLine()      Mark a bank line as matched to a JE.
 *   - bankRecUnmatchLine()    Reverse the above.
 *   - bankRecApplyRules()     Walk unmatched lines, apply approved rules,
 *                             stage suggested rules.
 *   - bankRecLineMatchesRule()  Single rule check (also used by AI suggester).
 *   - bankRecAutoSuggestMatches()  Heuristic JE-line match suggester.
 */

declare(strict_types=1);

/**
 * Parse + insert CSV rows. The CSV is expected to have a header row.
 * If $headerMap is null we auto-detect by header name keywords.
 *
 * @param array{date_col?:string|int,desc_col?:string|int,amount_col?:string|int,fitid_col?:string|int}|null $headerMap
 */
function bankRecImportCsv(int $tenantId, int $bankAccountId, string $csvBody, ?array $headerMap, ?int $userId): array
{
    $rows = [];
    $fh   = fopen('php://memory', 'r+');
    fwrite($fh, $csvBody);
    rewind($fh);
    $header = fgetcsv($fh);
    if (!$header) throw new RuntimeException('CSV is empty or unreadable');

    // Resolve column indexes
    $dateCol  = bankRecResolveCol($header, $headerMap['date_col']   ?? null, ['date','posted','transaction_date']);
    $descCol  = bankRecResolveCol($header, $headerMap['desc_col']   ?? null, ['description','memo','payee','narrative']);
    $amtCol   = bankRecResolveCol($header, $headerMap['amount_col'] ?? null, ['amount','value','debit_credit']);
    $fitidCol = bankRecResolveCol($header, $headerMap['fitid_col']  ?? null, ['fitid','transaction_id','txn_id','reference']);

    if ($dateCol === null || $descCol === null || $amtCol === null) {
        throw new RuntimeException('CSV must have date, description, and amount columns');
    }

    $pdo  = getDB();
    $now  = date('Y-m-d H:i:s');

    // Create a parent import row first
    $importId = scopedInsert('accounting_bank_statement_imports', [
        'bank_account_id'    => $bankAccountId,
        'source'             => 'csv',
        'created_by_user_id' => $userId,
    ]);

    $inserted = 0; $duplicates = 0; $minDate = null; $maxDate = null;
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO accounting_bank_statement_lines
            (tenant_id, bank_account_id, import_id, posted_date, description, amount, bank_reference, fitid)
         VALUES (:t, :b, :i, :d, :desc, :amt, :ref, :fitid)'
    );
    while (($r = fgetcsv($fh)) !== false) {
        if (!isset($r[$dateCol], $r[$descCol], $r[$amtCol])) continue;
        $date  = trim((string) $r[$dateCol]);
        $desc  = trim((string) $r[$descCol]);
        $amt   = (float) preg_replace('/[^0-9.\-]/', '', (string) $r[$amtCol]);
        if ($date === '' || $desc === '') continue;
        // Normalize to YYYY-MM-DD
        $ts = strtotime($date);
        if ($ts === false) continue;
        $iso = date('Y-m-d', $ts);
        $fit = $fitidCol !== null && isset($r[$fitidCol]) ? trim((string) $r[$fitidCol]) : null;
        if ($fit === '' || $fit === null) {
            // Synthesize a stable FITID so de-dup still works
            $fit = sha1($bankAccountId . '|' . $iso . '|' . $desc . '|' . $amt);
        }
        $stmt->execute([
            't'     => $tenantId,
            'b'     => $bankAccountId,
            'i'     => $importId,
            'd'     => $iso,
            'desc'  => substr($desc, 0, 255),
            'amt'   => $amt,
            'ref'   => null,
            'fitid' => substr($fit, 0, 120),
        ]);
        if ($stmt->rowCount() === 1) {
            $inserted++;
            if ($minDate === null || $iso < $minDate) $minDate = $iso;
            if ($maxDate === null || $iso > $maxDate) $maxDate = $iso;
        } else {
            $duplicates++;
        }
    }
    fclose($fh);

    scopedUpdate('accounting_bank_statement_imports', $importId, [
        'statement_from' => $minDate,
        'statement_to'   => $maxDate,
        'line_count'     => $inserted,
    ]);

    return [
        'import_id'  => $importId,
        'inserted'   => $inserted,
        'duplicates' => $duplicates,
        'date_from'  => $minDate,
        'date_to'    => $maxDate,
    ];
}

function bankRecResolveCol(array $header, $explicit, array $keywords): ?int
{
    if (is_int($explicit))    return $explicit;
    if (is_string($explicit)) {
        $i = array_search($explicit, $header, true);
        if ($i !== false) return (int) $i;
    }
    foreach ($header as $i => $h) {
        $hLow = strtolower(trim((string) $h));
        foreach ($keywords as $k) {
            if ($hLow === $k || str_contains($hLow, $k)) return (int) $i;
        }
    }
    return null;
}

function bankRecMarkLineMatched(int $tenantId, int $lineId, int $jeId, ?int $userId): void
{
    $current = scopedFind(
        'SELECT id, match_status, matched_je_id
           FROM accounting_bank_statement_lines
          WHERE tenant_id = :tenant_id AND id = :id',
        ['id' => $lineId]
    );
    if (!$current) throw new RuntimeException('Line not found');
    if (($current['match_status'] ?? '') === 'matched' && (int) ($current['matched_je_id'] ?? 0) === $jeId) {
        return;
    }
    if (($current['match_status'] ?? '') !== 'unmatched') {
        throw new RuntimeException('This bank line is already resolved. Refresh before matching it again.');
    }

    $stmt = getDB()->prepare(
        'UPDATE accounting_bank_statement_lines
            SET match_status = "matched",
                matched_je_id = :je,
                matched_at = NOW(),
                matched_by_user_id = :user_id
          WHERE tenant_id = :tenant_id AND id = :id AND match_status = "unmatched"'
    );
    $stmt->execute([
        'je'        => $jeId,
        'user_id'   => $userId,
        'tenant_id' => $tenantId,
        'id'        => $lineId,
    ]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('This bank line changed while it was being matched. Refresh and try again.');
    }
}

function bankRecMatchLine(int $tenantId, int $lineId, int $jeId, ?int $userId): array
{
    $line = scopedFind(
        'SELECT bl.id, bl.amount, bl.match_status, bl.matched_je_id,
                ba.gl_account_code, ba.entity_id AS bank_entity_id,
                COALESCE(NULLIF(ba.currency, ""), "USD") AS bank_currency
           FROM accounting_bank_statement_lines bl
           JOIN accounting_bank_accounts ba
             ON ba.tenant_id = bl.tenant_id AND ba.id = bl.bank_account_id
          WHERE bl.tenant_id = :tenant_id AND bl.id = :id',
        ['id' => $lineId]
    );
    if (!$line) throw new RuntimeException('Line not found');
    if (($line['match_status'] ?? '') === 'matched' && (int) ($line['matched_je_id'] ?? 0) === $jeId) {
        return ['ok' => true, 'line_id' => $lineId, 'je_id' => $jeId, 'idempotent_replay' => true];
    }
    if (($line['match_status'] ?? '') !== 'unmatched') {
        throw new RuntimeException('This bank line is already resolved');
    }

    $je = scopedFind(
        'SELECT je.id, je.status, je.entity_id, je.currency,
                ROUND(COALESCE(SUM(CASE WHEN account.code = :bank_code
                     THEN line.debit - line.credit ELSE 0 END), 0), 2) AS bank_movement
           FROM accounting_journal_entries je
           LEFT JOIN accounting_journal_entry_lines line ON line.je_id = je.id
           LEFT JOIN accounting_accounts account
             ON account.tenant_id = je.tenant_id AND account.id = line.account_id
          WHERE je.tenant_id = :tenant_id AND je.id = :id
          GROUP BY je.id, je.status, je.entity_id, je.currency',
        ['id' => $jeId, 'bank_code' => (string) $line['gl_account_code']]
    );
    if (!$je) throw new RuntimeException('JE not found');
    if (($je['status'] ?? '') !== 'posted') throw new RuntimeException('Only a posted journal entry can be matched');
    if (!empty($line['bank_entity_id']) && !empty($je['entity_id'])
        && (int) $line['bank_entity_id'] !== (int) $je['entity_id']) {
        throw new RuntimeException('The journal entry belongs to a different entity');
    }
    if (strcasecmp((string) ($line['bank_currency'] ?: 'USD'), (string) ($je['currency'] ?: 'USD')) !== 0) {
        throw new RuntimeException('The journal entry uses a different currency');
    }
    if (abs((float) $je['bank_movement'] - (float) $line['amount']) > 0.005) {
        throw new RuntimeException('The journal entry does not contain the matching cash movement for this bank account and amount');
    }
    $used = scopedFind(
        'SELECT id FROM accounting_bank_statement_lines
          WHERE tenant_id = :tenant_id AND matched_je_id = :je_id
            AND match_status = "matched" AND id <> :line_id
          LIMIT 1',
        ['je_id' => $jeId, 'line_id' => $lineId]
    );
    if ($used) throw new RuntimeException('That journal entry is already matched to another bank line');

    bankRecMarkLineMatched($tenantId, $lineId, $jeId, $userId);
    return ['ok' => true, 'line_id' => $lineId, 'je_id' => $jeId, 'idempotent_replay' => false];
}

function bankRecUnmatchLine(int $tenantId, int $lineId): array
{
    $line = scopedFind(
        'SELECT bl.id, bl.match_status, bl.matched_je_id,
                je.source_module, je.source_ref_type, je.source_ref_id
           FROM accounting_bank_statement_lines bl
           LEFT JOIN accounting_journal_entries je
             ON je.tenant_id = bl.tenant_id AND je.id = bl.matched_je_id
          WHERE bl.tenant_id = :tenant_id AND bl.id = :id',
        ['id' => $lineId]
    );
    if (!$line) throw new RuntimeException('Line not found');
    if (($line['match_status'] ?? '') === 'unmatched') {
        return ['ok' => true, 'line_id' => $lineId, 'idempotent_replay' => true];
    }

    $journalWasCreatedFromLine = (string) ($line['source_ref_type'] ?? '') === 'bank_statement_line'
        && (int) ($line['source_ref_id'] ?? 0) === $lineId;
    if (!$journalWasCreatedFromLine && !empty($line['matched_je_id'])) {
        try {
            $lineage = scopedFind(
                'SELECT id FROM accounting_subledger_links
                  WHERE tenant_id = :tenant_id AND journal_entry_id = :journal_entry_id
                    AND source_module = "treasury_feed"
                    AND source_record_id IN (:bank_line, :bank_split)
                  LIMIT 1',
                [
                    'journal_entry_id' => (int) $line['matched_je_id'],
                    'bank_line' => 'bank_line:' . $lineId,
                    'bank_split' => 'bank_line:split:' . $lineId,
                ]
            );
            $journalWasCreatedFromLine = (bool) $lineage;
        } catch (Throwable $_) {
            // Older tenants may not have the optional lineage table.
        }
    }
    if ($journalWasCreatedFromLine) {
        throw new RuntimeException(
            'This bank line created its ledger entry and cannot be detached from it. '
            . 'Reverse or correct the posted transaction instead.'
        );
    }

    scopedUpdate('accounting_bank_statement_lines', $lineId, [
        'match_status'      => 'unmatched',
        'matched_je_id'     => null,
        'matched_at'        => null,
        'matched_by_user_id' => null,
    ]);
    return ['ok' => true, 'line_id' => $lineId, 'idempotent_replay' => false];
}

/**
 * Bring historical Treasury bookings back into line with bank reconciliation.
 * Only explicit journal lineage is considered; amount/date similarity is never
 * enough to change a statement line's status.
 */
function bankRecRepairPostedMatches(int $tenantId, int $bankAccountId): array
{
    $pdo = getDB();
    $found = [];

    $queries = [
        // A line may already hold a journal id while its status remained stale.
        'SELECT bl.id AS line_id, bl.matched_je_id AS je_id
           FROM accounting_bank_statement_lines bl
           JOIN accounting_journal_entries je
             ON je.tenant_id = bl.tenant_id AND je.id = bl.matched_je_id AND je.status = "posted"
          WHERE bl.tenant_id = :tenant_id AND bl.bank_account_id = :bank_account_id
            AND bl.match_status = "unmatched"',

        // Treasury direct-post journals carry the statement line as source.
        'SELECT bl.id AS line_id, MAX(je.id) AS je_id
           FROM accounting_bank_statement_lines bl
           JOIN accounting_journal_entries je
             ON je.tenant_id = bl.tenant_id
            AND je.status = "posted"
            AND je.source_module = "treasury_feed"
            AND je.source_ref_type = "bank_statement_line"
            AND CAST(je.source_ref_id AS CHAR) = CAST(bl.id AS CHAR)
          WHERE bl.tenant_id = :tenant_id AND bl.bank_account_id = :bank_account_id
            AND bl.match_status = "unmatched"
          GROUP BY bl.id',
    ];

    foreach ($queries as $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['tenant_id' => $tenantId, 'bank_account_id' => $bankAccountId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((int) ($row['je_id'] ?? 0) > 0) $found[(int) $row['line_id']] = (int) $row['je_id'];
        }
    }

    // Event-routed Treasury postings are connected through subledger links.
    try {
        $stmt = $pdo->prepare(
            'SELECT bl.id AS line_id, MAX(sl.journal_entry_id) AS je_id
               FROM accounting_bank_statement_lines bl
               JOIN accounting_subledger_links sl
                 ON sl.tenant_id = bl.tenant_id
                AND sl.source_module = "treasury_feed"
                AND sl.source_record_id IN (CONCAT("bank_line:", bl.id), CONCAT("bank_line:split:", bl.id))
               JOIN accounting_journal_entries je
                 ON je.tenant_id = sl.tenant_id AND je.id = sl.journal_entry_id AND je.status = "posted"
              WHERE bl.tenant_id = :tenant_id AND bl.bank_account_id = :bank_account_id
                AND bl.match_status = "unmatched"
              GROUP BY bl.id'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'bank_account_id' => $bankAccountId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((int) ($row['je_id'] ?? 0) > 0) $found[(int) $row['line_id']] = (int) $row['je_id'];
        }
    } catch (Throwable $_) {
        // Older tenants may not have the optional subledger link table yet.
    }

    foreach ($found as $lineId => $jeId) {
        bankRecMarkLineMatched($tenantId, $lineId, $jeId, null);
    }

    return ['repaired' => count($found), 'line_ids' => array_keys($found)];
}

/**
 * Walk unmatched lines for a bank account, apply approved rules, stage
 * suggested rules. Returns counts.
 */
function bankRecApplyRules(int $tenantId, int $bankAccountId, ?int $userId): array
{
    $rules = scopedQuery(
        'SELECT * FROM accounting_bank_rules
         WHERE tenant_id = :tenant_id AND status = "active"
           AND (bank_account_id IS NULL OR bank_account_id = :b)
         ORDER BY is_approved DESC, id',
        ['b' => $bankAccountId]
    );
    if (empty($rules)) return ['rules_evaluated' => 0, 'auto_applied' => 0, 'suggested' => 0];

    $lines = scopedQuery(
        'SELECT * FROM accounting_bank_statement_lines
         WHERE tenant_id = :tenant_id AND bank_account_id = :b AND match_status = "unmatched"
           AND ai_suggested_rule_id IS NULL AND applied_rule_id IS NULL',
        ['b' => $bankAccountId]
    );

    $autoApplied = 0; $suggested = 0;
    $now = date('Y-m-d H:i:s');
    foreach ($lines as $l) {
        foreach ($rules as $r) {
            if (!bankRecLineMatchesRule($l, $r)) continue;

            if ((int) $r['is_approved'] === 1) {
                // Auto-apply: stamp the suggested + applied fields. We DO NOT
                // post a JE here — the user reviews this on the bank-rec page
                // and clicks "post JE" to actually move money. (Auto-posting
                // from rules is a Sprint A.3 follow-up and gated on a
                // tenant-level setting.)
                scopedUpdate('accounting_bank_statement_lines', (int) $l['id'], [
                    'ai_suggested_account_code' => $r['target_account_code'],
                    'ai_suggested_rule_id'      => $r['id'],
                    'ai_suggested_at'           => $now,
                    'ai_suggested_confidence'   => 1.000,
                    'applied_rule_id'           => $r['id'],
                ]);
                $autoApplied++;
            } else {
                scopedUpdate('accounting_bank_statement_lines', (int) $l['id'], [
                    'ai_suggested_account_code' => $r['target_account_code'],
                    'ai_suggested_rule_id'      => $r['id'],
                    'ai_suggested_at'           => $now,
                    'ai_suggested_confidence'   => 0.800,
                ]);
                $suggested++;
            }
            // Update rule stats (idempotent — this lib runs from a single PHP request)
            getDB()->prepare(
                'UPDATE accounting_bank_rules SET times_applied = times_applied + 1, last_applied_at = NOW()
                 WHERE id = :id AND tenant_id = :t'
            )->execute(['id' => $r['id'], 't' => $tenantId]);
            break;  // first matching rule wins per line
        }
    }
    return [
        'rules_evaluated' => count($rules),
        'auto_applied'    => $autoApplied,
        'suggested'       => $suggested,
        'lines_evaluated' => count($lines),
    ];
}

/**
 * Evaluate a single rule against a single bank line. No DB I/O.
 * Pure function so the AI suggester / unit tests can call it cheaply.
 */
function bankRecLineMatchesRule(array $line, array $rule): bool
{
    $desc      = (string) ($line['description'] ?? '');
    $amtCents  = (int) round(((float) ($line['amount'] ?? 0)) * 100);
    $direction = $amtCents > 0 ? 'credit' : ($amtCents < 0 ? 'debit' : 'any');

    if ($rule['direction'] !== 'any' && $rule['direction'] !== $direction) return false;

    if ($rule['amount_min_cents'] !== null && abs($amtCents) < (int) $rule['amount_min_cents']) return false;
    if ($rule['amount_max_cents'] !== null && abs($amtCents) > (int) $rule['amount_max_cents']) return false;

    $pat   = (string) $rule['pattern'];
    $kind  = (string) $rule['pattern_kind'];
    $hay   = strtolower($desc);
    $needle= strtolower($pat);

    return match ($kind) {
        'contains'    => str_contains($hay, $needle),
        'starts_with' => str_starts_with($hay, $needle),
        'equals'      => $hay === $needle,
        'regex'       => @preg_match('/' . str_replace('/', '\/', $pat) . '/i', $desc) === 1,
        default       => false,
    };
}

/**
 * Heuristic match suggester — finds JE lines that match the bank line on
 * (signed amount, posting_date ±3 days). The AI assistant uses this set
 * as its candidate pool, then chooses the best by description / memo.
 */
function bankRecAutoSuggestMatches(int $tenantId, array $bankLine, int $bankAccountId): array
{
    $amount = (float) ($bankLine['amount'] ?? 0);
    if ($amount === 0.0) return [];
    $bankSideSql = $amount > 0
        ? 'AND l.debit = :abs_amt AND l.credit = 0'
        : 'AND l.credit = :abs_amt AND l.debit = 0';
    // Use distinct :d_lo / :d_hi placeholders. The previous `:d` repeat
    // broke under PDO_MYSQL native prepares (EMULATE_PREPARES=false) and
    // returned zero matches every time → treasury entries stayed
    // permanently "unmatched" on the bank reconciliation screen.
    return scopedQuery(
        'SELECT je.id AS je_id, je.je_number, je.posting_date, je.memo,
                je.source_module, je.source_ref_id,
                l.debit, l.credit, l.description AS line_desc, a.code AS account_code
         FROM accounting_journal_entry_lines l
         JOIN accounting_journal_entries je ON je.id = l.je_id AND je.tenant_id = l.tenant_id
         JOIN accounting_accounts a ON a.id = l.account_id AND a.tenant_id = l.tenant_id
         JOIN accounting_bank_accounts ba
           ON ba.tenant_id = l.tenant_id AND ba.id = :bank_account_id
          AND ba.gl_account_code = a.code
         WHERE l.tenant_id = :tenant_id
           AND je.status = "posted"
           ' . $bankSideSql . '
           AND je.posting_date BETWEEN DATE_SUB(:d_lo, INTERVAL 3 DAY) AND DATE_ADD(:d_hi, INTERVAL 3 DAY)
           AND je.id NOT IN (
               SELECT matched_je_id FROM accounting_bank_statement_lines
               WHERE tenant_id = :tenant_id_sub AND matched_je_id IS NOT NULL
           )
         ORDER BY je.posting_date DESC LIMIT 20',
        [
            'bank_account_id' => $bankAccountId,
            'abs_amt'       => abs($amount),
            'd_lo'          => $bankLine['posted_date'],
            'd_hi'          => $bankLine['posted_date'],
            'tenant_id_sub' => $tenantId,
        ]
    );
}

/**
 * Find open invoices whose remaining balance exactly matches an incoming
 * bank line. Exact cents are intentionally required for the automatic badge;
 * ambiguous and partial receipts remain available through manual workflows.
 */
function bankRecInvoiceMatchCandidates(
    int $tenantId,
    array $bankLine,
    int $bankAccountId,
    int $limit = 5
): array {
    $rows = bankRecAttachInvoiceSuggestions($tenantId, $bankAccountId, [$bankLine], $limit);
    return $rows[0]['invoice_matches'] ?? [];
}

/**
 * Attach invoice_matches + the top invoice_match to statement rows in one
 * query. This keeps the bank-feed list useful without an N+1 query per line.
 */
function bankRecAttachInvoiceSuggestions(
    int $tenantId,
    int $bankAccountId,
    array $bankLines,
    int $limitPerLine = 5
): array {
    foreach ($bankLines as &$line) {
        $line['invoice_matches'] = [];
        $line['invoice_match'] = null;
    }
    unset($line);

    $amountKeys = [];
    $latestPosted = null;
    foreach ($bankLines as $line) {
        $amount = round((float) ($line['amount'] ?? 0), 2);
        if ($amount <= 0) continue;
        $amountKeys[(string) (int) round($amount * 100)] = $amount;
        $date = (string) ($line['posted_date'] ?? '');
        if ($date !== '' && ($latestPosted === null || $date > $latestPosted)) $latestPosted = $date;
    }
    if (!$amountKeys || !$latestPosted) return $bankLines;

    try {
        $bank = scopedFind(
            'SELECT entity_id FROM accounting_bank_accounts WHERE tenant_id = :tenant_id AND id = :id',
            ['id' => $bankAccountId]
        );
        if (!$bank) return $bankLines;

        $params = ['latest_posted' => $latestPosted];
        $amountParams = [];
        $i = 0;
        foreach ($amountKeys as $amount) {
            $key = 'invoice_amount_' . $i++;
            $amountParams[] = ':' . $key;
            $params[$key] = number_format($amount, 2, '.', '');
        }
        $entitySql = '';
        if (!empty($bank['entity_id'])) {
            $entitySql = ' AND (bi.entity_id = :bank_entity_id OR bi.entity_id IS NULL)';
            $params['bank_entity_id'] = (int) $bank['entity_id'];
        }

        $invoices = scopedQuery(
            'SELECT bi.id, bi.invoice_number, bi.client_name, bi.client_company_id, bi.entity_id,
                    bi.issue_date, bi.due_date, bi.currency, bi.amount_due, bi.status,
                    bi.journal_entry_id, je.status AS journal_status
               FROM billing_invoices bi
               LEFT JOIN accounting_journal_entries je
                 ON je.tenant_id = bi.tenant_id AND je.id = bi.journal_entry_id
              WHERE bi.tenant_id = :tenant_id
                AND bi.status IN ("draft", "approved", "sent", "partially_paid")
                AND bi.amount_due > 0
                AND ROUND(bi.amount_due, 2) IN (' . implode(',', $amountParams) . ')
                AND bi.issue_date <= :latest_posted' . $entitySql . '
              ORDER BY bi.due_date ASC, bi.id ASC',
            $params
        );

        $byAmount = [];
        foreach ($invoices as $invoice) {
            $key = (string) (int) round((float) $invoice['amount_due'] * 100);
            $byAmount[$key][] = $invoice;
        }

        foreach ($bankLines as &$line) {
            $amount = round((float) ($line['amount'] ?? 0), 2);
            if ($amount <= 0) continue;
            $key = (string) (int) round($amount * 100);
            $matches = [];
            foreach ($byAmount[$key] ?? [] as $invoice) {
                $description = strtolower((string) ($line['description'] ?? ''));
                $invoiceNumber = strtolower((string) $invoice['invoice_number']);
                $clientName = strtolower((string) $invoice['client_name']);
                $numberMatch = $invoiceNumber !== '' && str_contains($description, $invoiceNumber);
                $clientTokens = array_values(array_filter(
                    preg_split('/[^a-z0-9]+/i', $clientName) ?: [],
                    static fn(string $token): bool => strlen($token) >= 4
                ));
                $tokenHits = count(array_filter(
                    $clientTokens,
                    static fn(string $token): bool => str_contains($description, $token)
                ));
                $clientMatch = $clientTokens && $tokenHits >= min(2, count($clientTokens));

                $canApply = in_array($invoice['status'], ['approved', 'sent', 'partially_paid'], true)
                    && ($invoice['journal_status'] ?? null) === 'posted';
                $score = $canApply ? 0.86 : 0.72;
                if ($clientMatch) $score += 0.08;
                if ($numberMatch) $score += 0.06;
                $score = min(1.0, $score);
                $reason = 'Exact invoice balance of ' . number_format($amount, 2) . ' ' . $invoice['currency'];
                if ($numberMatch) $reason .= '; invoice number appears in the bank description';
                elseif ($clientMatch) $reason .= '; customer name appears in the bank description';
                if (!$canApply) {
                    $reason .= $invoice['status'] === 'draft'
                        ? '; finalize and post the draft before applying payment'
                        : '; post the invoice before applying payment';
                }

                $matches[] = [
                    'candidate_type' => 'invoice',
                    'invoice_id' => (int) $invoice['id'],
                    'invoice_number' => (string) $invoice['invoice_number'],
                    'client_name' => (string) $invoice['client_name'],
                    'status' => (string) $invoice['status'],
                    'amount_due' => (float) $invoice['amount_due'],
                    'currency' => (string) $invoice['currency'],
                    'issue_date' => (string) $invoice['issue_date'],
                    'due_date' => (string) $invoice['due_date'],
                    'journal_entry_id' => !empty($invoice['journal_entry_id']) ? (int) $invoice['journal_entry_id'] : null,
                    'can_apply_payment' => $canApply,
                    'label' => 'Invoice ' . $invoice['invoice_number'] . ' - ' . $invoice['client_name'],
                    'score' => $score,
                    'reasoning' => $reason,
                ];
            }
            usort($matches, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
            $line['invoice_matches'] = array_slice($matches, 0, max(1, $limitPerLine));
            $line['invoice_match'] = $line['invoice_matches'][0] ?? null;
        }
        unset($line);
    } catch (\Throwable $e) {
        error_log('[bank-rec] invoice suggestion lookup failed: ' . $e->getMessage());
    }

    return $bankLines;
}

/**
 * Attach released AP payments to matching outgoing bank lines. A sent payment
 * can be cleared and reconciled in one action; a previously cleared payment
 * can be linked without posting cash a second time.
 */
function bankRecAttachApPaymentSuggestions(
    int $tenantId,
    int $bankAccountId,
    array $bankLines,
    int $limitPerLine = 5
): array {
    foreach ($bankLines as &$line) {
        $line['ap_payment_matches'] = [];
        $line['ap_payment_match'] = null;
    }
    unset($line);

    $amountKeys = [];
    $earliestPosted = null;
    $latestPosted = null;
    foreach ($bankLines as $line) {
        $amount = round((float) ($line['amount'] ?? 0), 2);
        if ($amount >= 0) continue;
        $amountKeys[(string) (int) round(abs($amount) * 100)] = abs($amount);
        $date = (string) ($line['posted_date'] ?? '');
        if ($date === '') continue;
        if ($earliestPosted === null || $date < $earliestPosted) $earliestPosted = $date;
        if ($latestPosted === null || $date > $latestPosted) $latestPosted = $date;
    }
    if (!$amountKeys || !$earliestPosted || !$latestPosted) return $bankLines;

    try {
        $bank = scopedFind(
            'SELECT id, entity_id, COALESCE(NULLIF(currency, ""), "USD") AS currency
               FROM accounting_bank_accounts
              WHERE tenant_id = :tenant_id AND id = :id',
            ['id' => $bankAccountId]
        );
        if (!$bank) return $bankLines;

        $params = [
            'bank_account_id' => $bankAccountId,
            'bank_currency' => (string) $bank['currency'],
            'date_from' => date('Y-m-d', strtotime($earliestPosted . ' -30 days')),
            'date_to' => date('Y-m-d', strtotime($latestPosted . ' +3 days')),
        ];
        $amountParams = [];
        $i = 0;
        foreach ($amountKeys as $amount) {
            $key = 'payment_amount_' . $i++;
            $amountParams[] = ':' . $key;
            $params[$key] = number_format($amount, 2, '.', '');
        }
        $entitySql = '';
        if (!empty($bank['entity_id'])) {
            $entitySql = ' AND p.entity_id = :bank_entity_id';
            $params['bank_entity_id'] = (int) $bank['entity_id'];
        }

        $payments = scopedQuery(
            'SELECT p.id, p.vendor_name, p.pay_date, p.method,
                    p.reference, p.amount, p.currency, p.status, p.entity_id,
                    p.bank_account_id, p.journal_entry_id,
                    je.status AS journal_status,
                    matched.id AS matched_line_id
               FROM ap_payments p
               LEFT JOIN accounting_journal_entries je
                 ON je.tenant_id = p.tenant_id AND je.id = p.journal_entry_id
               LEFT JOIN accounting_bank_statement_lines matched
                 ON matched.tenant_id = p.tenant_id
                AND matched.matched_je_id = p.journal_entry_id
                AND matched.match_status = "matched"
              WHERE p.tenant_id = :tenant_id
                AND p.status IN ("sent", "cleared")
                AND p.unallocated_amount <= 0.005
                AND ROUND(p.amount, 2) IN (' . implode(',', $amountParams) . ')
                AND p.currency = :bank_currency
                AND p.pay_date BETWEEN :date_from AND :date_to
                AND (p.bank_account_id IS NULL OR p.bank_account_id = :bank_account_id)'
                . $entitySql . '
              ORDER BY p.pay_date DESC, p.id DESC',
            $params
        );

        $byAmount = [];
        foreach ($payments as $payment) {
            $key = (string) (int) round((float) $payment['amount'] * 100);
            $byAmount[$key][] = $payment;
        }

        foreach ($bankLines as &$line) {
            $lineAmount = round((float) ($line['amount'] ?? 0), 2);
            if ($lineAmount >= 0) continue;
            $key = (string) (int) round(abs($lineAmount) * 100);
            $description = strtolower((string) ($line['description'] ?? ''));
            $postedAt = strtotime((string) ($line['posted_date'] ?? '')) ?: 0;
            $matches = [];
            foreach ($byAmount[$key] ?? [] as $payment) {
                if (!empty($payment['matched_line_id']) && (int) $payment['matched_line_id'] !== (int) $line['id']) continue;
                $paidAt = strtotime((string) ($payment['pay_date'] ?? '')) ?: 0;
                if ($postedAt && $paidAt && abs($postedAt - $paidAt) > 30 * 86400) continue;

                $reference = strtolower(trim((string) ($payment['reference'] ?? '')));
                $vendorTokens = array_values(array_filter(
                    preg_split('/[^a-z0-9]+/i', strtolower((string) $payment['vendor_name'])) ?: [],
                    static fn(string $token): bool => strlen($token) >= 4
                ));
                $referenceMatch = $reference !== '' && str_contains($description, $reference);
                $vendorMatch = $vendorTokens && count(array_filter(
                    $vendorTokens,
                    static fn(string $token): bool => str_contains($description, $token)
                )) >= min(2, count($vendorTokens));
                $canMatch = ($payment['status'] === 'sent')
                    || ($payment['status'] === 'cleared' && ($payment['journal_status'] ?? null) === 'posted');
                $score = $canMatch ? 0.86 : 0.70;
                if ($vendorMatch) $score += 0.08;
                if ($referenceMatch) $score += 0.06;
                $score = min(1.0, $score);
                $reason = 'Exact released payment amount of '
                    . number_format((float) $payment['amount'], 2) . ' ' . $payment['currency'];
                if ($referenceMatch) $reason .= '; payment reference appears in the bank description';
                elseif ($vendorMatch) $reason .= '; vendor name appears in the bank description';
                if (!$canMatch) $reason .= '; repair the payment ledger posting before matching';

                $matches[] = [
                    'candidate_type' => 'ap_payment',
                    'payment_id' => (int) $payment['id'],
                    'vendor_name' => (string) $payment['vendor_name'],
                    'pay_date' => (string) $payment['pay_date'],
                    'method' => (string) $payment['method'],
                    'reference' => (string) ($payment['reference'] ?? ''),
                    'amount' => (float) $payment['amount'],
                    'currency' => (string) $payment['currency'],
                    'status' => (string) $payment['status'],
                    'journal_entry_id' => !empty($payment['journal_entry_id']) ? (int) $payment['journal_entry_id'] : null,
                    'can_clear_and_match' => $canMatch,
                    'label' => 'Payment #' . $payment['id'] . ' - ' . $payment['vendor_name'],
                    'score' => $score,
                    'reasoning' => $reason,
                ];
            }
            usort($matches, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
            $line['ap_payment_matches'] = array_slice($matches, 0, max(1, $limitPerLine));
            $line['ap_payment_match'] = $line['ap_payment_matches'][0] ?? null;
        }
        unset($line);
    } catch (\Throwable $e) {
        error_log('[bank-rec] AP payment suggestion lookup failed: ' . $e->getMessage());
    }

    return $bankLines;
}

/**
 * Rule learner — turn accepted AI categorizations into draft rules.
 *
 * Algorithm:
 *   1. Pull every accepted bank line (categorized_via='ai_accepted')
 *      with their description and chosen account_code.
 *   2. Group by account_code.
 *   3. For each cluster: extract every alphanumeric token (≥4 chars,
 *      lowercased) from each description, count occurrences across
 *      DISTINCT line descriptions in the cluster.
 *   4. Tokens hit by ≥ $minOccurrences distinct lines become rule
 *      candidates. Skip tokens already in an active rule's pattern for
 *      the same target account (no duplicates).
 *   5. Insert the best (highest-occurrence) candidate per cluster as a
 *      new accounting_bank_rules row with is_approved=0 + created_via=
 *      'ai_learned' so the user can one-click Approve in the rules UI.
 *
 * Returns counts + the list of drafted rules so the caller can show
 * them inline.
 *
 * @return array{drafted: int, drafts: array<int, array<string,mixed>>, clusters_evaluated: int}
 */
function bankRecLearnRulesFromAccepts(int $tenantId, int $minOccurrences, ?int $userId): array
{
    $minOccurrences = max(2, $minOccurrences);

    $rows = scopedQuery(
        'SELECT id, description, categorized_account_code, amount
         FROM accounting_bank_statement_lines
         WHERE tenant_id = :tenant_id
           AND categorized_via = "ai_accepted"
           AND categorized_account_code IS NOT NULL
           AND description IS NOT NULL
         ORDER BY categorized_at DESC
         LIMIT 2000'
    );
    if (empty($rows)) return ['drafted' => 0, 'drafts' => [], 'clusters_evaluated' => 0];

    // Group by account_code → distinct descriptions
    $clusters = [];
    foreach ($rows as $r) {
        $code = (string) $r['categorized_account_code'];
        $desc = trim((string) $r['description']);
        if (!isset($clusters[$code])) $clusters[$code] = [];
        if (!isset($clusters[$code][$desc])) $clusters[$code][$desc] = (float) $r['amount'];
    }

    // Existing patterns for de-dup
    $existing = scopedQuery(
        'SELECT pattern, target_account_code FROM accounting_bank_rules
         WHERE tenant_id = :tenant_id AND status IN ("active","paused")'
    );
    $existingSet = [];
    foreach ($existing as $r) {
        $existingSet[strtolower((string) $r['target_account_code']) . '|' . strtolower((string) $r['pattern'])] = true;
    }

    $drafts = [];
    foreach ($clusters as $accountCode => $descMap) {
        if (count($descMap) < $minOccurrences) continue;

        // Tokenize each description: alphanumeric tokens of ≥ 4 chars, lowercased
        $tokenCounts = [];
        foreach (array_keys($descMap) as $desc) {
            $tokens = bankRecExtractTokens($desc);
            // count each token once per description (so 'AWS AWS AWS' in one
            // description does NOT inflate the count)
            foreach (array_unique($tokens) as $tok) {
                $tokenCounts[$tok] = ($tokenCounts[$tok] ?? 0) + 1;
            }
        }
        // Pick the highest-occurrence token that beats the threshold and
        // isn't already a rule pattern for this account.
        arsort($tokenCounts);
        foreach ($tokenCounts as $tok => $count) {
            if ($count < $minOccurrences) break;
            $key = strtolower($accountCode) . '|' . $tok;
            if (isset($existingSet[$key])) continue;

            // Direction heuristic: if every line is debit, lock to debit
            $allDebit = true; $allCredit = true;
            foreach ($descMap as $amt) {
                if ($amt > 0) $allDebit  = false;
                if ($amt < 0) $allCredit = false;
            }
            $direction = $allDebit ? 'debit' : ($allCredit ? 'credit' : 'any');

            $insertId = scopedInsert('accounting_bank_rules', [
                'name'                => 'Auto-learned: ' . strtoupper($tok) . ' → ' . $accountCode,
                'pattern_kind'        => 'contains',
                'pattern'             => $tok,
                'direction'           => $direction,
                'target_account_code' => $accountCode,
                'is_approved'         => 0,
                'created_via'         => 'ai_learned',
                'created_by_user_id'  => $userId,
            ]);
            $drafts[] = [
                'id'                  => $insertId,
                'name'                => 'Auto-learned: ' . strtoupper($tok) . ' → ' . $accountCode,
                'pattern'             => $tok,
                'target_account_code' => $accountCode,
                'direction'           => $direction,
                'occurrences'         => $count,
            ];
            $existingSet[$key] = true;
            break;  // one rule per cluster per learner run
        }
    }

    return [
        'drafted'            => count($drafts),
        'drafts'             => $drafts,
        'clusters_evaluated' => count($clusters),
    ];
}

/**
 * Extract candidate rule tokens from a bank-line description.
 * Pure function — exposed so unit tests can hit it directly.
 *
 *   - lowercased
 *   - alphanumeric tokens only (split on non-[a-z0-9])
 *   - ≥ 4 chars
 *   - drops generic stop-tokens that show up everywhere (date / amount /
 *     boilerplate ACH labels)
 *
 * @return array<int, string>
 */
function bankRecExtractTokens(string $desc): array
{
    $low = strtolower($desc);
    $raw = preg_split('/[^a-z0-9]+/', $low) ?: [];
    $stop = [
        'ach','debit','credit','payment','xfer','transfer','online','mobile','from',
        'amount','txn','transaction','reference','ref','memo','date','posted','pending',
        'inc','llc','corp','ltd','co',
    ];
    $out = [];
    foreach ($raw as $t) {
        if (strlen($t) < 4) continue;
        if (preg_match('/^\d+$/', $t)) continue;       // pure numeric (txn IDs)
        if (in_array($t, $stop, true)) continue;
        $out[] = $t;
    }
    return $out;
}
