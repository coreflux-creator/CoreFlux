<?php
/**
 * Smoke — Jaz payload mappers must match the vendored Jaz OpenAPI.
 *
 * This is the safety-net that the previous shape-bug-of-the-month
 * sessions were missing. It exercises every Jaz outbound mapper
 * (`mapBillToJaz`, `mapInvoiceToJaz`, `mapJournalToJaz`) against
 * the canonical schema definitions in `spec/jaz_openapi.json`
 * (vendored from `github.com/teamtinvio/jaz-ai @ spec/openapi.yaml`
 * — the source of truth used by Jaz's own MCP server / CLI).
 *
 * For each mapper the smoke:
 *   1. Builds a minimal-but-realistic CoreFlux row.
 *   2. Runs the mapper.
 *   3. Asserts every output field is declared in the corresponding
 *      Create*ClientRequest schema (no stray field that Jaz will
 *      reject as "invalid_request_body").
 *   4. Asserts every REQUIRED field declared by the schema is present
 *      in the output (with non-null value).
 *   5. Validates that typed sub-objects (BTCurrency, JournalEntry,
 *      LineItem) match their own schemas — same field/required check
 *      applied recursively.
 *
 * Run: php -d zend.assertions=1 /app/tests/jaz_payload_contract_smoke.php
 */
declare(strict_types=1);

$passes  = 0;
$failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nJaz payload contract smoke (mapper ↔ OpenAPI)\n";
echo "==============================================\n\n";

$specPath = __DIR__ . '/../spec/jaz_openapi.json';
check('vendored Jaz OpenAPI spec exists at spec/jaz_openapi.json', is_file($specPath));
$spec = json_decode((string) file_get_contents($specPath), true);
check('spec parses as valid JSON', is_array($spec));
check('spec carries definitions block',                 isset($spec['definitions']) && is_array($spec['definitions']));
check('spec defines CreateBillClientRequest',           isset($spec['definitions']['CreateBillClientRequest']));
check('spec defines CreateInvoiceClientRequest',        isset($spec['definitions']['CreateInvoiceClientRequest']));
check('spec defines CreateJournalClientRequest',        isset($spec['definitions']['CreateJournalClientRequest']));
check('spec defines BTCurrency',                        isset($spec['definitions']['BTCurrency']));
check('spec defines JournalEntry',                      isset($spec['definitions']['JournalEntry']));

if ($failures) {
    foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
    exit(1);
}

/**
 * Resolve a `$ref` chain (single-level or allOf-wrapped) to the
 * actual sub-schema. Returns null when the property has no schema we
 * can introspect (free-form objects, enums, etc).
 */
function specResolve(array $spec, array $prop): ?array
{
    if (isset($prop['$ref'])) {
        $name = basename($prop['$ref']);
        return $spec['definitions'][$name] ?? null;
    }
    if (isset($prop['allOf'][0]['$ref'])) {
        $name = basename($prop['allOf'][0]['$ref']);
        return $spec['definitions'][$name] ?? null;
    }
    if (isset($prop['items']['$ref'])) {
        $name = basename($prop['items']['$ref']);
        return $spec['definitions'][$name] ?? null;
    }
    return null;
}

/**
 * Asserts $payload is structurally compatible with the schema named
 * $schemaName. Emits a `check()` per property.
 */
function assertMatchesSchema(array $spec, string $schemaName, array $payload, string $labelPrefix): void
{
    $schema = $spec['definitions'][$schemaName] ?? null;
    if (!$schema) {
        check("{$labelPrefix}: schema {$schemaName} found", false);
        return;
    }
    $allowed  = array_keys($schema['properties'] ?? []);
    $required = $schema['required'] ?? [];

    // 1) No stray fields outside the declared properties.
    $strays = array_diff(array_keys($payload), $allowed);
    check("{$labelPrefix}: no stray fields (got " . (count($strays) ? implode(',', $strays) : 'none') . ')',
        count($strays) === 0);

    // 2) All required fields present + non-null.
    foreach ($required as $req) {
        $present = array_key_exists($req, $payload) && $payload[$req] !== null && $payload[$req] !== '';
        check("{$labelPrefix}: required '{$req}' present", $present);
    }

    // 3) Recursively validate referenced sub-schemas for the values
    //    we actually emitted.
    foreach ($payload as $key => $val) {
        $prop = $schema['properties'][$key] ?? null;
        if (!$prop) continue;
        $subSchema = specResolve($spec, $prop);
        if (!$subSchema) continue;
        if (isset($prop['type']) && $prop['type'] === 'array' && is_array($val)) {
            foreach ($val as $i => $item) {
                if (is_array($item)) {
                    $name = basename($prop['items']['$ref'] ?? '');
                    if ($name) assertMatchesSchema($spec, $name, $item, "{$labelPrefix}.{$key}[{$i}]");
                }
            }
        } elseif (is_array($val) && isset($subSchema['properties'])) {
            $name = basename(($prop['$ref'] ?? ($prop['allOf'][0]['$ref'] ?? '')));
            if ($name) assertMatchesSchema($spec, $name, $val, "{$labelPrefix}.{$key}");
        }
    }
}

