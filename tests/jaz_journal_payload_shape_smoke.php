<?php
/**
 * Smoke — mapJournalToJaz matches the official Jaz OpenAPI schema.
 *
 * Bug we're locking out: the legacy mapper sent {postingDate, narration,
 * currency: "USD", lines: [{debit, credit}]} — five field-level
 * mismatches against `CreateJournalClientRequest` in Jaz's published
 * OpenAPI (spec/openapi.yaml @ teamtinvio/jaz-ai v5.17.x). The live
 * symptom was every `create_draft_journal` failing with HTTP 400
 * "Invalid request body" and the outbox getting stuck on "retrying".
 *
 * Verified payload shape (per the spec):
 *   reference        REQUIRED string
 *   valueDate        REQUIRED YYYY-MM-DD
 *   journalEntries   REQUIRED ≥2 items
 *     accountResourceId  REQUIRED string (uuid)
 *     type               REQUIRED enum DEBIT|CREDIT
 *     amount             number ≥0 (positive; sign carried by type)
 *     description        string
 *   currency         optional BTCurrency { sourceCurrency: "USD" }
 *   internalNotes    optional string (NOT 'narration')
 *   saveAsDraft      optional bool (default true, supplied by adapter wrapper)
 *
 * Run: php -d zend.assertions=1 /app/tests/jaz_journal_payload_shape_smoke.php
 */
declare(strict_types=1);

$passes  = 0;
$failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nJaz POST /journals payload-shape smoke\n";
echo "======================================\n\n";

