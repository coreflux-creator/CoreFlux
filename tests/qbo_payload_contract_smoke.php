<?php
/**
 * Smoke — QBO payload mappers match the hand-rolled QBO schema.
 *
 * Mirrors the Jaz contract smoke but against `spec/qbo_schema.json`,
 * which is hand-curated from Intuit's per-entity HTML docs (Intuit
 * does NOT publish an OpenAPI spec). Drives the three QBO builders:
 *   qboBuildJournalEntryPayload (sync_je.php)
 *   qboBuildBillPayload         (sync_bills.php)
 *   qboBuildInvoicePayload      (sync_invoices.php)
 *
 * Each check asserts:
 *   - every emitted top-level field is in writableProperties[]
 *   - every required[] field is present + non-null
 *   - DocNumber respects 21-char cap when present
 *   - PostingType / DetailType values are in the allowed enums
 *   - line items match their sub-schemas
 *
 * Note: the builders return PLACEHOLDER lines like
 * `{_unresolved_account_id: N}` when a resolver returns null — the
 * caller (`qboSync*`) filters those before POSTing, so the contract
 * smoke also filters them out before asserting against the schema.
 *
 * Run: php -d zend.assertions=1 /app/tests/qbo_payload_contract_smoke.php
 */
declare(strict_types=1);

$passes = 0; $failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nQBO payload contract smoke (mappers ↔ Intuit entity refs)\n";
echo "=========================================================\n\n";

$specPath = __DIR__ . '/../spec/qbo_schema.json';
check('spec/qbo_schema.json exists', is_file($specPath));
$spec = json_decode((string) file_get_contents($specPath), true);
check('schema parses as JSON',                          is_array($spec));
foreach (['JournalEntryCreate', 'BillCreate', 'InvoiceCreate',
          'JournalEntryLine', 'BillLine', 'InvoiceLine',
          'JournalEntryLineDetail', 'AccountBasedExpenseLineDetail',
          'SalesItemLineDetail', 'ReferenceType'] as $d) {
    check("schema defines {$d}", isset($spec['definitions'][$d]));
}

if ($failures) { foreach ($failures as $f) echo "  FAIL: {$f}\n"; exit(1); }

/**
 * Assert $payload conforms to $schemaName.
 *   - drops keys in $ignoreKeys (used to strip placeholder lines)
 *   - emits a check per stray field + required-presence + recurses
 *     into nested sub-objects via $childRefs map
 */
function assertQbo(array $spec, string $schemaName, array $payload, string $labelPrefix, array $childRefs = []): void
{
    $schema = $spec['definitions'][$schemaName];
    $allowed  = $schema['writableProperties'] ?? [];
    $required = $schema['required'] ?? [];

    $strays = array_diff(array_keys($payload), $allowed);
    check("{$labelPrefix}: no stray fields (got " . (count($strays) ? implode(',', $strays) : 'none') . ')',
        count($strays) === 0);

    foreach ($required as $req) {
        $present = array_key_exists($req, $payload) && $payload[$req] !== null && $payload[$req] !== '';
        check("{$labelPrefix}: required '{$req}' present", $present);
    }

    // DocNumber cap.
    if (isset($payload['DocNumber'])) {
        check("{$labelPrefix}: DocNumber within 21-char cap",
            strlen((string) $payload['DocNumber']) <= 21);
    }

    // Recurse into nested object refs the caller declared.
    foreach ($childRefs as $key => $refName) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            assertQbo($spec, $refName, $payload[$key], "{$labelPrefix}.{$key}");
        }
    }
}

// Load builders without bootstrapping the framework. Each builder is
// self-contained — qboBuildJournalEntryPayload uses no DB; the others
// touch getDB() so we stub a small PDO for them.
$pdo = new \PDO('sqlite::memory:');
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE accounting_accounts (id INTEGER PRIMARY KEY, tenant_id INT, code TEXT)");
$pdo->exec("INSERT INTO accounting_accounts VALUES (50, 7, '5000'), (60, 7, '6000')");
if (!function_exists('getDB')) { function getDB(): \PDO { global $pdo; return $pdo; } }

$pull = function ($file, $fn) {
    $src = file_get_contents($file);
    return preg_match('/function ' . preg_quote($fn) . '\(.*?\n\}\n/s', $src, $m) ? $m[0] : '';
};
eval(preg_replace('/^\s*<\?php/', '',
    $pull('/app/core/qbo/sync_je.php',       'qboBuildJournalEntryPayload') .
    $pull('/app/core/qbo/sync_bills.php',    'qboBuildBillPayload') .
    $pull('/app/core/qbo/sync_invoices.php', 'qboBuildInvoicePayload')
) ?: '');

$resolveAccount = fn($id) => $id > 0 ? ['value' => (string) ($id * 10), 'name' => "Account #{$id}"] : null;
$resolveItem    = fn($pid) => $pid ? ['value' => '9001', 'name' => 'Time Activity'] : null;

// ── 1. Journal entry ──
echo "\n── qboBuildJournalEntryPayload ──\n";
$je = ['posting_date' => '2026-06-05', 'je_number' => 'JE-0003', 'memo' => 'cash deposit'];
$jeLines = [
    ['account_id' => 1, 'debit' => 1500.0, 'credit' => 0, 'memo' => 'cash'],
    ['account_id' => 2, 'debit' => 0,      'credit' => 1500.0, 'memo' => 'ar'],
];
$jePayload = qboBuildJournalEntryPayload($je, $jeLines, $resolveAccount);
// Drop the `_unresolved_*` placeholder lines like sync_je.php does.
$jePayload['Line'] = array_values(array_filter($jePayload['Line'],
    fn($l) => !isset($l['_unresolved_account_id'])));

