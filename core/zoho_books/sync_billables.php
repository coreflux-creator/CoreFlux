<?php
/**
 * Zoho Books — Slice 4: Bills + Payments push.
 *
 * Bills target `/books/v3/bills`. Payment target `/books/v3/vendorpayments`.
 * Account resolution reuses Slice 2's `zohoBooksResolveAccountRef`,
 * vendor resolution reuses Slice 3's mapping cache with a Zoho contacts
 * fallback.
 *
 * Public surface:
 *   zohoBooksResolveVendorRef(int $tid, string $name): ?array
 *   zohoBooksBuildBillPayload(array $bill, array $lines, array $vendorRef, callable $resolveAccount): array
 *   zohoBooksSyncBills(int $tid, ?int $userId, array $opts=[]): array
 *   zohoBooksSyncPayments(int $tid, ?int $userId, array $opts=[]): array
 */
declare(strict_types=1);

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/sync_je.php';   // ZOHO_BOOKS_SOURCE + zohoBooksResolveAccountRef
require_once __DIR__ . '/../integrations/entity_mappings.php';

function zohoBooksResolveVendorRef(int $tenantId, string $name): ?array
{
    $name = trim($name);
    if ($name === '') return null;
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT id FROM ap_vendors_index WHERE tenant_id = :t AND vendor_name = :n LIMIT 1');
    $stmt->execute(['t' => $tenantId, 'n' => $name]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if ($row) {
        $m = mappingFindExternal($tenantId, ZOHO_BOOKS_SOURCE, 'vendor', (int) $row['id']);
        if ($m) {
            $snap = !empty($m['payload_snapshot']) ? json_decode((string) $m['payload_snapshot'], true) : null;
            return ['value' => (string) $m['external_id'],
                    'name'  => is_array($snap) ? (string) ($snap['contact_name'] ?? $name) : $name];
        }
    }
    try {
        $resp = zohoBooksCall($tenantId, 'GET', '/books/v3/contacts', null, [
            'contact_name' => $name, 'contact_type' => 'vendor', 'per_page' => 1,
        ]);
    } catch (\Throwable $_) { return null; }
    $contacts = $resp['contacts'] ?? [];
    if (!is_array($contacts) || count($contacts) === 0) return null;
    $hit  = $contacts[0];
    $zoId = (string) ($hit['contact_id'] ?? '');
    if ($zoId === '') return null;
    if ($row) mappingUpsert($tenantId, ZOHO_BOOKS_SOURCE, 'vendor', $zoId, (int) $row['id'], $hit, 'pull');
    return ['value' => $zoId, 'name' => (string) ($hit['contact_name'] ?? $name)];
}

function zohoBooksBuildBillPayload(array $bill, array $lines, array $vendorRef, callable $resolveAccount): array
{
    $payload = [
        'vendor_id'    => (string) $vendorRef['value'],
        'bill_number'  => substr((string) ($bill['bill_number'] ?? ''), 0, 100),
        'date'         => (string) ($bill['issue_date'] ?? date('Y-m-d')),
        'due_date'     => (string) ($bill['due_date']   ?? date('Y-m-d')),
        'notes'        => (string) ($bill['memo'] ?? ''),
        'line_items'   => [],
    ];
    foreach ($lines as $line) {
        $amount = (float) ($line['amount'] ?? 0);
        if ($amount <= 0) continue;
        $acct = $resolveAccount((int) ($line['account_id'] ?? 0));
        if (!$acct) {
            $payload['line_items'][] = ['_unresolved_account_id' => (int) ($line['account_id'] ?? 0)];
            continue;
        }
        $payload['line_items'][] = [
            'account_id'  => (string) $acct['value'],
            'description' => (string) ($line['description'] ?? ''),
            'amount'      => round($amount, 2),
        ];
    }
    return $payload;
}

function zohoBooksSyncBills(int $tenantId, ?int $userId, array $opts = []): array
{
    $start = microtime(true);
    $limit  = max(1, min(500, (int) ($opts['limit'] ?? 50)));
    $dryRun = !empty($opts['dry_run']);

    $conn = zohoBooksConnection($tenantId);
    if (!$conn || $conn['status'] !== 'active' || (string) $conn['organization_id'] === 'pending') {
        throw new \RuntimeException('Zoho Books is not connected for this tenant');
    }
    $cfg = zohoBooksSyncConfigRead($tenantId);
    if (!in_array($cfg['bills'] ?? 'off', ['push', 'two_way'], true)) {
        throw new \RuntimeException('Bills direction is not push/two_way for this tenant');
    }

    $pdo = getDB();
    $stmt = $pdo->prepare(
        "SELECT b.id, b.bill_number, b.vendor_name, b.issue_date, b.due_date, b.memo, b.status
           FROM ap_bills b
      LEFT JOIN external_entity_mappings m
             ON m.tenant_id = b.tenant_id
            AND m.source_system = ?
            AND m.internal_entity_type = 'bill'
            AND m.internal_entity_id = b.id
          WHERE b.tenant_id = ?
            AND b.status IN ('approved','open','partially_paid','paid')
            AND m.id IS NULL
       ORDER BY b.issue_date ASC, b.id ASC
          LIMIT " . (int) $limit
    );
    $stmt->execute([ZOHO_BOOKS_SOURCE, $tenantId]);
    $bills = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $pushed = 0; $skipped = 0; $failed = 0; $results = [];

    foreach ($bills as $bill) {
        $bid = (int) $bill['id'];
        // tenant-leak-allow: defense-in-depth — caller scoped row by tenant_id before this id-only read
        $ls = $pdo->prepare('SELECT id, line_no, description, amount, account_id FROM ap_bill_lines WHERE bill_id = :id ORDER BY line_no, id');
        $ls->execute(['id' => $bid]);
        $lines = $ls->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $vendorRef = zohoBooksResolveVendorRef($tenantId, (string) $bill['vendor_name']);
        if (!$vendorRef) {
            $skipped++;
            $results[] = ['bill_id' => $bid, 'status' => 'skipped', 'reason' => 'vendor_unmapped'];
            zohoBooksAudit($tenantId, 'sync_bill_skip', [
                'entity_type' => 'bill', 'direction' => 'push',
                'actor_user_id' => $userId, 'items_skipped' => 1,
                'detail' => ['bill_id' => $bid, 'vendor_name' => $bill['vendor_name']],
            ]);
            continue;
        }
        $resolver = static fn (int $id) => zohoBooksResolveAccountRef($tenantId, $id);
        $payload  = zohoBooksBuildBillPayload($bill, $lines, $vendorRef, $resolver);
        $unresolved = [];
        foreach ($payload['line_items'] as $l) {
            if (isset($l['_unresolved_account_id'])) $unresolved[] = (int) $l['_unresolved_account_id'];
        }
        if (!empty($unresolved)) {
            $skipped++;
            $results[] = ['bill_id' => $bid, 'status' => 'skipped', 'reason' => 'unmapped_accounts', 'unresolved_account_ids' => array_values(array_unique($unresolved))];
            zohoBooksAudit($tenantId, 'sync_bill_skip', [
                'entity_type' => 'bill', 'direction' => 'push', 'actor_user_id' => $userId, 'items_skipped' => 1,
                'detail' => ['bill_id' => $bid, 'unresolved_account_ids' => array_values(array_unique($unresolved))],
            ]);
            continue;
        }
        if ($dryRun) { $pushed++; $results[] = ['bill_id' => $bid, 'status' => 'dry_run', 'payload' => $payload]; continue; }
        try {
            $resp = zohoBooksCall($tenantId, 'POST', '/books/v3/bills', $payload);
            $zoId = (string) ($resp['bill']['bill_id'] ?? '');
            if ($zoId === '') throw new \RuntimeException('Zoho returned no bill_id');
            mappingUpsert($tenantId, ZOHO_BOOKS_SOURCE, 'bill', $zoId, $bid, $payload, 'push');
            $pushed++;
            $results[] = ['bill_id' => $bid, 'zoho_id' => $zoId, 'status' => 'pushed'];
            zohoBooksAudit($tenantId, 'sync_bill_push', [
                'entity_type' => 'bill', 'direction' => 'push', 'ok' => true,
                'actor_user_id' => $userId, 'items_processed' => 1,
                'detail' => ['bill_id' => $bid, 'zoho_id' => $zoId],
            ]);
        } catch (\Throwable $e) {
            $failed++;
            $results[] = ['bill_id' => $bid, 'status' => 'failed', 'reason' => substr($e->getMessage(), 0, 300)];
            zohoBooksAudit($tenantId, 'sync_bill_push', [
                'entity_type' => 'bill', 'direction' => 'push', 'ok' => false,
                'actor_user_id' => $userId, 'items_failed' => 1,
                'detail' => ['bill_id' => $bid, 'error' => substr($e->getMessage(), 0, 500)],
            ]);
        }
    }

    $latency = (int) round((microtime(true) - $start) * 1000);
    zohoBooksAudit($tenantId, 'sync_bills', [
        'entity_type' => 'bill', 'direction' => 'push', 'ok' => ($failed === 0),
        'actor_user_id' => $userId,
        'items_processed' => $pushed, 'items_skipped' => $skipped, 'items_failed' => $failed,
        'detail' => ['considered' => count($bills), 'latency_ms' => $latency, 'dry_run' => $dryRun],
    ]);

    return [
        'pushed' => $pushed, 'skipped' => $skipped, 'failed' => $failed,
        'considered' => count($bills), 'latency_ms' => $latency, 'dry_run' => $dryRun,
        'results' => $results,
    ];
}

function zohoBooksSyncPayments(int $tenantId, ?int $userId, array $opts = []): array
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
        "SELECT p.id, p.payment_date, p.amount, p.vendor_name, p.bank_account_id, p.reference, p.method
           FROM ap_payments p
      LEFT JOIN external_entity_mappings m
             ON m.tenant_id = p.tenant_id
            AND m.source_system = ?
            AND m.internal_entity_type = 'payment'
            AND m.internal_entity_id = p.id
          WHERE p.tenant_id = ?
            AND p.status IN ('issued','paid','approved')
            AND m.id IS NULL
       ORDER BY p.payment_date ASC, p.id ASC
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
            continue;
        }
        $bankAcct = (int) $p['bank_account_id'] > 0 ? zohoBooksResolveAccountRef($tenantId, (int) $p['bank_account_id']) : null;
        if (!$bankAcct) {
            $skipped++;
            $results[] = ['payment_id' => $pid, 'status' => 'skipped', 'reason' => 'bank_account_unmapped'];
            continue;
        }
        $payload = [
            'vendor_id'        => (string) $vendorRef['value'],
            'paid_through_account_id' => (string) $bankAcct['value'],
            'payment_mode'     => (string) ($p['method'] ?? 'check'),
            'amount'           => round((float) $p['amount'], 2),
            'date'             => (string) ($p['payment_date'] ?? date('Y-m-d')),
            'reference_number' => (string) ($p['reference'] ?? ''),
        ];
        if ($dryRun) { $pushed++; $results[] = ['payment_id' => $pid, 'status' => 'dry_run', 'payload' => $payload]; continue; }
        try {
            $resp = zohoBooksCall($tenantId, 'POST', '/books/v3/vendorpayments', $payload);
            $zoId = (string) ($resp['vendorpayment']['payment_id'] ?? '');
            if ($zoId === '') throw new \RuntimeException('Zoho returned no payment_id');
            mappingUpsert($tenantId, ZOHO_BOOKS_SOURCE, 'payment', $zoId, $pid, $payload, 'push');
            $pushed++;
            $results[] = ['payment_id' => $pid, 'zoho_id' => $zoId, 'status' => 'pushed'];
            zohoBooksAudit($tenantId, 'sync_payment_push', [
                'entity_type' => 'payment', 'direction' => 'push', 'ok' => true,
                'actor_user_id' => $userId, 'items_processed' => 1,
                'detail' => ['payment_id' => $pid, 'zoho_id' => $zoId],
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
        'entity_type' => 'payment', 'direction' => 'push', 'ok' => ($failed === 0),
        'actor_user_id' => $userId,
        'items_processed' => $pushed, 'items_skipped' => $skipped, 'items_failed' => $failed,
        'detail' => ['considered' => count($payments), 'latency_ms' => $latency, 'dry_run' => $dryRun],
    ]);

    return [
        'pushed' => $pushed, 'skipped' => $skipped, 'failed' => $failed,
        'considered' => count($payments), 'latency_ms' => $latency, 'dry_run' => $dryRun,
        'results' => $results,
    ];
}
