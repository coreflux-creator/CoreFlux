<?php
/**
 * QBO Slice 4b — BillPayment push driver.
 *
 * Pushes CoreFlux ap_payments (status='sent' or 'cleared') into QBO
 * BillPayment. Requires:
 *   - vendor mapping (created during Slice 3 customer/vendor pull, or
 *     by the Bill pusher's qboResolveVendorRef)
 *   - at least one already-pushed Bill mapped via external_entity_mappings
 *     (entity_type='bill') so we can build BillPayment.Line[].LinkedTxn
 *
 * Without bill applications data in CoreFlux we can't allocate the
 * payment across multiple bills — so the MVP behaviour is:
 *   1. Find every Bill for this vendor that has a CoreFlux<->QBO mapping
 *      and an open amount_due > 0.
 *   2. Apply payment.amount FIFO until exhausted.
 *
 * Idempotent via external_entity_mappings (entity_type='payment').
 */
declare(strict_types=1);

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/sync_je.php';      // QBO_SOURCE
require_once __DIR__ . '/sync_bills.php';   // qboResolveVendorRef
require_once __DIR__ . '/../integrations/entity_mappings.php';

function qboBuildBillPaymentPayload(array $payment, array $vendorRef, array $linkedTxns): array
{
    return [
        'TxnDate'   => (string) ($payment['pay_date'] ?? date('Y-m-d')),
        'VendorRef' => ['value' => $vendorRef['value'], 'name' => $vendorRef['name']],
        'PayType'   => $payment['method'] === 'check' ? 'Check' : 'CreditCard',
        'TotalAmt'  => round((float) ($payment['amount'] ?? 0), 2),
        'PrivateNote' => (string) ($payment['notes'] ?? ''),
        'Line'      => array_map(static function ($t) {
            return [
                'Amount'     => round((float) $t['amount'], 2),
                'LinkedTxn'  => [['TxnId' => (string) $t['qbo_bill_id'], 'TxnType' => 'Bill']],
            ];
        }, $linkedTxns),
    ];
}

