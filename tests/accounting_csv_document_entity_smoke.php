<?php
/** Pure legal-entity CSV mapping guard for source documents. */
declare(strict_types=1);

require_once __DIR__ . '/../core/accounting/csv_document_entity.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    $checks++;
};
$expectError = static function (callable $action, string $fragment) use ($assert): void {
    try {
        $action();
        throw new RuntimeException('Expected import validation error');
    } catch (InvalidArgumentException $error) {
        $assert(str_contains($error->getMessage(), $fragment), $fragment);
    }
};
$one = ['MAIN' => ['id' => 7, 'code' => 'MAIN', 'base_currency' => 'USD']];
$two = $one + ['WEST' => ['id' => 9, 'code' => 'WEST', 'base_currency' => 'USD']];
$assert(accountingCsvDocumentEntity($one, '', '')['id'] === 7, 'single entity defaults safely');
$assert(accountingCsvDocumentLineAmounts(['line_quantity' => '2', 'line_unit_price' => '3'])['total'] === 6.0,
    'blank totals are computed from quantity and price');
$expectError(static fn() => accountingCsvDocumentLineAmounts([
    'line_quantity' => '2', 'line_unit_price' => '3', 'line_total' => '7',
]), 'subtotal plus tax');
$expectError(static fn() => accountingCsvDocumentLineAmounts(['line_unit_price' => '1e999']), 'finite value');
$assert(accountingCsvDocumentEntity($two, 'west', 'USD')['id'] === 9, 'explicit code is case-insensitive');
$expectError(static fn() => accountingCsvDocumentEntity($two, '', 'USD'), 'required');
$expectError(static fn() => accountingCsvDocumentEntity($two, 'OTHER', 'USD'), 'active legal entity');
$expectError(static fn() => accountingCsvDocumentEntity($one, 'MAIN', 'EUR'), 'currency');
$expectError(static fn() => accountingCsvDocumentEntity([], '', 'USD'), 'Create an active');

$review = accountingCsvReviewDocumentGroups([
    'rows' => [
        2 => ['invoice_number' => 'INV-1', 'client_name' => 'Acme', 'issue_date' => '2026-10-01', 'due_date' => '2026-10-31', 'entity_code' => 'MAIN'],
        3 => ['invoice_number' => 'INV-1', 'line_description' => 'First service'],
        4 => ['invoice_number' => 'INV-2', 'client_name' => 'Beta', 'issue_date' => '2026-10-01', 'due_date' => '2026-10-31', 'entity_code' => 'WEST'],
        5 => ['invoice_number' => 'INV-2', 'line_description' => 'Second service', 'entity_code' => 'MAIN'],
    ],
    'errors' => [3 => ['line_quantity: not numeric']],
], $two, 'invoice_number', 'invoice', ['client_name', 'issue_date', 'due_date']);
$assert($review['result']['groups'] === 2, 'groups counted');
$assert($review['entities']['INV-1']['id'] === 7 && $review['entities']['INV-2']['id'] === 9, 'entity per document');
$assert($review['result']['rows'][3]['entity_code'] === 'MAIN', 'preview shows inherited entity on continuation row');
$assert($review['result']['rows'][2]['line_total'] === '0.00', 'preview shows computed line total');
$assert(isset($review['result']['errors'][3]) && isset($review['result']['errors'][5]), 'bad line and conflicting entity visible');
$assert($review['result']['error_count'] === 2, 'error count includes group checks');

$missing = accountingCsvReviewDocumentGroups([
    'rows' => [2 => ['bill_number' => 'B-1', 'entity_code' => 'MAIN']],
    'errors' => [],
], $one, 'bill_number', 'bill', ['vendor_name', 'bill_date', 'due_date']);
$assert(count($missing['result']['errors'][2]) === 3, 'missing bill header is rejected');
$unassigned = accountingCsvReviewDocumentGroups([
    'rows' => [
        2 => ['invoice_number' => 'INV-1', 'client_name' => 'Acme', 'issue_date' => '2026-10-01', 'due_date' => '2026-10-31'],
        3 => ['invoice_number' => '', 'line_description' => 'Orphaned service'],
    ],
    'errors' => [],
], $one, 'invoice_number', 'invoice', ['client_name', 'issue_date', 'due_date']);
$assert($unassigned['result']['groups'] === 1 && $unassigned['result']['error_count'] === 1,
    'unassigned line is not counted as a document');
$assert(isset($unassigned['result']['blocking_error']) && isset($unassigned['result']['errors'][3]),
    'unassigned line blocks partial import');
$empty = accountingCsvReviewDocumentGroups(['rows' => [], 'errors' => []], $one,
    'invoice_number', 'invoice', ['client_name', 'issue_date', 'due_date']);
$assert($empty['result']['groups'] === 0 && isset($empty['result']['blocking_error']),
    'header-only file cannot import an empty document');
echo "Accounting CSV document entity: {$checks} checks passed.\n";
