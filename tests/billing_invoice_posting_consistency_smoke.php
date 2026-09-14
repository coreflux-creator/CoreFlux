<?php
declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
$failures = [];
$ok = static function (string $label, bool $passed) use (&$failures): void {
    echo ($passed ? 'OK  ' : 'BAD ') . $label . PHP_EOL;
    if (!$passed) $failures[] = $label;
};

foreach ([
    'modules/billing/lib/billing.php',
    'modules/billing/api/invoices.php',
    'core/seeds/event_registry_seed.php',
] as $file) {
    $output = [];
    $code = 0;
    exec('php -l ' . escapeshellarg("{$root}/{$file}") . ' 2>&1', $output, $code);
    $ok("PHP lint {$file}", $code === 0);
}

require_once "{$root}/modules/billing/lib/billing.php";

$generated = billingNormalizeInvoiceLineAmounts([
    'source_type' => 'time',
    'quantity' => 48,
    'unit_price' => 3.0035,
    'subtotal' => 144.00,
    'tax_rate_pct' => 0,
    'tax_amount' => 0,
    'total' => 144.17,
]);
$ok('generated line uses full-precision stored rate', $generated === [
    'subtotal' => 144.17,
    'tax_amount' => 0.0,
    'total' => 144.17,
]);

$manual = billingNormalizeInvoiceLineAmounts([
    'source_type' => 'manual',
    'quantity' => 0,
    'unit_price' => 0,
    'subtotal' => 100.00,
    'tax_rate_pct' => 0,
    'tax_amount' => 8.00,
    'total' => 107.00,
]);
$ok('manual line preserves entered subtotal and tax', $manual === [
    'subtotal' => 100.0,
    'tax_amount' => 8.0,
    'total' => 108.0,
]);

$api = (string) file_get_contents("{$root}/modules/billing/api/invoices.php");
$library = (string) file_get_contents("{$root}/modules/billing/lib/billing.php");
$ok('send normalizes invoice before delivery',
    substr_count($api, 'billingNormalizeStoredInvoiceAmounts($tid, $id)') >= 2);
$ok('normalization never rewrites a posted invoice',
    str_contains($library, "if (!empty(\$invoice['journal_entry_id']))"));
$ok('event payload satisfies canonical invoice contract',
    str_contains($api, "'total'          => (float) \$row['total']")
    && str_contains($api, "'due_date'       => (string) \$row['due_date']"));

$migration = (string) file_get_contents("{$root}/modules/billing/migrations/014_invoice_amount_consistency.sql");
$ok('migration repairs unposted legacy line totals',
    str_contains($migration, 'ROUND(line.quantity * line.unit_price, 2)')
    && str_contains($migration, "invoice.status IN ('draft', 'approved', 'sent')"));
$ok('migration rebuilds invoice headers from lines',
    str_contains($migration, 'ROUND(SUM(subtotal), 2) AS subtotal')
    && str_contains($migration, 'invoice.amount_due = ROUND(amounts.total - invoice.amount_paid, 2)'));

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Billing invoice posting consistency smoke passed.' . PHP_EOL;