assertQbo($spec, 'JournalEntryCreate', $jePayload, 'je');
check("je: Line[] has ≥2 entries",
    is_array($jePayload['Line']) && count($jePayload['Line']) >= 2);
$debits  = array_filter($jePayload['Line'], fn($l) => ($l['JournalEntryLineDetail']['PostingType'] ?? '') === 'Debit');
$credits = array_filter($jePayload['Line'], fn($l) => ($l['JournalEntryLineDetail']['PostingType'] ?? '') === 'Credit');
check("je: has at least one Debit line", count($debits) >= 1);
check("je: has at least one Credit line", count($credits) >= 1);
foreach ($jePayload['Line'] as $i => $line) {
    assertQbo($spec, 'JournalEntryLine', $line, "je.Line[{$i}]",
        ['JournalEntryLineDetail' => 'JournalEntryLineDetail']);
    check("je.Line[{$i}]: DetailType is in allowed set",
        in_array($line['DetailType'] ?? '', $spec['definitions']['JournalEntryLine']['constraints']['DetailType.allowed'], true));
}

// ── 2. Bill ──
echo "\n── qboBuildBillPayload ──\n";
$bill = ['tenant_id' => 7, 'bill_date' => '2026-06-01', 'due_date' => '2026-06-30',
         'bill_number' => 'BILL-2026-0017', 'notes_internal' => 'desk rent'];
$billLines = [
    ['id' => 1, 'total' => 1200.0, 'description' => 'June rent', 'gl_expense_account_code' => '5000'],
];
$vendorRef = ['value' => 'qbo-v-77', 'name' => 'Acme LLC'];
$billPayload = qboBuildBillPayload($bill, $billLines, $vendorRef, $resolveAccount);
$billPayload['Line'] = array_values(array_filter($billPayload['Line'],
    fn($l) => !isset($l['_missing_account_code']) && !isset($l['_unresolved_account_code']) && !isset($l['_unresolved_account_id'])));

assertQbo($spec, 'BillCreate', $billPayload, 'bill');
check("bill: VendorRef.value set",         ($billPayload['VendorRef']['value'] ?? null) === 'qbo-v-77');
foreach ($billPayload['Line'] as $i => $line) {
    assertQbo($spec, 'BillLine', $line, "bill.Line[{$i}]",
        ['AccountBasedExpenseLineDetail' => 'AccountBasedExpenseLineDetail']);
    check("bill.Line[{$i}]: DetailType is in allowed set",
        in_array($line['DetailType'] ?? '', $spec['definitions']['BillLine']['constraints']['DetailType.allowed'], true));
}

// ── 3. Invoice ──
echo "\n── qboBuildInvoicePayload ──\n";
$inv = ['issue_date' => '2026-06-01', 'due_date' => '2026-06-30',
        'invoice_number' => 'INV-2026-0022', 'notes_internal' => 'consulting',
        'notes_external' => 'May hours — thanks for the business'];
$invLines = [
    ['placement_id' => 42, 'subtotal' => 6000.0, 'quantity' => 40, 'unit_price' => 150.0, 'description' => 'May hours'],
];
$customerRef = ['value' => 'qbo-c-88', 'name' => 'Beta Corp'];
$invPayload = qboBuildInvoicePayload($inv, $invLines, $customerRef, $resolveItem);
$invPayload['Line'] = array_values(array_filter($invPayload['Line'],
    fn($l) => !isset($l['_no_item_mapping'])));

assertQbo($spec, 'InvoiceCreate', $invPayload, 'invoice');
check("invoice: CustomerRef.value set",   ($invPayload['CustomerRef']['value'] ?? null) === 'qbo-c-88');
foreach ($invPayload['Line'] as $i => $line) {
    assertQbo($spec, 'InvoiceLine', $line, "invoice.Line[{$i}]",
        ['SalesItemLineDetail' => 'SalesItemLineDetail']);
    check("invoice.Line[{$i}]: DetailType is in allowed set",
        in_array($line['DetailType'] ?? '', $spec['definitions']['InvoiceLine']['constraints']['DetailType.allowed'], true));
}

// ── 4. DocNumber long-name truncation (regression for >21-char names) ──
echo "\n── DocNumber 21-char cap ──\n";
$longInv = $inv; $longInv['invoice_number'] = 'INV-2026-VERY-LONG-NUMBER-12345';
$invPayload2 = qboBuildInvoicePayload($longInv, $invLines, $customerRef, $resolveItem);
check("invoice: long DocNumber truncated to ≤21 chars",
    strlen((string) ($invPayload2['DocNumber'] ?? '')) <= 21);

$longBill = $bill; $longBill['bill_number'] = 'BILL-2026-VERY-LONG-DOC-NUMBER-XYZ';
$billPayload2 = qboBuildBillPayload($longBill, $billLines, $vendorRef, $resolveAccount);
check("bill: long DocNumber truncated to ≤21 chars",
    strlen((string) ($billPayload2['DocNumber'] ?? '')) <= 21);

$total = $passes + count($failures);
echo "\n=========================================\n";
echo "qbo_payload_contract smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
echo "=========================================\n";
if ($failures) { foreach ($failures as $f) echo "  FAIL: {$f}\n"; exit(1); }
exit(0);
