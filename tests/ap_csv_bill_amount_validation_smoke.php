<?php
/** Pure AP CSV validation; invoice CSV keeps its own line rules. */
declare(strict_types=1);

require_once __DIR__ . '/../core/accounting/csv_document_entity.php';
require_once __DIR__ . '/../modules/ap/lib/ap.php';

$checks = [];
$check = static function (string $name, bool $ok) use (&$checks): void {
    $checks[$name] = $ok;
    echo ($ok ? 'OK  ' : 'FAIL  ') . $name . PHP_EOL;
};
$rejects = static function (array $row, string $fragment): bool {
    try {
        apValidateImportedBillLineAmounts($row, accountingCsvDocumentLineAmounts($row));
        return false;
    } catch (InvalidArgumentException $e) {
        return str_contains($e->getMessage(), $fragment);
    }
};
$row = ['line_quantity' => '2', 'line_unit_price' => '12.50',
    'line_subtotal' => '25.00', 'line_tax_amount' => '2.00', 'line_total' => '27.00'];
$valid = accountingCsvDocumentLineAmounts($row);
apValidateImportedBillLineAmounts($row, $valid);
$check('positive taxable bill line is accepted', $valid['total'] === 27.0);
$check('zero quantity is rejected', $rejects(array_replace($row,
    ['line_quantity' => '0', 'line_subtotal' => '0', 'line_tax_amount' => '0', 'line_total' => '0']), 'quantity'));
$check('zero unit price is rejected', $rejects(array_replace($row,
    ['line_unit_price' => '0', 'line_subtotal' => '0', 'line_tax_amount' => '0', 'line_total' => '0']), 'unit price'));
$check('negative amount cannot become a payable discount', $rejects(array_replace($row,
    ['line_unit_price' => '-12.50', 'line_subtotal' => '-25', 'line_tax_amount' => '0', 'line_total' => '-25']), 'unit price'));
$check('subtotal must match quantity and price', $rejects(array_replace($row,
    ['line_subtotal' => '26', 'line_total' => '28']), 'subtotal must equal'));
$check('negative tax is rejected', $rejects(array_replace($row,
    ['line_tax_amount' => '-1', 'line_total' => '24']), 'tax cannot be negative'));
$check('amount-only CSV line needs an explicit unit price', $rejects(array_replace($row,
    ['line_unit_price' => '']), 'unit price'));
$check('CSV quantity beyond stored precision is rejected', $rejects(array_replace($row,
    ['line_quantity' => '2.00001']), 'four decimals'));
$check('CSV price beyond stored precision is rejected', $rejects(array_replace($row,
    ['line_unit_price' => '12.50001']), 'four decimals'));

$entities = ['MAIN' => ['id' => 7, 'code' => 'MAIN', 'base_currency' => 'USD']];
$review = accountingCsvReviewDocumentGroups([
    'rows' => [
        2 => $row + ['bill_number' => 'B-TEST', 'vendor_name' => 'Invented Vendor',
            'bill_date' => '2026-10-08', 'due_date' => '2026-11-07'],
        3 => array_replace($row, ['line_unit_price' => '0']) + ['bill_number' => 'B-TEST'],
    ], 'errors' => [],
], $entities, 'bill_number', 'bill', ['vendor_name', 'bill_date', 'due_date'],
    'apValidateImportedBillLineAmounts');
$check('AP review reports the invalid continuation line without dropping its bill',
    $review['result']['groups'] === 1
    && isset($review['result']['errors'][3])
    && !isset($review['result']['errors'][2])
    && count($review['groups']['B-TEST']) === 2);
$check('CSV importer wires AP-only rule into preview and commit',
    substr_count((string) file_get_contents(__DIR__ . '/../modules/ap/api/bills_csv_import.php'),
        "'apValidateImportedBillLineAmounts'") === 2);

$failed = count(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo $failed ? "Failed: {$failed}\n" : 'Passed: ' . count($checks) . "\n";
exit($failed ? 1 : 0);
