<?php
/**
 * Zoho Books — Slice 3: Contacts pull (customers + vendors).
 *
 * Zoho's `/books/v3/contacts` returns both customers and vendors in a
 * unified collection, distinguished by `contact_type ∈ {customer, vendor}`.
 * We pull each subtype, paginated, and upsert into the matching CoreFlux
 * master table:
 *   - customer → `staffing_clients`            (mapped under entity_type='customer')
 *   - vendor   → `ap_vendors_index`            (mapped under entity_type='vendor')
 *
 * Match strategy (mirrors QBO Slice 3 exactly):
 *   1. existing mapping (zoho contact_id ↔ internal id)
 *   2. UNIQUE-by-name UPSERT (uq_sc_tenant_name / uq_apv_tenant_name)
 *   3. INSERT new row
 *
 * Public surface:
 *   zohoBooksSyncContactsCustomers(int $tid, ?int $userId, array $opts=[]): array
 *   zohoBooksSyncContactsVendors(int $tid, ?int $userId, array $opts=[]): array
 *   zohoBooksUpsertCustomer(int $tid, array $zo): array  // {internal_id, action}
 *   zohoBooksUpsertVendor(int $tid, array $zo): array
 *
 * Opts:
 *   - limit:     int (default 1000, cap 5000)
 *   - max_pages: int (default 10, cap 50)
 */
declare(strict_types=1);

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/sync_je.php';                 // ZOHO_BOOKS_SOURCE constant
require_once __DIR__ . '/../integrations/entity_mappings.php';

function zohoBooksSyncContactsCustomers(int $tenantId, ?int $userId, array $opts = []): array
{
    $__zbSub = isset($opts["sub_tenant_id"]) && (int) $opts["sub_tenant_id"] > 0 ? (int) $opts["sub_tenant_id"] : null;
    $GLOBALS["__zb_sub_tenant_id"] = $__zbSub ?? 0;
    return _zohoBooksSyncContactKind($tenantId, $userId, $opts, [
        'kind'   => 'customer',
        'cfgKey' => 'contacts',
        'upsert' => 'zohoBooksUpsertCustomer',
    ]);
}

function zohoBooksSyncContactsVendors(int $tenantId, ?int $userId, array $opts = []): array
{
    $__zbSub = isset($opts["sub_tenant_id"]) && (int) $opts["sub_tenant_id"] > 0 ? (int) $opts["sub_tenant_id"] : null;
    $GLOBALS["__zb_sub_tenant_id"] = $__zbSub ?? 0;
    return _zohoBooksSyncContactKind($tenantId, $userId, $opts, [
        'kind'   => 'vendor',
        'cfgKey' => 'contacts',
        'upsert' => 'zohoBooksUpsertVendor',
    ]);
}

function _zohoBooksSyncContactKind(int $tenantId, ?int $userId, array $opts, array $cfg): array
{
    $start    = microtime(true);
    $limit    = max(1, min(5000, (int) ($opts['limit']     ?? 1000)));
    $maxPages = max(1, min(50,   (int) ($opts['max_pages'] ?? 10)));

    $conn = zohoBooksConnection($tenantId, isset($opts["sub_tenant_id"]) && (int) $opts["sub_tenant_id"] > 0 ? (int) $opts["sub_tenant_id"] : null);
    if (!$conn || $conn['status'] !== 'active' || (string) $conn['organization_id'] === 'pending') {
        throw new \RuntimeException('Zoho Books is not connected for this tenant');
    }
    $config = zohoBooksSyncConfigRead($tenantId);
    if (!in_array($config[$cfg['cfgKey']] ?? 'off', ['pull', 'two_way'], true)) {
        throw new \RuntimeException('Contacts direction is not pull/two_way for this tenant');
    }

    $kind     = $cfg['kind'];                          // 'customer' | 'vendor'
    $upsertFn = $cfg['upsert'];

    $created = 0; $updated = 0; $unchanged = 0; $failed = 0; $pulled = 0;
    $page = 1; $pages = 0; $results = [];

    while ($pulled < $limit && $pages < $maxPages) {
        $pages++;
        try {
            $resp = zohoBooksCall($tenantId, 'GET', '/books/v3/contacts', null, [
                'page'         => $page,
                'per_page'     => min(200, $limit - $pulled),
                'contact_type' => $kind,
            ]);
        } catch (\Throwable $e) {
            zohoBooksAudit($tenantId, 'sync_' . $kind . 's_error', [
                'ok' => false, 'actor_user_id' => $userId,
                'entity_type' => $kind, 'direction' => 'pull',
                'detail' => ['error' => substr($e->getMessage(), 0, 500), 'page' => $page],
            ]);
            throw $e;
        }
        $rows = is_array($resp['contacts'] ?? null) ? $resp['contacts'] : [];
        if (count($rows) === 0) break;

        foreach ($rows as $zo) {
            try {
                $up = $upsertFn($tenantId, $zo);
                if      ($up['action'] === 'created')   $created++;
                elseif  ($up['action'] === 'updated')   $updated++;
                else                                    $unchanged++;
                $results[] = ['zoho_id' => (string) ($zo['contact_id'] ?? ''), 'internal_id' => $up['internal_id'], 'action' => $up['action']];
            } catch (\Throwable $e) {
                $failed++;
                $results[] = ['zoho_id' => (string) ($zo['contact_id'] ?? ''), 'action' => 'failed', 'reason' => substr($e->getMessage(), 0, 300)];
            }
        }
        $pulled += count($rows);
        $hasMore = (bool) ($resp['page_context']['has_more_page'] ?? false);
        if (!$hasMore) break;
        $page++;
    }

    $latency = (int) round((microtime(true) - $start) * 1000);
    zohoBooksAudit($tenantId, 'sync_' . $kind . 's', [
        'entity_type' => $kind, 'direction' => 'pull',
        'ok' => ($failed === 0),
        'actor_user_id' => $userId,
        'items_processed' => $created + $updated,
        'items_skipped'   => $unchanged,
        'items_failed'    => $failed,
        'detail' => [
            'created' => $created, 'updated' => $updated,
            'unchanged' => $unchanged, 'failed' => $failed,
            'pulled' => $pulled, 'pages' => $pages, 'latency_ms' => $latency,
        ],
    ]);

    return [
        'created'    => $created,
        'updated'    => $updated,
        'unchanged'  => $unchanged,
        'failed'     => $failed,
        'pulled'     => $pulled,
        'pages'      => $pages,
        'latency_ms' => $latency,
        'results'    => $results,
    ];
}

