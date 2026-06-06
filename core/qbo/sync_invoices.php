<?php
/**
 * QBO Slice 4b — Invoice push driver.
 *
 * Pushes posted CoreFlux billing_invoices into QBO Invoice. Lines are
 * SalesItemLineDetail with an ItemRef resolved via
 *   `qboItemRefForPlacement()` → `qboDefaultItemRef()`
 * (see sync_items.php). CustomerRef is resolved via the staffing_clients
 * → external_entity_mappings (entity_type='customer') link the Slice 3
 * pull populated.
 *
 * Skip reasons (audited to qbo_sync_audit):
 *   - customer_unmapped: no QBO Customer.Id for invoice.client_name
 *   - no_default_item:   no Service/Active item in QBO yet (run Pull items first)
 *
 * Idempotent via external_entity_mappings (entity_type='invoice').
 */
declare(strict_types=1);

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/sync_je.php'; // QBO_SOURCE
require_once __DIR__ . '/sync_items.php';
require_once __DIR__ . '/../integrations/entity_mappings.php';

function qboResolveCustomerRef(int $tenantId, string $clientName): ?array
{
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT id FROM staffing_clients WHERE tenant_id = :t AND name = :n LIMIT 1');
    $stmt->execute(['t' => $tenantId, 'n' => $clientName]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) return null;
    $cid = (int) $row['id'];
    $existing = mappingFindExternal($tenantId, QBO_SOURCE, 'customer', $cid);
    if (!$existing) return null;
    $snap = $existing['payload_snapshot'] ? json_decode((string) $existing['payload_snapshot'], true) : null;
    return [
        'value' => (string) $existing['external_id'],
        'name'  => is_array($snap) ? (string) ($snap['DisplayName'] ?? $clientName) : $clientName,
    ];
}

function qboBuildInvoicePayload(array $invoice, array $lines, array $customerRef, callable $itemResolver): array
{
    $payload = [
        'TxnDate'     => (string) ($invoice['issue_date'] ?? date('Y-m-d')),
        'DueDate'     => (string) ($invoice['due_date']   ?? date('Y-m-d')),
        'DocNumber'   => substr((string) ($invoice['invoice_number'] ?? ''), 0, 21),
        'CustomerRef' => ['value' => $customerRef['value'], 'name' => $customerRef['name'] ?? ''],
        'PrivateNote' => (string) ($invoice['notes_internal'] ?? ''),
        'CustomerMemo'=> ['value' => substr((string) ($invoice['notes_external'] ?? ''), 0, 1000)],
        'Line'        => [],
    ];
    foreach ($lines as $line) {
        $subtotal = (float) ($line['subtotal'] ?? 0);
        if ($subtotal <= 0) continue;
        $itemRef = $itemResolver((int) ($line['placement_id'] ?? 0) ?: null);
        if (!$itemRef) {
            $payload['Line'][] = ['_no_item_mapping' => true];
            continue;
        }
        $payload['Line'][] = [
            'Description' => (string) ($line['description'] ?? ''),
            'Amount'      => round($subtotal, 2),
            'DetailType'  => 'SalesItemLineDetail',
            'SalesItemLineDetail' => [
                'ItemRef'  => ['value' => (string) $itemRef['value'], 'name' => (string) ($itemRef['name'] ?? '')],
                'Qty'      => (float) ($line['quantity']   ?? 1),
                'UnitPrice'=> (float) ($line['unit_price'] ?? $subtotal),
            ],
        ];
    }
    return $payload;
}

