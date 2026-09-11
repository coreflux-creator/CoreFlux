<?php
/**
 * QuickBooks Online — Slice 3 inbound sync drivers.
 *
 * Pulls QBO Customer + Vendor master data into CoreFlux, idempotent
 * via `external_entity_mappings` (source='quickbooks_online',
 * entity_type='customer' | 'vendor'). Tenants must have the relevant
 * entity's direction set to `pull` or `two_way`.
 *
 * - QBO Customer  → CoreFlux `staffing_clients`
 *   Match-by-name UNIQUE KEY uq_sc_tenant_name (tenant_id, name).
 * - QBO Vendor    → CoreFlux `ap_vendors_index`
 *   Match-by-name UNIQUE KEY uq_apv_tenant_name (tenant_id, vendor_name).
 *
 * QBO Query API pagination:
 *   /v3/company/{realm}/query?query=SELECT * FROM Customer STARTPOSITION 1 MAXRESULTS 1000
 *
 * Each page is upserted, then we advance STARTPOSITION until QBO returns
 * fewer rows than requested (or the optional `limit` opt is reached).
 *
 * Public surface:
 *   qboSyncCustomers(int $tid, ?int $userId, array $opts=[]): array
 *   qboSyncVendors(int $tid, ?int $userId, array $opts=[]): array
 *   qboUpsertCustomer(int $tid, array $qboCustomer): array  // {internal_id, action: 'created'|'updated'|'unchanged'}
 *   qboUpsertVendor(int $tid, array $qboVendor): array
 *
 * Opts shared by both syncers:
 *   - limit:   int (default 1000) — max records to ingest this run.
 *   - max_pages: int (default 10) — safety net against runaway loops.
 *   - modified_since: optional ISO datetime for incremental scheduled pulls.
 */
declare(strict_types=1);

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/../integrations/entity_mappings.php';
require_once __DIR__ . '/conflict_rules.php';
require_once __DIR__ . '/../../modules/staffing/lib/clients.php';

const QBO_PAGE_SIZE = 100;

// =====================================================================
// Customer pull → staffing_clients
// =====================================================================

function qboSyncCustomers(int $tenantId, ?int $userId, array $opts = []): array
{
    return _qboSyncMasterEntity($tenantId, $userId, $opts, [
        'entity'       => 'customer',
        'qbo_resource' => 'Customer',
        'upsert'       => 'qboUpsertCustomer',
    ]);
}

function qboSyncVendors(int $tenantId, ?int $userId, array $opts = []): array
{
    return _qboSyncMasterEntity($tenantId, $userId, $opts, [
        'entity'       => 'vendor',
        'qbo_resource' => 'Vendor',
        'upsert'       => 'qboUpsertVendor',
    ]);
}

