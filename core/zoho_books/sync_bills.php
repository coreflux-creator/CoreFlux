<?php
/**
 * Zoho Books — Slice 4: Bill push driver.
 *
 * Mirrors QBO Slice 4b (sync_bills.php) for Zoho's `/books/v3/bills`
 * endpoint. Each line references a Zoho `account_id`, which the Slice 2
 * `zohoBooksResolveAccountRef()` helper already knows how to lift from
 * either an existing mapping or the COA mirror (Slice 3).
 *
 * Vendor mapping precedence:
 *   1. ap_vendors_index → external_entity_mappings (entity_type='vendor')
 *   2. Live lookup via `/books/v3/contacts?contact_type=vendor&contact_name=...`
 *      → upsert mapping when matched.
 *
 * Idempotent via external_entity_mappings (source='zoho_books',
 * entity_type='bill').
 *
 * Zoho `/books/v3/bills` POST payload:
 *   { vendor_id, bill_number, date, due_date, notes,
 *     line_items: [ { account_id, description, rate, quantity } ] }
 *
 * Public surface:
 *   zohoBooksResolveVendorRef(int $tid, string $vendorName): ?array
 *   zohoBooksBuildBillPayload(array $bill, array $lines, ?array $vendorRef, callable $resolveAccount): array
 *   zohoBooksSyncBills(int $tid, ?int $userId, array $opts=[]): array
 */
declare(strict_types=1);

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/sync_je.php';   // ZOHO_BOOKS_SOURCE + zohoBooksResolveAccountRef
require_once __DIR__ . '/../integrations/entity_mappings.php';

function zohoBooksResolveVendorRef(int $tenantId, string $vendorName): ?array
{
    $vendorName = trim($vendorName);
    if ($vendorName === '') return null;

    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT id FROM ap_vendors_index WHERE tenant_id = :t AND vendor_name = :n LIMIT 1');
    $stmt->execute(['t' => $tenantId, 'n' => $vendorName]);
    $vRow = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$vRow) return null;
    $vid = (int) $vRow['id'];

    $existing = mappingFindExternal($tenantId, ZOHO_BOOKS_SOURCE, 'vendor', $vid);
    if ($existing) {
        $snap = !empty($existing['payload_snapshot']) ? json_decode((string) $existing['payload_snapshot'], true) : null;
        return [
            'value' => (string) $existing['external_id'],
            'name'  => is_array($snap) ? (string) ($snap['contact_name'] ?? $snap['company_name'] ?? $vendorName) : $vendorName,
        ];
    }

    try {
        $resp = zohoBooksCall($tenantId, 'GET', '/books/v3/contacts', null, [
            'contact_name' => $vendorName,
            'contact_type' => 'vendor',
            'per_page'     => 1,
        ]);
    } catch (\Throwable $_) { return null; }
    $hits = $resp['contacts'] ?? [];
    if (!is_array($hits) || count($hits) === 0) return null;
    $hit = $hits[0];
    $zoId = (string) ($hit['contact_id'] ?? '');
    if ($zoId === '') return null;
    mappingUpsert($tenantId, ZOHO_BOOKS_SOURCE, 'vendor', $zoId, $vid, $hit, 'pull');
    return ['value' => $zoId, 'name' => (string) ($hit['contact_name'] ?? $vendorName)];
}

