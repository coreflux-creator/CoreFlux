<?php
/** Pure direct-invoice validation shared by CoreFlux and CoreOne. */
declare(strict_types=1);

require_once __DIR__ . '/../modules/billing/lib/invoice_drafts.php';

$checks = [];
$check = static function (string $name, bool $ok) use (&$checks): void {
    $checks[$name] = $ok;
    echo ($ok ? 'OK  ' : 'FAIL  ') . $name . PHP_EOL;
};
$rejects = static function (array $lines, string $message): bool {
    try {
        billingValidateDirectInvoiceLines($lines);
        return false;
    } catch (InvalidArgumentException $e) {
        return str_contains($e->getMessage(), $message);
    }
};
$service = ['item_type' => 'fixed_fee', 'description' => 'Setup', 'quantity' => '2',
    'unit' => 'each', 'unit_price' => '12.50', 'taxable' => true];
$discount = ['item_type' => 'discount', 'description' => 'Courtesy discount', 'quantity' => '1',
    'unit' => 'each', 'unit_price' => '-2.00'];
$prepared = billingValidateDirectInvoiceLines([$service, $discount]);
$amounts = billingComputeTax($prepared, 10.0);
$check('service and untaxed discount produce expected totals',
    $amounts['subtotal'] === 23.0 && $amounts['tax_total'] === 2.5
    && $amounts['total'] === 25.5 && $amounts['lines'][1]['tax_rate_pct'] === 0.0);
$check('CoreOne line without item type resolves to other',
    billingValidateDirectInvoiceLines([array_diff_key($service, ['item_type' => true])])[0]['item_type'] === 'other');
$check('negative line without type infers untaxed discount',
    billingValidateDirectInvoiceLines([array_diff_key($discount, ['item_type' => true])])[0]['item_type'] === 'discount');
$check('zero priced informational line is allowed beside a charged line',
    count(billingValidateDirectInvoiceLines([$service, array_replace($service, ['unit_price' => '0'])])) === 2);
$check('negative service is rejected', $rejects([array_replace($service, ['unit_price' => '-1'])], 'must be a discount'));
$check('positive discount is rejected', $rejects([array_replace($discount, ['unit_price' => '1'])], 'negative amount'));
$check('taxable discount is rejected', $rejects([array_replace($discount, ['taxable' => true])], 'cannot carry tax'));
$check('empty description is rejected', $rejects([array_replace($service, ['description' => ''])], 'description'));
$check('zero quantity is rejected', $rejects([array_replace($service, ['quantity' => '0'])], 'quantity'));
$check('missing price is rejected', $rejects([array_replace($service, ['unit_price' => ''])], 'unit price'));
$check('price beyond stored precision is rejected', $rejects([array_replace($service, ['unit_price' => '1.00001'])], 'four decimals'));
$check('unknown item type is rejected', $rejects([array_replace($service, ['item_type' => 'otherthing'])], 'not supported'));
$check('object-shaped invoice lines fail with a validation message',
    $rejects(['line-one' => $service], 'ordered list'));
$check('ambiguous taxable string is rejected', $rejects([array_replace($service, ['taxable' => 'false'])], 'true or false'));
$totalRejects = static function (array $computed): bool {
    try {
        billingValidateDirectInvoiceTotal($computed);
        return false;
    } catch (InvalidArgumentException $e) {
        return str_contains($e->getMessage(), 'Invoice total must be positive');
    }
};
$check('discount-only invoice is rejected', $totalRejects(billingComputeTax(
    billingValidateDirectInvoiceLines([$discount]), 10.0)));
$check('zero-total invoice is rejected', $totalRejects(billingComputeTax(
    billingValidateDirectInvoiceLines([array_replace($service, ['unit_price' => '0'])]), 10.0)));
billingValidateDirectInvoiceTotal($amounts);
$check('positive mixed invoice is accepted', true);
$serviceCode = (string) file_get_contents(__DIR__ . '/../modules/billing/lib/invoice_drafts.php');
$apiCode = (string) file_get_contents(__DIR__ . '/../modules/billing/api/invoices.php');
$formCode = (string) file_get_contents(__DIR__ . '/../modules/billing/ui/InvoiceCreate.jsx');
$check('create and edit enforce the same positive document total',
    str_contains($serviceCode, 'billingValidateDirectInvoiceTotal($computed);')
    && str_contains($apiCode, 'billingValidateDirectInvoiceTotal($computed);'));
$check('form refuses partially filled rows instead of dropping them',
    str_contains($formCode, 'activeLines.forEach((line, index) => {')
    && str_contains($formCode, 'lines: activeLines.map((l) => ({')
    && str_contains($formCode, "line.item_type !== 'other'")
    && str_contains($formCode, 'enter a unit price')
    && !str_contains($formCode, '.filter((l) => l.description &&'));

$failed = count(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo $failed ? "Failed: {$failed}\n" : 'Passed: ' . count($checks) . "\n";
exit($failed ? 1 : 0);
