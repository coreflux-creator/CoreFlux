<?php
/** The AP release gate must scope allocations through their payment and bill. */
declare(strict_types=1);

$source = (string) file_get_contents(__DIR__ . '/../modules/ap/api/payments.php');
$start = strpos($source, 'function apPaymentReleaseIssue(');
$end = strpos($source, 'function apPaymentAuditRow(', $start ?: 0);
if ($start === false || $end === false) {
    throw new RuntimeException('AP payment release gate was not found.');
}
$gate = substr($source, $start, $end - $start);

$checks = [
    'Allocation joins its tenant-scoped payment' => str_contains(
        $gate, 'JOIN ap_payments p ON p.id = a.payment_id AND p.tenant_id = :t'
    ),
    'Bill tenant matches payment tenant' => str_contains(
        $gate, 'JOIN ap_bills b ON b.id = a.bill_id AND b.tenant_id = p.tenant_id'
    ),
    'Release gate never reads a nonexistent allocation tenant column' => !str_contains(
        $gate, 'a.tenant_id'
    ),
];
foreach ($checks as $label => $passed) {
    if (!$passed) throw new RuntimeException($label);
}

echo 'AP payment release scope: ' . count($checks) . " checks passed.\n";
