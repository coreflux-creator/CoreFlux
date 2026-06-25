<?php
/**
 * treasury_csv_import_smoke.php
 *
 * End-to-end smoke for the Treasury bank statement CSV importer.
 *
 * Runs the importer in-process against an SQLite in-memory database
 * to verify:
 *   1. Header alias resolution (Date / Posting Date / Description /
 *      Amount / Debit / Credit / Reference).
 *   2. Date normalisation (2026-02-15, 02/15/2026, 15-Feb-2026).
 *   3. Amount parsing ($1,234.56 / (1234.56) / signed amounts /
 *      Debit+Credit derivation).
 *   4. INSERT IGNORE de-dup via synthesised fitid — re-uploading
 *      the same CSV is a no-op.
 *   5. Foreign-key gate — wrong bank_account_id is rejected.
 *   6. Empty-row + missing-amount rows skipped, not fatal.
 *
 * Run:  php -d zend.assertions=1 tests/treasury_csv_import_smoke.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/modules/treasury/lib/csv_import.php';

$pass = 0; $fail = 0; $failures = [];
$a = function (string $label, bool $cond) use (&$pass, &$fail, &$failures) {
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else       { $fail++; $failures[] = $label; echo "  ✗ $label\n"; }
};

echo "Treasury CSV importer smoke\n";
echo "===========================\n";

// 1) Pure helpers --------------------------------------------------------
echo "\n1. helpers — findColumn, parseAmount, normaliseDate\n";
$h = ['Posting Date', 'Description', 'Debit', 'Credit', 'Check Number'];
$a('findColumn matches by tolerant normalisation',
    treasuryCsvFindColumn($h, ['posted_date', 'date', 'posting date']) === 0
    && treasuryCsvFindColumn($h, ['description']) === 1
    && treasuryCsvFindColumn($h, ['debit']) === 2
    && treasuryCsvFindColumn($h, ['check_no', 'check number']) === 4);
$a('findColumn returns null for missing alias',
    treasuryCsvFindColumn($h, ['nonexistent']) === null);
$a('parseAmount handles plain decimals',
    treasuryCsvParseAmount('1234.56') === 1234.56);
$a('parseAmount strips currency symbols + commas',
    treasuryCsvParseAmount('$1,234.56') === 1234.56);
$a('parseAmount handles parentheses-negative',
    treasuryCsvParseAmount('(1,234.56)') === -1234.56);
$a('parseAmount returns null on garbage',
    treasuryCsvParseAmount('') === null
    && treasuryCsvParseAmount('abc') === null
    && treasuryCsvParseAmount(null) === null);
$a('normaliseDate accepts ISO',
    treasuryCsvNormaliseDate('2026-02-15') === '2026-02-15');
$a('normaliseDate accepts US slash',
    treasuryCsvNormaliseDate('02/15/2026') === '2026-02-15');
$a('normaliseDate accepts dd-Mmm-yyyy',
    treasuryCsvNormaliseDate('15-Feb-2026') === '2026-02-15');
$a('normaliseDate rejects garbage',
    treasuryCsvNormaliseDate('') === null
    && treasuryCsvNormaliseDate('not a date') === null);

// 2) End-to-end against SQLite in-memory --------------------------------
echo "\n2. end-to-end import against SQLite in-memory\n";
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "  SKIP pdo_sqlite is not installed in this PHP runtime\n";
    goto endpoint_checks;
}
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(
    'CREATE TABLE accounting_bank_accounts (
        id INTEGER PRIMARY KEY,
        tenant_id INTEGER NOT NULL,
        name TEXT NOT NULL
    )'
);
$pdo->exec(
    'CREATE TABLE accounting_bank_statement_lines (
        id INTEGER PRIMARY KEY,
        tenant_id INTEGER NOT NULL,
        bank_account_id INTEGER NOT NULL,
        import_id INTEGER NULL,
        posted_date TEXT NOT NULL,
        description TEXT NULL,
        amount NUMERIC NOT NULL,
        bank_reference TEXT NULL,
        external_id TEXT NULL,
        source_system TEXT NOT NULL DEFAULT "manual",
        fitid TEXT NULL,
        match_status TEXT NOT NULL DEFAULT "unmatched",
        created_at TEXT,
        UNIQUE (tenant_id, bank_account_id, fitid)
    )'
);
$pdo->exec(
    "INSERT INTO accounting_bank_accounts (id, tenant_id, name)
     VALUES (101, 7, 'Operating - Chase')"
);

// Build a synthetic bank statement CSV with mixed formats.
$tmp = tempnam(sys_get_temp_dir(), 'cf_treas_csv_');
file_put_contents($tmp,
    "\xEF\xBB\xBFPosting Date,Description,Amount,Reference\n" .
    "2026-02-01,Opening deposit,5000.00,WIRE-001\n" .
    "2026-02-02,\"AWS, Inc.\",-89.50,CARD-0042\n" .
    "02/03/2026,Payroll funding,(2400.50),ACH-001\n" .
    "2026-02-04,,200.00,NO-DESC\n" .            // missing description → skip
    "not a date,Bogus row,100.00,X\n" .         // bad date → skip
    "2026-02-05,Refund,abc,REF-A\n" .            // bad amount → skip
    "\n" .                                       // empty row → skip
    "2026-02-06,Marketing ad spend,-150.00,CARD-0043\n"
);

$res = treasuryImportBankCsv($pdo, 7, 101, $tmp);
$a('rows_seen counts every non-empty data row', $res['rows_seen'] >= 7);
$a('rows_inserted matches the 4 well-formed rows', $res['rows_inserted'] === 4);
$a('rows_skipped accounts for malformed rows', $res['rows_skipped'] >= 3);
$a('errors[] populated for skipped rows', count($res['errors']) >= 3);
$a('date_range spans 2026-02-01 → 2026-02-06',
    $res['date_range'][0] === '2026-02-01' && $res['date_range'][1] === '2026-02-06');

// Verify the parenthesised "(2400.50)" was stored as -2400.50.
$st = $pdo->query("SELECT amount FROM accounting_bank_statement_lines WHERE description='Payroll funding'");
$amt = (float) $st->fetchColumn();
$a('parenthesised debit stored as negative', abs($amt - -2400.50) < 0.001);

// Verify the Amount column wasn't sign-flipped — money in stays positive.
$st = $pdo->query("SELECT amount FROM accounting_bank_statement_lines WHERE description='Opening deposit'");
$amt = (float) $st->fetchColumn();
$a('positive Amount preserved', abs($amt - 5000.00) < 0.001);

// 3) De-dup — re-import same CSV.
echo "\n3. INSERT IGNORE de-dup on synthesised fitid\n";
$res2 = treasuryImportBankCsv($pdo, 7, 101, $tmp);
$a('second run sees the same rows', $res2['rows_seen'] === $res['rows_seen']);
$a('second run inserts zero new rows', $res2['rows_inserted'] === 0);
$a('second run rows_duplicate equals first-run rows_inserted', $res2['rows_duplicate'] === 4);

// 4) Foreign-key gate.
echo "\n4. bank_account_id gating\n";
$res3 = treasuryImportBankCsv($pdo, 7, 999, $tmp);
$a('wrong bank_account_id returns errors[] and no inserts',
    $res3['rows_inserted'] === 0 && !empty($res3['errors']));
$res4 = treasuryImportBankCsv($pdo, 99, 101, $tmp);
$a('wrong tenant_id returns errors[] and no inserts',
    $res4['rows_inserted'] === 0 && !empty($res4['errors']));

// 5) Debit/Credit derivation (no signed Amount column).
echo "\n5. Debit + Credit pair derivation\n";
$tmp2 = tempnam(sys_get_temp_dir(), 'cf_treas_csv2_');
file_put_contents($tmp2,
    "Date,Memo,Debit,Credit\n" .
    "2026-02-10,Withdrawal,250.00,\n" .
    "2026-02-11,Deposit,,1000.00\n"
);
$res5 = treasuryImportBankCsv($pdo, 7, 101, $tmp2);
$a('debit+credit pair → 2 rows inserted', $res5['rows_inserted'] === 2);
$st = $pdo->query("SELECT amount FROM accounting_bank_statement_lines WHERE description='Withdrawal'");
$a('Debit-only row stored as negative', (float) $st->fetchColumn() === -250.0);
$st = $pdo->query("SELECT amount FROM accounting_bank_statement_lines WHERE description='Deposit'");
$a('Credit-only row stored as positive', (float) $st->fetchColumn() === 1000.0);

unlink($tmp); unlink($tmp2);

endpoint_checks:
// 6) Endpoint structural checks.
echo "\n6. /api/treasury/import_csv.php endpoint\n";
$ep = dirname(__DIR__) . '/modules/treasury/api/import_csv.php';
$a('endpoint exists', file_exists($ep));
$src = (string) file_get_contents($ep);
$a('endpoint RBAC-gated by accounting.bank.manage',
    str_contains($src, "rbac_legacy_require(\$ctx['user'], 'accounting.bank.manage')"));
$a('endpoint enforces POST', str_contains($src, "if (api_method() !== 'POST')"));
$a('endpoint requires bank_account_id > 0',
    str_contains($src, "if (\$bankAccountId <= 0)"));
$a('endpoint enforces 25MB upload cap',
    str_contains($src, '25 * 1024 * 1024'));
$a('endpoint invokes treasuryImportBankCsv',
    str_contains($src, 'treasuryImportBankCsv($pdo, $tid, $bankAccountId, $tmp)'));

echo "\n===========================\n";
echo "Treasury CSV importer smoke: $pass ✓ / $fail ✗\n";
echo "===========================\n";
if ($fail > 0) {
    foreach ($failures as $msg) echo " ! $msg\n";
    exit(1);
}
exit(0);