/* ===================================================================
 * Upserters — translate Zoho payload → CoreFlux row
 * =================================================================== */

/**
 * Zoho Customer → staffing_clients.
 *
 * Match: (1) existing mapping (2) name match via uq_sc_tenant_name
 * (3) INSERT new.
 */
function zohoBooksUpsertCustomer(int $tenantId, array $zo): array
{
    $zoId = (string) ($zo['contact_id'] ?? '');
    if ($zoId === '') throw new \InvalidArgumentException('Zoho customer missing contact_id');

    $name        = trim((string) ($zo['contact_name'] ?? $zo['company_name'] ?? ''));
    if ($name === '') throw new \InvalidArgumentException('Zoho customer missing contact_name');
    $legalName   = trim((string) ($zo['company_name'] ?? ''));
    $email       = trim((string) ($zo['email'] ?? ''));
    $phone       = trim((string) ($zo['phone'] ?? $zo['mobile'] ?? ''));
    $addr        = is_array($zo['billing_address'] ?? null) ? $zo['billing_address'] : [];

    $pdo = getDB();
    $mapping = mappingFindInternal($tenantId, ZOHO_BOOKS_SOURCE, 'customer', $zoId);
    $internalId = $mapping ? (int) $mapping['internal_entity_id'] : 0;

    if (!$internalId) {
        $stmt = $pdo->prepare('SELECT id FROM staffing_clients WHERE tenant_id = :t AND name = :n LIMIT 1');
        $stmt->execute(['t' => $tenantId, 'n' => $name]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $internalId = $row ? (int) $row['id'] : 0;
    }

    $payload = [
        'name'                  => $name,
        'legal_name'            => $legalName !== '' ? $legalName : null,
        'primary_contact_email' => $email !== '' ? $email : null,
        'primary_contact_phone' => $phone !== '' ? $phone : null,
        'billing_address_line1' => isset($addr['address']) ? (string) $addr['address'] : null,
        'billing_city'          => isset($addr['city'])    ? (string) $addr['city']    : null,
        'billing_state'         => isset($addr['state'])   ? substr((string) $addr['state'], 0, 40) : null,
        'billing_postal_code'   => isset($addr['zip'])     ? (string) $addr['zip'] : null,
        'billing_country'       => isset($addr['country']) ? substr((string) $addr['country'], 0, 2) : null,
        'status'                => (string) ($zo['status'] ?? 'active') === 'active' ? 'active' : 'inactive',
    ];

    $action = 'unchanged';
    if (!$internalId) {
        $pdo->prepare(
            'INSERT INTO staffing_clients
                (tenant_id, name, legal_name, primary_contact_email, primary_contact_phone,
                 billing_address_line1, billing_city, billing_state, billing_postal_code, billing_country, status)
             VALUES (:t, :n, :ln, :em, :ph, :a1, :ci, :st, :pc, :co, :s)'
        )->execute([
            't' => $tenantId, 'n' => $payload['name'], 'ln' => $payload['legal_name'],
            'em' => $payload['primary_contact_email'], 'ph' => $payload['primary_contact_phone'],
            'a1' => $payload['billing_address_line1'], 'ci' => $payload['billing_city'],
            'st' => $payload['billing_state'], 'pc' => $payload['billing_postal_code'],
            'co' => $payload['billing_country'], 's' => $payload['status'],
        ]);
        $internalId = (int) $pdo->lastInsertId();
        $action = 'created';
    } else {
        $cur = $pdo->prepare('SELECT * FROM staffing_clients WHERE id = :id AND tenant_id = :t LIMIT 1');
        $cur->execute(['id' => $internalId, 't' => $tenantId]);
        $row = $cur->fetch(\PDO::FETCH_ASSOC) ?: [];
        $changed = false;
        foreach ($payload as $k => $v) {
            if ((string) ($row[$k] ?? '') !== (string) ($v ?? '')) { $changed = true; break; }
        }
        if ($changed) {
            $pdo->prepare(
                'UPDATE staffing_clients
                    SET legal_name = :ln, primary_contact_email = :em, primary_contact_phone = :ph,
                        billing_address_line1 = :a1, billing_city = :ci, billing_state = :st,
                        billing_postal_code = :pc, billing_country = :co, status = :s
                  WHERE id = :id AND tenant_id = :t'
            )->execute([
                'ln' => $payload['legal_name'], 'em' => $payload['primary_contact_email'],
                'ph' => $payload['primary_contact_phone'], 'a1' => $payload['billing_address_line1'],
                'ci' => $payload['billing_city'], 'st' => $payload['billing_state'],
                'pc' => $payload['billing_postal_code'], 'co' => $payload['billing_country'],
                's'  => $payload['status'], 'id' => $internalId, 't' => $tenantId,
            ]);
            $action = 'updated';
        }
    }

    mappingUpsert($tenantId, ZOHO_BOOKS_SOURCE, 'customer', $zoId, $internalId, $zo, 'pull');
    return ['internal_id' => $internalId, 'action' => $action];
}

/**
 * Zoho Vendor → ap_vendors_index.
 *
 * Match: (1) existing mapping (2) name match via uq_apv_tenant_name
 * (3) INSERT new.
 */
function zohoBooksUpsertVendor(int $tenantId, array $zo): array
{
    $zoId = (string) ($zo['contact_id'] ?? '');
    if ($zoId === '') throw new \InvalidArgumentException('Zoho vendor missing contact_id');

    $name  = trim((string) ($zo['contact_name'] ?? $zo['company_name'] ?? ''));
    if ($name === '') throw new \InvalidArgumentException('Zoho vendor missing contact_name');
    $email = trim((string) ($zo['email'] ?? ''));
    $phone = trim((string) ($zo['phone'] ?? $zo['mobile'] ?? ''));

    $pdo = getDB();
    $mapping = mappingFindInternal($tenantId, ZOHO_BOOKS_SOURCE, 'vendor', $zoId);
    $internalId = $mapping ? (int) $mapping['internal_entity_id'] : 0;
    if (!$internalId) {
        $stmt = $pdo->prepare('SELECT id FROM ap_vendors_index WHERE tenant_id = :t AND vendor_name = :n LIMIT 1');
        $stmt->execute(['t' => $tenantId, 'n' => $name]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $internalId = $row ? (int) $row['id'] : 0;
    }

    $payload = [
        'vendor_name' => $name,
        'email'       => $email !== '' ? $email : null,
        'phone'       => $phone !== '' ? $phone : null,
        'status'      => (string) ($zo['status'] ?? 'active') === 'active' ? 'active' : 'inactive',
    ];

    $action = 'unchanged';
    if (!$internalId) {
        $pdo->prepare(
            'INSERT INTO ap_vendors_index (tenant_id, vendor_name, email, phone, status)
             VALUES (:t, :n, :em, :ph, :s)'
        )->execute([
            't' => $tenantId, 'n' => $payload['vendor_name'],
            'em' => $payload['email'], 'ph' => $payload['phone'], 's' => $payload['status'],
        ]);
        $internalId = (int) $pdo->lastInsertId();
        $action = 'created';
    } else {
        $cur = $pdo->prepare('SELECT * FROM ap_vendors_index WHERE id = :id AND tenant_id = :t LIMIT 1');
        $cur->execute(['id' => $internalId, 't' => $tenantId]);
        $row = $cur->fetch(\PDO::FETCH_ASSOC) ?: [];
        $changed = false;
        foreach ($payload as $k => $v) {
            if ((string) ($row[$k] ?? '') !== (string) ($v ?? '')) { $changed = true; break; }
        }
        if ($changed) {
            $pdo->prepare(
                'UPDATE ap_vendors_index SET email = :em, phone = :ph, status = :s
                  WHERE id = :id AND tenant_id = :t'
            )->execute([
                'em' => $payload['email'], 'ph' => $payload['phone'],
                's' => $payload['status'], 'id' => $internalId, 't' => $tenantId,
            ]);
            $action = 'updated';
        }
    }

    mappingUpsert($tenantId, ZOHO_BOOKS_SOURCE, 'vendor', $zoId, $internalId, $zo, 'pull');
    return ['internal_id' => $internalId, 'action' => $action];
}