// Set up the minimum PDO + stubs so we can exercise the mapper end-to-end.
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
// Seed two mapped accounts so the resolver hits the fast path.
$pdo->exec("INSERT INTO accounting_destination_links
  (tenant_id, sub_tenant_id, provider, coreflux_object_type, coreflux_object_id,
   provider_object_type, provider_object_id, sync_status, idempotency_key)
   VALUES
   (1, 1, 'jaz', 'account', 1002, 'account', 'jaz-cash-uuid',  'posted', 'seed-cash'),
   (1, 1, 'jaz', 'account', 1010, 'account', 'jaz-ar-uuid',    'posted', 'seed-ar')");

if (!function_exists('getDB')) {
    function getDB(): \PDO { global $pdo; return $pdo; }
}
if (!class_exists('AccountingAdapterValidationException')) {
    class AccountingAdapterValidationException extends \RuntimeException {}
}
if (!function_exists('accountingAccountMappingLookup')) {
    function accountingAccountMappingLookup(int $tenantId, int $subTenantId, string $provider, int $cfId): ?array { return null; }
}

// Load mapper + helpers without provider_adapter.
$src = file_get_contents(__DIR__ . '/../core/accounting/jaz_payload_mapper.php');
$only = function ($pattern) use ($src) {
    return preg_match($pattern, $src, $m) ? $m[0] : '';
};
eval(preg_replace('/^\s*<\?php/', '',
    $only('/function _accCents\(.*?\n\}\n/s') .
    $only('/function _accAmount\(.*?\n\}\n/s') .
    $only('/function _accLookupJazResourceId.*?\n\}\n/s') .
    $only('/function mapJournalToJaz.*?\n\}\n/s')
) ?: '');

echo "── happy path: balanced 2-line CF journal ──\n";
$cfJournal = [
    'id' => 3,
    'je_number'    => 'JE-2026-000003',
    'posting_date' => '2026-06-05',
    'memo'         => 'cash deposit',
    'currency'     => 'USD',
    'lines' => [
        ['account_id' => 1002, 'debit'  => 1500.00, 'credit' => 0,    'description' => 'Cash in'],
        ['account_id' => 1010, 'debit'  => 0,       'credit' => 1500.00, 'description' => 'AR cleared'],
    ],
];
$out = mapJournalToJaz(1, 1, $cfJournal);

check('top-level reference present',           ($out['reference'] ?? null) === 'JE-2026-000003');
check('top-level valueDate present',           ($out['valueDate'] ?? null) === '2026-06-05');
check('top-level internalNotes present',       ($out['internalNotes'] ?? null) === 'cash deposit');
check('currency is BTCurrency object',         is_array($out['currency'] ?? null) && ($out['currency']['sourceCurrency'] ?? null) === 'USD');
check('journalEntries key present',            is_array($out['journalEntries'] ?? null));
check('exactly 2 entries for a 1-debit / 1-credit JE', count($out['journalEntries']) === 2);

echo "\n── legacy field names are gone ──\n";
check("'postingDate' NOT in payload (was the bug)", !array_key_exists('postingDate', $out));
check("'narration'   NOT in payload (was the bug)", !array_key_exists('narration',   $out));
check("'lines'       NOT in payload (was the bug)", !array_key_exists('lines',       $out));
check('currency is not a plain string',             !is_string($out['currency'] ?? null));

echo "\n── journalEntries[] shape ──\n";
$drEntry = $out['journalEntries'][0];
$crEntry = $out['journalEntries'][1];
check('debit entry accountResourceId is mapped uuid', ($drEntry['accountResourceId'] ?? null) === 'jaz-cash-uuid');
check("debit entry type === 'DEBIT'",                ($drEntry['type'] ?? null) === 'DEBIT');
check('debit entry amount is positive number',        is_numeric($drEntry['amount'] ?? null) && $drEntry['amount'] > 0);
check('debit entry amount equals 1500',               (float) $drEntry['amount'] === 1500.00);
check('debit entry has no debit/credit columns',      !array_key_exists('debit', $drEntry) && !array_key_exists('credit', $drEntry));
check("credit entry type === 'CREDIT'",              ($crEntry['type'] ?? null) === 'CREDIT');
check('credit entry amount equals 1500',              (float) $crEntry['amount'] === 1500.00);
check('credit entry uses AR account resource id',     ($crEntry['accountResourceId'] ?? null) === 'jaz-ar-uuid');
check('descriptions threaded through to each entry',  ($drEntry['description'] ?? null) === 'Cash in' && ($crEntry['description'] ?? null) === 'AR cleared');

echo "\n── unbalanced rejection still works ──\n";
$bad = $cfJournal;
$bad['lines'][1]['credit'] = 1499.99;
try {
    mapJournalToJaz(1, 1, $bad);
    check('unbalanced JE throws Validation', false);
} catch (AccountingAdapterValidationException $e) {
    check('unbalanced JE throws Validation', str_contains($e->getMessage(), 'unbalanced'));
}

echo "\n── insufficient lines rejection ──\n";
try {
    mapJournalToJaz(1, 1, ['lines' => [['account_id' => 1002, 'debit' => 1]]]);
    check('<2 lines throws Validation', false);
} catch (AccountingAdapterValidationException $e) {
    check('<2 lines throws Validation', str_contains($e->getMessage(), '≥2 lines'));
}

echo "\n── zero-amount line filtering ──\n";
$zero = $cfJournal;
$zero['lines'][0]['debit'] = 0;
$zero['lines'][1]['credit'] = 0;
try {
    mapJournalToJaz(1, 1, $zero);
    check('all-zero amounts throws Validation (no DEBIT or CREDIT entry produced)', false);
} catch (AccountingAdapterValidationException $e) {
    check('all-zero amounts throws Validation (no DEBIT or CREDIT entry produced)',
        str_contains($e->getMessage(), 'at least one DEBIT and one CREDIT') ||
        str_contains($e->getMessage(), 'unbalanced'));
}

echo "\n── default currency fallback ──\n";
$noccy = $cfJournal;
unset($noccy['currency']);
$out2 = mapJournalToJaz(1, 1, $noccy);
check('currency defaults to USD BTCurrency object',
    ($out2['currency']['sourceCurrency'] ?? null) === 'USD');

echo "\n── reference fallback when je_number missing ──\n";
$noref = $cfJournal;
unset($noref['je_number']);
$noref['id'] = 99;
$out3 = mapJournalToJaz(1, 1, $noref);
check('reference falls back to CF-JE-<id>',  $out3['reference'] === 'CF-JE-99');

echo "\n── source-level contract assertions ──\n";
$mapperSrc = $src;
check('mapper docblock mentions Jaz OpenAPI CreateJournalClientRequest',
    str_contains($mapperSrc, 'CreateJournalClientRequest'));
check('mapper emits journalEntries (not lines)',
    str_contains($mapperSrc, "'journalEntries' =>"));
check('mapper emits valueDate (not postingDate)',
    str_contains($mapperSrc, "'valueDate'") && !preg_match("/'postingDate'\s*=>/", $mapperSrc));
check('mapper emits internalNotes (not narration)',
    str_contains($mapperSrc, "'internalNotes'") && !preg_match("/'narration'\s*=>/", $mapperSrc));
check('mapper wraps currency as BTCurrency object',
    str_contains($mapperSrc, "'sourceCurrency'"));
check('mapper emits type DEBIT/CREDIT enum',
    str_contains($mapperSrc, "'type'              => 'DEBIT'") &&
    str_contains($mapperSrc, "'type'              => 'CREDIT'"));

$total = $passes + count($failures);
echo "\n=========================================\n";
echo "jaz_journal_payload_shape smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
echo "=========================================\n";
if ($failures) {
    foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
    exit(1);
}
exit(0);
