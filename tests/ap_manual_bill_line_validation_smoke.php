<?php
/** Pure manual AP intake checks; no database or financial records. */
declare(strict_types=1);

require_once __DIR__ . '/../modules/ap/lib/bill_drafts.php';

$checks = [];
$check = static function (string $name, bool $ok) use (&$checks): void {
    $checks[$name] = $ok;
    echo ($ok ? 'OK  ' : 'FAIL  ') . $name . PHP_EOL;
};
$rejects = static function (array $lines, string $message): bool {
    try {
        apValidateManualBillLines($lines, 0);
        return false;
    } catch (InvalidArgumentException $e) {
        return str_contains($e->getMessage(), $message);
    }
};
$line = ['item_type' => 'expense', 'description' => 'Invented service',
    'quantity' => '2', 'unit_price' => '12.50'];
$valid = apValidateManualBillLines([$line], 8);
$check('positive line computes the approvable bill amount',
    abs($valid['subtotal'] - 25.0) < 0.001 && abs($valid['tax_total'] - 2.0) < 0.001
    && abs($valid['total'] - 27.0) < 0.001);
$check('zero quantity is refused at intake', $rejects([array_replace($line, ['quantity' => '0'])], 'quantity'));
$check('zero price is refused at intake', $rejects([array_replace($line, ['unit_price' => '0'])], 'unit_price'));
$check('negative quantity is refused at intake', $rejects([array_replace($line, ['quantity' => '-1'])], 'quantity'));
$check('negative price is refused at intake', $rejects([array_replace($line, ['unit_price' => '-1'])], 'unit_price'));
$check('nonnumeric quantity is refused at intake', $rejects([array_replace($line, ['quantity' => 'abc'])], 'quantity'));
$check('sub-cent rounded zero is refused at intake',
    $rejects([array_replace($line, ['quantity' => '0.0001', 'unit_price' => '0.0001'])], 'total'));
$check('misleading positive discount line is refused',
    $rejects([array_replace($line, ['item_type' => 'discount'])], 'discounts are not supported'));
$check('one invalid line rejects an otherwise valid bill',
    $rejects([$line, array_replace($line, ['unit_price' => '0'])], 'Bill line 2'));

$failed = count(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo $failed ? "Failed: {$failed}\n" : 'Passed: ' . count($checks) . "\n";
exit($failed ? 1 : 0);
