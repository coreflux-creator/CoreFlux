<?php
/**
 * Smoke — Jaz journal POST finalizes (does NOT save as draft).
 *
 * Root cause locked in here: `createDraftJournal` used to merge
 * `saveAsDraft: true` into the payload, so every CoreFlux JE that
 * "succeeded" landed in Jaz's Drafts queue instead of Recorded
 * Journals. The user reported "outbox says posted but nothing in
 * Jaz" — the journal WAS there, just hidden in Drafts.
 *
 * Fix: createDraftJournal now defaults `saveAsDraft: false` because
 * CF JEs already clear our internal approval gate
 * (workflow_approvals.consumed_by_je_id) before they're enqueued.
 * Callers can still opt in by passing `saveAsDraft: true` explicitly.
 *
 * The smoke runs the adapter against a stub jazCall() so we can read
 * back the payload that would have been put on the wire — no live
 * Jaz needed.
 *
 * Run: php -d zend.assertions=1 /app/tests/jaz_journal_finalize_smoke.php
 */
declare(strict_types=1);

$passes = 0; $failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nJaz journal finalize smoke\n";
echo "==========================\n\n";

// Stub everything the adapter calls so we don't drag in the framework.
$GLOBALS['__jaz_last_post'] = null;
if (!function_exists('jazCall')) {
    function jazCall(array $key, string $verb, string $path, array $payload) {
        $GLOBALS['__jaz_last_post'] = compact('verb', 'path', 'payload');
        return ['data' => ['resourceId' => 'jaz-je-uuid-1', 'reference' => $payload['reference']]];
    }
}
if (!class_exists('AccountingProviderAdapter')) {
    abstract class AccountingProviderAdapter {
        public function normalizeProviderError(\Throwable $e) { return ['error_code' => 'x', 'message' => $e->getMessage()]; }
    }
}
if (!class_exists('AccountingAdapterValidationException')) {
    class AccountingAdapterValidationException extends \RuntimeException {}
}
if (!function_exists('rbac_legacy_can')) { function rbac_legacy_can(array $u, string $p): bool { return true; } }

$src = file_get_contents(__DIR__ . '/../core/accounting/jaz_adapter.php');
// Pull just the createDraftJournal method + the keyOrThrow + wrapWriteResult helpers
// it depends on, plus a lightweight test class that exposes them publicly.
$adapterShim = <<<'PHP'
class JazAdapterShim {
    public function keyOrThrow(int $t, int $st): array { return ['api_key' => 'TEST']; }
    public function wrapWriteResult(string $type, $resp, string $idem, string $default): array {
        $rid = $resp['data']['resourceId'] ?? $resp['resourceId'] ?? '';
        return [
            'provider_object_type' => $type,
            'provider_object_id'   => (string) $rid,
            'idempotency_key'      => $idem,
            'status'               => $rid !== '' ? ($default === 'draft' ? 'draft' : 'posted') : 'pending',
            'jaz_payload'          => $resp,
        ];
    }
PHP;
preg_match('/public function createDraftJournal\(.*?\n    \}\n/s', $src, $m);
eval($adapterShim . "\n" . $m[0] . "\n}");

$adapter = new JazAdapterShim();
$journal = [
    'reference'      => 'JE-2026-000003',
    'valueDate'      => '2026-06-05',
    'currency'       => ['sourceCurrency' => 'USD'],
    'internalNotes'  => 'cash deposit',
    'journalEntries' => [
        ['accountResourceId' => 'jaz-cash-uuid', 'type' => 'DEBIT',  'amount' => 1500, 'description' => 'cash'],
        ['accountResourceId' => 'jaz-ar-uuid',   'type' => 'CREDIT', 'amount' => 1500, 'description' => 'ar'],
    ],
];

echo "── default behaviour: saveAsDraft = false ──\n";
$result = $adapter->createDraftJournal(1, 1, $journal, 'idem-1');
$wire = $GLOBALS['__jaz_last_post']['payload'] ?? [];
check('jazCall actually invoked',                                $wire !== []);
check("POSTs to 'journals'",                                     ($GLOBALS['__jaz_last_post']['path'] ?? '') === 'journals');
check('payload carries saveAsDraft = FALSE (this was the bug)',  ($wire['saveAsDraft'] ?? null) === false);
check('payload preserves the mapper output (reference)',         ($wire['reference'] ?? null) === 'JE-2026-000003');
check('payload preserves journalEntries[]',                      is_array($wire['journalEntries'] ?? null));
check('result.status === posted (not draft)',                    ($result['status'] ?? null) === 'posted');
check('result.provider_object_id captured',                      ($result['provider_object_id'] ?? null) === 'jaz-je-uuid-1');

echo "\n── opt-in draft override still works ──\n";
$GLOBALS['__jaz_last_post'] = null;
$draftJournal = $journal + ['saveAsDraft' => true];
$result2 = $adapter->createDraftJournal(1, 1, $draftJournal, 'idem-2');
$wire2 = $GLOBALS['__jaz_last_post']['payload'] ?? [];
check('explicit saveAsDraft:true survives the array_merge override',
    ($wire2['saveAsDraft'] ?? null) === true);
check('explicit draft → result.status === draft',
    ($result2['status'] ?? null) === 'draft');

echo "\n── source-level contract checks ──\n";
check('adapter docblock documents the saveAsDraft override intent',
    str_contains($src, 'CoreFlux JEs have already cleared our own approval'));
check('createDraftJournal merges saveAsDraft => false',
    preg_match("/'saveAsDraft'\s*=>\s*false/", $src) === 1);
check('bills + invoices are NOT changed (still saveAsDraft: true)',
    preg_match("/createDraftBill.*?'saveAsDraft'\s*=>\s*true/s", $src) === 1 &&
    preg_match("/createDraftInvoice.*?'saveAsDraft'\s*=>\s*true/s", $src) === 1);

$total = $passes + count($failures);
echo "\n=========================================\n";
echo "jaz_journal_finalize smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
echo "=========================================\n";
foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
exit($failures ? 1 : 0);
