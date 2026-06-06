<?php
/**
 * Smoke — Zoho Books payload mappers match `spec/zoho_schema.json`.
 *
 * Drives the three Zoho builders (Bill, Invoice, JE) and asserts
 *   - every emitted top-level field is in writableProperties[]
 *   - every required[] field is present + non-null
 *   - line-items match their sub-schema
 *   - JournalLineItem.debit_or_credit is in {'debit','credit'}
 *
 * The builders return `_unresolved_*` / `_missing_*` placeholder lines
 * that the caller filters; we filter them here too so the contract
 * check matches the wire shape, not the work-in-progress shape.
 *
 * Run: php -d zend.assertions=1 /app/tests/zoho_payload_contract_smoke.php
 */
declare(strict_types=1);

$passes = 0; $failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nZoho payload contract smoke\n";
echo "===========================\n\n";

$spec = json_decode((string) file_get_contents('/app/spec/zoho_schema.json'), true);
check('spec/zoho_schema.json parses', is_array($spec));
foreach (['BillCreate', 'BillLineItem', 'InvoiceCreate', 'InvoiceLineItem',
          'JournalCreate', 'JournalLineItem'] as $d) {
    check("definitions[{$d}] present", isset($spec['definitions'][$d]['writableProperties']));
}

function assertZoho(array $spec, string $schemaName, array $payload, string $label): void {
    $s = $spec['definitions'][$schemaName];
    $allowed  = $s['writableProperties'] ?? [];
    $required = $s['required'] ?? [];
    $strays = array_diff(array_keys($payload), $allowed);
    check("{$label}: no stray fields (got " . (count($strays) ? implode(',', $strays) : 'none') . ')',
        count($strays) === 0);
    foreach ($required as $req) {
        $ok = array_key_exists($req, $payload) && $payload[$req] !== null && $payload[$req] !== '';
        check("{$label}: required '{$req}' present", $ok);
    }
}

// Stub getDB() so the builders that touch accounts table find a real PDO.
$pdo = new \PDO('sqlite::memory:');
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE accounting_accounts (id INTEGER PRIMARY KEY, tenant_id INT, code TEXT)");
$pdo->exec("INSERT INTO accounting_accounts VALUES (1, 7, '1002'), (2, 7, '1010')");
if (!function_exists('getDB')) { function getDB(): \PDO { global $pdo; return $pdo; } }

// Pull the three builders without booting framework.
$pull = function($file, $fn) {
    $src = (string) file_get_contents($file);
    return preg_match('/function ' . preg_quote($fn) . '\(.*?\n\}\n/s', $src, $m) ? $m[0] : '';
};
eval(preg_replace('/^\s*<\?php/', '',
    $pull('/app/core/zoho_books/sync_bills.php',    'zohoBooksBuildBillPayload') .
    $pull('/app/core/zoho_books/sync_invoices.php', 'zohoBooksBuildInvoicePayload') .
    $pull('/app/core/zoho_books/sync_je.php',       'zohoBooksBuildJournalPayload')
) ?: '');

$resolve = fn($id, $code = null) => $id > 0 ? ['value' => "zoho-acc-{$id}", 'name' => "Acc #{$id}"] : null;

// 1. Bill
echo "\n── zohoBooksBuildBillPayload ──\n";
$bill = ['bill_date' => '2026-06-01', 'due_date' => '2026-06-30', 'bill_number' => 'BILL-22',
         'currency_code' => 'USD', 'notes_internal' => 'rent'];
$billLines = [['id' => 1, 'total' => 1200, 'description' => 'June rent', 'gl_expense_account_code' => '5000']];
$vRef = ['value' => 'zb-v-77'];
$bp = zohoBooksBuildBillPayload($bill, $billLines, $vRef, $resolve);
$bp['line_items'] = array_values(array_filter($bp['line_items'], fn($l) =>
    !isset($l['_unresolved_account_id']) && !isset($l['_unresolved_account_code']) && !isset($l['_missing_account_code'])));
assertZoho($spec, 'BillCreate', $bp, 'bill');
foreach ($bp['line_items'] as $i => $ln) assertZoho($spec, 'BillLineItem', $ln, "bill.line_items[{$i}]");

// 2. Invoice
echo "\n── zohoBooksBuildInvoicePayload ──\n";
$inv = ['issue_date' => '2026-06-01', 'due_date' => '2026-06-30', 'invoice_number' => 'INV-22'];
$invLines = [['placement_id' => 42, 'subtotal' => 6000, 'quantity' => 40, 'unit_price' => 150,
              'description' => 'May hours', 'gl_revenue_account_code' => '4000']];
$cRef = ['value' => 'zb-c-88'];
$ip = zohoBooksBuildInvoicePayload($inv, $invLines, $cRef, $resolve);
$ip['line_items'] = array_values(array_filter($ip['line_items'], fn($l) =>
    !isset($l['_unresolved_account_id']) && !isset($l['_unresolved_account_code']) && !isset($l['_missing_account_code'])));
assertZoho($spec, 'InvoiceCreate', $ip, 'invoice');
foreach ($ip['line_items'] as $i => $ln) assertZoho($spec, 'InvoiceLineItem', $ln, "invoice.line_items[{$i}]");

// 3. Journal
echo "\n── zohoBooksBuildJournalPayload ──\n";
$je = ['posting_date' => '2026-06-05', 'je_number' => 'JE-3', 'memo' => 'cash deposit'];
$jeLines = [
    ['account_id' => 1, 'debit'  => 1500, 'credit' => 0, 'memo' => 'cash'],
    ['account_id' => 2, 'debit'  => 0,    'credit' => 1500, 'memo' => 'ar'],
];
$jp = zohoBooksBuildJournalPayload($je, $jeLines, $resolve);
$jp['line_items'] = array_values(array_filter($jp['line_items'], fn($l) => !isset($l['_unresolved_account_id'])));
assertZoho($spec, 'JournalCreate', $jp, 'journal');
foreach ($jp['line_items'] as $i => $ln) {
    assertZoho($spec, 'JournalLineItem', $ln, "journal.line_items[{$i}]");
    check("journal.line_items[{$i}].debit_or_credit ∈ allowed",
        in_array($ln['debit_or_credit'] ?? '', $spec['definitions']['JournalLineItem']['constraints']['debit_or_credit.allowed'], true));
}

$total = $passes + count($failures);
echo "\n=========================================\n";
echo "zoho_payload_contract smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
echo "=========================================\n";
foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
exit($failures ? 1 : 0);
