<?php
/**
 * QBO Slice 4b — Bill push driver.
 *
 * Pushes posted CoreFlux ap_bills into QBO Bill. Bills use
 * AccountBasedExpenseLineDetail, which (unlike Invoice) only needs an
 * AccountRef on each line — no Item mapping required. Reuses the Slice 2
 * `qboResolveAccountRef()` helper, now backed by the Slice 4a COA mirror.
 *
 * Match strategy on the vendor side:
 *   1. ap_vendors_index → external_entity_mappings (entity_type='vendor')
 *   2. If none, attempt to upsert one via QBO Query by DisplayName.
 *      Failure → skip the bill, audit `sync_bill_skip` with reason.
 *
 * Idempotent via external_entity_mappings (source='quickbooks_online',
 * entity_type='bill').
 */
declare(strict_types=1);

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/sync_je.php';   // for qboResolveAccountRef + QBO_SOURCE
require_once __DIR__ . '/../integrations/entity_mappings.php';

/**
 * Resolve a CoreFlux vendor (ap_vendors_index row) to a QBO Vendor.Id.
 * Returns ['value'=>qboId, 'name'=>qboName] or null.
 */
function qboResolveVendorRef(int $tenantId, string $vendorName): ?array
{
    // Internal lookup by name (CoreFlux denormalises vendor_name on the bill).
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT id FROM ap_vendors_index WHERE tenant_id = :t AND vendor_name = :n LIMIT 1');
    $stmt->execute(['t' => $tenantId, 'n' => $vendorName]);
    $vRow = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$vRow) return null;
    $vid = (int) $vRow['id'];

    $existing = mappingFindExternal($tenantId, QBO_SOURCE, 'vendor', $vid);
    if ($existing) {
        $snap = $existing['payload_snapshot'] ? json_decode((string) $existing['payload_snapshot'], true) : null;
        return [
            'value' => (string) $existing['external_id'],
            'name'  => is_array($snap) ? (string) ($snap['DisplayName'] ?? $vendorName) : $vendorName,
        ];
    }
    // Query QBO by DisplayName.
    $conn = qboConnection($tenantId);
    if (!$conn) return null;
    $safe = str_replace("'", "\\'", $vendorName);
    try {
        $resp = qboCall($tenantId, 'GET', '/v3/company/' . $conn['realm_id'] . '/query', null, [
            'query'        => "select Id, DisplayName from Vendor where DisplayName = '{$safe}'",
            'minorversion' => 65,
        ]);
    } catch (\Throwable $e) {
        return null;
    }
    $hits = $resp['QueryResponse']['Vendor'] ?? [];
    if (!is_array($hits) || count($hits) === 0) return null;
    $hit = $hits[0];
    $qboId = (string) ($hit['Id'] ?? '');
    if ($qboId === '') return null;
    mappingUpsert($tenantId, QBO_SOURCE, 'vendor', $qboId, $vid, $hit, 'pull');
    return ['value' => $qboId, 'name' => (string) ($hit['DisplayName'] ?? $vendorName)];
}

function qboBuildBillPayload(array $bill, array $lines, ?array $vendorRef, callable $resolveAccount): array
{
    $payload = [
        'TxnDate'     => (string) ($bill['bill_date'] ?? date('Y-m-d')),
        'DueDate'     => (string) ($bill['due_date']  ?? date('Y-m-d')),
        'DocNumber'   => substr((string) ($bill['bill_number'] ?? ''), 0, 21), // QBO caps at 21
        'PrivateNote' => (string) ($bill['notes_internal'] ?? ''),
        'VendorRef'   => $vendorRef ? ['value' => $vendorRef['value'], 'name' => $vendorRef['name']] : null,
        'Line'        => [],
    ];
    foreach ($lines as $line) {
        $total = (float) ($line['total'] ?? 0);
        if ($total <= 0) continue;
        $code = trim((string) ($line['gl_expense_account_code'] ?? ''));
        if ($code === '') {
            $payload['Line'][] = ['_missing_account_code' => true, '_line_id' => $line['id'] ?? null];
            continue;
        }
        // Resolve by CoreFlux account code → id → QBO mapping.
        $pdo = getDB();
        $stmt = $pdo->prepare('SELECT id FROM accounting_accounts WHERE tenant_id = :t AND code = :c LIMIT 1');
        $stmt->execute(['t' => (int) ($bill['tenant_id'] ?? 0), 'c' => $code]);
        $aRow = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$aRow) {
            $payload['Line'][] = ['_unresolved_account_code' => $code];
            continue;
        }
        $ref = $resolveAccount((int) $aRow['id']);
        if (!$ref) {
            $payload['Line'][] = ['_unresolved_account_id' => (int) $aRow['id'], '_account_code' => $code];
            continue;
        }
        $payload['Line'][] = [
            'Description' => (string) ($line['description'] ?? ''),
            'Amount'      => round($total, 2),
            'DetailType'  => 'AccountBasedExpenseLineDetail',
            'AccountBasedExpenseLineDetail' => [
                'AccountRef' => ['value' => (string) $ref['value'], 'name' => (string) ($ref['name'] ?? '')],
            ],
        ];
    }
    return $payload;
}

