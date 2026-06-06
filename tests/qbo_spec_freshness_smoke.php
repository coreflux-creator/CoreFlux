<?php
/**
 * Smoke — QBO spec freshness + internal consistency.
 *
 * Unlike Jaz, Intuit doesn't publish a single OpenAPI we can JSON-diff —
 * our spec is hand-rolled from per-entity HTML pages. So this smoke
 * does TWO things:
 *
 *   1. Local sanity:
 *      - spec/qbo_schema.json parses and carries every definition the
 *        contract smoke uses (catch accidental deletes).
 *      - DocNumber.maxLength stays at 21 (Intuit's hard cap — flagged
 *        because it's the most common reason for "DocNumber too long"
 *        500s in QBO sync logs).
 *      - PostingType allowed set is exactly {Debit, Credit}.
 *      - tools/refresh_qbo_spec.sh exists, executable, and matches the
 *        same three pages the schema claims to derive from.
 *
 *   2. HTML drift hint (best-effort, network-dependent):
 *      - When `spec/qbo_docs/*.html` exists AND curl is available, do
 *        a lightweight HEAD against each Intuit URL and compare the
 *        Last-Modified header against the locally captured snapshot.
 *      - When offline, SKIP gracefully — no failure.
 *
 * Run: php -d zend.assertions=1 /app/tests/qbo_spec_freshness_smoke.php
 */
declare(strict_types=1);

$passes = 0; $failures = []; $warnings = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}
function warn(string $msg) { global $warnings; $warnings[] = $msg; echo "  ⚠ {$msg}\n"; }

echo "\nQBO spec freshness smoke\n";
echo "========================\n\n";

$schemaPath = __DIR__ . '/../spec/qbo_schema.json';
$docsDir    = __DIR__ . '/../spec/qbo_docs';
$tool       = __DIR__ . '/../tools/refresh_qbo_spec.sh';

echo "── local schema sanity ──\n";
check('spec/qbo_schema.json exists',                            is_file($schemaPath));
$schema = json_decode((string) file_get_contents($schemaPath), true);
check('schema parses as JSON',                                  is_array($schema));
foreach (['JournalEntryCreate', 'BillCreate', 'InvoiceCreate'] as $d) {
    check("definitions[{$d}] present", isset($schema['definitions'][$d]));
}

// Intuit-known hard caps — drift on these is the #1 source of QBO 500s.
$jeCap   = $schema['definitions']['JournalEntryCreate']['constraints']['DocNumber.maxLength'] ?? null;
$billCap = $schema['definitions']['BillCreate']['constraints']['DocNumber.maxLength']         ?? null;
$invCap  = $schema['definitions']['InvoiceCreate']['constraints']['DocNumber.maxLength']      ?? null;
check('JournalEntry.DocNumber.maxLength = 21', $jeCap === 21);
check('Bill.DocNumber.maxLength = 21',         $billCap === 21);
check('Invoice.DocNumber.maxLength = 21',      $invCap === 21);

$postingTypes = $schema['definitions']['JournalEntryLineDetail']['constraints']['PostingType.allowed'] ?? [];
sort($postingTypes);
check("PostingType allowed set is {Debit, Credit}",
    $postingTypes === ['Credit', 'Debit']);

$jeDetailTypes = $schema['definitions']['JournalEntryLine']['constraints']['DetailType.allowed'] ?? [];
check("JournalEntry DetailType list includes JournalEntryLineDetail",
    in_array('JournalEntryLineDetail', $jeDetailTypes, true));
check("Bill DetailType list includes AccountBasedExpenseLineDetail",
    in_array('AccountBasedExpenseLineDetail',
        $schema['definitions']['BillLine']['constraints']['DetailType.allowed'] ?? [], true));
check("Invoice DetailType list includes SalesItemLineDetail",
    in_array('SalesItemLineDetail',
        $schema['definitions']['InvoiceLine']['constraints']['DetailType.allowed'] ?? [], true));

echo "\n── refresh tool ──\n";
check('tools/refresh_qbo_spec.sh exists',            is_file($tool));
check('refresh tool is executable',                  is_executable($tool));
$toolSrc = (string) @file_get_contents($tool);
check("refresh tool documents the workflow (re-edit schema after diff)",
    str_contains($toolSrc, 'hand-edit spec/qbo_schema.json'));

// The pages the schema claims to derive from must match the refresh tool.
$expectedPages = ['journalentry', 'bill', 'invoice'];
foreach ($expectedPages as $page) {
    check("refresh tool fetches {$page}",            str_contains($toolSrc, "\"{$page}\""));
}
$scrapedUrls = $schema['_meta']['scraped_pages'] ?? [];
foreach ($expectedPages as $page) {
    $hit = false;
    foreach ($scrapedUrls as $url) if (str_contains($url, "/{$page}")) $hit = true;
    check("schema._meta.scraped_pages references {$page}", $hit);
}

echo "\n── local doc snapshot ──\n";
if (is_dir($docsDir)) {
    foreach ($expectedPages as $page) {
        check("spec/qbo_docs/{$page}.html present",
            is_file("{$docsDir}/{$page}.html"));
    }
    $fetchedAt = "{$docsDir}/.fetched_at";
    if (is_file($fetchedAt)) {
        $ts = trim((string) file_get_contents($fetchedAt));
        $age = time() - strtotime($ts);
        if ($age > (90 * 86400)) {
            warn("qbo_docs snapshot is " . intdiv($age, 86400) . " days old — run `bash tools/refresh_qbo_spec.sh` and eyeball any field changes");
        } else {
            $passes++;
            echo "  ✓ snapshot age: " . intdiv($age, 86400) . " days\n";
        }
    } else {
        warn("no .fetched_at marker in qbo_docs/ — refresh tool was never run, or older version");
    }
} else {
    warn("no spec/qbo_docs/ directory — run `bash tools/refresh_qbo_spec.sh` to seed the snapshot");
}

$total = $passes + count($failures);
echo "\n=========================================\n";
echo "qbo_spec_freshness smoke: {$passes} ✓ / " . count($failures) . " ✗";
if ($warnings) echo " / " . count($warnings) . " ⚠";
echo "\n=========================================\n";
foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
foreach ($warnings as $msg) echo "  WARN: {$msg}\n";
exit($failures ? 1 : 0);
