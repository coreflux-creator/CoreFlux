<?php
/**
 * Smoke — Nested-transaction safety for lib-level helpers.
 *
 * Regression for the Feb-2026 "There is already an active transaction"
 * error users hit on:
 *   • Create Bill   (POST /api/ap/bills)
 *   • Create Invoice (POST /api/billing/invoices)
 *   • AR payment allocation triggering PWP release (billing → ap chain)
 *
 * Every lib-level helper that opens its own tx MUST detect an outer
 * caller-owned tx and skip its begin/commit. This file proves the
 * `$ownsTxn = !$pdo->inTransaction();` guard pattern is wired
 * everywhere on the bill-create / invoice-create / payment-allocate
 * code paths.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

echo "\n1. Static guard checks — every patched helper has the nested-safe pattern\n";

$assertGuard = function (string $path, string $fn) use ($a) {
    $src = (string) @file_get_contents($path);
    // Find the function body bounded by the next "function " token or EOF.
    if (!preg_match("/function {$fn}\\b.*?(\\{)/s", $src, $m, PREG_OFFSET_CAPTURE)) {
        $a("{$path}::{$fn} declared", false, 'function not found'); return;
    }
    $start = $m[1][1];
    $depth = 0; $end = strlen($src);
    for ($i = $start; $i < strlen($src); $i++) {
        if ($src[$i] === '{') $depth++;
        elseif ($src[$i] === '}') { $depth--; if ($depth === 0) { $end = $i; break; } }
    }
    $body = substr($src, $start, $end - $start);
    $a("{$fn}: declares \$ownsTxn = !\$pdo->inTransaction()", str_contains($body, '$ownsTxn = !$pdo->inTransaction()'));
    $a("{$fn}: guards beginTransaction()",               str_contains($body, 'if ($ownsTxn) $pdo->beginTransaction();'));
    $a("{$fn}: guards commit()",                          str_contains($body, 'if ($ownsTxn) $pdo->commit();'));
    $a("{$fn}: guards rollBack()",                        str_contains($body, 'if ($ownsTxn && $pdo->inTransaction()) $pdo->rollBack();'));
};

$assertGuard('/app/modules/ap/lib/ap.php',      'apNextInternalRef');
$assertGuard('/app/modules/ap/lib/ap.php',      'apAllocatePayment');
$assertGuard('/app/modules/ap/lib/pwp.php',     'apPwpAutoLinkForArInvoice');
$assertGuard('/app/modules/ap/lib/pwp.php',     'apPwpReleaseForArInvoice');
$assertGuard('/app/modules/billing/lib/billing.php', 'billingNextInvoiceNumber');
$assertGuard('/app/modules/billing/lib/billing.php', 'billingAllocatePayment');

echo "\n2. Live PDO exercise — apNextInternalRef survives an outer-owned tx\n";

$pdo = new \PDO('sqlite::memory:');
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

$pdo->exec("CREATE TABLE tenants (
    id INTEGER PRIMARY KEY, ap_bill_prefix TEXT, ap_next_bill_seq INT,
    billing_invoice_prefix TEXT, billing_next_invoice_seq INT
)");
$pdo->exec("INSERT INTO tenants(id, ap_bill_prefix, ap_next_bill_seq, billing_invoice_prefix, billing_next_invoice_seq)
            VALUES (1, 'BILL', 42, 'INV', 17)");

// SQLite doesn't understand 'FOR UPDATE'; strip it for the test only.
$replaceForUpdate = static function (string $src): string {
    return str_replace(' FOR UPDATE', '', $src);
};

// Load apNextInternalRef in isolation with stubbed getDB().
$GLOBALS['pdo'] = $pdo;
$libAp = $replaceForUpdate((string) file_get_contents('/app/modules/ap/lib/ap.php'));
// Extract just the apNextInternalRef function.
preg_match('/(\/\*\*[^\/]*?Atomically allocate the next internal bill[^\/]*?\*\/\s*function apNextInternalRef.*?^\})/sm', $libAp, $m);
$snippet = $m[1] ?? '';
$a('extracted apNextInternalRef snippet', $snippet !== '');

if (!function_exists('getDB')) {
    function getDB(): \PDO { return $GLOBALS['pdo']; }
}
if ($snippet) eval($snippet);

// Case A: outer tx open — must NOT throw.
$pdo->beginTransaction();
$caught = null; $ref = null;
try {
    $ref = apNextInternalRef(1);
} catch (\Throwable $e) {
    $caught = $e->getMessage();
}
$a('apNextInternalRef inside outer tx succeeds (no nested-tx exception)',
    $caught === null, $caught ?? '');
$a('returns BILL-YYYY-0042 format', $ref !== null && preg_match('/^BILL-\d{4}-0042$/', $ref) === 1, (string) $ref);
$a('outer transaction still active (helper did not commit our tx)',
    $pdo->inTransaction());

// And after the outer commit, the seq must have advanced.
$pdo->commit();
$seqAfter = (int) $pdo->query('SELECT ap_next_bill_seq FROM tenants WHERE id = 1')->fetchColumn();
$a('seq advanced to 43 (durable through outer commit)', $seqAfter === 43);

// Case B: no outer tx — must still work standalone.
$ref2 = apNextInternalRef(1);
$a('apNextInternalRef without outer tx still works', preg_match('/^BILL-\d{4}-0043$/', $ref2) === 1, $ref2);
$a('and is durable when helper owns the tx',
    (int) $pdo->query('SELECT ap_next_bill_seq FROM tenants WHERE id = 1')->fetchColumn() === 44);

// Same exercise for billingNextInvoiceNumber.
$libBilling = $replaceForUpdate((string) file_get_contents('/app/modules/billing/lib/billing.php'));
preg_match('/(\/\*\*[^\/]*?Atomically allocate the next invoice number[^\/]*?\*\/\s*function billingNextInvoiceNumber.*?^\})/sm', $libBilling, $m2);
if (!empty($m2[1])) eval($m2[1]);

$pdo->beginTransaction();
$caughtB = null; $inv = null;
try { $inv = billingNextInvoiceNumber(1); } catch (\Throwable $e) { $caughtB = $e->getMessage(); }
$a('billingNextInvoiceNumber inside outer tx succeeds', $caughtB === null, $caughtB ?? '');
$a('returns INV-YYYY-0017 format', $inv !== null && preg_match('/^INV-\d{4}-0017$/', $inv) === 1, (string) $inv);
$pdo->commit();

echo "\n3. The Create-Bill API handler still wraps in cf_begin_transaction()\n";
$billsApi = (string) file_get_contents('/app/modules/ap/api/bills.php');
$a('POST/create still uses cf_begin_transaction',
    (bool) preg_match("/if \\(\\\$method === 'POST' && \\\$action === ''\\) \\{.*?cf_begin_transaction\\(\\);/s", $billsApi));
$a('and calls apNextInternalRef inside the tx',
    (bool) preg_match("/cf_begin_transaction\\(\\);.*?apNextInternalRef/s", $billsApi));

echo "\n— pass={$pass}  fail={$fail}\n";
exit($fail === 0 ? 0 : 1);