function qboSyncBills(int $tenantId, ?int $userId, array $opts = []): array
{
    $start = microtime(true);
    $limit  = max(1, min(500, (int) ($opts['limit'] ?? 50)));
    $dryRun = !empty($opts['dry_run']);

    $conn = qboConnection($tenantId);
    if (!$conn || $conn['status'] !== 'active') {
        throw new \RuntimeException('QuickBooks is not connected for this tenant');
    }
    $cfg = qboSyncConfigRead($tenantId);
    if (!in_array($cfg['bills'] ?? 'off', ['push', 'two_way'], true)) {
        throw new \RuntimeException('Bills direction is not push/two_way for this tenant');
    }
    $realm = (string) $conn['realm_id'];

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
    $stmt->execute([QBO_SOURCE, $tenantId]);
    $bills = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $pushed = 0; $skipped = 0; $failed = 0;
    $results = [];

    foreach ($bills as $bill) {
        $bid = (int) $bill['id'];
        $lineStmt = $pdo->prepare(
            'SELECT id, line_no, description, total, gl_expense_account_code
               FROM ap_bill_lines
              WHERE bill_id = :id
           ORDER BY line_no, id'
        );
        $lineStmt->execute(['id' => $bid]);
        $lines = $lineStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $vendorRef = qboResolveVendorRef($tenantId, (string) $bill['vendor_name']);
        if (!$vendorRef) {
            $skipped++;
            $results[] = ['bill_id' => $bid, 'bill_number' => $bill['bill_number'], 'status' => 'skipped', 'reason' => 'vendor_unmapped'];
            qboAudit($tenantId, 'sync_bill_skip', [
                'entity_type' => 'bill', 'direction' => 'push',
                'actor_user_id' => $userId, 'items_skipped' => 1,
                'detail' => ['bill_id' => $bid, 'reason' => 'vendor_unmapped', 'vendor_name' => $bill['vendor_name']],
            ]);
            continue;
        }

        $resolver = static function (int $acctId) use ($tenantId) { return qboResolveAccountRef($tenantId, $acctId); };
        $payload = qboBuildBillPayload($bill, $lines, $vendorRef, $resolver);

        $issues = [];
        foreach ($payload['Line'] as $l) {
            if (isset($l['_unresolved_account_id']))   $issues[] = 'unresolved_account:' . ($l['_account_code'] ?? '#' . $l['_unresolved_account_id']);
            if (isset($l['_unresolved_account_code'])) $issues[] = 'missing_coreflux_account:' . $l['_unresolved_account_code'];
            if (isset($l['_missing_account_code']))    $issues[] = 'missing_account_code';
        }
        if ($issues || empty($payload['Line'])) {
            $skipped++;
            $results[] = ['bill_id' => $bid, 'bill_number' => $bill['bill_number'], 'status' => 'skipped', 'reasons' => $issues ?: ['no_billable_lines']];
            qboAudit($tenantId, 'sync_bill_skip', [
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

        // Charter retry+DLQ — respect backoff and dead-letter status.
        $retryGate = qboPushFailureCheck($tenantId, 'bill', $bid);
        if ($retryGate !== 'go') {
            $skipped++;
            $results[] = ['bill_id' => $bid, 'bill_number' => $bill['bill_number'], 'status' => $retryGate];
            continue;
        }

        try {
            $resp = qboCall($tenantId, 'POST', '/v3/company/' . $realm . '/bill?minorversion=65', $payload);
            $qboId = (string) ($resp['Bill']['Id'] ?? '');
            if ($qboId === '') throw new \RuntimeException('QBO accepted but returned no Bill.Id');
            mappingUpsert($tenantId, QBO_SOURCE, 'bill', $qboId, $bid, $payload, 'push');
            qboPushFailureClear($tenantId, 'bill', $bid);
            $pushed++;
            // Charter primitive #5 — post-push verification.
            $verify = qboVerifyCreate($tenantId, 'bill', $qboId, 'active');
            $itemStatus = ($verify['verified'] ?? false) ? 'pushed' : 'pushed_unverified';
            $results[] = ['bill_id' => $bid, 'bill_number' => $bill['bill_number'], 'qbo_id' => $qboId, 'status' => $itemStatus, 'verify' => $verify];
            qboAudit($tenantId, 'sync_bill_push', [
                'entity_type' => 'bill', 'direction' => 'push',
                'actor_user_id' => $userId, 'items_processed' => 1,
                'detail' => ['bill_id' => $bid, 'qbo_id' => $qboId, 'verify' => $verify],
            ]);
        } catch (\Throwable $e) {
            $failed++;
            // Charter retry+DLQ — record the failure for backoff.
            qboPushFailureRecord($tenantId, 'bill', $bid, $e);
            // Charter primitive #6 — capture raw vendor body.
            $vendorRaw  = ($e instanceof QboApiException && is_array($e->raw)) ? $e->raw : null;
            $vendorHttp = ($e instanceof QboApiException) ? (int) $e->httpStatus : null;
            $vendorCode = ($e instanceof QboApiException) ? (string) $e->errorCode : null;
            $results[] = [
                'bill_id' => $bid, 'bill_number' => $bill['bill_number'],
                'status' => 'failed', 'reason' => substr($e->getMessage(), 0, 300),
                'vendor' => ['http_status' => $vendorHttp, 'code' => $vendorCode, 'raw' => $vendorRaw],
            ];
            qboAudit($tenantId, 'sync_bill_push', [
                'entity_type' => 'bill', 'direction' => 'push', 'ok' => false,
                'actor_user_id' => $userId, 'items_failed' => 1,
                'detail' => [
                    'bill_id' => $bid,
                    'error' => substr($e->getMessage(), 0, 500),
                    'vendor_http_status' => $vendorHttp,
                    'vendor_error_code'  => $vendorCode,
                    'vendor_raw'         => $vendorRaw,
                ],
            ]);
        }
    }
    $latency = (int) round((microtime(true) - $start) * 1000);
    qboAudit($tenantId, 'sync_bills', [
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
