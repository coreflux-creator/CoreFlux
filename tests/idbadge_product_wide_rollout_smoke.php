<?php
/**
 * Smoke — IdBadge pattern extended product-wide.
 *
 * Locks in:
 *   1. Every CSV importer with an update-existing path accepts the
 *      table's own primary-key column as an integer (no misleading
 *      cross-table FK columns added — those tables don't have FKs).
 *   2. People CSV commit uses person_id-first lookup with hard-error
 *      on miss (no silent fall-through to email).
 *   3. All major list UIs (companies, AP vendors, AP bills, AP
 *      payments, billing invoices, billing payments, mercury
 *      recipients, mercury payments) render <IdBadge /> with the
 *      right prefix.
 *   4. Company detail header surfaces the C-{id} badge.
 *   5. Column headers + colSpan bumps stay consistent.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/CsvImportService.php';

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$importers = [
    'people'          => '/app/modules/people/api/csv_import.php',
    'ap_vendors'      => '/app/modules/ap/api/csv_import.php',
    'ap_bills'        => '/app/modules/ap/api/bills_csv_import.php',
    'ap_payments'     => '/app/modules/ap/api/payments_csv_import.php',
    'billing_invoices'=> '/app/modules/billing/api/csv_import.php',
    'billing_payments'=> '/app/modules/billing/api/payments_csv_import.php',
    'staffing_clients'=> '/app/modules/staffing/api/csv_import.php',
    'mercury_recipients' => '/app/api/mercury_recipients_csv_import.php',
];

$idColumns = [
    'people'             => 'person_id',
    'ap_vendors'         => 'vendor_id',
    'ap_bills'           => 'bill_id',
    'ap_payments'        => 'payment_id',
    'billing_invoices'   => 'invoice_id',
    'billing_payments'   => 'payment_id',
    'staffing_clients'   => 'client_id',
    'mercury_recipients' => 'recipient_id',
];

echo "\n1. CSV schemas — own-table PK column accepted as optional integer\n";
foreach ($importers as $name => $path) {
    $src = (string) file_get_contents($path);
    $col = $idColumns[$name];
    $a("{$name}.csv accepts '{$col}' as type=integer",
        preg_match(
            "/'{$col}'\\s*=>\\s*\\['label'[^]]*'type'\\s*=>\\s*'integer'[^]]*\\]/",
            $src
        ) === 1);
    // Cross-table FK columns SHOULD NOT have been added (those tables
    // don't have FK relationships — adding them would mislead operators).
    if ($name === 'ap_bills') {
        $a("ap_bills.csv does NOT have vendor_id column (vendor_name is free-text on that table)",
            !preg_match("/'vendor_id'\\s*=>/", $src));
    }
    if ($name === 'ap_payments') {
        $a("ap_payments.csv does NOT have vendor_id column",
            !preg_match("/'vendor_id'\\s*=>/", $src));
    }
    if ($name === 'billing_invoices') {
        $a("billing_invoices.csv does NOT have client_id column",
            !preg_match("/'client_id'\\s*=>/", $src));
    }
    if ($name === 'billing_payments') {
        $a("billing_payments.csv does NOT have client_id column",
            !preg_match("/'client_id'\\s*=>/", $src));
    }
}

echo "\n2. People CSV commit — person_id-first lookup with hard miss\n";
$peopleSrc = (string) file_get_contents($importers['people']);
$a('person_id read before falling back to email',
    str_contains($peopleSrc, "\$pid = isset(\$row['person_id']) && \$row['person_id'] !== '' ? (int) \$row['person_id'] : 0;"));
$a('person_id miss throws (no silent fallback to email)',
    str_contains($peopleSrc, "throw new \\RuntimeException(\"person_id not found: {\$pid}\");"));
$a('email-fallback path still runs when no id present',
    str_contains($peopleSrc, "WHERE tenant_id = :tenant_id AND LOWER(email_primary) = LOWER(:email)"));

echo "\n3. UI list pages render <IdBadge />\n";
$listPages = [
    'people/Directory (LIVE — /modules/people/directory)'
        => ['/app/modules/people/ui/Directory.jsx', 'prefix="P"'],
    'people/DirectoryModule (companies/clients/vendors)'
        => ['/app/modules/people/ui/DirectoryModule.jsx', 'prefix="C"'],
    'ap/VendorsList'
        => ['/app/modules/ap/ui/VendorsList.jsx', 'prefix="V"'],
    'ap/BillsList'
        => ['/app/modules/ap/ui/BillsList.jsx', 'prefix="B"'],
    'ap/PaymentsList'
        => ['/app/modules/ap/ui/PaymentsList.jsx', 'prefix="PAY"'],
    'billing/InvoicesList'
        => ['/app/modules/billing/ui/InvoicesList.jsx', 'prefix="INV"'],
    'billing/PaymentsList'
        => ['/app/modules/billing/ui/PaymentsList.jsx', 'prefix="RCP"'],
    'treasury/MercuryRecipients'
        => ['/app/modules/treasury/ui/MercuryRecipients.jsx', 'prefix="R"'],
    'treasury/MercuryPayments'
        => ['/app/modules/treasury/ui/MercuryPayments.jsx', 'prefix="MP"'],
];
foreach ($listPages as $name => [$path, $prefix]) {
    $src = (string) file_get_contents($path);
    $a("{$name} imports IdBadge",
        str_contains($src, "import IdBadge from"));
    $a("{$name} renders IdBadge with {$prefix}",
        str_contains($src, $prefix) && str_contains($src, '<IdBadge'));
}

echo "\n4. Company detail header surfaces C-{id} badge\n";
$dirSrc = (string) file_get_contents('/app/modules/people/ui/DirectoryModule.jsx');
$a('DirectoryModule detail header renders C-prefixed badge next to name',
    preg_match('/<IdBadge id=\{c\.id\}\s+prefix="C"/', $dirSrc) === 1);

echo "\n5. Column-header bumps consistent\n";
$colSpanFiles = [
    '/app/modules/people/ui/Directory.jsx'       => 'colSpan={7}',
    '/app/modules/ap/ui/VendorsList.jsx'         => 'colSpan={9}',
    '/app/modules/ap/ui/BillsList.jsx'           => 'colSpan={10}',
    '/app/modules/billing/ui/InvoicesList.jsx'   => 'colSpan={8}',
    '/app/modules/billing/ui/PaymentsList.jsx'   => 'colSpan={8}',
    '/app/modules/people/ui/DirectoryModule.jsx' => 'colSpan={6}',
];
foreach ($colSpanFiles as $path => $needle) {
    $src = (string) file_get_contents($path);
    $a(basename($path) . " has {$needle} empty-state span",
        str_contains($src, $needle));
}

echo "\n6. PHP syntax (every touched importer)\n";
foreach ($importers as $name => $path) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $rc);
    $a("php -l {$name}", $rc === 0, implode("\n", $out));
}

echo "\n=========================================\n";
echo "IdBadge product-wide rollout smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
