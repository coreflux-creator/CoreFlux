<?php
/**
 * Zoho Books — Slice 4: Vendor payment push driver.
 *
 * Mirrors QBO Slice 4b (sync_payments.php) for Zoho's
 * `/books/v3/vendorpayments` endpoint. Pushes CoreFlux ap_payments
 * (status='sent' or 'cleared') into Zoho as vendor payments and
 * applies them FIFO across mapped open bills for the same vendor.
 *
 * Pre-requisites for a payment to push:
 *   - Vendor mapping (created by Slice 3 contacts pull or
 *     `zohoBooksResolveVendorRef()` from sync_bills.php).
 *   - At least one already-pushed Bill mapped via external_entity_mappings
 *     (entity_type='bill') so the payment can link a `bill_id`.
 *
 * Idempotent via external_entity_mappings (entity_type='payment').
 *
 * Zoho payload:
 *   { vendor_id, date, amount, payment_mode, description,
 *     bills: [{ bill_id, amount_applied }] }
 *
 * Public surface:
 *   zohoBooksBuildVendorPaymentPayload(array $payment, array $vendorRef, array $linkedTxns): array
 *   zohoBooksSyncVendorPayments(int $tid, ?int $userId, array $opts=[]): array
 */
declare(strict_types=1);

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/sync_je.php';      // ZOHO_BOOKS_SOURCE
require_once __DIR__ . '/sync_bills.php';   // zohoBooksResolveVendorRef
require_once __DIR__ . '/../integrations/entity_mappings.php';

function zohoBooksBuildVendorPaymentPayload(array $payment, array $vendorRef, array $linkedTxns): array
{
    $method = strtolower((string) ($payment['method'] ?? 'check'));
    // Zoho accepts: cash | check | creditcard | banktransfer | ach | wire | ...
    $mode = match ($method) {
        'check'        => 'check',
        'ach', 'wire'  => 'banktransfer',
        'cash'         => 'cash',
        'credit_card', 'creditcard' => 'creditcard',
        default        => 'banktransfer',
    };
    return [
        'vendor_id'    => (string) $vendorRef['value'],
        'date'         => (string) ($payment['pay_date'] ?? date('Y-m-d')),
        'amount'       => round((float) ($payment['amount'] ?? 0), 2),
        'payment_mode' => $mode,
        'description'  => (string) ($payment['notes'] ?? ''),
        'bills'        => array_map(static function ($t) {
            return [
                'bill_id'        => (string) $t['zoho_bill_id'],
                'amount_applied' => round((float) $t['amount'], 2),
            ];
        }, $linkedTxns),
    ];
}

function zohoBooksSyncVendorPayments(int $tenantId, ?int $userId, array $opts = []): array
{
    $start = microtime(true);
    $limit  = max(1, min(500, (int) ($opts['limit'] ?? 50)));
    $dryRun = !empty($opts['dry_run']);

    $conn = zohoBooksConnection($tenantId);
    if (!$conn || $conn['status'] !== 'active' || (string) $conn['organization_id'] === 'pending') {
        throw new \RuntimeException('Zoho Books is not connected for this tenant');
    }
    $cfg = zohoBooksSyncConfigRead($tenantId);
    if (!in_array($cfg['payments'] ?? 'off', ['push', 'two_way'], true)) {
        throw new \RuntimeException('Payments direction is not push/two_way for this tenant');
    }

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
    $stmt->execute([ZOHO_BOOKS_SOURCE, $tenantId]);
    $payments = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $pushed = 0; $skipped = 0; $failed = 0; $results = [];

    foreach ($payments as $p) {
        $pid = (int) $p['id'];
        $vendorRef = zohoBooksResolveVendorRef($tenantId, (string) $p['vendor_name']);
        if (!$vendorRef) {
            $skipped++;
            $results[] = ['payment_id' => $pid, 'status' => 'skipped', 'reason' => 'vendor_unmapped'];
            zohoBooksAudit($tenantId, 'sync_payment_skip', [
                'entity_type' => 'payment', 'direction' => 'push',
                'actor_user_id' => $userId, 'items_skipped' => 1,
                'detail' => ['payment_id' => $pid, 'reason' => 'vendor_unmapped'],
            ]);
            continue;
        }

        // Mapped open bills for this vendor — FIFO allocate.
        $billStmt = $pdo->prepare(
            'SELECT b.id, b.amount_due, m.external_id AS zoho_bill_id
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
        $billStmt->execute([ZOHO_BOOKS_SOURCE, 'bill', $tenantId, (string) $p['vendor_name']]);
        $bills = $billStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        if (!$bills) {
            $skipped++;
            $results[] = ['payment_id' => $pid, 'status' => 'skipped', 'reason' => 'no_mapped_bills_with_balance'];
            zohoBooksAudit($tenantId, 'sync_payment_skip', [
                'entity_type' => 'payment', 'direction' => 'push',
                'actor_user_id' => $userId, 'items_skipped' => 1,
                'detail' => ['payment_id' => $pid, 'reason' => 'no_mapped_bills_with_balance'],
            ]);
            continue;
        }
        $remaining = (float) $p['amount'];
        $linkedTxns = [];
        foreach ($bills as $b) {
            if ($remaining <= 0.001) break;
            $apply = min((float) $b['amount_due'], $remaining);
            if ($apply <= 0) continue;
            $linkedTxns[] = ['zoho_bill_id' => (string) $b['zoho_bill_id'], 'amount' => $apply];
            $remaining -= $apply;
        }
        if (empty($linkedTxns)) {
            $skipped++;
            $results[] = ['payment_id' => $pid, 'status' => 'skipped', 'reason' => 'no_allocation'];
            continue;
        }
        $payload = zohoBooksBuildVendorPaymentPayload($p, $vendorRef, $linkedTxns);

        if ($dryRun) {
            $pushed++;
            $results[] = ['payment_id' => $pid, 'status' => 'dry_run', 'payload' => $payload];
            continue;
        }
        try {
            $resp = zohoBooksCall($tenantId, 'POST', '/books/v3/vendorpayments', $payload);
            $zoId = (string) ($resp['vendorpayment']['payment_id'] ?? $resp['vendorpayment']['vendor_payment_id'] ?? '');
            if ($zoId === '') throw new \RuntimeException('Zoho Books accepted but returned no vendorpayment.payment_id');
            mappingUpsert($tenantId, ZOHO_BOOKS_SOURCE, 'payment', $zoId, $pid, $payload, 'push');
            $pushed++;
            $results[] = ['payment_id' => $pid, 'zoho_id' => $zoId, 'status' => 'pushed', 'applied_to' => count($linkedTxns)];
            zohoBooksAudit($tenantId, 'sync_payment_push', [
                'entity_type' => 'payment', 'direction' => 'push',
                'ok' => true, 'actor_user_id' => $userId, 'items_processed' => 1,
                'detail' => ['payment_id' => $pid, 'zoho_id' => $zoId, 'applied_to' => count($linkedTxns)],
            ]);
        } catch (\Throwable $e) {
            $failed++;
            $results[] = ['payment_id' => $pid, 'status' => 'failed', 'reason' => substr($e->getMessage(), 0, 300)];
            zohoBooksAudit($tenantId, 'sync_payment_push', [
                'entity_type' => 'payment', 'direction' => 'push', 'ok' => false,
                'actor_user_id' => $userId, 'items_failed' => 1,
                'detail' => ['payment_id' => $pid, 'error' => substr($e->getMessage(), 0, 500)],
            ]);
        }
    }
    $latency = (int) round((microtime(true) - $start) * 1000);
    zohoBooksAudit($tenantId, 'sync_payments', [
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