function _qboSyncMasterEntity(int $tenantId, ?int $userId, array $opts, array $cfg): array
{
    $start    = microtime(true);
    $limit    = max(1, min(5000, (int) ($opts['limit'] ?? 1000)));
    $maxPages = max(1, min(50,    (int) ($opts['max_pages'] ?? 10)));
    $since    = trim((string) ($opts['modified_since'] ?? ''));

    $conn = qboConnection($tenantId);
    if (!$conn || $conn['status'] !== 'active') {
        throw new \RuntimeException('QuickBooks is not connected for this tenant');
    }
    $entity     = $cfg['entity'];                 // 'customer' | 'vendor'
    $resource   = $cfg['qbo_resource'];           // 'Customer' | 'Vendor'
    $upsertFn   = $cfg['upsert'];
    $cfgKey     = $entity . 's';                  // 'customers' | 'vendors'
    $config     = qboSyncConfigRead($tenantId);
    if (!in_array($config[$cfgKey] ?? 'off', ['pull', 'two_way'], true)) {
        throw new \RuntimeException(ucfirst($entity) . 's direction is not pull/two_way for this tenant');
    }
    $realm = (string) $conn['realm_id'];

    $created = 0; $updated = 0; $unchanged = 0; $failed = 0;
    $startPos = 1;
    $pulled   = 0;
    $pages    = 0;
    $results  = [];

    while ($pulled < $limit && $pages < $maxPages) {
        $pages++;
        $pageSize = min(QBO_PAGE_SIZE, $limit - $pulled);
        $where = $since !== ''
            ? " WHERE MetaData.LastUpdatedTime >= '" . addslashes($since) . "'"
            : '';
        $query = sprintf('SELECT * FROM %s%s STARTPOSITION %d MAXRESULTS %d', $resource, $where, $startPos, $pageSize);
        try {
            $resp = qboCall($tenantId, 'GET', '/v3/company/' . $realm . '/query', null, [
                'query'        => $query,
                'minorversion' => 65,
            ]);
        } catch (\Throwable $e) {
            qboAudit($tenantId, 'sync_' . $entity . '_error', [
                'ok' => false, 'actor_user_id' => $userId,
                'direction' => 'pull', 'entity_type' => $entity,
                'detail' => ['error' => substr($e->getMessage(), 0, 500), 'page' => $pages, 'startPosition' => $startPos],
            ]);
            throw $e;
        }
        $rows = $resp['QueryResponse'][$resource] ?? [];
        if (!is_array($rows) || count($rows) === 0) break;

        foreach ($rows as $row) {
            try {
                $r = $upsertFn($tenantId, $row);
                $action = $r['action'] ?? 'unchanged';
                if      ($action === 'created')   $created++;
                elseif  ($action === 'updated')   $updated++;
                else                              $unchanged++;
                $results[] = [
                    'qbo_id'      => (string) ($row['Id'] ?? ''),
                    'name'        => (string) ($row['DisplayName'] ?? $row['CompanyName'] ?? ''),
                    'internal_id' => $r['internal_id'] ?? null,
                    'action'      => $action,
                ];
            } catch (\Throwable $e) {
                $failed++;
                $results[] = [
                    'qbo_id' => (string) ($row['Id'] ?? ''),
                    'name'   => (string) ($row['DisplayName'] ?? $row['CompanyName'] ?? ''),
                    'action' => 'failed', 'error' => substr($e->getMessage(), 0, 300),
                ];
            }
        }
        $pulled += count($rows);
        if (count($rows) < $pageSize) break;   // last page
        $startPos += count($rows);
    }

    $latency = (int) round((microtime(true) - $start) * 1000);
    qboAudit($tenantId, 'sync_' . $entity, [
        'entity_type' => $entity, 'direction' => 'pull',
        'ok' => $failed === 0,
        'actor_user_id'   => $userId,
        'items_processed' => $created + $updated,
        'items_skipped'   => $unchanged,
        'items_failed'    => $failed,
        'detail' => [
            'created' => $created, 'updated' => $updated, 'unchanged' => $unchanged,
            'failed'  => $failed,  'pulled'  => $pulled,
            'pages'   => $pages,   'latency_ms' => $latency,
            'modified_since' => $since,
        ],
    ]);
    return [
        'entity'    => $entity,
        'created'   => $created,
        'updated'   => $updated,
        'unchanged' => $unchanged,
        'failed'    => $failed,
        'pulled'    => $pulled,
        'pages'     => $pages,
        'latency_ms'=> $latency,
        'modified_since' => $since,
        'results'   => $results,
    ];
}

// =====================================================================
// Upserters — translate QBO payload → CoreFlux row
// =====================================================================

/**
 * QBO Customer → staffing_clients.
 *
 * Match strategy (in order):
 *   1. existing mapping (source='quickbooks_online', entity_type='customer', external_id=QBO Id)
 *   2. UNIQUE KEY uq_sc_tenant_name → match by name (auto-link an existing
 *      manually-created client and bind the mapping)
 *   3. ensure one canonical company/client row in the shared placement catalog.
 */