function zohoBooksBuildBillPayload(array $bill, array $lines, ?array $vendorRef, callable $resolveAccount): array
{
    $payload = [
        'vendor_id'   => $vendorRef ? (string) $vendorRef['value'] : '',
        'bill_number' => (string) ($bill['bill_number'] ?? ''),
        'date'        => (string) ($bill['bill_date'] ?? date('Y-m-d')),
        'due_date'    => (string) ($bill['due_date']  ?? date('Y-m-d')),
        'notes'       => (string) ($bill['notes_internal'] ?? ''),
        'line_items'  => [],
    ];
    foreach ($lines as $line) {
        $total = (float) ($line['total'] ?? 0);
        if ($total <= 0) continue;
        $code = trim((string) ($line['gl_expense_account_code'] ?? ''));
        if ($code === '') {
            $payload['line_items'][] = ['_missing_account_code' => true, '_line_id' => $line['id'] ?? null];
            continue;
        }
        $pdo = getDB();
        $stmt = $pdo->prepare('SELECT id FROM accounting_accounts WHERE tenant_id = :t AND code = :c LIMIT 1');
        $stmt->execute(['t' => (int) ($bill['tenant_id'] ?? 0), 'c' => $code]);
        $aRow = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$aRow) {
            $payload['line_items'][] = ['_unresolved_account_code' => $code];
            continue;
        }
        $ref = $resolveAccount((int) $aRow['id']);
        if (!$ref) {
            $payload['line_items'][] = ['_unresolved_account_id' => (int) $aRow['id'], '_account_code' => $code];
            continue;
        }
        $payload['line_items'][] = [
            'account_id'  => (string) $ref['value'],
            'description' => (string) ($line['description'] ?? ''),
            'rate'        => round($total, 2),
            'quantity'    => 1,
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
    $sql = "SELECT b.id, b.tenant_id, b.bill_number, b.vendor_name, b.bill_date, b.due_date, b.notes_internal, b.status
              FROM ap_bills b
         LEFT JOIN external_entity_mappings m
                ON m.tenant_id = b.tenant_id
               AND m.source_system = ?
               AND m.internal_entity_type = 'bill'
               AND m.internal_entity_id = b.id
             WHERE b.tenant_id = ?
               AND b.status IN ('approved','partially_paid','paid')
               AND m.id IS NULL
          ORDER BY b.bill_date ASC, b.id ASC
             LIMIT " . (int) $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([ZOHO_BOOKS_SOURCE, $tenantId]);
    $bills = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $pushed = 0; $skipped = 0; $failed = 0; $results = [];

    foreach ($bills as $bill) {
        $bid = (int) $bill['id'];
        // tenant-leak-allow: defense-in-depth — caller scoped row by tenant_id before this id-only read
        $lineStmt = $pdo->prepare(
            'SELECT id, line_no, description, total, gl_expense_account_code
               FROM ap_bill_lines
              WHERE bill_id = :id
           ORDER BY line_no, id'
        );
        $lineStmt->execute(['id' => $bid]);
        $lines = $lineStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $vendorRef = zohoBooksResolveVendorRef($tenantId, (string) $bill['vendor_name']);
        if (!$vendorRef) {
            $skipped++;
            $results[] = ['bill_id' => $bid, 'bill_number' => $bill['bill_number'], 'status' => 'skipped', 'reason' => 'vendor_unmapped'];
            zohoBooksAudit($tenantId, 'sync_bill_skip', [
                'entity_type' => 'bill', 'direction' => 'push',
                'actor_user_id' => $userId, 'items_skipped' => 1,
                'detail' => ['bill_id' => $bid, 'reason' => 'vendor_unmapped', 'vendor_name' => $bill['vendor_name']],
            ]);
            continue;
        }

        $resolver = static function (int $acctId) use ($tenantId) { return zohoBooksResolveAccountRef($tenantId, $acctId); };
        $payload  = zohoBooksBuildBillPayload($bill, $lines, $vendorRef, $resolver);

        $issues = [];
        foreach ($payload['line_items'] as $l) {
            if (isset($l['_unresolved_account_id']))   $issues[] = 'unresolved_account:' . ($l['_account_code'] ?? '#' . $l['_unresolved_account_id']);
            if (isset($l['_unresolved_account_code'])) $issues[] = 'missing_zoho_account:' . $l['_unresolved_account_code'];
            if (isset($l['_missing_account_code']))    $issues[] = 'missing_account_code';
        }
        if ($issues || empty($payload['line_items'])) {
            $skipped++;
            $results[] = ['bill_id' => $bid, 'bill_number' => $bill['bill_number'], 'status' => 'skipped', 'reasons' => $issues ?: ['no_billable_lines']];
            zohoBooksAudit($tenantId, 'sync_bill_skip', [
                'entity_type' => 'bill', 'direction' => 'push',
                'actor_user_id' => $userId, 'items_skipped' => 1,
                'detail' => ['bill_id' => $bid, 'reasons' => $issues ?: ['no_billable_lines']],
            ]);
            continue;
        }
        if ($dryRun) {
            $pushed++;
            $results[] = ['bill_id' => $bid, 'bill_number' => $bill['bill_number'], 'status' => 'dry_run', 'payload' => $payload];
            continue;
        }
        try {
            $resp = zohoBooksCall($tenantId, 'POST', '/books/v3/bills', $payload);
            $zoId = (string) ($resp['bill']['bill_id'] ?? '');
            if ($zoId === '') throw new \RuntimeException('Zoho Books accepted but returned no bill.bill_id');
            mappingUpsert($tenantId, ZOHO_BOOKS_SOURCE, 'bill', $zoId, $bid, $payload, 'push');
            $pushed++;
            $results[] = ['bill_id' => $bid, 'bill_number' => $bill['bill_number'], 'zoho_id' => $zoId, 'status' => 'pushed'];
            zohoBooksAudit($tenantId, 'sync_bill_push', [
                'entity_type' => 'bill', 'direction' => 'push',
                'ok' => true, 'actor_user_id' => $userId, 'items_processed' => 1,
                'detail' => ['bill_id' => $bid, 'zoho_id' => $zoId],
            ]);
        } catch (\Throwable $e) {
            $failed++;
            $results[] = ['bill_id' => $bid, 'bill_number' => $bill['bill_number'], 'status' => 'failed', 'reason' => substr($e->getMessage(), 0, 300)];
            zohoBooksAudit($tenantId, 'sync_bill_push', [
                'entity_type' => 'bill', 'direction' => 'push', 'ok' => false,
                'actor_user_id' => $userId, 'items_failed' => 1,
                'detail' => ['bill_id' => $bid, 'error' => substr($e->getMessage(), 0, 500)],
            ]);
        }
    }
    $latency = (int) round((microtime(true) - $start) * 1000);
    zohoBooksAudit($tenantId, 'sync_bills', [
        'entity_type' => 'bill', 'direction' => 'push',
        'ok' => ($failed === 0),
        'actor_user_id'   => $userId,
        'items_processed' => $pushed,
        'items_skipped'   => $skipped,
        'items_failed'    => $failed,
        'detail' => ['considered' => count($bills), 'latency_ms' => $latency, 'dry_run' => $dryRun],
    ]);
    return [
        'pushed' => $pushed, 'skipped' => $skipped, 'failed' => $failed,
        'considered' => count($bills), 'latency_ms' => $latency, 'dry_run' => $dryRun,
        'results' => $results,
    ];
}