// ── Set up a real-PDO sandbox so the mappers can resolve account links. ──
$pdo = new \PDO('sqlite::memory:');
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE accounting_destination_links (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INT, sub_tenant_id INT, provider TEXT,
  coreflux_object_type TEXT, coreflux_object_id INT,
  provider_object_type TEXT, provider_object_id TEXT,
  sync_status TEXT DEFAULT 'pending', idempotency_key TEXT
)");
$pdo->exec("CREATE TABLE accounting_account_mappings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INT, sub_tenant_id INT, provider TEXT,
  coreflux_account_id INT, coreflux_account_code TEXT,
  provider_account_id TEXT, provider_account_code TEXT,
  provider_account_name TEXT, provider_account_type TEXT
)");
foreach ([
    ['account',  1002, 'jaz-cash-uuid'],
    ['account',  1010, 'jaz-ar-uuid'],
    ['account',  5000, 'jaz-cogs-uuid'],
    ['account',  4000, 'jaz-rev-uuid'],
    ['vendor',   77,   'jaz-vendor-uuid'],
    ['customer', 88,   'jaz-customer-uuid'],
] as [$type, $cfId, $jazId]) {
    $st = $pdo->prepare("INSERT INTO accounting_destination_links
        (tenant_id, sub_tenant_id, provider, coreflux_object_type, coreflux_object_id,
         provider_object_type, provider_object_id, sync_status, idempotency_key)
         VALUES (1, 1, 'jaz', :t, :id, :t, :j, 'posted', :ik)");
    $st->execute(['t' => $type, 'id' => $cfId, 'j' => $jazId, 'ik' => "seed-{$type}-{$cfId}"]);
}

if (!function_exists('getDB')) { function getDB(): \PDO { global $pdo; return $pdo; } }
if (!class_exists('AccountingAdapterValidationException')) {
    class AccountingAdapterValidationException extends \RuntimeException {}
}
if (!function_exists('accountingAccountMappingLookup')) {
    function accountingAccountMappingLookup(int $tenantId, int $subTenantId, string $provider, int $cfId): ?array { return null; }
}

// Load just the mappers + helpers (skip provider_adapter to avoid
// pulling the whole framework into the smoke).
$src = file_get_contents(__DIR__ . '/../core/accounting/jaz_payload_mapper.php');
$only = function ($pattern) use ($src) { return preg_match($pattern, $src, $m) ? $m[0] : ''; };
eval(preg_replace('/^\s*<\?php/', '',
    $only('/function _accCents\(.*?\n\}\n/s') .
    $only('/function _accAmount\(.*?\n\}\n/s') .
    $only('/function _accLookupJazResourceId.*?\n\}\n/s') .
    $only('/function mapBillToJaz.*?\n\}\n/s') .
    $only('/function mapInvoiceToJaz.*?\n\}\n/s') .
    $only('/function mapJournalToJaz.*?\n\}\n/s')
) ?: '');

// ── 1. Journal mapper (already verified — should be clean). ──
echo "\n── mapJournalToJaz ──\n";
$journal = [
    'id' => 3,
    'je_number'    => 'JE-2026-000003',
    'posting_date' => '2026-06-05',
    'memo'         => 'cash deposit',
    'currency'     => 'USD',
    'lines' => [
        ['account_id' => 1002, 'debit' => 1500, 'credit' => 0, 'description' => 'cash'],
        ['account_id' => 1010, 'debit' => 0, 'credit' => 1500, 'description' => 'ar'],
    ],
];
$out = mapJournalToJaz(1, 1, $journal);
assertMatchesSchema($spec, 'CreateJournalClientRequest', $out, 'journal');

// ── 2. Bill mapper. ──
echo "\n── mapBillToJaz ──\n";
$bill = [
    'id' => 17,
    'vendor_id'    => 77,
    'internal_ref' => 'BILL-2026-017',
    'bill_date'    => '2026-06-01',
    'due_date'     => '2026-06-30',
    'currency'     => 'USD',
    'notes'        => 'desk rent',
    'lines' => [
        ['account_id' => 5000, 'description' => 'June rent', 'quantity' => 1, 'unit_amount' => 1200.00],
    ],
];
$outBill = mapBillToJaz(1, 1, $bill);
assertMatchesSchema($spec, 'CreateBillClientRequest', $outBill, 'bill');

// ── 3. Invoice mapper. ──
echo "\n── mapInvoiceToJaz ──\n";
$invoice = [
    'id' => 22,
    'customer_id'    => 88,
    'invoice_number' => 'INV-2026-022',
    'invoice_date'   => '2026-06-01',
    'due_date'       => '2026-06-30',
    'currency'       => 'USD',
    'notes'          => 'consulting',
    'lines' => [
        ['account_id' => 4000, 'description' => 'May hours', 'quantity' => 40, 'unit_amount' => 150.00],
    ],
];
$outInv = mapInvoiceToJaz(1, 1, $invoice);
assertMatchesSchema($spec, 'CreateInvoiceClientRequest', $outInv, 'invoice');

$total = $passes + count($failures);
echo "\n=========================================\n";
echo "jaz_payload_contract smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
echo "=========================================\n";
if ($failures) {
    foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
    exit(1);
}
exit(0);
