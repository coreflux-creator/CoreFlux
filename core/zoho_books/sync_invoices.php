<?php
/**
 * Zoho Books — Slice 4: Invoices push.
 *
 * Mirrors QBO Slice 4 (sync_invoices.php). Uses Slice 3's contact
 * mapping cache: skips with `customer_unmapped` when no mapping exists
 * after a name-match lookup.
 *
 * Zoho `/books/v3/invoices` POST payload:
 *   { customer_id, invoice_number, date, due_date, notes,
 *     line_items: [ { name, description, quantity, rate } ] }
 *
 * Public surface:
 *   zohoBooksResolveCustomerRef(int $tid, string $name): ?array
 *   zohoBooksBuildInvoicePayload(array $invoice, array $lines, array $customerRef): array
 *   zohoBooksSyncInvoices(int $tid, ?int $userId, array $opts=[]): array
 */
declare(strict_types=1);

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/sync_je.php';   // ZOHO_BOOKS_SOURCE constant
require_once __DIR__ . '/../integrations/entity_mappings.php';

function zohoBooksResolveCustomerRef(int $tenantId, string $name): ?array
{
    $name = trim($name);
    if ($name === '') return null;
    // 1. existing mapping via staffing_clients.id → zoho contact_id
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT id FROM staffing_clients WHERE tenant_id = :t AND name = :n LIMIT 1');
    $stmt->execute(['t' => $tenantId, 'n' => $name]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if ($row) {
        $m = mappingFindExternal($tenantId, ZOHO_BOOKS_SOURCE, 'customer', (int) $row['id']);
        if ($m) {
            $snap = !empty($m['payload_snapshot']) ? json_decode((string) $m['payload_snapshot'], true) : null;
            return [
                'value' => (string) $m['external_id'],
                'name'  => is_array($snap) ? (string) ($snap['contact_name'] ?? $snap['company_name'] ?? $name) : $name,
            ];
        }
    }
    // 2. live discovery via Zoho contacts API
    try {
        $resp = zohoBooksCall($tenantId, 'GET', '/books/v3/contacts', null, [
            'contact_name' => $name,
            'contact_type' => 'customer',
            'per_page'     => 1,
        ]);
    } catch (\Throwable $_) { return null; }
    $contacts = $resp['contacts'] ?? [];
    if (!is_array($contacts) || count($contacts) === 0) return null;
    $hit = $contacts[0];
    $zoId = (string) ($hit['contact_id'] ?? '');
    if ($zoId === '') return null;
    if ($row) {
        mappingUpsert($tenantId, ZOHO_BOOKS_SOURCE, 'customer', $zoId, (int) $row['id'], $hit, 'pull');
    }
    return ['value' => $zoId, 'name' => (string) ($hit['contact_name'] ?? $name)];
}

function zohoBooksBuildInvoicePayload(array $invoice, array $lines, array $customerRef): array
{
    $payload = [
        'customer_id'    => (string) $customerRef['value'],
        'invoice_number' => (string) ($invoice['invoice_number'] ?? ''),
        'date'           => (string) ($invoice['issue_date'] ?? date('Y-m-d')),
        'due_date'       => (string) ($invoice['due_date']   ?? date('Y-m-d')),
        'notes'          => (string) ($invoice['notes_external'] ?? ''),
        'line_items'     => [],
    ];
    foreach ($lines as $line) {
        $subtotal = (float) ($line['subtotal'] ?? 0);
        if ($subtotal <= 0) continue;
        $qty   = (float) ($line['quantity']   ?? 1);
        $rate  = (float) ($line['unit_price'] ?? ($qty > 0 ? $subtotal / $qty : $subtotal));
        $payload['line_items'][] = [
            'name'        => substr((string) ($line['description'] ?? 'Item'), 0, 100),
            'description' => (string) ($line['description'] ?? ''),
            'quantity'    => $qty,
            'rate'        => round($rate, 2),
        ];
    }
    return $payload;
}

function zohoBooksSyncInvoices(int $tenantId, ?int $userId, array $opts = []): array
{
    $__zbSub = isset($opts["sub_tenant_id"]) && (int) $opts["sub_tenant_id"] > 0 ? (int) $opts["sub_tenant_id"] : null;
    $GLOBALS["__zb_sub_tenant_id"] = $__zbSub ?? 0;
    $start = microtime(true);
    $limit  = max(1, min(500, (int) ($opts['limit'] ?? 50)));
    $dryRun = !empty($opts['dry_run']);

    $conn = zohoBooksConnection($tenantId, isset($opts["sub_tenant_id"]) && (int) $opts["sub_tenant_id"] > 0 ? (int) $opts["sub_tenant_id"] : null);
    if (!$conn || $conn['status'] !== 'active' || (string) $conn['organization_id'] === 'pending') {
        throw new \RuntimeException('Zoho Books is not connected for this tenant');
    }
    $cfg = zohoBooksSyncConfigRead($tenantId);
    if (!in_array($cfg['invoices'] ?? 'off', ['push', 'two_way'], true)) {
        throw new \RuntimeException('Invoices direction is not push/two_way for this tenant');
    }

    $pdo = getDB();
    $stmt = $pdo->prepare(
        "SELECT i.id, i.invoice_number, i.client_name, i.issue_date, i.due_date,
                i.notes_internal, i.notes_external, i.status
           FROM billing_invoices i
      LEFT JOIN external_entity_mappings m
             ON m.tenant_id = i.tenant_id
            AND m.source_system = ?
            AND m.internal_entity_type = 'invoice'
            AND m.internal_entity_id = i.id
          WHERE i.tenant_id = ?
            AND i.status IN ('approved','sent','partially_paid','paid')
            AND m.id IS NULL
       ORDER BY i.issue_date ASC, i.id ASC
          LIMIT " . (int) $limit
    );
    $stmt->execute([ZOHO_BOOKS_SOURCE, $tenantId]);
    $invoices = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $pushed = 0; $skipped = 0; $failed = 0; $results = [];

    foreach ($invoices as $inv) {
        $iid = (int) $inv['id'];
        // tenant-leak-allow: defense-in-depth — caller scoped row by tenant_id before this id-only read
        $ls = $pdo->prepare('SELECT id, line_no, description, quantity, unit_price, subtotal, placement_id
                               FROM billing_invoice_lines WHERE invoice_id = :id ORDER BY line_no, id');
        $ls->execute(['id' => $iid]);
        $lines = $ls->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $custRef = zohoBooksResolveCustomerRef($tenantId, (string) $inv['client_name']);
        if (!$custRef) {
            $skipped++;
            $results[] = ['invoice_id' => $iid, 'invoice_number' => $inv['invoice_number'], 'status' => 'skipped', 'reason' => 'customer_unmapped'];
            zohoBooksAudit($tenantId, 'sync_invoice_skip', [
                'entity_type' => 'invoice', 'direction' => 'push',
                'actor_user_id' => $userId, 'items_skipped' => 1,
                'detail' => ['invoice_id' => $iid, 'client_name' => $inv['client_name']],
            ]);
            continue;
        }
        $payload = zohoBooksBuildInvoicePayload($inv, $lines, $custRef);
        if (count($payload['line_items']) === 0) {
            $skipped++;
            $results[] = ['invoice_id' => $iid, 'status' => 'skipped', 'reason' => 'no_billable_lines'];
            continue;
        }
        if ($dryRun) {
            $pushed++;
            $results[] = ['invoice_id' => $iid, 'status' => 'dry_run', 'payload' => $payload];
            continue;
        }
        try {
            $resp = zohoBooksCall($tenantId, 'POST', '/books/v3/invoices', $payload);
            $zoId = (string) ($resp['invoice']['invoice_id'] ?? '');
            if ($zoId === '') throw new \RuntimeException('Zoho returned no invoice_id');
            mappingUpsert($tenantId, ZOHO_BOOKS_SOURCE, 'invoice', $zoId, $iid, $payload, 'push');
            $pushed++;
            $results[] = ['invoice_id' => $iid, 'zoho_id' => $zoId, 'status' => 'pushed'];
            zohoBooksAudit($tenantId, 'sync_invoice_push', [
                'entity_type' => 'invoice', 'direction' => 'push',
                'ok' => true, 'actor_user_id' => $userId, 'items_processed' => 1,
                'detail' => ['invoice_id' => $iid, 'zoho_id' => $zoId],
            ]);
        } catch (\Throwable $e) {
            $failed++;
            $results[] = ['invoice_id' => $iid, 'status' => 'failed', 'reason' => substr($e->getMessage(), 0, 300)];
            zohoBooksAudit($tenantId, 'sync_invoice_push', [
                'entity_type' => 'invoice', 'direction' => 'push', 'ok' => false,
                'actor_user_id' => $userId, 'items_failed' => 1,
                'detail' => ['invoice_id' => $iid, 'error' => substr($e->getMessage(), 0, 500)],
            ]);
        }
    }

    $latency = (int) round((microtime(true) - $start) * 1000);
    zohoBooksAudit($tenantId, 'sync_invoices', [
        'entity_type' => 'invoice', 'direction' => 'push',
        'ok' => ($failed === 0), 'actor_user_id' => $userId,
        'items_processed' => $pushed, 'items_skipped' => $skipped, 'items_failed' => $failed,
        'detail' => ['considered' => count($invoices), 'latency_ms' => $latency, 'dry_run' => $dryRun],
    ]);

    return [
        'pushed' => $pushed, 'skipped' => $skipped, 'failed' => $failed,
        'considered' => count($invoices), 'latency_ms' => $latency, 'dry_run' => $dryRun,
        'results' => $results,
    ];
}