function qboSyncInvoices(int $tenantId, ?int $userId, array $opts = []): array
{
    $start = microtime(true);
    $limit  = max(1, min(500, (int) ($opts['limit'] ?? 50)));
    $dryRun = !empty($opts['dry_run']);

    $conn = qboConnection($tenantId);
    if (!$conn || $conn['status'] !== 'active') {
        throw new \RuntimeException('QuickBooks is not connected for this tenant');
    }
    $cfg = qboSyncConfigRead($tenantId);
    if (!in_array($cfg['invoices'] ?? 'off', ['push', 'two_way'], true)) {
        throw new \RuntimeException('Invoices direction is not push/two_way for this tenant');
    }
    $realm = (string) $conn['realm_id'];

    $pdo = getDB();
    $stmt = $pdo->prepare(
        "SELECT i.id, i.tenant_id, i.invoice_number, i.client_name, i.issue_date, i.due_date,
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
    $stmt->execute([QBO_SOURCE, $tenantId]);
    $invoices = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $pushed = 0; $skipped = 0; $failed = 0;
    $results = [];

    foreach ($invoices as $inv) {
        $iid = (int) $inv['id'];
        $lineStmt = $pdo->prepare(
            'SELECT id, line_no, description, quantity, unit_price, subtotal, placement_id
               FROM billing_invoice_lines
              WHERE invoice_id = :id
           ORDER BY line_no, id'
        );
        $lineStmt->execute(['id' => $iid]);
        $lines = $lineStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $customerRef = qboResolveCustomerRef($tenantId, (string) $inv['client_name']);
        if (!$customerRef) {
            $skipped++;
            $results[] = ['invoice_id' => $iid, 'invoice_number' => $inv['invoice_number'], 'status' => 'skipped', 'reason' => 'customer_unmapped'];
            qboAudit($tenantId, 'sync_invoice_skip', [
                'entity_type' => 'invoice', 'direction' => 'push',
                'actor_user_id' => $userId, 'items_skipped' => 1,
                'detail' => ['invoice_id' => $iid, 'reason' => 'customer_unmapped', 'client_name' => $inv['client_name']],
            ]);
            continue;
        }
        $itemResolver = static function (?int $pid) use ($tenantId) { return qboItemRefForPlacement($tenantId, $pid); };
        $payload = qboBuildInvoicePayload($inv, $lines, $customerRef, $itemResolver);

        $noItem = false;
        foreach ($payload['Line'] as $l) {
            if (isset($l['_no_item_mapping'])) { $noItem = true; break; }
        }
        if ($noItem || empty($payload['Line'])) {
            $skipped++;
            $results[] = ['invoice_id' => $iid, 'invoice_number' => $inv['invoice_number'], 'status' => 'skipped',
                          'reason' => $noItem ? 'no_default_item' : 'no_billable_lines'];
            qboAudit($tenantId, 'sync_invoice_skip', [
                'entity_type' => 'invoice', 'direction' => 'push',
                'actor_user_id' => $userId, 'items_skipped' => 1,
                'detail' => ['invoice_id' => $iid, 'reason' => $noItem ? 'no_default_item' : 'no_billable_lines'],
            ]);
            continue;
        }

        if ($dryRun) {
            $pushed++;
            $results[] = ['invoice_id' => $iid, 'invoice_number' => $inv['invoice_number'], 'status' => 'dry_run', 'payload' => $payload];
            continue;
        }
        try {
            $resp = qboCall($tenantId, 'POST', '/v3/company/' . $realm . '/invoice?minorversion=65', $payload);
            $qboId = (string) ($resp['Invoice']['Id'] ?? '');
            if ($qboId === '') throw new \RuntimeException('QBO accepted but returned no Invoice.Id');
            mappingUpsert($tenantId, QBO_SOURCE, 'invoice', $qboId, $iid, $payload, 'push');
            $pushed++;
            // Charter primitive #5 — post-push verification.
            $verify = qboVerifyCreate($tenantId, 'invoice', $qboId, 'active');
            $itemStatus = ($verify['verified'] ?? false) ? 'pushed' : 'pushed_unverified';
            $results[] = ['invoice_id' => $iid, 'invoice_number' => $inv['invoice_number'], 'qbo_id' => $qboId, 'status' => $itemStatus, 'verify' => $verify];
            qboAudit($tenantId, 'sync_invoice_push', [
                'entity_type' => 'invoice', 'direction' => 'push',
                'actor_user_id' => $userId, 'items_processed' => 1,
                'detail' => ['invoice_id' => $iid, 'qbo_id' => $qboId, 'verify' => $verify],
            ]);
        } catch (\Throwable $e) {
            $failed++;
            // Charter primitive #6 — capture raw vendor body.
            $vendorRaw  = ($e instanceof QboApiException && is_array($e->raw)) ? $e->raw : null;
            $vendorHttp = ($e instanceof QboApiException) ? (int) $e->httpStatus : null;
            $vendorCode = ($e instanceof QboApiException) ? (string) $e->errorCode : null;
            $results[] = [
                'invoice_id' => $iid, 'invoice_number' => $inv['invoice_number'],
                'status' => 'failed', 'reason' => substr($e->getMessage(), 0, 300),
                'vendor' => ['http_status' => $vendorHttp, 'code' => $vendorCode, 'raw' => $vendorRaw],
            ];
            qboAudit($tenantId, 'sync_invoice_push', [
                'entity_type' => 'invoice', 'direction' => 'push', 'ok' => false,
                'actor_user_id' => $userId, 'items_failed' => 1,
                'detail' => [
                    'invoice_id' => $iid,
                    'error' => substr($e->getMessage(), 0, 500),
                    'vendor_http_status' => $vendorHttp,
                    'vendor_error_code'  => $vendorCode,
                    'vendor_raw'         => $vendorRaw,
                ],
            ]);
        }
    }

    $latency = (int) round((microtime(true) - $start) * 1000);
    qboAudit($tenantId, 'sync_invoices', [
        'entity_type' => 'invoice', 'direction' => 'push',
        'ok' => ($failed === 0),
        'actor_user_id'   => $userId,
        'items_processed' => $pushed,
        'items_skipped'   => $skipped,
        'items_failed'    => $failed,
        'detail' => ['considered' => count($invoices), 'latency_ms' => $latency, 'dry_run' => $dryRun],
    ]);
    return [
        'pushed' => $pushed, 'skipped' => $skipped, 'failed' => $failed,
        'considered' => count($invoices), 'latency_ms' => $latency, 'dry_run' => $dryRun,
        'results' => $results,
    ];
}
