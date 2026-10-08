<?php
/** Pure invoice CSV arithmetic checks; discounts remain valid when net is positive. */
declare(strict_types=1);

require_once __DIR__ . '/../core/accounting/csv_document_entity.php';
require_once __DIR__ . '/../modules/billing/lib/billing.php';

$checks = [];
$check = static function (string $name, bool $ok) use (&$checks): void {
    $checks[$name] = $ok;
    echo ($ok ? 'OK  ' : 'FAIL  ') . $name . PHP_EOL;
};
$rejects = static function (array $row, string $fragment): bool {
    try {
        billingValidateImportedInvoiceLineAmounts($row, accountingCsvDocumentLineAmounts($row));
        return false;
    } catch (InvalidArgumentException $e) {
        return str_contains($e->getMessage(), $fragment);
    }
};
$service = ['line_quantity' => '2', 'line_unit_price' => '12.50',
    'line_subtotal' => '25', 'line_tax_amount' => '2', 'line_total' => '27'];
$discount = ['line_quantity' => '1', 'line_unit_price' => '-2',
    'line_subtotal' => '-2', 'line_tax_amount' => '0', 'line_total' => '-2'];
billingValidateImportedInvoiceLineAmounts($service, accountingCsvDocumentLineAmounts($service));
billingValidateImportedInvoiceLineAmounts($discount, accountingCsvDocumentLineAmounts($discount));
$check('positive service and untaxed discount lines are accepted', true);
$check('subtotal mismatch is rejected', $rejects(array_replace($service,
    ['line_subtotal' => '26', 'line_total' => '28']), 'subtotal must equal'));
$check('missing unit price is rejected', $rejects(array_replace($service,
    ['line_unit_price' => '']), 'unit price'));
$check('price beyond stored precision is rejected', $rejects(array_replace($service,
    ['line_unit_price' => '12.50001']), 'four decimals'));
$check('explicit subtotal beyond cents is rejected', $rejects(array_replace($service,
    ['line_subtotal' => '25.001']), 'line subtotal must use at most two decimals'));
$check('explicit tax beyond cents is rejected', $rejects(array_replace($service,
    ['line_tax_amount' => '2.001']), 'line tax amount must use at most two decimals'));
$check('explicit total beyond cents is rejected', $rejects(array_replace($service,
    ['line_total' => '27.001']), 'line total must use at most two decimals'));
$check('negative sales tax is rejected', $rejects(array_replace($service,
    ['line_tax_amount' => '-1', 'line_total' => '24']), 'tax cannot be negative'));
$check('discount with positive tax is rejected', $rejects(array_replace($discount,
    ['line_tax_amount' => '1', 'line_total' => '-1']), 'discounts cannot carry tax'));

$entities = ['MAIN' => ['id' => 7, 'code' => 'MAIN', 'base_currency' => 'USD']];
$header = ['invoice_number' => 'INV-TEST', 'client_name' => 'Invented Client',
    'issue_date' => '2026-10-08', 'due_date' => '2026-11-07'];
$review = accountingCsvReviewDocumentGroups([
    'rows' => [2 => $service + $header, 3 => $discount + ['invoice_number' => 'INV-TEST']],
    'errors' => [],
], $entities, 'invoice_number', 'invoice', ['client_name', 'issue_date', 'due_date'],
    'billingValidateImportedInvoiceLineAmounts', 'billingValidateImportedInvoiceGroupAmounts');
$check('net-positive invoice with a discount passes document review',
    $review['result']['error_count'] === 0 && $review['result']['groups'] === 1);
$badGroup = accountingCsvReviewDocumentGroups([
    'rows' => [2 => $discount + $header], 'errors' => [],
], $entities, 'invoice_number', 'invoice', ['client_name', 'issue_date', 'due_date'],
    'billingValidateImportedInvoiceLineAmounts', 'billingValidateImportedInvoiceGroupAmounts');
$check('discount-only invoice is rejected as a whole',
    $badGroup['result']['error_count'] === 1
    && str_contains(implode(' ', $badGroup['result']['errors'][2] ?? []), 'Invoice total must be positive'));
$check('invoice CSV importer applies both guards in preview and commit',
    substr_count((string) file_get_contents(__DIR__ . '/../modules/billing/api/csv_import.php'),
        "'billingValidateImportedInvoiceLineAmounts', 'billingValidateImportedInvoiceGroupAmounts'") === 2);

$failed = count(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo $failed ? "Failed: {$failed}\n" : 'Passed: ' . count($checks) . "\n";
exit($failed ? 1 : 0);
