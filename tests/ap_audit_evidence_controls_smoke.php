<?php
/**
 * AP audit evidence controls smoke.
 *
 * Locks the rule that AP bill approval and payment release evidence writes
 * through the platform audit writer with source-row snapshots.
 */
declare(strict_types=1);

$ROOT = realpath(__DIR__ . '/..');
$pass = 0;
$fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { echo "  OK  {$msg}\n"; $pass++; }
    else       { echo "  BAD {$msg}\n"; $fail++; }
};
$lint = function (string $rel) use ($ROOT): bool {
    $out = [];
    $rc = 0;
    exec('php -l ' . escapeshellarg("{$ROOT}/{$rel}") . ' 2>&1', $out, $rc);
    return $rc === 0;
};
$containsAll = function (string $haystack, array $needles): bool {
    foreach ($needles as $needle) {
        if (!str_contains($haystack, (string) $needle)) return false;
    }
    return true;
};

$lib = (string) file_get_contents("{$ROOT}/modules/ap/lib/ap.php");
$payments = (string) file_get_contents("{$ROOT}/modules/ap/api/payments.php");
$bridge = (string) file_get_contents("{$ROOT}/modules/ap/lib/workflow_bridge.php");
$sync = (string) file_get_contents("{$ROOT}/modules/ap/lib/workflow_sync.php");
$auditDoc = (string) file_get_contents("{$ROOT}/docs/AUDIT_GOVERNANCE.md");
$alignment = (string) file_get_contents("{$ROOT}/docs/PRODUCT_ARCHITECTURE_ALIGNMENT.md");

echo "Files parse\n";
foreach ([
    'modules/ap/lib/ap.php',
    'modules/ap/api/payments.php',
    'modules/ap/lib/workflow_bridge.php',
    'modules/ap/lib/workflow_sync.php',
] as $rel) {
    $a("php -l {$rel}", $lint($rel));
}

echo "\nAP audit writer\n";
$a('apAudit requires shared platform audit writer',
    str_contains($lib, "require_once __DIR__ . '/../../../core/audit.php'")
    && str_contains($lib, 'platformAuditLogWrite('));
$a('apAudit accepts platform audit options',
    str_contains($lib, 'function apAudit(string $event, array $meta = [], ?int $targetId = null, array $opts = [])'));
$a('apAudit stamps AP source/object metadata',
    $containsAll($lib, ["'object_type' => apAuditObjectType(\$event)", "'source' => \$meta['source'] ?? 'ap'"]));
$a('apAudit maps high-risk AP object types',
    $containsAll($lib, ['ap_payment', 'ap_bill', 'ap_expense_report', 'ap_vendor', 'ap_1099']));
$a('apAudit no longer inserts audit_log directly',
    !preg_match('/function apAudit[\s\S]*INSERT INTO audit_log/', $lib));

echo "\nBill workflow evidence\n";
$a('submit bridge snapshots bill before and after routing',
    $containsAll($bridge, [
        'apWorkflowBillRow($tenantId, $billId)',
        "'before' => \$beforeBill",
        "'after' => \$afterBill",
    ]));
$a('workflow sync snapshots rejected bills',
    $containsAll($sync, [
        "apAudit('ap.bill.approval_rejected'",
        "'before' => \$beforeBill",
        "'after' => \$updated",
    ]));
$a('workflow sync snapshots final approved bills',
    $containsAll($sync, [
        "apAudit('ap.bill.approved'",
        "'before' => \$beforeBill",
        "'after' => \$updated",
        "accountingTryEnqueueDraft(\$tenantId, 'bill', \$bill, \$userId);",
    ]));

echo "\nPayment release evidence\n";
$a('payment audit row helpers exist',
    str_contains($payments, 'function apPaymentAuditRow(')
    && str_contains($payments, 'function apPaymentAuditRows(')
    && str_contains($payments, 'WHERE tenant_id = :t AND id = :id'));
$a('draft and allocation audits carry source snapshots',
    $containsAll($payments, [
        "apAudit('ap.payment.drafted'",
        "'after' => apPaymentAuditRow(\$tid, \$id)",
        "apAudit('ap.payment.allocated'",
        "'before' => \$row",
        "'after' => \$updated",
    ]));
$a('send and blocked release audits carry payment snapshots',
    $containsAll($payments, [
        "apAudit('ap.payment.sent'",
        "'after' => \$sent",
        "apAudit('ap.payment.release_blocked'",
        "'before' => \$payment",
    ]));
$a('batch origination audits before/after payment rows',
    $containsAll($payments, [
        "apAudit('ap.payment.batch_originated'",
        '$originatedRows = apPaymentAuditRows($tid, $ids)',
        "'before' => \$rows",
        "'after' => \$originatedRows",
    ]));
$a('rail originate, clear, and void audits snapshot payment rows',
    $containsAll($payments, [
        "apAudit('ap.payment.originated'",
        "'after' => \$originated",
        "apAudit('ap.payment.cleared'",
        "'after' => \$cleared",
        "apAudit('ap.payment.voided'",
        "'after' => \$voided",
    ]));

echo "\nDocs\n";
$a('audit governance names AP controls',
    str_contains($auditDoc, 'AP bill approvals and payment release'));
$a('architecture alignment records AP controls',
    $containsAll($alignment, [
        'Accounts Payable Bill And Payment Controls',
        '`apAudit` delegates to the shared `platformAuditLogWrite` writer',
        'AP payment draft, allocation, release, batch origination',
    ]));

echo "\nAP audit evidence controls smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