function qboSyncBillPayments(int $tenantId, ?int $userId, array $opts = []): array
{
    $start = microtime(true);
    $limit  = max(1, min(500, (int) ($opts['limit'] ?? 50)));
    $dryRun = !empty($opts['dry_run']);

    $conn = qboConnection($tenantId);
    if (!$conn || $conn['status'] !== 'active') {
        throw new \RuntimeException('QuickBooks is not connected for this tenant');
    }
    $cfg = qboSyncConfigRead($tenantId);
    if (!in_array($cfg['payments'] ?? 'off', ['push', 'two_way'], true)) {
        throw new \RuntimeException('Payments direction is not push/two_way for this tenant');
    }
    $realm = (string) $conn['realm_id'];

    $pdo = getDB();
    $stmt = $pdo->prepare(
        "SELECT p.id, p.tenant_id, p.vendor_name, p.pay_date, p.method, p.amount, p.notes
           FROM ap_payments p
      LEFT JOIN external_entity_mappings m
             ON m.tenant_id = p.tenant_id
            AND m.source_system = ?
            AND m.internal_entity_type = 'payment'
            AND m.internal_entity_id = p.id
          WHERE p.tenant_id = ?
            AND p.status IN ('sent','cleared')
            AND m.id IS NULL
       ORDER BY p.pay_date ASC, p.id ASC
          LIMIT " . (int) $limit
    );
    $stmt->execute([QBO_SOURCE, $tenantId]);
    $payments = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $pushed = 0; $skipped = 0; $failed = 0;
    $results = [];

    foreach ($payments as $p) {
        $pid = (int) $p['id'];
        $vendorRef = qboResolveVendorRef($tenantId, (string) $p['vendor_name']);
        if (!$vendorRef) {
            $skipped++;
            $results[] = ['payment_id' => $pid, 'status' => 'skipped', 'reason' => 'vendor_unmapped'];
            qboAudit($tenantId, 'sync_payment_skip', ['entity_type' => 'payment', 'direction' => 'push', 'actor_user_id' => $userId, 'items_skipped' => 1, 'detail' => ['payment_id' => $pid, 'reason' => 'vendor_unmapped']]);
            continue;
        }
        // Find mapped bills for this vendor with positive amount_due.
        // Allocate FIFO until payment is exhausted.
        $billStmt = $pdo->prepare(
            'SELECT b.id, b.amount_due, m.external_id AS qbo_bill_id
               FROM ap_bills b
          JOIN external_entity_mappings m
                 ON m.tenant_id = b.tenant_id
                AND m.source_system = ?
                AND m.internal_entity_type = ?
                AND m.internal_entity_id = b.id
              WHERE b.tenant_id = ?
                AND b.vendor_name = ?
                AND b.amount_due > 0
           ORDER BY b.bill_date ASC, b.id ASC'
        );
        $billStmt->execute([QBO_SOURCE, 'bill', $tenantId, (string) $p['vendor_name']]);
        $bills = $billStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        if (!$bills) {
            $skipped++;
            $results[] = ['payment_id' => $pid, 'status' => 'skipped', 'reason' => 'no_mapped_bills_with_balance'];
            qboAudit($tenantId, 'sync_payment_skip', ['entity_type' => 'payment', 'direction' => 'push', 'actor_user_id' => $userId, 'items_skipped' => 1, 'detail' => ['payment_id' => $pid, 'reason' => 'no_mapped_bills_with_balance']]);
            continue;
        }
        $remaining = (float) $p['amount'];
        $linkedTxns = [];
        foreach ($bills as $b) {
            if ($remaining <= 0.001) break;
            $apply = min((float) $b['amount_due'], $remaining);
            if ($apply <= 0) continue;
            $linkedTxns[] = ['qbo_bill_id' => (string) $b['qbo_bill_id'], 'amount' => $apply];
            $remaining -= $apply;
        }
        if (empty($linkedTxns)) {
            $skipped++;
            $results[] = ['payment_id' => $pid, 'status' => 'skipped', 'reason' => 'no_allocation'];
            continue;
        }
        $payload = qboBuildBillPaymentPayload($p, $vendorRef, $linkedTxns);

        if ($dryRun) {
            $pushed++;
            $results[] = ['payment_id' => $pid, 'status' => 'dry_run', 'payload' => $payload];
            continue;
        }
        try {
            $resp = qboCall($tenantId, 'POST', '/v3/company/' . $realm . '/billpayment?minorversion=65', $payload);
            $qboId = (string) ($resp['BillPayment']['Id'] ?? '');
            if ($qboId === '') throw new \RuntimeException('QBO accepted but returned no BillPayment.Id');
            mappingUpsert($tenantId, QBO_SOURCE, 'payment', $qboId, $pid, $payload, 'push');
            $pushed++;
            $results[] = ['payment_id' => $pid, 'qbo_id' => $qboId, 'status' => 'pushed', 'applied_to' => count($linkedTxns)];
            qboAudit($tenantId, 'sync_payment_push', ['entity_type' => 'payment', 'direction' => 'push', 'actor_user_id' => $userId, 'items_processed' => 1, 'detail' => ['payment_id' => $pid, 'qbo_id' => $qboId, 'applied_to' => count($linkedTxns)]]);
        } catch (\Throwable $e) {
            $failed++;
            $results[] = ['payment_id' => $pid, 'status' => 'failed', 'reason' => substr($e->getMessage(), 0, 300)];
            qboAudit($tenantId, 'sync_payment_push', ['entity_type' => 'payment', 'direction' => 'push', 'ok' => false, 'actor_user_id' => $userId, 'items_failed' => 1, 'detail' => ['payment_id' => $pid, 'error' => substr($e->getMessage(), 0, 500)]]);
        }
    }
    $latency = (int) round((microtime(true) - $start) * 1000);
    qboAudit($tenantId, 'sync_payments', [
        'entity_type' => 'payment', 'direction' => 'push',
        'ok' => ($failed === 0),
        'actor_user_id'   => $userId,
        'items_processed' => $pushed,
        'items_skipped'   => $skipped,
        'items_failed'    => $failed,
        'detail' => ['considered' => count($payments), 'latency_ms' => $latency, 'dry_run' => $dryRun],
    ]);
    return [
        'pushed' => $pushed, 'skipped' => $skipped, 'failed' => $failed,
        'considered' => count($payments), 'latency_ms' => $latency, 'dry_run' => $dryRun,
        'results' => $results,
    ];
}
