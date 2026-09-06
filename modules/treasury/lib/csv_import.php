<?php
/**
 * /app/modules/treasury/lib/csv_import.php
 *
 * Stream-parse a bank statement CSV and insert each row as a
 * `accounting_bank_statement_lines` entry under the chosen
 * `bank_account_id`. Mirrors what the Plaid sync produces so the
 * same matching/reconciliation flow works for manually-uploaded
 * statements from banks not on Plaid.
 *
 * CSV format (header row required):
 *   posted_date | description | amount [| bank_reference]
 *
 * Header-name detection is forgiving — common bank exports use
 * `Date / Posting Date / Transaction Date`, `Description / Memo /
 * Details`, `Amount / Debit / Credit`. Either:
 *   - A signed `Amount` column (+ = credit, - = debit), or
 *   - Separate `Debit` and `Credit` columns (we'll net them) is
 *   accepted.
 *
 * De-dup: synthesizes an `fitid` from sha1(posted_date|amount|
 * description|bank_reference). The table's UNIQUE KEY on
 * (tenant_id, bank_account_id, fitid) silently skips duplicates,
 * so re-uploading the same CSV is a no-op.
 *
 *   Returns:
 *   {
 *     rows_seen, rows_inserted, rows_duplicate, rows_skipped,
 *     date_range:[min, max], errors:[...],
 *   }
 */
declare(strict_types=1);

/**
 * Locate a column index by a list of accepted header aliases
 * (case-insensitive, whitespace/special-char tolerant).
 */
function treasuryCsvFindColumn(array $headers, array $aliases): ?int
{
    foreach ($headers as $i => $h) {
        $norm = strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $h));
        foreach ($aliases as $a) {
            $aNorm = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $a));
            if ($norm === $aNorm) return (int) $i;
        }
    }
    return null;
}

/**
 * Normalise a date cell to YYYY-MM-DD. Returns null on failure so
 * the row is skipped, not exception-propagated.
 */
function treasuryCsvNormaliseDate(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') return null;
    // Common formats: 2026-02-15 / 02/15/2026 / 15-Feb-2026
    $ts = strtotime($raw);
    if ($ts === false || $ts <= 0) return null;
    return date('Y-m-d', $ts);
}

/**
 * Strip currency symbols + thousands separators, parse decimal.
 * Returns null on parse failure.
 */
function treasuryCsvParseAmount(?string $raw): ?float
{
    if ($raw === null) return null;
    $raw = trim($raw);
    if ($raw === '') return null;
    // Parentheses denote negative ($1,234.56) → -1234.56
    $isNegParen = (strlen($raw) >= 2 && $raw[0] === '(' && substr($raw, -1) === ')');
    if ($isNegParen) $raw = substr($raw, 1, -1);
    $raw = preg_replace('/[\$£€,]/', '', $raw) ?? '';
    $raw = str_replace(' ', '', $raw);
    if ($raw === '' || $raw === '-') return null;
    if (!is_numeric($raw)) return null;
    $v = (float) $raw;
    return $isNegParen ? -abs($v) : $v;
}

/**
 * Keep the importer usable on hosts where mbstring is not installed.
 */
function treasuryCsvLimitText(string $value, int $length): string
{
    return function_exists('mb_substr')
        ? mb_substr($value, 0, $length)
        : substr($value, 0, $length);
}

/**
 * Normalise harmless bank-feed formatting differences for cross-source
 * duplicate detection. First Citizens, for example, sometimes emits two
 * spaces before an account suffix while Plaid emits one.
 */
function treasuryCsvNormaliseDescription(string $value): string
{
    return strtolower(trim((string) (preg_replace('/\s+/', ' ', $value) ?? $value)));
}

/**
 * Discover the live statement-line schema. Older production databases can
 * pre-date the optional source audit columns, so CSV import must not fail just
 * because a later migration has not landed yet.
 *
 * @return array<string,array<string,mixed>> keyed by lower-case column name
 */