function qboUpsertCustomer(int $tenantId, array $qbo): array
{
    $qboId = (string) ($qbo['Id'] ?? '');
    if ($qboId === '') throw new \InvalidArgumentException('QBO Customer missing Id');

    $displayName = trim((string) ($qbo['DisplayName'] ?? $qbo['CompanyName'] ?? ''));
    if ($displayName === '') throw new \InvalidArgumentException('QBO Customer missing DisplayName/CompanyName');
    $legalName   = trim((string) ($qbo['CompanyName']   ?? ''));
    $email       = trim((string) ($qbo['PrimaryEmailAddr']['Address']  ?? ''));
    $phone       = trim((string) ($qbo['PrimaryPhone']['FreeFormNumber'] ?? ''));
    $addr        = is_array($qbo['BillAddr'] ?? null) ? $qbo['BillAddr'] : [];
    $countryRaw  = trim((string) ($addr['Country'] ?? ''));
    $country     = in_array(strtolower($countryRaw), ['us', 'usa', 'united states', 'united states of america'], true)
        ? 'US'
        : ($countryRaw !== '' ? strtoupper(substr($countryRaw, 0, 2)) : null);
    $isSubcustomer = !empty($qbo['Job'])
        || trim((string) ($qbo['ParentRef']['value'] ?? '')) !== '';

    $pdo = getDB();
    $clientTenantId = staffingClientCatalogTenantId($tenantId);
    $mapping = mappingFindInternal($tenantId, QBO_SOURCE, 'customer', $qboId);
    $internalId = $mapping ? (int) $mapping['internal_entity_id'] : 0;
    $existing = null;

    // Old releases could map customers to sub-tenant-local rows. Trust a
    // mapping only when it points into the shared placement/client catalog.
    if ($internalId) {
        $stmt = $pdo->prepare('SELECT * FROM staffing_clients WHERE tenant_id = :t AND id = :id LIMIT 1');
        $stmt->execute(['t' => $clientTenantId, 'id' => $internalId]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        $snapshot = !empty($mapping['payload_snapshot'])
            ? json_decode((string) $mapping['payload_snapshot'], true)
            : null;
        $mappedName = is_array($snapshot) ? trim((string) ($snapshot['DisplayName'] ?? $snapshot['CompanyName'] ?? '')) : '';
        $catalogName = trim((string) ($existing['name'] ?? ''));
        if (!$existing || ($mappedName !== ''
            && strcasecmp($catalogName, $mappedName) !== 0
            && strcasecmp($catalogName, $displayName) !== 0)) {
            $internalId = 0;
            $existing = null;
        }
    }

    if (!$internalId) {
        // JobDiva, placements, CSV, and QBO converge on this same row.
        $stmt = $pdo->prepare('SELECT * FROM staffing_clients WHERE tenant_id = :t AND name = :n LIMIT 1');
        $stmt->execute(['t' => $clientTenantId, 'n' => $displayName]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        $internalId = $existing ? (int) $existing['id'] : 0;
    }

    $status = 'active';
    if ($isSubcustomer || (array_key_exists('Active', $qbo) && empty($qbo['Active']))) {
        $activePlacement = $pdo->prepare(
            "SELECT 1 FROM placements p
         LEFT JOIN staffing_clients sc
                ON sc.tenant_id = p.tenant_id
               AND sc.id = :client_id
              WHERE p.tenant_id = :t
                AND p.status = 'active'
                AND p.deleted_at IS NULL
                AND (
                     p.end_client_company_id = sc.company_id
                  OR (sc.id IS NULL AND p.end_client_name = :name)
                )
              LIMIT 1"
        );
        $activePlacement->execute([
            't' => $clientTenantId,
            'client_id' => $internalId ?: 0,
            'name' => $displayName,
        ]);
        $status = $activePlacement->fetchColumn() ? 'active' : 'inactive';
    }

    $payload = [
        'name'                  => $displayName,
        'legal_name'            => $legalName !== '' ? $legalName : null,
        'primary_contact_email' => $email !== '' ? $email : null,
        'primary_contact_phone' => $phone !== '' ? $phone : null,
        'billing_address_line1' => isset($addr['Line1']) ? (string) $addr['Line1'] : null,
        'billing_address_line2' => isset($addr['Line2']) ? (string) $addr['Line2'] : null,
        'billing_city'          => isset($addr['City'])  ? (string) $addr['City']  : null,
        'billing_state'         => isset($addr['CountrySubDivisionCode']) ? substr((string) $addr['CountrySubDivisionCode'], 0, 40) : null,
        'billing_postal_code'   => isset($addr['PostalCode']) ? (string) $addr['PostalCode'] : null,
        'billing_country'       => $country,
        'status'                => $status,
    ];
    $action = $internalId ? 'unchanged' : 'created';
    if ($internalId) {
        // Slice 5 — conflict detection for two_way customers.
        $cur = $existing ?: [];
        $conflict = qboDetectConflict($tenantId, 'customer', $internalId, $qboId, $qbo, $cur['updated_at'] ?? null);
        if ($conflict['winner'] === 'coreflux') {
            // CoreFlux side wins → don't overwrite locally; pretend no change.
            return ['internal_id' => $internalId, 'action' => 'conflict_coreflux_wins'];
        }
        foreach ($payload as $k => $v) {
            if ((string) ($cur[$k] ?? '') !== (string) ($v ?? '')) {
                $action = 'updated';
                break;
            }
        }
    }

    $clientRef = staffingClientEnsureForCompany(
        $clientTenantId,
        !empty($existing['company_id']) ? (int) $existing['company_id'] : null,
        $displayName,
        $payload + ['sync_company_patch' => true]
    );
    $internalId = (int) $clientRef['client_id'];

    $row = mappingUpsert($tenantId, QBO_SOURCE, 'customer', $qboId, $internalId, $qbo, 'pull');
    if (!$row['changed'] && $action === 'unchanged') $action = 'unchanged';
    return ['internal_id' => $internalId, 'action' => $action];
}

/**
 * QBO Vendor → ap_vendors_index.
 *
 * Mirrors qboUpsertCustomer's match-strategy and content-hash flow,
 * adapted to the vendor schema's columns.
 */
function qboUpsertVendor(int $tenantId, array $qbo): array
{
    $qboId = (string) ($qbo['Id'] ?? '');
    if ($qboId === '') throw new \InvalidArgumentException('QBO Vendor missing Id');

    $displayName = trim((string) ($qbo['DisplayName'] ?? $qbo['CompanyName'] ?? ''));
    if ($displayName === '') throw new \InvalidArgumentException('QBO Vendor missing DisplayName/CompanyName');
    // QBO Vendor.Vendor1099 flag → 1099 readiness signal.
    $is1099 = !empty($qbo['Vendor1099']) ? 1 : 0;
    $type = $is1099 ? '1099_individual' : 'other';

    $pdo = getDB();
    $mapping = mappingFindInternal($tenantId, QBO_SOURCE, 'vendor', $qboId);
    $internalId = $mapping ? (int) $mapping['internal_entity_id'] : 0;
    if (!$internalId) {
        $stmt = $pdo->prepare('SELECT id FROM ap_vendors_index WHERE tenant_id = :t AND vendor_name = :n LIMIT 1');
        $stmt->execute(['t' => $tenantId, 'n' => $displayName]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $internalId = $row ? (int) $row['id'] : 0;
    }
    $action = 'unchanged';
    if (!$internalId) {
        $pdo->prepare(
            'INSERT INTO ap_vendors_index
                (tenant_id, vendor_name, vendor_type, requires_1099)
             VALUES (:t, :n, :ty, :r)'
        )->execute([
            't'  => $tenantId,
            'n'  => $displayName,
            'ty' => $type,
            'r'  => $is1099,
        ]);
        $internalId = (int) $pdo->lastInsertId();
        $action = 'created';
    } else {
        // Cheap dirty-check on vendor_type + requires_1099.
        $stmt = $pdo->prepare('SELECT vendor_type, requires_1099 FROM ap_vendors_index WHERE id = :id AND tenant_id = :t LIMIT 1');
        $stmt->execute(['id' => $internalId, 't' => $tenantId]);
        $cur = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $changed = ((string) ($cur['vendor_type']   ?? '') !== $type)
                || ((int)    ($cur['requires_1099'] ?? -1) !== $is1099);
        if ($changed) {
            $pdo->prepare(
                'UPDATE ap_vendors_index
                    SET vendor_type = :ty, requires_1099 = :r
                  WHERE id = :id AND tenant_id = :t'
            )->execute(['ty' => $type, 'r' => $is1099, 'id' => $internalId, 't' => $tenantId]);
            $action = 'updated';
        }
    }
    $row = mappingUpsert($tenantId, QBO_SOURCE, 'vendor', $qboId, $internalId, $qbo, 'pull');
    if (!$row['changed'] && $action === 'unchanged') $action = 'unchanged';
    return ['internal_id' => $internalId, 'action' => $action];
}
