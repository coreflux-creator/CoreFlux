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

function bankRecMatchLine(int $tenantId, int $lineId, int $jeId, ?int $userId): array
{
    $line = scopedFind('SELECT id FROM accounting_bank_statement_lines WHERE tenant_id = :tenant_id AND id = :id', ['id' => $lineId]);
    if (!$line) throw new RuntimeException('Line not found');
    $je   = scopedFind('SELECT id FROM accounting_journal_entries WHERE tenant_id = :tenant_id AND id = :id', ['id' => $jeId]);
    if (!$je) throw new RuntimeException('JE not found');
    scopedUpdate('accounting_bank_statement_lines', $lineId, [
        'match_status'      => 'matched',
        'matched_je_id'     => $jeId,
        'matched_at'        => date('Y-m-d H:i:s'),
        'matched_by_user_id' => $userId,
    ]);
    return ['ok' => true, 'line_id' => $lineId, 'je_id' => $jeId];
}

function bankRecUnmatchLine(int $tenantId, int $lineId): array
{
    $line = scopedFind('SELECT id FROM accounting_bank_statement_lines WHERE tenant_id = :tenant_id AND id = :id', ['id' => $lineId]);
    if (!$line) throw new RuntimeException('Line not found');
    scopedUpdate('accounting_bank_statement_lines', $lineId, [
        'match_status'      => 'unmatched',
        'matched_je_id'     => null,
        'matched_at'        => null,
        'matched_by_user_id' => null,
    ]);
    return ['ok' => true, 'line_id' => $lineId];
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
    // Bank-line amount: + = credit (deposit) → matches a JE with debit on bank GL account.
    // We just match absolute amount on either side of any JE line for simplicity.
    return scopedQuery(
        'SELECT je.id AS je_id, je.je_number, je.posting_date, je.memo,
                je.source_module, je.source_ref_id,
                l.debit, l.credit, l.description AS line_desc, a.code AS account_code
         FROM accounting_journal_entry_lines l
         JOIN accounting_journal_entries je ON je.id = l.je_id AND je.tenant_id = l.tenant_id
         JOIN accounting_accounts a ON a.id = l.account_id AND a.tenant_id = l.tenant_id
         WHERE l.tenant_id = :tenant_id
           AND je.status = "posted"
           AND ABS(l.debit - l.credit) = :abs_amt
           AND je.posting_date BETWEEN DATE_SUB(:d, INTERVAL 3 DAY) AND DATE_ADD(:d, INTERVAL 3 DAY)
           AND je.id NOT IN (
               SELECT matched_je_id FROM accounting_bank_statement_lines
               WHERE tenant_id = :tenant_id AND matched_je_id IS NOT NULL
           )
         ORDER BY je.posting_date DESC LIMIT 20',
        ['abs_amt' => abs($amount), 'd' => $bankLine['posted_date']]
    );
}