function treasuryCsvStatementColumnInfo(PDO $pdo): array
{
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $info = [];

    if ($driver === 'sqlite') {
        foreach ($pdo->query('PRAGMA table_info(accounting_bank_statement_lines)')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = strtolower((string) ($row['name'] ?? ''));
            if ($name !== '') {
                $info[$name] = [
                    'type'     => (string) ($row['type'] ?? ''),
                    'nullable' => empty($row['notnull']),
                    'default'  => $row['dflt_value'] ?? null,
                ];
            }
        }
        return $info;
    }

    foreach ($pdo->query('SHOW COLUMNS FROM accounting_bank_statement_lines')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $name = strtolower((string) ($row['Field'] ?? ''));
        if ($name !== '') {
            $info[$name] = [
                'type'     => (string) ($row['Type'] ?? ''),
                'nullable' => strtoupper((string) ($row['Null'] ?? 'YES')) === 'YES',
                'default'  => $row['Default'] ?? null,
            ];
        }
    }
    return $info;
}

function treasuryImportBankCsv(
    PDO $pdo,
    int $tenantId,
    int $bankAccountId,
    string $filePath
): array {
    $summary = [
        'rows_seen'      => 0,
        'rows_inserted'  => 0,
        'rows_duplicate' => 0,
        'rows_skipped'   => 0,
        'date_range'     => [null, null],
        'errors'         => [],
    ];
    if ($tenantId <= 0 || $bankAccountId <= 0) {
        $summary['errors'][] = 'tenant_id + bank_account_id are required';
        return $summary;
    }
    if (!is_readable($filePath)) {
        $summary['errors'][] = 'csv file not readable';
        return $summary;
    }

    // Confirm the bank account belongs to this tenant — defensive,
    // since the endpoint also gates this but the lib should be safe
    // to call from any context.
    $chk = $pdo->prepare('SELECT id FROM accounting_bank_accounts WHERE id = :id AND tenant_id = :t LIMIT 1');
    $chk->execute(['id' => $bankAccountId, 't' => $tenantId]);
    if (!$chk->fetchColumn()) {
        $summary['errors'][] = "bank_account_id {$bankAccountId} not found for this tenant";
        return $summary;
    }

    try {
        $columnInfo = treasuryCsvStatementColumnInfo($pdo);
    } catch (\Throwable $e) {
        $summary['errors'][] = 'could not inspect the bank statement table: ' . $e->getMessage();
        return $summary;
    }
    $requiredColumns = ['tenant_id', 'bank_account_id', 'posted_date', 'description', 'amount', 'fitid'];
    $missingColumns = array_values(array_filter(
        $requiredColumns,
        static fn(string $column): bool => !isset($columnInfo[$column])
    ));
    if ($missingColumns) {
        $summary['errors'][] = 'bank statement table is missing required column(s): ' . implode(', ', $missingColumns);
        return $summary;
    }

    $h = fopen($filePath, 'rb');
    if (!$h) {
        $summary['errors'][] = 'fopen failed';
        return $summary;
    }
    $headers = fgetcsv($h, null, ',', '"', '');
    if (!is_array($headers) || empty($headers)) {
        fclose($h);
        $summary['errors'][] = 'no header row';
        return $summary;
    }
    // Strip BOM from first header cell.
    if (isset($headers[0]) && str_starts_with((string) $headers[0], "\xEF\xBB\xBF")) {
        $headers[0] = substr((string) $headers[0], 3);
    }

    $dateCol   = treasuryCsvFindColumn($headers, ['posted_date', 'posted date', 'post date', 'date', 'posting date', 'transaction date', 'txn date']);
    $descCol   = treasuryCsvFindColumn($headers, ['description', 'memo', 'details', 'narrative', 'name']);
    $amountCol = treasuryCsvFindColumn($headers, ['amount', 'transaction amount', 'value']);
    $debitCol  = treasuryCsvFindColumn($headers, ['debit', 'withdrawal', 'debit amount']);
    $creditCol = treasuryCsvFindColumn($headers, ['credit', 'deposit', 'credit amount']);
    $refCol    = treasuryCsvFindColumn($headers, ['bank_reference', 'reference', 'check number', 'check_no', 'fitid', 'transaction id']);
    // Audit / integration columns — when present, the bank's own
    // transaction id is stored as `external_id` so downstream reports
    // can correlate CoreFlux rows back to Mercury / Plaid / OFX
    // statements without re-hashing the description+amount+date tuple.
    // When external_id is provided we use it as the fitid base instead
    // of the auto-computed sha1, making re-imports of the same export
    // deterministically idempotent on the bank's own id.
    $extIdCol  = treasuryCsvFindColumn($headers, ['external_id', 'source_id', 'mercury_id', 'plaid_transaction_id', 'transaction_id']);
    $srcSysCol = treasuryCsvFindColumn($headers, ['source_system', 'source']);

    if ($dateCol === null) { fclose($h); $summary['errors'][] = 'no date column found'; return $summary; }
    if ($descCol === null) { fclose($h); $summary['errors'][] = 'no description column found'; return $summary; }
    if ($amountCol === null && ($debitCol === null && $creditCol === null)) {
        fclose($h);
        $summary['errors'][] = 'no amount column (or debit+credit pair) found';
        return $summary;
    }

    // Use a portable de-dup pattern that works on both MySQL (the
    // production driver) and SQLite (the test driver): check existence
    // first, INSERT only if absent. The (tenant_id, bank_account_id,
    // fitid) UNIQUE KEY on the production table still protects against
    // races; this code path just keeps the lib testable without MySQL.
    try {
        $check = $pdo->prepare(
            'SELECT 1 FROM accounting_bank_statement_lines
              WHERE tenant_id = :tid AND bank_account_id = :acc AND fitid = :fitid
              LIMIT 1'
        );
        $checkNatural = $pdo->prepare(
            'SELECT description FROM accounting_bank_statement_lines
              WHERE tenant_id = :tid AND bank_account_id = :acc
                AND posted_date = :dt AND amount = :amt'
        );

        $insertColumns = ['tenant_id', 'bank_account_id', 'posted_date', 'description', 'amount', 'fitid'];
        $insertValues  = [':tid', ':acc', ':dt', ':desc', ':amt', ':fitid'];
        foreach (['bank_reference' => ':ref', 'external_id' => ':ext', 'source_system' => ':src'] as $column => $placeholder) {
            if (isset($columnInfo[$column])) {
                $insertColumns[] = $column;
                $insertValues[]  = $placeholder;
            }
        }
        if (isset($columnInfo['match_status'])) {
            $insertColumns[] = 'match_status';
            $insertValues[]  = ':match_status';
        }
        if (isset($columnInfo['created_at'])) {
            $insertColumns[] = 'created_at';
            $insertValues[]  = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? "datetime('now')"
                : 'NOW()';
        }
        $ins = $pdo->prepare(
            'INSERT INTO accounting_bank_statement_lines (' . implode(', ', $insertColumns) . ') VALUES (' .
            implode(', ', $insertValues) . ')'
        );
    } catch (\Throwable $e) {
        fclose($h);
        $summary['errors'][] = 'could not prepare bank statement import: ' . $e->getMessage();
        return $summary;
    }

    $rowNum = 1;
    while (($row = fgetcsv($h, null, ',', '"', '')) !== false) {
        $rowNum++;
        $summary['rows_seen']++;
        if ($row === [null] || $row === false) { $summary['rows_skipped']++; continue; }

        $rawDate = (string) ($row[$dateCol] ?? '');
        $rawDesc = trim((string) ($row[$descCol] ?? ''));
        $date = treasuryCsvNormaliseDate($rawDate);
        if ($date === null) {
            $summary['rows_skipped']++;
            if (count($summary['errors']) < 20) {
                $summary['errors'][] = "row {$rowNum}: invalid date '{$rawDate}'";
            }
            continue;
        }
        if ($rawDesc === '') {
            $summary['rows_skipped']++;
            if (count($summary['errors']) < 20) {
                $summary['errors'][] = "row {$rowNum}: missing description";
            }
            continue;
        }

        // Amount resolution: prefer signed Amount; else compute from
        // (credit - debit). Either side may be blank or zero.
        $amount = null;
        if ($amountCol !== null) {
            $amount = treasuryCsvParseAmount((string) ($row[$amountCol] ?? ''));
        }
        if ($amount === null && ($debitCol !== null || $creditCol !== null)) {
            $debit  = $debitCol  !== null ? treasuryCsvParseAmount((string) ($row[$debitCol]  ?? '')) : null;
            $credit = $creditCol !== null ? treasuryCsvParseAmount((string) ($row[$creditCol] ?? '')) : null;
            if ($debit !== null || $credit !== null) {
                // Bank statements: credit = money in (positive), debit = money out (negative).
                $amount = ($credit ?? 0.0) - ($debit ?? 0.0);
            }
        }
        if ($amount === null) {
            $summary['rows_skipped']++;
            if (count($summary['errors']) < 20) {
                $summary['errors'][] = "row {$rowNum}: no parseable amount";
            }
            continue;
        }

        $ref = $refCol !== null ? trim((string) ($row[$refCol] ?? '')) : '';
        if ($ref === '') $ref = null;

        $extId = $extIdCol !== null ? trim((string) ($row[$extIdCol] ?? '')) : '';
        if ($extId === '') $extId = null;
        $srcSys = $srcSysCol !== null ? trim((string) ($row[$srcSysCol] ?? '')) : '';
        if ($srcSys === '' || !in_array($srcSys, ['manual','jobdiva','qbo','mercury','plaid','jaz','zoho','airtable','gusto','other'], true)) {
            $srcSys = 'manual';
        }

        // When the bank/feed provides its own transaction id, use it as
        // the fitid seed — re-imports of the same Mercury / Plaid
        // export are then deterministically idempotent on that id,
        // not on the (date + amount + desc + ref) hash which can shift
        // when descriptions get re-formatted upstream.
        if ($extId !== null) {
            $fitid = $srcSys . '_' . substr(sha1($extId), 0, 24);
        } else {
            $fitidSeed = $date . '|' . number_format($amount, 2, '.', '') . '|' . $rawDesc . '|' . ($ref ?? '');
            $fitid = 'csv_' . substr(sha1($fitidSeed), 0, 24);
        }

        try {
            $check->execute([
                'tid'   => $tenantId,
                'acc'   => $bankAccountId,
                'fitid' => $fitid,
            ]);
            if ($check->fetchColumn()) {
                $summary['rows_duplicate']++;
                continue;
            }
            // Plaid and a bank CSV identify the same transaction differently.
            // Compare the stable facts as a second de-dup key so importing
            // older history does not duplicate rows already supplied by Plaid.
            $checkNatural->execute([
                'tid' => $tenantId,
                'acc' => $bankAccountId,
                'dt'  => $date,
                'amt' => number_format($amount, 2, '.', ''),
            ]);
            $normalisedDescription = treasuryCsvNormaliseDescription($rawDesc);
            $matchesExisting = false;
            foreach ($checkNatural->fetchAll(PDO::FETCH_COLUMN) as $existingDescription) {
                if (treasuryCsvNormaliseDescription((string) $existingDescription) === $normalisedDescription) {
                    $matchesExisting = true;
                    break;
                }
            }
            if ($matchesExisting) {
                $summary['rows_duplicate']++;
                continue;
            }

            $insertParams = [
                'tid'   => $tenantId,
                'acc'   => $bankAccountId,
                'dt'    => $date,
                'desc'  => treasuryCsvLimitText($rawDesc, 255),
                'amt'   => $amount,
                'fitid' => $fitid,
            ];
            if (isset($columnInfo['bank_reference'])) {
                $insertParams['ref'] = $ref !== null ? treasuryCsvLimitText($ref, 120) : null;
            }
            if (isset($columnInfo['external_id'])) {
                $insertParams['ext'] = $extId !== null ? treasuryCsvLimitText($extId, 128) : null;
            }
            if (isset($columnInfo['source_system'])) {
                $insertParams['src'] = $srcSys;
            }
            if (isset($columnInfo['match_status'])) {
                $insertParams['match_status'] = 'unmatched';
            }
            $ins->execute($insertParams);
            $summary['rows_inserted']++;
            if ($summary['date_range'][0] === null || $date < $summary['date_range'][0]) $summary['date_range'][0] = $date;
            if ($summary['date_range'][1] === null || $date > $summary['date_range'][1]) $summary['date_range'][1] = $date;
        } catch (\Throwable $e) {
            $summary['rows_skipped']++;
            if (count($summary['errors']) < 20) {
                $summary['errors'][] = "row {$rowNum}: " . $e->getMessage();
            }
        }
    }
    fclose($h);
    return $summary;
}
