<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/modules/accounting/lib/bank_rec.php';

$passed = 0;
$failed = 0;
$check = static function (string $name, bool $result) use (&$passed, &$failed): void {
    echo ($result ? 'OK ' : 'FAIL ') . $name . PHP_EOL;
    $result ? $passed++ : $failed++;
};
$rejects = static function (string $csv, string $message, ?array $map = null): bool {
    try {
        bankRecParseCsvRows($csv, $map, 7);
        return false;
    } catch (RuntimeException $e) {
        return str_contains($e->getMessage(), $message);
    }
};

$csv = "\xEF\xBB\xBFDate,Description,Amount,Transaction ID\n"
    . "9/19/2026,Vendor payment,\"($1,234.50)\",TX-1\n"
    . "2026-09-20,Client receipt,125.00,TX-2\n";
$rows = bankRecParseCsvRows($csv, null, 7);
$check('BOM and US/ISO dates parse', count($rows) === 2 && $rows[0]['date'] === '2026-09-19'
    && $rows[1]['date'] === '2026-09-20');
$check('parenthesized amount stays negative', $rows[0]['amount'] === -1234.5);
$check('bank transaction IDs are retained', $rows[0]['fitid'] === 'TX-1');

$sameCsv = "date,description,amount\n2026-09-19,Monthly fee,-10\n2026-09-19,Monthly fee,-10\n";
$same = bankRecParseCsvRows($sameCsv, null, 7);
$check('identical real transactions get separate IDs', $same[0]['fitid'] !== $same[1]['fitid']);
$check('repeat upload gets the same generated IDs', $same === bankRecParseCsvRows($sameCsv, null, 7));
$check('blank rows do not create transactions', count(bankRecParseCsvRows($sameCsv . "\n\n", null, 7)) === 2);

$check('invalid date rejects the batch with record number', $rejects("date,description,amount\n2026-02-30,Fee,-10\n", 'record 2: invalid date'));
$check('missing amount rejects the batch', $rejects("date,description,amount\n2026-09-19,Fee,\n", 'record 2: invalid amount'));
$check('missing description rejects the batch', $rejects("date,description,amount\n2026-09-19,,-10\n", 'record 2: missing description'));
$check('zero amount rejects the batch', $rejects("date,description,amount\n2026-09-19,Fee,0\n", 'record 2: invalid amount'));
$check('more than two decimals rejects the batch', $rejects("date,description,amount\n2026-09-19,Fee,10.001\n", 'record 2: invalid amount'));
$check('invalid explicit mapping rejects the batch', $rejects("date,description,amount\n2026-09-19,Fee,-10\n", 'CSV needs date', ['date_col' => 'missing']));
$check('same column cannot represent date and amount', $rejects("date,description,amount\n2026-09-19,Fee,-10\n", 'different columns', ['amount_col' => 'date']));
$check('missing explicit transaction ID column is reported', $rejects("date,description,amount\n2026-09-19,Fee,-10\n", 'Transaction ID column', ['fitid_col' => 'reference']));
$check('transaction ID cannot reuse the amount column', $rejects("date,description,amount\n2026-09-19,Fee,-10\n", 'separate CSV column', ['fitid_col' => 'amount']));
$check('empty file is rejected', $rejects('', 'empty or unreadable'));

echo "Passed: {$passed}; Failed: {$failed}" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
