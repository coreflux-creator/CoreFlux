<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/tenant_scope.php';
require_once __DIR__ . '/lib/payment_links.php';

$opts = getopt('', ['tenant:', 'apply']);
$tenantId = (int) ($opts['tenant'] ?? 0);
if ($tenantId <= 0) {
    fwrite(STDERR, "Usage: php sim/repair_payment_links.php --tenant=ID [--apply]\n");
    exit(2);
}

$pdo = getDB();
$check = $pdo->prepare('SELECT is_simulation FROM tenants WHERE id = :id');
$check->execute(['id' => $tenantId]);
if ((int) $check->fetchColumn() !== 1) {
    fwrite(STDERR, "Refusing to repair a non-simulation tenant.\n");
    exit(3);
}
setRequestTenantId($tenantId);

$events = $pdo->prepare(
    'SELECT e.id, e.payload, e.event_date, e.journal_entry_id
       FROM accounting_events e
       JOIN accounting_journal_entries j
         ON j.tenant_id = e.tenant_id AND j.id = e.journal_entry_id
      WHERE e.tenant_id = :tenant_id AND e.status = "posted" AND j.status = "posted"
        AND e.event_type = "ap.payment.cleared" AND e.source_module = "ap"
        AND j.source_module = "ap" AND e.source_record_id LIKE "sim:%"
      ORDER BY e.id'
);
$events->execute(['tenant_id' => $tenantId]);
$pending = [];
foreach ($events->fetchAll(PDO::FETCH_ASSOC) as $event) {
    $payload = json_decode((string) $event['payload'], true);
    $billId = (int) ($payload['bill_id'] ?? 0);
    $paymentId = (int) ($payload['payment_id'] ?? 0);
    if ($billId <= 0 || $paymentId <= 0) throw new RuntimeException("Event {$event['id']} has no bill or payment ID");
    $payment = $pdo->prepare('SELECT p.id, p.entity_id, a.id AS allocation_id FROM ap_payments p
        LEFT JOIN ap_payment_allocations a ON a.payment_id = p.id AND a.bill_id = :bill_id
        WHERE p.tenant_id = :tenant_id AND p.id = :payment_id LIMIT 1');
    $payment->execute(['bill_id' => $billId, 'tenant_id' => $tenantId, 'payment_id' => $paymentId]);
    $record = $payment->fetch(PDO::FETCH_ASSOC);
    if (!$record || !$record['allocation_id'] || (int) ($record['entity_id'] ?? 0) === 0) {
        $pending[] = [$billId, $paymentId, (int) $event['journal_entry_id'], (string) $event['event_date']];
    }
}

printf("Simulation tenant %d: %d missing payment allocations.\n", $tenantId, count($pending));
if (!isset($opts['apply'])) {
    echo "Dry run only. Add --apply to link these cleared payments.\n";
    exit(0);
}
foreach ($pending as [$billId, $paymentId, $jeId, $payDate]) {
    simLinkClearedPayment($tenantId, $billId, $paymentId, $jeId, $payDate);
    printf("Linked payment #%d to bill #%d and JE #%d.\n", $paymentId, $billId, $jeId);
}
