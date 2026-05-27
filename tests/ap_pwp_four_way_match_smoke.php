<?php
/**
 * Smoke — AP 4-Way Match: Pay-When-Paid (PWP) send-time gate.
 *
 * The 4th match (after PO + receipt + bill = the existing 3-way match)
 * is: client payment must be received for the same hours before vendor
 * disbursement is releasable. Operationally:
 *
 *   1. AR invoice is issued from a placement billing cycle.
 *   2. apPwpAutoLinkForArInvoice() links any sibling AP bills (same
 *      placement + period) carrying PWP terms — sets
 *      pwp_status='awaiting_ar'.
 *   3. The AP payment for those bills enters draft/queued like normal.
 *   4. ✘ Send is BLOCKED while pwp_status='awaiting_ar' (this smoke).
 *   5. AR cash receipt → billingAllocatePayment() runs
 *      apPwpReleaseForArInvoice() which flips pwp_status='triggered'
 *      and (optionally) auto-approves the AP bill.
 *   6. ✔ Send now succeeds.
 *
 * This file asserts gate enforcement at every release-to-rail surface
 * (single `send` action + `originate_batch`) and the helper that
 * detects "awaiting AR" bills allocated to a payment. Plus the UI-
 * facing list payload now carries `pwp_blocked` so the operator sees
 * the gate before clicking.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$lib  = (string) file_get_contents('/app/modules/ap/lib/pwp.php');
$api  = (string) file_get_contents('/app/modules/ap/api/payments.php');

echo "\n1. apPwpAllocatedBillsAwaitingAr() helper exists & is shaped right\n";
$a('helper function declared',
    str_contains($lib, 'function apPwpAllocatedBillsAwaitingAr(int $tenantId, int $paymentId): array'));
$a('helper queries ap_payment_allocations JOIN ap_bills',
    str_contains($lib, 'FROM ap_payment_allocations a')
    && str_contains($lib, 'JOIN ap_bills b ON b.id = a.bill_id'));
$a('helper filters on pwp_status = awaiting_ar',
    (bool) preg_match('/b\.pwp_status\s*=\s*"awaiting_ar"/', $lib));
$a('helper enforces tenant scope explicitly',
    str_contains($lib, 'b.tenant_id  = :t'));
$a('helper returns the blocking refs the UI can display',
    str_contains($lib, 'b.internal_ref, b.vendor_name, b.linked_ar_invoice_id'));

echo "\n2. AP payments API wires the gate\n";
$a('payments.php requires the pwp lib',
    str_contains($api, "require_once __DIR__ . '/../lib/pwp.php'"));

echo "\n3. Single-action `send` blocks when allocations are awaiting AR\n";
$a('send-action calls apPwpAllocatedBillsAwaitingAr(tid, id)',
    str_contains($api, '$pwpBlocked = apPwpAllocatedBillsAwaitingAr($tid, $id);'));
$a('returns HTTP 409 with structured pwp_awaiting_ar code',
    str_contains($api, "'code' => 'pwp_awaiting_ar'")
    && str_contains($api, "'blocked_bill_refs' => \$refs")
    && (bool) preg_match("/api_error\(\s*'Pay-when-paid gate:/", $api));
$a('PWP gate check sits AFTER the SoD check and disputed/void check',
    strpos($api, "Segregation of duties: you cannot release your own payment.")
    < strpos($api, '$pwpBlocked = apPwpAllocatedBillsAwaitingAr'));
$a('PWP gate fires BEFORE the UPDATE that flips payment to "sent"',
    strpos($api, '$pwpBlocked = apPwpAllocatedBillsAwaitingAr')
    < strpos($api, 'SET status = "sent"'));

echo "\n4. Batch `originate_batch` blocks the WHOLE batch on any awaiting-AR bill\n";
$a('batch path iterates rows and collects pwpBatchBlocked',
    str_contains($api, '$pwpBatchBlocked = [];')
    && str_contains($api, '$blocked = apPwpAllocatedBillsAwaitingAr($tid, (int) $r[\'id\']);'));
$a('batch refuses with 409 + pwp_awaiting_ar code + per-payment detail',
    str_contains($api, 'payment(s) in this batch have bills awaiting AR collection')
    && str_contains($api, "'code' => 'pwp_awaiting_ar', 'blocked' => \$pwpBatchBlocked"));
$a('batch gate fires BEFORE paymentRailsBuildItem (no bank decrypt for a blocked batch)',
    strpos($api, '$pwpBatchBlocked = [];')
    < strpos($api, 'paymentRailsBuildItem'));

echo "\n5. GET list attaches pwp_blocked + pwp_blocked_count per row\n";
$a('list path computes blocked counts via single GROUP BY query',
    str_contains($api, "SELECT a.payment_id, COUNT(*) AS blocked_count")
    && str_contains($api, "pwp_status = 'awaiting_ar'")
    && str_contains($api, 'GROUP BY a.payment_id'));
$a('list response attaches pwp_blocked_count + pwp_blocked',
    str_contains($api, "\$row['pwp_blocked_count']")
    && str_contains($api, "\$row['pwp_blocked']       = (\$row['pwp_blocked_count'] ?? 0) > 0;"));
$a('list degrades gracefully on PDO error (sets pwp_blocked=false)',
    (bool) preg_match("/catch \(\\\\Throwable \\\$e\) \{\s*\/\/ Non-fatal/", $api));

echo "\n6. End-to-end traceability proof — release path still in place\n";
$billingLib = (string) file_get_contents('/app/modules/billing/lib/billing.php');
$invoicesApi = (string) file_get_contents('/app/modules/billing/api/invoices.php');
$a('AR cash receipt path (billing.php) still calls apPwpReleaseForArInvoice',
    str_contains($billingLib, 'apPwpReleaseForArInvoice($tenantId, (int) $a[\'invoice_id\']'));
$a('AR invoice issuance still calls apPwpAutoLinkForArInvoice',
    str_contains($invoicesApi, 'apPwpAutoLinkForArInvoice($tid, (int) $c[\'id\']'));

echo "\n7. PHP syntax\n";
foreach ([
    '/app/modules/ap/api/payments.php',
    '/app/modules/ap/lib/pwp.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $a("php -l {$f}", $rc === 0, implode("\n", $out));
}

echo "\n=========================================\n";
echo "AP 4-way match (PWP) gate smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
